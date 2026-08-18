<?php

namespace App\Console\Commands;

use App\Models\GeneratedDocument;
use App\Models\ReportExport;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredArtifacts extends Command
{
    protected $signature = 'artifacts:purge-expired {--batch=100}';

    protected $description = 'Remove expired private document and report objects while retaining immutable ledger rows.';

    public function handle(TenantContext $context): int
    {
        $limit = max(1, min(1000, (int) $this->option('batch')));
        $purged = 0;
        $tenantIds = ReportExport::withoutGlobalScopes()->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('purged_at')->limit($limit)->pluck('tenant_id')
            ->merge(GeneratedDocument::withoutGlobalScopes()->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('purged_at')->limit($limit)->pluck('tenant_id'))->unique();
        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                continue;
            }
            $context->clear();
            $context->set($tenant);
            foreach ([ReportExport::class, GeneratedDocument::class] as $model) {
                $records = $model::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('purged_at')->limit($limit - $purged)->get();
                foreach ($records as $record) {
                    if ($record->storage_disk && $record->storage_path) {
                        Storage::disk($record->storage_disk)->delete($record->storage_path);
                    }
                    $record->forceFill(['purged_at' => now()])->save();
                    $purged++;
                    if ($purged >= $limit) {
                        break 3;
                    }
                }
            }
        }
        $context->clear();
        $this->info("Purged {$purged} expired artifact objects.");

        return self::SUCCESS;
    }
}
