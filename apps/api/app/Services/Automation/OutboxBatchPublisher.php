<?php

namespace App\Services\Automation;

use App\Jobs\PublishOutboxMessage;
use App\Models\Outbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OutboxBatchPublisher
{
    private const MAX_ATTEMPTS = 10;

    private const STALE_CLAIM_MINUTES = 5;

    /**
     * @return list<array{id: string, tenant_id: string, claim_token: string}>
     */
    public function claim(int $batchSize = 100): array
    {
        $batchSize = max(1, min($batchSize, 500));

        return DB::transaction(function () use ($batchSize): array {
            $claimToken = (string) Str::uuid();
            $now = now();

            $messages = $this->claimableQuery()
                ->lockForUpdate()
                ->orderBy('occurred_at')
                ->limit($batchSize)
                ->get(['id', 'tenant_id']);

            if ($messages->isEmpty()) {
                return [];
            }

            Outbox::withoutGlobalScopes()
                ->whereIn('id', $messages->pluck('id'))
                ->whereNull('published_at')
                ->update(['claim_token' => $claimToken, 'claimed_at' => $now]);

            return $messages
                ->map(fn (Outbox $message): array => [
                    'id' => $message->id,
                    'tenant_id' => $message->tenant_id,
                    'claim_token' => $claimToken,
                ])
                ->all();
        }, 3);
    }

    public function publish(int $batchSize = 100): int
    {
        $claimed = $this->claim($batchSize);

        foreach ($claimed as $message) {
            $this->dispatchClaimed($message);
        }

        return count($claimed);
    }

    public function publishOne(string $tenantId, string $messageId): bool
    {
        $claimed = DB::transaction(function () use ($tenantId, $messageId): ?array {
            $message = $this->claimableQuery()
                ->where('tenant_id', $tenantId)
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first(['id', 'tenant_id']);

            if ($message === null) {
                return null;
            }

            $claimToken = (string) Str::uuid();

            Outbox::withoutGlobalScopes()
                ->whereKey($message->id)
                ->where('tenant_id', $tenantId)
                ->whereNull('published_at')
                ->update(['claim_token' => $claimToken, 'claimed_at' => now()]);

            return ['id' => $message->id, 'tenant_id' => $tenantId, 'claim_token' => $claimToken];
        }, 3);

        if ($claimed === null) {
            return false;
        }

        $this->dispatchClaimed($claimed);

        return true;
    }

    private function claimableQuery(): Builder
    {
        return Outbox::withoutGlobalScopes()
            ->whereNull('published_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('available_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', now()->subMinutes(self::STALE_CLAIM_MINUTES));
            });
    }

    /** @param array{id: string, tenant_id: string, claim_token: string} $message */
    private function dispatchClaimed(array $message): void
    {
        try {
            PublishOutboxMessage::dispatch(
                $message['tenant_id'],
                $message['id'],
                $message['claim_token'],
            )->afterCommit();
        } catch (Throwable $exception) {
            Outbox::withoutGlobalScopes()
                ->whereKey($message['id'])
                ->where('tenant_id', $message['tenant_id'])
                ->where('claim_token', $message['claim_token'])
                ->update([
                    'claim_token' => null,
                    'claimed_at' => null,
                    'last_error' => 'Queue dispatch failed: '.$exception->getMessage(),
                    'available_at' => now()->addMinute(),
                ]);

            throw $exception;
        }
    }
}
