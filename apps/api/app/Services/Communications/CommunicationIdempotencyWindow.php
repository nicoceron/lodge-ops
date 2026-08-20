<?php

namespace App\Services\Communications;

use App\Models\Communication;
use App\Models\DeliveryAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class CommunicationIdempotencyWindow
{
    /** @param Collection<int, DeliveryAttempt> $attempts */
    public function anchor(Communication $communication, Collection $attempts, CarbonImmutable $at): CarbonImmutable
    {
        if ($communication->delivery_idempotency_started_at !== null) {
            $startedAt = $communication->delivery_idempotency_started_at;
        } elseif ($attempts->isNotEmpty()) {
            $startedAt = $attempts->sortBy('attempted_at')->first()->attempted_at;
        } else {
            $startedAt = $at;
        }
        $expiresAt = $communication->delivery_idempotency_expires_at
            ?? $startedAt->addHours((int) config('communications.provider.idempotency_window_hours', 24));

        if ($communication->delivery_idempotency_started_at === null || $communication->delivery_idempotency_expires_at === null) {
            $communication->forceFill([
                'delivery_idempotency_started_at' => $startedAt,
                'delivery_idempotency_expires_at' => $expiresAt,
            ])->save();
        }

        return $expiresAt;
    }

    /** @param Collection<int, DeliveryAttempt> $attempts */
    public function hasAuthoritativeOutcome(Collection $attempts): bool
    {
        return $attempts->contains(fn (DeliveryAttempt $attempt): bool => in_array(
            $attempt->status,
            ['provider_accepted', 'sent', 'delivered', 'rejected', 'hard_bounced', 'complained', 'suppressed'],
            true,
        ));
    }

    /** @param Collection<int, DeliveryAttempt> $attempts */
    public function hasUnresolvedUncertainty(Collection $attempts): bool
    {
        return $attempts->contains(fn (DeliveryAttempt $attempt): bool => in_array(
            $attempt->status,
            ['sending', 'outcome_uncertain', 'reconciliation_required'],
            true,
        ));
    }

    /** @param Collection<int, DeliveryAttempt> $attempts */
    public function requiresReconciliation(Collection $attempts, CarbonImmutable $expiresAt, CarbonImmutable $at): bool
    {
        if ($attempts->contains(fn (DeliveryAttempt $attempt): bool => $attempt->status === 'reconciliation_required')) {
            return true;
        }

        return $at->greaterThanOrEqualTo($expiresAt) && $this->hasUnresolvedUncertainty($attempts);
    }

    /** @param Collection<int, DeliveryAttempt> $attempts */
    public function markReconciliationRequired(
        Communication $communication,
        Collection $attempts,
        CarbonImmutable $expiresAt,
    ): void {
        $ids = $attempts
            ->filter(fn (DeliveryAttempt $attempt): bool => in_array(
                $attempt->status,
                ['sending', 'outcome_uncertain', 'reconciliation_required'],
                true,
            ))
            ->pluck('id');
        if ($ids->isNotEmpty()) {
            DeliveryAttempt::query()->whereIn('id', $ids)->update([
                'status' => 'reconciliation_required',
                'retry_state' => 'reconciliation_required',
                'safe_error' => 'Provider outcome remained uncertain beyond the immutable idempotency window.',
                'reconcile_after' => $expiresAt,
            ]);
        }
        $communication->forceFill(['status' => 'reconciliation_required'])->save();
    }
}
