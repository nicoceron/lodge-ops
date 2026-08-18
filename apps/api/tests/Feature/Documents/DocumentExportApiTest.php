<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentKind;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Jobs\GenerateDocument;
use App\Jobs\GenerateReportExport;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DocumentExportApiTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_document_api_replays_safely_and_downloads_verified_pdf_without_path_metadata(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        Queue::fake();
        Storage::fake('documents');
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        DocumentTemplate::query()->create(['name' => 'Confirmation', 'kind' => DocumentKind::ReservationConfirmation->value, 'version' => 1, 'definition' => ['locale' => 'en'], 'is_active' => true]);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'document-api-key-0001'];
        $first = $this->withHeaders($headers)->postJson("/api/v1/reservations/{$reservation->id}/document-requests", ['kind' => DocumentKind::ReservationConfirmation->value, 'locale' => 'en'])->assertCreated()->assertJsonMissing(['storage_path']);
        $replay = $this->withHeaders($headers)->postJson("/api/v1/reservations/{$reservation->id}/document-requests", ['kind' => DocumentKind::ReservationConfirmation->value, 'locale' => 'en'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        app()->call([new GenerateDocument($first->json('data.id')), 'handle']);
        $document = GeneratedDocument::withoutGlobalScopes()->where('document_generation_request_id', $first->json('data.id'))->firstOrFail();
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/generated-documents/'.$document->id)->assertOk()->assertJsonMissing(['storage_path']);
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->get('/api/v1/generated-documents/'.$document->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audits', ['event' => 'document_downloaded', 'auditable_id' => $document->id]);
        $emailHeaders = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'same-email-key-0001'];
        $firstEmail = $this->withHeaders($emailHeaders)->postJson('/api/v1/generated-documents/'.$document->id.'/email')->assertAccepted();
        $replayedEmail = $this->withHeaders($emailHeaders)->postJson('/api/v1/generated-documents/'.$document->id.'/email')->assertAccepted()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($firstEmail->json('data.communication_id'), $replayedEmail->json('data.communication_id'));
        $this->assertDatabaseCount('communications', 1);

        Storage::disk('documents')->put($document->storage_path, 'corrupt');
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->get('/api/v1/generated-documents/'.$document->id.'/download')->assertServiceUnavailable();
    }

    public function test_report_api_generates_downloadable_csv_and_hides_storage_path(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        Queue::fake();
        Storage::fake('documents');
        $guest = Guest::factory()->create();
        Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'report-api-key-000001'])->postJson('/api/v1/report-exports', ['property_id' => $property->id, 'kind' => 'arrivals', 'format' => 'csv', 'filters' => ['from' => now()->toDateString(), 'to' => now()->addDays(3)->toDateString()]])->assertCreated()->assertJsonMissing(['storage_path']);
        app()->call([new GenerateReportExport($response->json('data.id')), 'handle']);
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/report-exports/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonMissing(['storage_path']);
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->get('/api/v1/report-exports/'.$response->json('data.id').'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_operations_can_request_operational_exports_but_not_finance_exports(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        Queue::fake();
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'operations-report-0001'];
        $filters = ['from' => now()->toDateString(), 'to' => now()->addDay()->toDateString()];

        $this->withHeaders($headers)->postJson('/api/v1/report-exports', ['property_id' => $property->id, 'kind' => 'revenue', 'format' => 'csv', 'filters' => $filters])->assertForbidden();
        $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'operations-report-0002']))->postJson('/api/v1/report-exports', ['property_id' => $property->id, 'kind' => 'arrivals', 'format' => 'csv', 'filters' => $filters])->assertCreated();
    }

    public function test_property_scoped_staff_cannot_read_another_property_document(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        Queue::fake();
        Storage::fake('documents');
        $otherProperty = Property::factory()->create();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        DocumentTemplate::query()->create(['name' => 'Confirmation', 'kind' => DocumentKind::ReservationConfirmation->value, 'version' => 1, 'definition' => [], 'is_active' => true]);
        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'property-document-0001'])->postJson("/api/v1/reservations/{$reservation->id}/document-requests", ['kind' => DocumentKind::ReservationConfirmation->value])->assertCreated();
        app(TenantContext::class)->set($tenant, $membership);
        app()->call([new GenerateDocument($response->json('data.id')), 'handle']);
        $document = GeneratedDocument::withoutGlobalScopes()->where('document_generation_request_id', $response->json('data.id'))->firstOrFail();

        $membership->forceFill(['role' => MembershipRole::Operations, 'property_id' => $otherProperty->id])->save();
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/generated-documents/'.$document->id)->assertForbidden();
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->get('/api/v1/generated-documents/'.$document->id.'/download')->assertForbidden();
    }
}
