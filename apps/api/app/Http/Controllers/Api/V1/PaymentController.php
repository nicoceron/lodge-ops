<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FolioLineType;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $payments = Payment::query()
            ->when($request->query('reservation_id'), fn ($query, $value) => $query->where('reservation_id', $value))
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return PaymentResource::collection($payments);
    }

    public function store(StorePaymentRequest $request): PaymentResource
    {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        $payment = DB::transaction(function () use ($data): Payment {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($data['reservation_id']);

            if (! empty($data['provider']) && ! empty($data['provider_reference'])) {
                $existing = Payment::query()
                    ->where('provider', $data['provider'])
                    ->where('provider_reference', $data['provider_reference'])
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $captured = (bool) ($data['captured'] ?? false);
            unset($data['captured']);
            $payment = Payment::query()->create([
                ...$data,
                'currency' => $reservation->currency,
                'status' => $captured ? PaymentStatus::Succeeded : PaymentStatus::Pending,
                'processed_at' => $captured ? now() : null,
            ]);

            if ($captured) {
                $reservation->folioLines()->create([
                    'payment_id' => $payment->id,
                    'type' => FolioLineType::Payment,
                    'description' => 'Payment received',
                    'quantity' => 1,
                    'unit_amount_minor' => -$payment->amount_minor,
                    'amount_minor' => -$payment->amount_minor,
                    'currency' => $reservation->currency,
                    'posted_at' => now(),
                ]);
            }

            $this->outbox->record(
                'payment',
                $payment->id,
                $captured ? 'payment.succeeded' : 'payment.created',
                ['payment_id' => $payment->id, 'reservation_id' => $reservation->id, 'amount_minor' => $payment->amount_minor],
            );

            return $payment;
        }, 3);

        return new PaymentResource($payment);
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment);
    }
}
