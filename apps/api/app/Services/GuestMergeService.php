<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\GuestMergeAlias;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class GuestMergeService
{
    public function merge(Guest $source, Guest $target): Guest
    {
        if ($source->is($target)) {
            throw new DomainException('A guest cannot be merged into itself.');
        }

        return DB::transaction(function () use ($source, $target): Guest {
            $tenantId = app(TenantContext::class)->tenant()->id;
            $locked = Guest::query()->whereIn('id', [$source->id, $target->id])->lockForUpdate()->get()->keyBy('id');
            /** @var Guest|null $sourceGuest */
            $sourceGuest = $locked->get($source->id);
            /** @var Guest|null $targetGuest */
            $targetGuest = $locked->get($target->id);
            if ($sourceGuest === null || $targetGuest === null || $sourceGuest->merged_into_id !== null) {
                throw new DomainException('Guest merge candidates are unavailable.');
            }

            GuestMergeAlias::query()->create([
                'guest_id' => $targetGuest->id,
                'source_guest_id' => $sourceGuest->id,
                'name' => trim("{$sourceGuest->first_name} {$sourceGuest->last_name}"),
                'email' => $sourceGuest->email,
                'phone' => $sourceGuest->phone,
                'merged_at' => now(),
            ]);

            $targetEmail = $targetGuest->email ?? $sourceGuest->email;
            $targetPhone = $targetGuest->phone ?? $sourceGuest->phone;
            $sourceGuest->update(['email' => null, 'phone' => null]);
            $targetGuest->update([
                'email' => $targetEmail,
                'phone' => $targetPhone,
                'language' => $targetGuest->language ?? $sourceGuest->language,
                'preferences' => array_replace_recursive($sourceGuest->preferences ?? [], $targetGuest->preferences ?? []),
                'marketing_consent' => $targetGuest->marketing_consent || $sourceGuest->marketing_consent,
            ]);

            foreach (['reservations' => 'primary_guest_id', 'proposals' => 'primary_guest_id', 'communications' => 'guest_id', 'opportunities' => 'guest_id', 'crm_activities' => 'guest_id', 'guest_portal_access_tokens' => 'guest_id', 'guest_payment_evidence' => 'guest_id'] as $table => $column) {
                DB::table($table)->where('tenant_id', $tenantId)->where($column, $sourceGuest->id)->update([$column => $targetGuest->id]);
            }
            $this->mergeUniqueRelation('reservation_guests', $tenantId, $sourceGuest->id, $targetGuest->id, ['reservation_id']);
            $this->mergeUniqueRelation('guest_portal_profiles', $tenantId, $sourceGuest->id, $targetGuest->id, ['reservation_id']);
            $this->mergeUniqueRelation('guest_portal_acknowledgements', $tenantId, $sourceGuest->id, $targetGuest->id, ['reservation_id', 'document_id']);
            $this->mergeUniqueRelation('surveys', $tenantId, $sourceGuest->id, $targetGuest->id, ['reservation_id', 'kind']);

            $sourceGuest->update(['merged_into_id' => $targetGuest->id, 'merged_at' => now()]);

            return $targetGuest->fresh();
        }, 3);
    }

    /** @param list<string> $uniqueColumns */
    private function mergeUniqueRelation(string $table, string $tenantId, string $sourceId, string $targetId, array $uniqueColumns): void
    {
        $rows = DB::table($table)->where('tenant_id', $tenantId)->where('guest_id', $sourceId)->get();
        foreach ($rows as $row) {
            $duplicate = DB::table($table)->where('tenant_id', $tenantId)->where('guest_id', $targetId);
            foreach ($uniqueColumns as $column) {
                $duplicate->where($column, $row->{$column});
            }
            if ($duplicate->exists()) {
                DB::table($table)->where('id', $row->id)->delete();
            } else {
                DB::table($table)->where('id', $row->id)->update(['guest_id' => $targetId]);
            }
        }
    }
}
