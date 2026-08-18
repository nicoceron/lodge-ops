<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentStatus;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use App\Models\DocumentGenerationRequest;
use App\Models\DocumentTemplate;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\ReportExport;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DocumentExportSchemaTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_document_requests_and_report_exports_cast_their_lifecycle_facts(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
        ]);
        $payment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'COP',
            'amount_minor' => 50_000,
            'processed_at' => now(),
        ]);
        $change = ReservationChange::query()->create([
            'reservation_id' => $reservation->id,
            'actor_id' => $user->id,
            'type' => 'refund_completed',
            'status' => 'completed',
            'currency' => 'COP',
            'amount_minor' => 5_000,
            'occurred_at' => now(),
        ]);
        $template = DocumentTemplate::query()->create([
            'name' => 'Payment receipt',
            'kind' => DocumentKind::PaymentReceipt->value,
            'version' => 1,
            'definition' => ['sections' => ['payment']],
            'is_active' => true,
        ]);
        $request = DocumentGenerationRequest::query()->create([
            'requested_by' => $user->id,
            'document_template_id' => $template->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $change->id,
            'kind' => DocumentKind::PaymentReceipt,
            'locale' => 'es',
            'status' => DocumentGenerationStatus::Pending,
            'source_snapshot' => ['schema_version' => 1],
            'source_checksum' => str_repeat('a', 64),
            'deduplication_key' => 'document-request-1',
        ]);
        $export = ReportExport::query()->create([
            'requested_by' => $user->id,
            'property_id' => $property->id,
            'kind' => ReportExportKind::Arrivals,
            'format' => ReportExportFormat::Xlsx,
            'locale' => 'es',
            'filters' => ['from' => '2026-08-18'],
            'filter_checksum' => str_repeat('b', 64),
            'deduplication_key' => 'report-export-1',
            'status' => ReportExportStatus::Pending,
        ]);

        $this->assertSame(DocumentKind::PaymentReceipt, $request->kind);
        $this->assertSame(DocumentGenerationStatus::Pending, $request->status);
        $this->assertTrue($request->reservation->is($reservation));
        $this->assertTrue($request->payment->is($payment));
        $this->assertTrue($request->reservationChange->is($change));
        $this->assertSame(1, $request->source_snapshot['schema_version']);
        $this->assertSame(0, $request->attempts);
        $this->assertSame(ReportExportKind::Arrivals, $export->kind);
        $this->assertSame(ReportExportFormat::Xlsx, $export->format);
        $this->assertSame(ReportExportStatus::Pending, $export->status);
        $this->assertTrue($export->property->is($property));
    }

    public function test_tenant_composite_keys_reject_cross_tenant_document_subjects_and_report_properties(): void
    {
        [$tenantA, $propertyA, $userA] = $this->tenantEnvironment();
        $subjectsA = $this->subjects($propertyA->id, $userA->id);
        [, $propertyB, $userB] = $this->tenantEnvironment();
        $tenantB = app(TenantContext::class)->tenant();
        $subjectsB = $this->subjects($propertyB->id, $userB->id);

        foreach (['document_template_id', 'reservation_id', 'guest_id', 'payment_id', 'reservation_change_id'] as $column) {
            $row = $this->validDocumentRequestRow($tenantB->id, $userB->id, $subjectsB);
            $row['id'] = (string) Str::uuid();
            $row['deduplication_key'] = 'cross-tenant-'.$column;
            $row[$column] = $subjectsA[$column];

            $this->assertQueryRejected(fn () => DB::table('document_generation_requests')->insert($row));
        }

        $this->assertQueryRejected(fn () => DB::table('report_exports')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'requested_by' => $userB->id,
            'property_id' => $propertyA->id,
            'kind' => ReportExportKind::Arrivals->value,
            'format' => ReportExportFormat::Csv->value,
            'locale' => 'en',
            'filters' => '{}',
            'filter_checksum' => str_repeat('b', 64),
            'deduplication_key' => 'cross-tenant-property',
            'status' => ReportExportStatus::Pending->value,
            'row_count' => 0,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    public function test_database_constraints_reject_unknown_document_and_export_values(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment();
        $subjects = $this->subjects($property->id, $user->id);

        foreach (['kind', 'status'] as $column) {
            $row = $this->validDocumentRequestRow($tenant->id, $user->id, $subjects);
            $row['id'] = (string) Str::uuid();
            $row['deduplication_key'] = 'invalid-document-'.$column;
            $row[$column] = 'unknown';

            $this->assertQueryRejected(fn () => DB::table('document_generation_requests')->insert($row));
        }

        foreach (['kind', 'format', 'status'] as $column) {
            $row = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'requested_by' => $user->id,
                'property_id' => $property->id,
                'kind' => ReportExportKind::Arrivals->value,
                'format' => ReportExportFormat::Csv->value,
                'locale' => 'en',
                'filters' => '{}',
                'filter_checksum' => str_repeat('b', 64),
                'deduplication_key' => 'invalid-export-'.$column,
                'status' => ReportExportStatus::Pending->value,
                'row_count' => 0,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $row[$column] = 'unknown';

            $this->assertQueryRejected(fn () => DB::table('report_exports')->insert($row));
        }
    }

    public function test_tenant_scoped_deduplication_keys_are_unique(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment();
        $subjects = $this->subjects($property->id, $user->id);
        $row = $this->validDocumentRequestRow($tenant->id, $user->id, $subjects);
        $row['id'] = (string) Str::uuid();
        DB::table('document_generation_requests')->insert($row);

        $duplicate = $row;
        $duplicate['id'] = (string) Str::uuid();
        $this->assertQueryRejected(fn () => DB::table('document_generation_requests')->insert($duplicate));
        $this->assertDatabaseCount('document_generation_requests', 1);
    }

    /** @return array<string, string> */
    private function subjects(string $propertyId, int $userId): array
    {
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $propertyId,
            'primary_guest_id' => $guest->id,
        ]);
        $payment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'COP',
            'amount_minor' => 10_000,
            'processed_at' => now(),
        ]);
        $change = ReservationChange::query()->create([
            'reservation_id' => $reservation->id,
            'actor_id' => $userId,
            'type' => 'refund_completed',
            'status' => 'completed',
            'currency' => 'COP',
            'amount_minor' => 1_000,
            'occurred_at' => now(),
        ]);
        $template = DocumentTemplate::query()->create([
            'name' => 'Receipt',
            'kind' => DocumentKind::PaymentReceipt->value,
            'version' => 1,
            'definition' => [],
            'is_active' => true,
        ]);

        return [
            'document_template_id' => $template->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $change->id,
        ];
    }

    /** @param array<string, string> $subjects @return array<string, mixed> */
    private function validDocumentRequestRow(string $tenantId, int $userId, array $subjects): array
    {
        return $subjects + [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'requested_by' => $userId,
            'kind' => DocumentKind::PaymentReceipt->value,
            'locale' => 'en',
            'status' => DocumentGenerationStatus::Pending->value,
            'source_snapshot' => '{}',
            'source_checksum' => str_repeat('a', 64),
            'deduplication_key' => 'deduplication-key',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The database constraint should reject this row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
