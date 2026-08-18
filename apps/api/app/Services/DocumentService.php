<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DocumentService
{
    /** @param array<string, mixed> $definition */
    public function createTemplate(string $name, string $kind, array $definition): DocumentTemplate
    {
        return DB::transaction(function () use ($name, $kind, $definition): DocumentTemplate {
            $tenantId = app(TenantContext::class)->tenant()->id;
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            DocumentTemplate::query()->where('kind', $kind)->lockForUpdate()->update(['is_active' => false]);
            $version = ((int) DocumentTemplate::query()->where('kind', $kind)->max('version')) + 1;

            return DocumentTemplate::query()->create([
                'name' => $name,
                'kind' => $kind,
                'version' => $version,
                'definition' => $definition,
                'is_active' => true,
            ]);
        });
    }
}
