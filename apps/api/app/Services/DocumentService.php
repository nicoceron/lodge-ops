<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /** @param array<string, mixed> $metadata */
    public function store(
        DocumentTemplate $template,
        string $contents,
        ?Reservation $reservation = null,
        ?Guest $guest = null,
        array $metadata = [],
    ): GeneratedDocument {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $subject = $reservation?->id ?? $guest?->id ?? 'unassigned';
        $path = "tenants/{$tenantId}/documents/{$subject}/".Str::uuid().'.pdf';
        $checksum = hash('sha256', $contents);
        Storage::disk('local')->put($path, $contents);

        return GeneratedDocument::query()->create([
            'document_template_id' => $template->id,
            'reservation_id' => $reservation?->id,
            'guest_id' => $guest?->id,
            'kind' => $template->kind,
            'status' => 'generated',
            'storage_path' => $path,
            'checksum' => $checksum,
            'storage_disk' => 'local',
            'file_name' => basename($path),
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'source_checksum' => $checksum,
            'renderer' => 'legacy-caller-supplied',
            'renderer_version' => 'legacy',
            'template_version' => $template->version,
            'locale' => app()->getLocale(),
            'generated_at' => now(),
            'metadata' => $metadata + ['template_version' => $template->version],
        ]);
    }
}
