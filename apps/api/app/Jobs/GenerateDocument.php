<?php

namespace App\Jobs;

use App\Contracts\Documents\DocumentRenderer;
use App\Enums\DocumentGenerationStatus;
use App\Models\DocumentGenerationRequest;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use App\Services\Automation\OutboxRecorder;
use App\Services\Documents\DocumentArtifactStore;
use App\Services\Documents\QueueGeneratedDocumentEmail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GenerateDocument implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $requestId)
    {
        $this->tries = (int) config('documents.jobs.documents.tries');
        $this->timeout = (int) config('documents.jobs.documents.timeout');
        $this->onQueue((string) config('documents.jobs.documents.queue'));
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('documents.jobs.documents.backoff');
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->toImmutable()->addMinutes((int) config('documents.jobs.documents.retry_for_minutes'));
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('document:'.$this->requestId))->expireAfter((int) config('documents.jobs.documents.overlap_expire_after'))];
    }

    public function handle(
        DocumentRenderer $renderer,
        DocumentArtifactStore $artifacts,
        OutboxRecorder $outbox,
        TenantContext $context,
        QueueGeneratedDocumentEmail $emails,
    ): void {
        $unscoped = DocumentGenerationRequest::withoutGlobalScopes()->findOrFail($this->requestId);
        $tenant = Tenant::query()->findOrFail($unscoped->tenant_id);
        $previous = $context->check() ? [$context->tenant(), $context->membership()] : null;
        $context->clear();
        $context->set($tenant);
        $stored = null;

        try {
            $request = DB::transaction(function (): DocumentGenerationRequest {
                $request = DocumentGenerationRequest::query()->whereKey($this->requestId)->lockForUpdate()->firstOrFail();
                if ($request->status === DocumentGenerationStatus::Generated) {
                    return $request;
                }
                $request->forceFill([
                    'status' => DocumentGenerationStatus::Processing,
                    'attempts' => $request->attempts + 1,
                    'started_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                ])->save();

                return $request;
            }, 3);
            if ($request->status === DocumentGenerationStatus::Generated) {
                $document = $request->generatedDocument()->first();
                if ($document !== null && in_array($document->kind, ['payment_receipt', 'refund_receipt'], true)) {
                    $emails->handleSystemReceipt($document);
                }

                return;
            }

            $bytes = $renderer->render($request->kind, $request->source_snapshot);
            $confirmation = data_get($request->source_snapshot, 'payload.reservation.confirmation', 'reservation');
            $stored = $artifacts->put($request->tenant_id, $request->id, $bytes, $request->kind->value.'-'.$confirmation.'.pdf');

            $document = DB::transaction(function () use ($request, $stored, $renderer, $outbox): GeneratedDocument {
                $locked = DocumentGenerationRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                $existing = $locked->generatedDocument()->first();
                if ($existing !== null) {
                    $locked->forceFill(['status' => DocumentGenerationStatus::Generated, 'completed_at' => now()])->save();

                    return $existing;
                }
                $document = GeneratedDocument::query()->create([
                    'document_generation_request_id' => $locked->id,
                    'document_template_id' => $locked->document_template_id,
                    'reservation_id' => $locked->reservation_id,
                    'guest_id' => $locked->guest_id,
                    'payment_id' => $locked->payment_id,
                    'reservation_change_id' => $locked->reservation_change_id,
                    'replaces_document_id' => data_get($locked->source_snapshot, 'replacement.replaces_document_id'),
                    'kind' => $locked->kind->value,
                    'status' => 'generated',
                    'storage_path' => $stored['path'],
                    'checksum' => $stored['checksum'],
                    'storage_disk' => $stored['disk'],
                    'file_name' => $stored['file_name'],
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                    'source_checksum' => $locked->source_checksum,
                    'renderer' => $renderer->name(),
                    'renderer_version' => $renderer->version(),
                    'template_version' => data_get($locked->source_snapshot, 'template.version'),
                    'locale' => $locked->locale,
                    'generated_at' => now(),
                    'expires_at' => null,
                    'metadata' => ['source_schema_version' => data_get($locked->source_snapshot, 'schema_version')],
                ]);
                $locked->forceFill(['status' => DocumentGenerationStatus::Generated, 'completed_at' => now(), 'last_error' => null])->save();
                $outbox->record('generated_document', $document->id, 'document.generated', [
                    'document_id' => $document->id, 'request_id' => $locked->id, 'kind' => $locked->kind->value,
                ]);

                return $document;
            }, 3);
            if (in_array($document->kind, ['payment_receipt', 'refund_receipt'], true)) {
                $emails->handleSystemReceipt($document);
            }
        } catch (Throwable $exception) {
            if (is_array($stored)) {
                $artifacts->delete($stored['disk'], $stored['path']);
            }
            DocumentGenerationRequest::query()->whereKey($this->requestId)->update([
                'status' => DocumentGenerationStatus::Failed->value,
                'failed_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 1000),
            ]);
            throw $exception;
        } finally {
            $context->clear();
            if ($previous !== null) {
                $context->set($previous[0], $previous[1]);
            }
        }
    }
}
