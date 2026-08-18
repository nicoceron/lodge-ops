<?php

namespace Tests\Feature\Documents;

use App\Contracts\Documents\DocumentRenderer;
use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentOrigin;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Jobs\GenerateDocument;
use App\Models\DocumentTemplate;
use App\Models\Guest;
use App\Models\GuestPortalAcknowledgement;
use App\Models\GuestPortalDocument;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\Documents\CanonicalJson;
use App\Services\Documents\DocumentArtifactStore;
use App\Services\Documents\DocumentSnapshotFactory;
use App\Services\Documents\RequestDocumentGeneration;
use App\Services\Documents\RetryDocumentGeneration;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DocumentGenerationFlowTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    /** @return iterable<string, array{ReservationStatus, bool}> */
    public static function reservationLifecycleStates(): iterable
    {
        foreach (ReservationStatus::cases() as $status) {
            yield $status->value => [$status, in_array($status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)];
        }
    }

    #[DataProvider('reservationLifecycleStates')]
    public function test_reservation_document_lifecycle_matrix(ReservationStatus $status, bool $valid): void
    {
        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => $status]);
        $template = DocumentTemplate::query()->create(['name' => 'Confirmation', 'kind' => DocumentKind::ReservationConfirmation->value, 'version' => 1, 'definition' => [], 'is_active' => true]);
        if (! $valid) {
            $this->expectException(\DomainException::class);
        }
        $snapshot = app(DocumentSnapshotFactory::class)->build(DocumentKind::ReservationConfirmation, $reservation, $template, 'en');
        if ($valid) {
            $this->assertSame($status->value, data_get($snapshot, 'payload.reservation.status'));
        }
    }

    /** @return iterable<string, array{PaymentStatus, bool}> */
    public static function paymentLifecycleStates(): iterable
    {
        foreach (PaymentStatus::cases() as $status) {
            yield $status->value => [$status, in_array($status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true)];
        }
    }

    #[DataProvider('paymentLifecycleStates')]
    public function test_payment_receipt_lifecycle_matrix(PaymentStatus $status, bool $valid): void
    {
        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        $payment = Payment::query()->create(['reservation_id' => $reservation->id, 'status' => $status, 'origin' => PaymentOrigin::Manual, 'method' => 'cash', 'currency' => 'COP', 'amount_minor' => 100, 'processed_at' => now()]);
        $template = DocumentTemplate::query()->create(['name' => 'Receipt', 'kind' => DocumentKind::PaymentReceipt->value, 'version' => 1, 'definition' => [], 'is_active' => true]);
        if (! $valid) {
            $this->expectException(\DomainException::class);
        }
        $snapshot = app(DocumentSnapshotFactory::class)->build(DocumentKind::PaymentReceipt, $reservation, $template, 'en', $payment);
        if ($valid) {
            $this->assertSame($status->value, data_get($snapshot, 'payload.payment.status'));
        }
    }

    public function test_canonical_snapshot_is_deterministic_redacted_and_truthful(): void
    {
        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create(['preferences' => ['secret' => 'never-export']]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        $payment = Payment::query()->create(['reservation_id' => $reservation->id, 'status' => PaymentStatus::Succeeded, 'origin' => PaymentOrigin::Manual, 'method' => 'cash', 'currency' => 'cop', 'amount_minor' => 250000, 'processed_at' => now(), 'evidence_url' => 'private/evidence.jpg']);
        $template = DocumentTemplate::query()->create(['name' => 'Receipt', 'kind' => DocumentKind::PaymentReceipt->value, 'version' => 1, 'definition' => ['locale' => 'en', 'show_balance' => true, 'html' => '<script>bad()</script>'], 'is_active' => true]);
        $factory = app(DocumentSnapshotFactory::class);
        $canonical = app(CanonicalJson::class);
        $first = $factory->build(DocumentKind::PaymentReceipt, $reservation, $template, 'en', $payment);
        $second = $factory->build(DocumentKind::PaymentReceipt, $reservation->fresh(), $template, 'en', $payment->fresh());

        $this->assertSame($canonical->encode($first), $canonical->encode($second));
        $this->assertSame($canonical->checksum($first), $canonical->checksum($second));
        $json = $canonical->encode($first);
        $this->assertStringContainsString('Payment recorded by staff', $json);
        $this->assertStringNotContainsString('private/evidence.jpg', $json);
        $this->assertStringNotContainsString('never-export', $json);
        $this->assertStringNotContainsString('<script>', $json);
        $provider = Payment::query()->create(['reservation_id' => $reservation->id, 'status' => PaymentStatus::Succeeded, 'origin' => PaymentOrigin::Provider, 'method' => 'card', 'provider' => 'sandbox', 'provider_reference' => 'provider-1', 'currency' => 'COP', 'amount_minor' => 100, 'processed_at' => now()]);
        $providerSnapshot = $factory->build(DocumentKind::PaymentReceipt, $reservation, $template, 'en', $provider);
        $this->assertSame('Payment reported by provider', data_get($providerSnapshot, 'payload.payment.wording'));
    }

    public function test_request_and_worker_generate_one_real_private_pdf_from_the_stored_snapshot(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        Queue::fake();
        Storage::fake('documents');
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        DocumentTemplate::query()->create(['name' => 'Confirmation', 'kind' => DocumentKind::ReservationConfirmation->value, 'version' => 1, 'definition' => ['locale' => 'en'], 'is_active' => true]);
        $request = app(RequestDocumentGeneration::class)->handle($user, $reservation, DocumentKind::ReservationConfirmation, 'en', 'same-command-key');
        $replay = app(RequestDocumentGeneration::class)->handle($user, $reservation, DocumentKind::ReservationConfirmation, 'en', 'same-command-key');
        $this->assertSame($request->id, $replay->id);
        Queue::assertPushed(GenerateDocument::class, 1);
        $payloadJob = new GenerateDocument($request->id);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $payloadJob);
        $this->assertStringNotContainsString($guest->email, serialize($payloadJob));

        $reservation->forceFill(['total_minor' => $reservation->total_minor + 100])->save();
        app()->call([new GenerateDocument($request->id), 'handle']);
        $request->refresh();
        $document = $request->generatedDocument;
        $this->assertSame(DocumentGenerationStatus::Generated, $request->status);
        $this->assertNotNull($document);
        $bytes = Storage::disk('documents')->get($document->storage_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', substr($bytes, -1024));
        $this->assertSame(hash('sha256', $bytes), $document->checksum);
        $this->assertSame($request->source_checksum, $document->source_checksum);
        $this->assertSame($request->source_snapshot['payload']['reservation']['total_minor'], $reservation->total_minor - 100);

        $pdfPath = tempnam(sys_get_temp_dir(), 'lodge-pdf-');
        $imageBase = tempnam(sys_get_temp_dir(), 'lodge-page-');
        $this->assertIsString($pdfPath);
        $this->assertIsString($imageBase);
        file_put_contents($pdfPath, $bytes);
        (new Process(['pdfinfo', $pdfPath]))->mustRun();
        $text = (new Process(['pdftotext', $pdfPath, '-']))->mustRun()->getOutput();
        $this->assertStringContainsString('Reservation confirmation', $text);
        (new Process(['pdftoppm', '-f', '1', '-singlefile', '-png', $pdfPath, $imageBase]))->mustRun();
        $pngPath = $imageBase.'.png';
        $dimensions = getimagesize($pngPath);
        $this->assertIsArray($dimensions);
        $this->assertGreaterThan(500, $dimensions[0]);
        $this->assertGreaterThan(500, $dimensions[1]);
        $image = imagecreatefrompng($pngPath);
        $this->assertNotFalse($image);
        $colors = [];
        for ($x = 0; $x < imagesx($image); $x += 50) {
            for ($y = 0; $y < imagesy($image); $y += 50) {
                $colors[imagecolorat($image, $x, $y)] = true;
            }
        }
        $this->assertGreaterThan(1, count($colors));
        imagedestroy($image);
        @unlink($pdfPath);
        @unlink($imageBase);
        @unlink($pngPath);

        app()->call([new GenerateDocument($request->id), 'handle']);
        $this->assertDatabaseCount('generated_documents', 1);

        $replacementRequest = app(RequestDocumentGeneration::class)->handle($user, $reservation->fresh(), DocumentKind::ReservationConfirmation, 'en', 'replacement-command-key', replaces: $document);
        app()->call([new GenerateDocument($replacementRequest->id), 'handle']);
        $replacement = $replacementRequest->fresh()->generatedDocument;
        $this->assertSame($document->id, $replacement->replaces_document_id);
        $this->assertSame($request->source_checksum, $document->fresh()->source_checksum);
        $this->assertDatabaseCount('generated_documents', 2);
    }

    public function test_structurally_pdf_like_but_unparseable_bytes_are_rejected_before_storage(): void
    {
        Storage::fake('documents');
        $bytes = "%PDF-1.7\n".str_repeat('not-a-real-pdf', 10)."\n%%EOF";

        $this->expectException(\DomainException::class);
        app(DocumentArtifactStore::class)->put('tenant-id', 'request-id', $bytes, 'invalid.pdf');
    }

    public function test_dispatch_waits_for_commit_and_is_discarded_on_outer_rollback(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        Queue::fake();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        DocumentTemplate::query()->create(['name' => 'Confirmation', 'kind' => DocumentKind::ReservationConfirmation->value, 'version' => 1, 'definition' => [], 'is_active' => true]);

        DB::beginTransaction();
        app(RequestDocumentGeneration::class)->handle($user, $reservation, DocumentKind::ReservationConfirmation, 'en', 'rolled-back-request');
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();

        app(RequestDocumentGeneration::class)->handle($user, $reservation, DocumentKind::ReservationConfirmation, 'en', 'committed-request');
        Queue::assertPushed(GenerateDocument::class, 1);
    }

    public function test_invalid_renderer_output_is_failed_redacted_and_retryable_with_the_same_snapshot(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        Queue::fake();
        Storage::fake('documents');
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::Confirmed]);
        DocumentTemplate::query()->create(['name' => 'Confirmation', 'kind' => DocumentKind::ReservationConfirmation->value, 'version' => 1, 'definition' => [], 'is_active' => true]);
        $request = app(RequestDocumentGeneration::class)->handle($user, $reservation, DocumentKind::ReservationConfirmation, 'en', 'invalid-renderer');
        $snapshot = $request->source_snapshot;
        app()->instance(DocumentRenderer::class, new class implements DocumentRenderer
        {
            public function render(DocumentKind $kind, array $snapshot): string
            {
                return 'not a pdf';
            }

            public function name(): string
            {
                return 'failing-test-renderer';
            }

            public function version(): string
            {
                return '1';
            }
        });

        try {
            app()->call([new GenerateDocument($request->id), 'handle']);
            $this->fail('Invalid renderer bytes should fail generation.');
        } catch (\DomainException) {
            // The request ledger below is the observable retry surface.
        }
        $request->refresh();
        $this->assertSame(DocumentGenerationStatus::Failed, $request->status);
        $this->assertStringContainsString('DomainException', $request->last_error);
        $this->assertStringNotContainsString($guest->email, $request->last_error);
        $this->assertDatabaseCount('generated_documents', 0);

        app(RetryDocumentGeneration::class)->handle($user, $request);
        $this->assertSame($snapshot, $request->fresh()->source_snapshot);
        Queue::assertPushed(GenerateDocument::class, 2);
    }

    public function test_refund_and_waiver_snapshots_preserve_completed_financial_and_acknowledged_truth(): void
    {
        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id, 'status' => ReservationStatus::CheckedOut]);
        $payment = Payment::query()->create(['reservation_id' => $reservation->id, 'status' => PaymentStatus::Refunded, 'origin' => PaymentOrigin::Provider, 'method' => 'card', 'provider' => 'test-provider', 'provider_reference' => 'charge-1', 'currency' => 'USD', 'amount_minor' => 50000, 'processed_at' => now()->subDay()]);
        $change = ReservationChange::query()->create(['reservation_id' => $reservation->id, 'type' => 'refund_completed', 'status' => 'completed', 'currency' => 'USD', 'amount_minor' => 12500, 'reference' => 'refund-1', 'metadata' => ['payment_id' => $payment->id, 'reason' => 'Guest adjustment'], 'occurred_at' => now()]);
        $refundTemplate = DocumentTemplate::query()->create(['name' => 'Refund receipt', 'kind' => DocumentKind::RefundReceipt->value, 'version' => 1, 'definition' => [], 'is_active' => true]);
        $refund = app(DocumentSnapshotFactory::class)->build(DocumentKind::RefundReceipt, $reservation, $refundTemplate, 'en', change: $change);
        $this->assertSame(12500, data_get($refund, 'payload.refund.refunded_minor'));
        $this->assertSame(37500, data_get($refund, 'payload.refund.remaining_paid_minor'));
        $this->assertSame('charge-1', data_get($refund, 'payload.refund.source_payment_reference'));

        $document = GuestPortalDocument::query()->create(['property_id' => $property->id, 'slug' => 'waiver', 'version' => '1.0', 'title' => 'Waiver', 'body' => 'Exact acknowledged terms.', 'is_active' => true]);
        $acknowledgement = GuestPortalAcknowledgement::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id, 'document_id' => $document->id, 'signature' => 'Guest Name', 'document_hash' => $document->body_hash, 'acknowledged_at' => now()]);
        $waiverTemplate = DocumentTemplate::query()->create(['name' => 'Waiver copy', 'kind' => DocumentKind::WaiverCopy->value, 'version' => 1, 'definition' => [], 'is_active' => true]);
        $waiver = app(DocumentSnapshotFactory::class)->build(DocumentKind::WaiverCopy, $reservation, $waiverTemplate, 'en', acknowledgement: $acknowledgement);
        $this->assertSame('Exact acknowledged terms.', data_get($waiver, 'payload.waiver.body'));
        $this->assertSame($acknowledgement->document_hash, data_get($waiver, 'payload.waiver.body_hash'));

        $this->expectException(\LogicException::class);
        $document->forceFill(['body' => 'Mutated terms.'])->save();
    }
}
