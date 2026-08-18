<?php

use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_generation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_change_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kind', DocumentKind::values());
            $table->string('locale', 12)->default('en');
            $table->enum('status', DocumentGenerationStatus::values())->default(DocumentGenerationStatus::Pending->value);
            $table->json('source_snapshot');
            $table->char('source_checksum', 64);
            $table->string('deduplication_key', 160);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'deduplication_key'], 'document_requests_deduplication_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'document_requests_status_created_idx');
            $table->index(['tenant_id', 'reservation_id', 'kind'], 'document_requests_reservation_kind_idx');
            $table->foreign(['tenant_id', 'document_template_id'], 'document_requests_tenant_template_fk')
                ->references(['tenant_id', 'id'])->on('document_templates');
            $table->foreign(['tenant_id', 'reservation_id'], 'document_requests_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'guest_id'], 'document_requests_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
            $table->foreign(['tenant_id', 'payment_id'], 'document_requests_tenant_payment_fk')
                ->references(['tenant_id', 'id'])->on('payments');
            $table->foreign(['tenant_id', 'reservation_change_id'], 'document_requests_tenant_change_fk')
                ->references(['tenant_id', 'id'])->on('reservation_changes');
        });

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->foreignUuid('document_generation_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_change_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('replaces_document_id')->nullable()->constrained('generated_documents')->restrictOnDelete();
            $table->string('storage_disk', 80)->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('source_checksum', 64)->nullable();
            $table->string('renderer', 80)->nullable();
            $table->string('renderer_version', 80)->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('locale', 12)->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('purged_at')->nullable();

            $table->foreign(['tenant_id', 'document_generation_request_id'], 'documents_tenant_request_fk')
                ->references(['tenant_id', 'id'])->on('document_generation_requests');
            $table->foreign(['tenant_id', 'payment_id'], 'documents_tenant_payment_fk')
                ->references(['tenant_id', 'id'])->on('payments');
            $table->foreign(['tenant_id', 'reservation_change_id'], 'documents_tenant_change_fk')
                ->references(['tenant_id', 'id'])->on('reservation_changes');
            $table->foreign(['tenant_id', 'replaces_document_id'], 'documents_tenant_replacement_fk')
                ->references(['tenant_id', 'id'])->on('generated_documents');
        });

        $this->backfillLegacyGeneratedDocuments();

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->string('storage_disk', 80)->nullable(false)->change();
            $table->string('file_name')->nullable(false)->change();
            $table->string('mime_type', 100)->nullable(false)->change();
            $table->unsignedBigInteger('size_bytes')->nullable(false)->change();
            $table->char('source_checksum', 64)->nullable(false)->change();
            $table->string('renderer', 80)->nullable(false)->change();
            $table->string('renderer_version', 80)->nullable(false)->change();
            $table->unsignedInteger('template_version')->nullable(false)->change();
            $table->string('locale', 12)->nullable(false)->change();
            $table->timestampTz('generated_at')->nullable(false)->change();
        });

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('format', ReportExportFormat::values())->default(ReportExportFormat::Csv->value);
            $table->string('locale', 12)->default('en');
            $table->char('filter_checksum', 64)->nullable();
            $table->string('storage_disk', 80)->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->string('deduplication_key', 160)->nullable();

            $table->foreign(['tenant_id', 'property_id'], 'report_exports_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties');
            $table->index(['tenant_id', 'status', 'created_at'], 'report_exports_status_created_idx');
            $table->index(['tenant_id', 'property_id', 'kind'], 'report_exports_property_kind_idx');
        });

        $this->backfillLegacyReportExports();

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->char('filter_checksum', 64)->nullable(false)->change();
            $table->string('deduplication_key', 160)->nullable(false)->change();
            $table->unique(['tenant_id', 'deduplication_key'], 'report_exports_deduplication_unique');
        });

        $this->addReportExportChecks();
    }

    public function down(): void
    {
        $this->dropReportExportChecks();

        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();
            $this->dropReportExportColumns();
            $this->dropGeneratedDocumentColumns();
            Schema::dropIfExists('document_generation_requests');
            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropUnique('report_exports_deduplication_unique');
            $table->dropIndex('report_exports_status_created_idx');
            $table->dropIndex('report_exports_property_kind_idx');
            $table->dropForeign('report_exports_tenant_property_fk');
            $table->dropForeign(['property_id']);
            $table->dropColumn([
                'property_id', 'format', 'locale', 'filter_checksum', 'storage_disk', 'file_name',
                'mime_type', 'size_bytes', 'checksum', 'attempts', 'started_at', 'failed_at',
                'last_error', 'expires_at', 'purged_at', 'deduplication_key',
            ]);
        });

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropForeign('documents_tenant_request_fk');
            $table->dropForeign('documents_tenant_payment_fk');
            $table->dropForeign('documents_tenant_change_fk');
            $table->dropForeign('documents_tenant_replacement_fk');
            $table->dropForeign(['document_generation_request_id']);
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['reservation_change_id']);
            $table->dropForeign(['replaces_document_id']);
            $table->dropColumn([
                'document_generation_request_id', 'payment_id', 'reservation_change_id', 'replaces_document_id',
                'storage_disk', 'file_name', 'mime_type', 'size_bytes', 'source_checksum', 'renderer',
                'renderer_version', 'template_version', 'locale', 'generated_at', 'expires_at', 'purged_at',
            ]);
        });

        Schema::dropIfExists('document_generation_requests');
    }

    private function dropReportExportColumns(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropUnique('report_exports_deduplication_unique');
            $table->dropIndex('report_exports_status_created_idx');
            $table->dropIndex('report_exports_property_kind_idx');
            $table->dropForeign(['property_id']);
            $table->dropForeign(['tenant_id', 'property_id']);
            $table->dropColumn([
                'property_id', 'format', 'locale', 'filter_checksum', 'storage_disk', 'file_name',
                'mime_type', 'size_bytes', 'checksum', 'attempts', 'started_at', 'failed_at',
                'last_error', 'expires_at', 'purged_at', 'deduplication_key',
            ]);
        });
    }

    private function dropGeneratedDocumentColumns(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropUnique(['document_generation_request_id']);
            $table->dropForeign(['document_generation_request_id']);
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['reservation_change_id']);
            $table->dropForeign(['replaces_document_id']);
            $table->dropForeign(['tenant_id', 'document_generation_request_id']);
            $table->dropForeign(['tenant_id', 'payment_id']);
            $table->dropForeign(['tenant_id', 'reservation_change_id']);
            $table->dropForeign(['tenant_id', 'replaces_document_id']);
            $table->dropColumn([
                'document_generation_request_id', 'payment_id', 'reservation_change_id', 'replaces_document_id',
                'storage_disk', 'file_name', 'mime_type', 'size_bytes', 'source_checksum', 'renderer',
                'renderer_version', 'template_version', 'locale', 'generated_at', 'expires_at', 'purged_at',
            ]);
        });
    }

    private function backfillLegacyGeneratedDocuments(): void
    {
        DB::table('generated_documents')->orderBy('id')->get()->each(function (object $document): void {
            $metadata = is_string($document->metadata)
                ? json_decode($document->metadata, true)
                : (array) ($document->metadata ?? []);

            DB::table('generated_documents')->where('id', $document->id)->update([
                'storage_disk' => 'local',
                'file_name' => basename((string) $document->storage_path),
                'mime_type' => 'application/pdf',
                'size_bytes' => 0,
                'source_checksum' => $document->checksum,
                'renderer' => 'legacy',
                'renderer_version' => 'legacy',
                'template_version' => (int) Arr::get($metadata, 'template_version', 0),
                'locale' => (string) Arr::get($metadata, 'locale', 'en'),
                'generated_at' => $document->created_at,
            ]);
        });
    }

    private function backfillLegacyReportExports(): void
    {
        DB::table('report_exports')->orderBy('id')->get()->each(function (object $export): void {
            $filters = is_string($export->filters)
                ? json_decode($export->filters, true)
                : (array) ($export->filters ?? []);
            $filters = $this->sortRecursively($filters);
            $canonical = json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            DB::table('report_exports')->where('id', $export->id)->update([
                'filter_checksum' => hash('sha256', $canonical),
                'deduplication_key' => 'legacy:'.$export->id,
            ]);
        });
    }

    /** @param array<array-key, mixed> $value @return array<array-key, mixed> */
    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function addReportExportChecks(): void
    {
        $kindValues = $this->quotedValues(ReportExportKind::values());
        $formatValues = $this->quotedValues(ReportExportFormat::values());
        $statusValues = $this->quotedValues(ReportExportStatus::values());

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_kind_check CHECK (kind IN ({$kindValues}))");
            DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ({$statusValues}))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER report_exports_values_insert BEFORE INSERT ON report_exports BEGIN SELECT RAISE(ABORT, 'invalid report export value') WHERE NEW.kind NOT IN ({$kindValues}) OR NEW.format NOT IN ({$formatValues}) OR NEW.status NOT IN ({$statusValues}); END");
            DB::unprepared("CREATE TRIGGER report_exports_values_update BEFORE UPDATE OF kind, format, status ON report_exports BEGIN SELECT RAISE(ABORT, 'invalid report export value') WHERE NEW.kind NOT IN ({$kindValues}) OR NEW.format NOT IN ({$formatValues}) OR NEW.status NOT IN ({$statusValues}); END");
        }
    }

    private function dropReportExportChecks(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_kind_check');
            DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_format_check');
            DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_status_check');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS report_exports_values_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS report_exports_values_update');
        }
    }

    /** @param list<string> $values */
    private function quotedValues(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));
    }
};
