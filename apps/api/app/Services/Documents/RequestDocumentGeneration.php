<?php

namespace App\Services\Documents;

use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use App\Jobs\GenerateDocument;
use App\Models\DocumentGenerationRequest;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\GuestPortalAcknowledgement;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class RequestDocumentGeneration
{
    public function __construct(
        private readonly DocumentSnapshotFactory $snapshots,
        private readonly CanonicalJson $canonical,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function handle(
        User $actor,
        Reservation $reservation,
        DocumentKind $kind,
        string $locale,
        string $idempotencyKey,
        ?Payment $payment = null,
        ?ReservationChange $change = null,
        ?GuestPortalAcknowledgement $acknowledgement = null,
        ?GeneratedDocument $replaces = null,
    ): DocumentGenerationRequest {
        $actor->can('generate', [DocumentGenerationRequest::class, $kind, $reservation]) || abort(403);
        $tenantId = app(TenantContext::class)->tenant()->id;
        if ($replaces !== null && ($replaces->reservation_id !== $reservation->id || $replaces->kind !== $kind->value)) {
            abort(422, 'A replacement must have the same reservation and document kind.');
        }
        $deduplicationKey = hash('sha256', implode('|', [$kind->value, $reservation->id, $payment?->id, $change?->id, $acknowledgement?->id, $replaces?->id, $locale, $idempotencyKey]));

        $request = DB::transaction(function () use ($actor, $reservation, $kind, $locale, $payment, $change, $acknowledgement, $replaces, $tenantId, $deduplicationKey): DocumentGenerationRequest {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if ($existing = DocumentGenerationRequest::query()->where('deduplication_key', $deduplicationKey)->first()) {
                return $existing;
            }
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $templates = DocumentTemplate::query()->where('kind', $kind->value)->where('is_active', true)->orderByDesc('version')->get();
            $template = $templates->first(fn (DocumentTemplate $item) => data_get($item->definition, 'locale') === $locale)
                ?? $templates->first(fn (DocumentTemplate $item) => data_get($item->definition, 'locale') === null)
                ?? $templates->first();
            abort_if($template === null, 422, 'No active trusted template is available for this document kind.');
            $snapshot = $this->snapshots->build($kind, $locked, $template, $locale, $payment, $change, $acknowledgement);
            if ($replaces !== null) {
                $snapshot['replacement'] = ['replaces_document_id' => $replaces->id];
            }
            $checksum = $this->canonical->checksum($snapshot);
            $created = DocumentGenerationRequest::query()->create([
                'requested_by' => $actor->id,
                'document_template_id' => $template->id,
                'reservation_id' => $locked->id,
                'guest_id' => $locked->primary_guest_id,
                'payment_id' => $payment?->id,
                'reservation_change_id' => $change?->id,
                'kind' => $kind,
                'locale' => $locale,
                'status' => DocumentGenerationStatus::Pending,
                'source_snapshot' => $snapshot,
                'source_checksum' => $checksum,
                'deduplication_key' => $deduplicationKey,
            ]);
            $this->outbox->record('document_generation_request', $created->id, 'document.generation.requested', [
                'request_id' => $created->id, 'reservation_id' => $locked->id, 'kind' => $kind->value,
            ]);

            return $created;
        }, 3);

        if ($request->wasRecentlyCreated) {
            DB::afterCommit(fn () => GenerateDocument::dispatch($request->id));
        }

        return $request;
    }
}
