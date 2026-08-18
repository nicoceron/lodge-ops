<?php

namespace Tests\Feature\Documents;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use App\Jobs\GenerateReportExport;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\Reports\CsvReportWriter;
use App\Services\Reports\ReportDefinitionRegistry;
use App\Services\Reports\RequestReportExport;
use App\Services\Reports\XlsxReportWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ReportExportFlowTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_every_report_definition_has_stable_columns_and_half_open_property_dates(): void
    {
        $registry = app(ReportDefinitionRegistry::class);
        foreach (ReportExportKind::cases() as $kind) {
            $definition = $registry->get($kind);
            $this->assertSame($kind, $definition->kind());
            $this->assertNotEmpty($definition->columns('en'), $kind->value);
            $filters = $definition->normalizeFilters(['from' => '2026-03-08', 'to' => '2026-03-08'], 'America/New_York');
            $this->assertSame('2026-03-08', $filters['from_local']);
            $this->assertSame('2026-03-09', $filters['to_local_exclusive']);
            $this->assertNotSame($filters['from_utc'], $filters['to_utc_exclusive']);
        }
    }

    public function test_csv_and_xlsx_writers_neutralize_formula_leading_text(): void
    {
        $columns = ['name' => 'Name', 'amount' => 'Amount'];
        $rows = [['name' => " \t=HYPERLINK(\"bad\")", 'amount' => 42]];
        $csv = app(CsvReportWriter::class)->write($columns, $rows);
        $this->assertSame(1, $csv['row_count']);
        $this->assertStringContainsString("' \t=HYPERLINK", $csv['bytes']);
        $xlsx = app(XlsxReportWriter::class)->write($columns, $rows);
        $this->assertSame(1, $xlsx['row_count']);
        $this->assertStringStartsWith("PK\x03\x04", $xlsx['bytes']);
        $path = tempnam(sys_get_temp_dir(), 'lodge-xlsx-');
        $this->assertIsString($path);
        file_put_contents($path, $xlsx['bytes']);
        $reader = new Reader;
        $reader->open($path);
        $parsed = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $parsed[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
            }
        }
        $reader->close();
        @unlink($path);
        $this->assertSame('Name', $parsed[0][0]);
        $this->assertSame("' \t=HYPERLINK(\"bad\")", $parsed[1][0]);
    }

    public function test_csv_writer_parses_empty_and_large_streams_with_exact_counts(): void
    {
        $columns = ['id' => 'ID', 'value' => 'Value'];
        $empty = app(CsvReportWriter::class)->write($columns, []);
        $this->assertSame(0, $empty['row_count']);
        $emptyReader = CsvReader::createFromString($empty['bytes']);
        $emptyReader->setHeaderOffset(0);
        $this->assertCount(0, iterator_to_array($emptyReader->getRecords()));

        $rows = (function (): iterable {
            for ($index = 1; $index <= 2500; $index++) {
                yield ['id' => $index, 'value' => 'row-'.$index];
            }
        })();
        $large = app(CsvReportWriter::class)->write($columns, $rows);
        $this->assertSame(2500, $large['row_count']);
        $largeReader = CsvReader::createFromString($large['bytes']);
        $largeReader->setHeaderOffset(0);
        $this->assertCount(2500, iterator_to_array($largeReader->getRecords()));
    }

    public function test_report_rows_fail_closed_when_a_property_id_from_another_tenant_is_supplied(): void
    {
        [, $firstProperty] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        Reservation::factory()->create(['property_id' => $firstProperty->id, 'primary_guest_id' => $guest->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->tenantEnvironment();
        $definition = app(ReportDefinitionRegistry::class)->get(ReportExportKind::Arrivals);
        $filters = $definition->normalizeFilters(['from' => now()->toDateString(), 'to' => now()->addDays(3)->toDateString()], 'UTC');
        $this->assertSame([], iterator_to_array($definition->rows($firstProperty->id, $filters, 'UTC')));
    }

    public function test_report_request_worker_and_download_metadata_are_integrity_recorded(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        Queue::fake();
        Storage::fake('documents');
        $guest = Guest::factory()->create();
        Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);
        $export = app(RequestReportExport::class)->handle($user, $property, ReportExportKind::Arrivals, ReportExportFormat::Csv, ['from' => now()->toDateString(), 'to' => now()->addDays(5)->toDateString()], 'en', 'report-command-key');
        $replay = app(RequestReportExport::class)->handle($user, $property, ReportExportKind::Arrivals, ReportExportFormat::Csv, ['from' => now()->toDateString(), 'to' => now()->addDays(5)->toDateString()], 'en', 'report-command-key');
        $this->assertSame($export->id, $replay->id);
        Queue::assertPushed(GenerateReportExport::class, 1);
        app()->call([new GenerateReportExport($export->id), 'handle']);
        $export->refresh();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame(1, $export->row_count);
        $bytes = Storage::disk('documents')->get($export->storage_path);
        $this->assertSame(hash('sha256', $bytes), $export->checksum);
        $this->assertStringContainsString('Confirmation', $bytes);
        $this->assertNull(data_get($export->toArray(), 'storage_path_exposed'));

        $path = $export->storage_path;
        $export->forceFill(['expires_at' => now()->subMinute()])->save();
        Artisan::call('artifacts:purge-expired');
        $this->assertFalse(Storage::disk('documents')->exists($path));
        $this->assertNotNull($export->fresh()->purged_at);
        $this->assertDatabaseHas('report_exports', ['id' => $export->id]);
    }
}
