<?php

namespace Tests\Feature\Documents;

use App\Enums\ReportExportKind;
use App\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DocumentExportMigrationCompatibilityTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_existing_document_and_export_rows_survive_the_forward_migration(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment();
        $template = DocumentTemplate::query()->create([
            'name' => 'Legacy itinerary',
            'kind' => 'itinerary',
            'version' => 3,
            'definition' => [],
            'is_active' => true,
        ]);

        $migrationPath = 'database/migrations/2026_08_18_000400_create_document_export_lifecycles.php';
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $migrationPath, '--force' => true]));

        $documentId = (string) Str::uuid();
        $exportId = (string) Str::uuid();
        $checksum = str_repeat('a', 64);
        $createdAt = now()->subDay()->startOfSecond();

        DB::table('generated_documents')->insert([
            'id' => $documentId,
            'tenant_id' => $tenant->id,
            'document_template_id' => $template->id,
            'kind' => 'itinerary',
            'status' => 'generated',
            'storage_path' => 'tenants/legacy/documents/itinerary.pdf',
            'checksum' => $checksum,
            'metadata' => json_encode(['template_version' => 3, 'locale' => 'es'], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('report_exports')->insert([
            'id' => $exportId,
            'tenant_id' => $tenant->id,
            'requested_by' => $user->id,
            'kind' => ReportExportKind::Arrivals->value,
            'filters' => json_encode(['to' => '2026-08-19', 'from' => '2026-08-18'], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'row_count' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $migrationPath, '--force' => true]));

        $this->assertDatabaseHas('generated_documents', [
            'id' => $documentId,
            'storage_disk' => 'local',
            'file_name' => 'itinerary.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
            'source_checksum' => $checksum,
            'renderer' => 'legacy',
            'renderer_version' => 'legacy',
            'template_version' => 3,
            'locale' => 'es',
        ]);
        $this->assertDatabaseHas('report_exports', [
            'id' => $exportId,
            'format' => 'csv',
            'locale' => 'en',
            'deduplication_key' => 'legacy:'.$exportId,
        ]);
        $this->assertNotNull(DB::table('report_exports')->where('id', $exportId)->value('filter_checksum'));
    }
}
