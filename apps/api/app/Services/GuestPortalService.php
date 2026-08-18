<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\GuestPortalStorageException;
use App\Models\GuestPaymentEvidence;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalAcknowledgement;
use App\Models\GuestPortalDocument;
use App\Models\GuestPortalProfile;
use App\Models\Reservation;
use App\Models\Survey;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GuestPortalService
{
    public function reservation(GuestPortalAccessToken $access): Reservation
    {
        $reservation = Reservation::query()
            ->whereKey($access->reservation_id)
            ->where(function ($query) use ($access): void {
                $query->where('primary_guest_id', $access->guest_id)
                    ->orWhereHas('guests', fn ($guestQuery) => $guestQuery->whereKey($access->guest_id));
            })
            ->firstOrFail();

        $reservation->load([
            'property.guestPortalDocuments' => fn ($query) => $query->where('is_active', true)->latest('created_at'),
            'allocations.requestedCategory',
            'allocations.resource.category',
            'allocations.serviceOccurrence.program',
            'guestPortalProfiles' => fn ($query) => $query->where('guest_id', $access->guest_id),
            'guestPortalAcknowledgements' => fn ($query) => $query->where('guest_id', $access->guest_id),
            'guestPaymentEvidence' => fn ($query) => $query->where('guest_id', $access->guest_id)->latest('submitted_at'),
            'payments',
            'surveys' => fn ($query) => $query->where('guest_id', $access->guest_id)->where('kind', 'post_stay'),
            'generatedDocuments' => fn ($query) => $query->where('status', 'generated')->whereNull('purged_at')->where(fn ($availability) => $availability->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('generated_at'),
        ]);

        $guests = $reservation->guests()->whereKey($access->guest_id)->get();
        if ($reservation->primary_guest_id === $access->guest_id && $reservation->primaryGuest !== null) {
            $guests->prepend($reservation->primaryGuest);
        }
        $reservation->setRelation('guestsForPortal', $guests->unique('id')->values());

        return $reservation;
    }

    /** @param array<string, mixed> $data */
    public function updatePreArrival(GuestPortalAccessToken $access, array $data): Reservation
    {
        GuestPortalProfile::query()->updateOrCreate(
            ['reservation_id' => $access->reservation_id, 'guest_id' => $access->guest_id],
            [
                'profile' => $data['profile'],
                'travel' => $data['travel'],
                'preferences' => $data['preferences'],
                'consented_at' => now(),
            ],
        );

        return $this->reservation($access);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{acknowledged: true, acknowledged_at: string}
     */
    public function acknowledge(
        GuestPortalAccessToken $access,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $reservation = $this->reservation($access);
        $document = GuestPortalDocument::query()
            ->where('property_id', $reservation->property_id)
            ->where('slug', $data['document_slug'])
            ->where('version', $data['document_version'])
            ->where('body_hash', $data['document_hash'])
            ->where('is_active', true)
            ->first();

        if ($document === null) {
            throw new DomainException('This document version is unavailable.');
        }

        if (GuestPortalAcknowledgement::query()
            ->where('reservation_id', $access->reservation_id)
            ->where('guest_id', $access->guest_id)
            ->where('document_id', $document->id)
            ->exists()) {
            throw new DomainException('This document has already been acknowledged.');
        }

        $acknowledgedAt = now();
        GuestPortalAcknowledgement::query()->create([
            'reservation_id' => $access->reservation_id,
            'guest_id' => $access->guest_id,
            'document_id' => $document->id,
            'signature' => $data['signature'],
            'document_hash' => $document->body_hash,
            'acknowledged_at' => $acknowledgedAt,
            'ip_address' => $ipAddress,
            'user_agent' => mb_substr((string) $userAgent, 0, 2000),
        ]);

        return ['acknowledged' => true, 'acknowledged_at' => $acknowledgedAt->toIso8601String()];
    }

    /** @return array{file_name: string, status: string, submitted_at: string} */
    public function storePaymentEvidence(GuestPortalAccessToken $access, UploadedFile $upload, array $data): array
    {
        $reservation = $this->reservation($access);
        $currency = strtoupper((string) ($data['currency'] ?? $reservation->currency));
        if ($currency !== $reservation->currency) {
            abort(422, 'Evidence currency must match the reservation currency.');
        }
        $amountMinor = (int) ($data['amount_minor'] ?? max(1, app(FolioService::class)->summary($reservation)['balance_minor']));
        $realPath = $upload->getRealPath();
        $contentType = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $sizeBytes = filesize($realPath);
        $sha256 = hash_file('sha256', $realPath);

        if (! is_string($contentType) || ! is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > 10 * 1024 * 1024) {
            abort(422, 'Invalid evidence file.');
        }

        $extension = match ($contentType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => abort(422, 'Unsupported evidence type.'),
        };
        app(PaymentEvidenceScanner::class)->assertSafe($upload);
        $existing = GuestPaymentEvidence::query()
            ->where('reservation_id', $access->reservation_id)
            ->where('guest_id', $access->guest_id)
            ->where('sha256', $sha256)
            ->first();
        if ($existing !== null) {
            return $this->paymentEvidenceResult($existing);
        }
        $directory = "guest-payment-evidence/{$access->tenant_id}/{$access->reservation_id}";
        $storagePath = Storage::disk('local')->putFileAs($directory, $upload, Str::uuid().'.'.$extension);

        if (! is_string($storagePath)) {
            throw new GuestPortalStorageException('Unable to store payment evidence.');
        }

        try {
            $submittedAt = now();
            $evidence = GuestPaymentEvidence::query()->create([
                'reservation_id' => $access->reservation_id,
                'guest_id' => $access->guest_id,
                'file_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'content_type' => $contentType,
                'size_bytes' => $sizeBytes,
                'sha256' => $sha256,
                'storage_path' => $storagePath,
                'status' => 'review_pending',
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'transfer_reference' => trim((string) ($data['transfer_reference'] ?? '')) ?: null,
                'scan_status' => 'accepted',
                'submitted_at' => $submittedAt,
            ]);
        } catch (QueryException $exception) {
            Storage::disk('local')->delete($storagePath);
            $existing = GuestPaymentEvidence::query()
                ->where('reservation_id', $access->reservation_id)
                ->where('guest_id', $access->guest_id)
                ->where('sha256', $sha256)
                ->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->paymentEvidenceResult($existing);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storagePath);
            throw $exception;
        }

        return $this->paymentEvidenceResult($evidence);
    }

    public function folio(GuestPortalAccessToken $access): Reservation
    {
        $reservation = $this->reservation($access);
        $reservation->load('folioLines');

        return $reservation;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{submitted: true, responded_at: string}
     */
    public function storeSurvey(GuestPortalAccessToken $access, array $data): array
    {
        $reservation = $this->reservation($access);

        if (! $this->feedbackAvailable($reservation)) {
            throw new DomainException('Feedback opens after departure.');
        }

        if (Survey::query()
            ->where('reservation_id', $access->reservation_id)
            ->where('guest_id', $access->guest_id)
            ->where('kind', 'post_stay')
            ->exists()) {
            throw new DomainException('Feedback has already been submitted.');
        }

        $respondedAt = now();
        Survey::query()->create([
            'reservation_id' => $access->reservation_id,
            'guest_id' => $access->guest_id,
            'kind' => 'post_stay',
            'score' => $data['stay_rating'],
            'answers' => $data,
            'responded_at' => $respondedAt,
        ]);

        return ['submitted' => true, 'responded_at' => $respondedAt->toIso8601String()];
    }

    /** @return array{file_name: string, status: string, submitted_at: string} */
    private function paymentEvidenceResult(GuestPaymentEvidence $evidence): array
    {
        return [
            'file_name' => $evidence->file_name,
            'status' => $evidence->status->value,
            'submitted_at' => $evidence->submitted_at->toIso8601String(),
        ];
    }

    private function feedbackAvailable(Reservation $reservation): bool
    {
        return $reservation->status === ReservationStatus::CheckedOut
            || $reservation->actual_end_at?->isPast() === true
            || $reservation->ends_at->isPast();
    }
}
