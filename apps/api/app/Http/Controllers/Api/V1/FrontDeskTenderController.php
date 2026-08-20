<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\CashMovementType;
use App\Enums\PaymentChannel;
use App\Enums\PaymentOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFrontDeskPaymentRequest;
use App\Http\Resources\CashShiftResource;
use App\Http\Resources\PaymentTenderDetailResource;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\GuestPaymentEvidence;
use App\Models\Payment;
use App\Models\PaymentTenderDetail;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\PaymentEvidenceScanner;
use App\Services\Payments\ApproveCashVariance;
use App\Services\Payments\CloseCashShift;
use App\Services\Payments\CompleteManualExternalRefund;
use App\Services\Payments\CorrectRemainingReversibleAmount;
use App\Services\Payments\FinancialCommandExecutor;
use App\Services\Payments\OpenCashShift;
use App\Services\Payments\RecordCashMovement;
use App\Services\Payments\RecordFrontDeskPayment;
use App\Services\Payments\RequestManualExternalRefund;
use App\Services\Payments\ResolveTenderDuplicate;
use App\Services\Payments\ReviewRefundEvidence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;

class FrontDeskTenderController extends Controller
{
    public function store(StoreFrontDeskPaymentRequest $request, Reservation $reservation, RecordFrontDeskPayment $command): JsonResponse
    {
        $this->authorize('create', Payment::class);
        abort_unless($reservation->getAttribute('tenant_id') === app(TenantContext::class)->id() && app(TenantContext::class)->canAccessProperty($reservation->property_id), 403);
        $data = $request->validated();
        $detail = $command->handle($request->user(), new FrontDeskPaymentInput(
            reservationId: $reservation->id,
            channel: PaymentChannel::from($data['channel']),
            amountMinor: $data['amount_minor'],
            idempotencyKey: $this->key($request),
            depositId: $data['deposit_id'] ?? null,
            processorAlias: $data['processor_alias'] ?? null,
            merchantAccountAlias: $data['merchant_account_alias'] ?? null,
            terminalIdentifier: $data['terminal_identifier'] ?? null,
            transactionReference: $data['transaction_reference'] ?? null,
            authorizationReference: $data['authorization_reference'] ?? null,
            batchReference: $data['batch_reference'] ?? null,
            cardBrand: $data['card_brand'] ?? null,
            cardLastFour: $data['card_last_four'] ?? null,
            note: $data['note'] ?? null,
        ));

        return (new PaymentTenderDetailResource($detail))->response()->setStatusCode(201);
    }

    public function openShift(Request $request, OpenCashShift $command): JsonResponse
    {
        $this->authorize('create', CashShift::class);
        $data = $request->validate([
            'property_id' => ['required', 'uuid'],
            'currency' => ['required', 'string', 'size:3'],
            'opening_float_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);
        abort_unless(Property::query()->whereKey($data['property_id'])->exists(), 404);

        return (new CashShiftResource($command->handle($request->user(), $data['property_id'], $data['currency'], $data['opening_float_minor'], $this->key($request))))
            ->response()->setStatusCode(201);
    }

    public function showShift(CashShift $cashShift): CashShiftResource
    {
        $this->authorize('view', $cashShift);

        return new CashShiftResource($cashShift->load('movements'));
    }

    public function movement(Request $request, CashShift $cashShift, RecordCashMovement $command): CashShiftResource
    {
        $this->authorize('operate', $cashShift);
        $data = $request->validate([
            'type' => ['required', 'in:pay_in,pay_out,correction'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:500'],
            'reverses_movement_id' => ['nullable', 'uuid'],
        ]);
        $reverses = isset($data['reverses_movement_id']) ? CashShiftMovement::query()->findOrFail($data['reverses_movement_id']) : null;
        $command->handle($request->user(), $cashShift, CashMovementType::from($data['type']), $data['amount_minor'], $data['reason'], $this->key($request), $reverses);

        return new CashShiftResource($cashShift->fresh()->load('movements'));
    }

    public function closeShift(Request $request, CashShift $cashShift, CloseCashShift $command): CashShiftResource
    {
        $this->authorize('operate', $cashShift);
        $data = $request->validate([
            'counted_cash_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'reason' => ['nullable', 'string', 'max:500'],
            'force' => ['sometimes', 'boolean'],
        ]);

        return new CashShiftResource($command->handle($request->user(), $cashShift, $data['counted_cash_minor'], $data['reason'] ?? null, $this->key($request), (bool) ($data['force'] ?? false)));
    }

    public function approveVariance(Request $request, CashShift $cashShift, ApproveCashVariance $command): CashShiftResource
    {
        $this->authorize('approveVariance', $cashShift);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new CashShiftResource($command->handle($request->user(), $cashShift, $data['reason'], $this->key($request)));
    }

    public function resolveDuplicate(Request $request, PaymentTenderDetail $detail, ResolveTenderDuplicate $command): PaymentTenderDetailResource
    {
        $this->authorize('resolve', $detail);
        $data = $request->validate([
            'decision' => ['required', 'in:confirmed_duplicate,needs_corrected_identity,dismissed_unposted,corrected_identity'],
            'reason' => ['required', 'string', 'max:500'],
            'processor_alias' => ['required_if:decision,corrected_identity', 'nullable', 'string', 'max:80'],
            'merchant_account_alias' => ['required_if:decision,corrected_identity', 'nullable', 'string', 'max:120'],
            'terminal_identifier' => ['required_if:decision,corrected_identity', 'nullable', 'string', 'max:80'],
            'transaction_reference' => ['required_if:decision,corrected_identity', 'nullable', 'string', 'max:160'],
            'authorization_reference' => ['nullable', 'string', 'max:160'],
            'batch_reference' => ['nullable', 'string', 'max:120'],
            'card_brand' => ['nullable', 'string', 'max:40'],
            'card_last_four' => ['nullable', 'regex:/^\d{4}$/'],
        ]);

        $corrected = $data['decision'] === 'corrected_identity' ? new FrontDeskPaymentInput(
            reservationId: $detail->reservation_id,
            channel: PaymentChannel::ExternalTerminal,
            amountMinor: $detail->amount_minor,
            idempotencyKey: 'tender-retry:'.hash('sha256', $this->key($request)),
            depositId: $detail->deposit_id,
            processorAlias: $data['processor_alias'],
            merchantAccountAlias: $data['merchant_account_alias'],
            terminalIdentifier: $data['terminal_identifier'],
            transactionReference: $data['transaction_reference'],
            authorizationReference: $data['authorization_reference'] ?? null,
            batchReference: $data['batch_reference'] ?? null,
            cardBrand: $data['card_brand'] ?? null,
            cardLastFour: $data['card_last_four'] ?? null,
        ) : null;

        return new PaymentTenderDetailResource($command->handle($request->user(), $detail, $data['decision'], $data['reason'], $this->key($request), $corrected));
    }

    public function requestRefund(Request $request, Payment $payment, RequestManualExternalRefund $command): JsonResponse
    {
        $this->authorize('reverse', $payment);
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        $refund = $command->handle($request->user(), $payment, $data['amount_minor'], $data['reason'], $this->key($request));

        return response()->json(['data' => ['id' => $refund->id, 'payment_id' => $payment->id, 'amount_minor' => $refund->amount_minor, 'status' => $refund->status]], 201);
    }

    public function uploadRefundEvidence(Request $request, ReservationChange $refund, PaymentEvidenceScanner $scanner, FinancialCommandExecutor $commands): JsonResponse
    {
        abort_unless($refund->type === 'refund_requested' && $refund->status === 'requested', 422, 'Evidence may only be attached to an open refund request.');
        $paymentId = (string) data_get($refund->metadata, 'payment_id');
        $payment = Payment::query()->with('reservation')->findOrFail($paymentId);
        $this->authorize('reverse', $payment);
        abort_unless($payment->origin === PaymentOrigin::Manual && $payment->reservation_id === $refund->reservation_id, 422, 'Evidence must belong to a manual payment refund.');
        $validated = $request->validate(['evidence' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(10 * 1024)]]);
        $upload = $request->file('evidence');
        abort_unless($upload instanceof UploadedFile, 422);
        $scanner->assertSafe($upload);
        $realPath = $upload->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $size = filesize($realPath);
        abort_unless(is_string($mime) && is_int($size) && $size > 0, 422);
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => abort(422, 'Unsupported evidence type.')
        };
        $fileHash = hash_file('sha256', $realPath);
        abort_unless(is_string($fileHash), 422, 'Evidence hashing failed.');
        $commandKey = $this->key($request);
        $key = "payment-evidence/{$payment->tenant_id}/refunds/{$refund->id}/".hash('sha256', $commandKey).'.'.$extension;
        $guest = $payment->reservation->primary_guest_id;
        abort_if($guest === null, 422, 'A reservation guest is required for evidence linkage.');
        $originalName = Str::of($upload->getClientOriginalName())->basename()->limit(255, '')->toString();
        /** @var GuestPaymentEvidence $evidence */
        $evidence = $commands->run($payment->tenant_id, __METHOD__, $commandKey, [
            'refund_id' => $refund->id,
            'payment_id' => $payment->id,
            'original_name' => $originalName,
            'detected_mime' => $mime,
            'size_bytes' => $size,
            'sha256' => $fileHash,
        ], function () use ($payment, $refund, $guest, $originalName, $mime, $size, $fileHash, $key, $realPath, $request): GuestPaymentEvidence {
            abort_unless(Storage::disk('local')->put($key, file_get_contents($realPath), ['visibility' => 'private']), 503, 'Private evidence storage is unavailable.');

            return GuestPaymentEvidence::query()->create([
                'reservation_id' => $payment->reservation_id,
                'guest_id' => $guest,
                'refund_change_id' => $refund->id,
                'file_name' => $originalName,
                'original_name' => $originalName,
                'content_type' => $mime,
                'detected_mime' => $mime,
                'size_bytes' => $size,
                'sha256' => $fileHash,
                'storage_path' => $key,
                'disk' => 'local',
                'storage_key' => $key,
                'status' => 'review_pending',
                'scan_status' => 'accepted',
                'scan_state' => 'accepted',
                'uploaded_by' => $request->user()->id,
                'submitted_at' => now(),
                'scanned_at' => now(),
            ]);
        });

        return response()->json(['data' => ['id' => $evidence->id, 'scan_state' => $evidence->scan_state, 'size_bytes' => $evidence->size_bytes]], 201);
    }

    public function reviewRefundEvidence(Request $request, GuestPaymentEvidence $evidence, ReviewRefundEvidence $command): JsonResponse
    {
        $this->authorize('review', $evidence);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $reviewed = $command->handle($request->user(), $evidence, $data['decision'], $data['reason'], $this->key($request));

        return response()->json(['data' => ['id' => $reviewed->id, 'status' => $reviewed->status->value, 'scan_state' => $reviewed->scan_state]]);
    }

    public function completeRefund(Request $request, ReservationChange $refund, CompleteManualExternalRefund $command): JsonResponse
    {
        $data = $request->validate([
            'execution_reference' => ['required', 'string', 'max:160'],
            'evidence_id' => ['nullable', 'uuid'],
            'cash_shift_id' => ['nullable', 'uuid'],
        ]);
        $evidence = isset($data['evidence_id']) ? GuestPaymentEvidence::query()->findOrFail($data['evidence_id']) : null;
        $shift = isset($data['cash_shift_id']) ? CashShift::query()->findOrFail($data['cash_shift_id']) : null;
        $completed = $command->handle($request->user(), $refund, $data['execution_reference'], $this->key($request), $evidence, $shift);

        return response()->json(['data' => ['id' => $completed->id, 'status' => $completed->status, 'amount_minor' => $completed->amount_minor]]);
    }

    public function correctRemaining(Request $request, Payment $payment, CorrectRemainingReversibleAmount $command): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $remainingRequest = $command->handle($request->user(), $payment, $data['reason'], $this->key($request));

        return response()->json(['data' => ['id' => $remainingRequest->id, 'status' => $remainingRequest->status, 'amount_minor' => $remainingRequest->amount_minor]], 201);
    }

    private function key(Request $request): string
    {
        return (string) $request->header('Idempotency-Key', hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent()));
    }
}
