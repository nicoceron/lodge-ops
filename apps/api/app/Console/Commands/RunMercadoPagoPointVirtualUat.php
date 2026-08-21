<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RunMercadoPagoPointVirtualUat extends Command
{
    protected $signature = 'payments:point-virtual-uat
        {status : processed, failed, canceled, expired, action_required, or refunded}
        {--wait=50 : Maximum seconds to poll the final state}';

    protected $description = 'Run the authorized Mercado Pago test-only Point /events harness; never available as an HTTP route.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('services.mercado_pago_point_uat.authorized')) {
            $this->error('Point virtual UAT requires local/testing plus MP_POINT_UAT_AUTHORIZED=1.');

            return self::FAILURE;
        }
        $status = (string) $this->argument('status');
        if (! in_array($status, ['processed', 'failed', 'canceled', 'expired', 'action_required', 'refunded'], true)) {
            $this->error('Unsupported Point virtual status.');

            return self::INVALID;
        }
        $token = (string) config('services.mercado_pago_point_uat.access_token');
        $expectedAccount = (string) config('services.mercado_pago_point_uat.provider_account');
        if (! str_starts_with($token, 'APP_USR') || $expectedAccount === '') {
            $this->error('Explicit test Access Token and expected test seller account are required.');

            return self::FAILURE;
        }

        $http = $this->http($token);
        $reference = (string) Str::uuid();
        $created = $http->withHeader('X-Idempotency-Key', (string) Str::uuid())->post('/v1/orders', [
            'type' => 'point',
            'total_amount' => '1.00',
            'description' => 'Inn authorized Point virtual UAT',
            'external_reference' => $reference,
            'expiration_time' => 'PT15M',
            'config' => ['point' => [
                'terminal_id' => (string) config('services.mercado_pago_point_uat.terminal'),
                'print_on_terminal' => false,
            ]],
            'transactions' => ['payments' => [[
                'amount' => '1.00',
            ]]],
        ])->throw()->json();
        $orderId = is_array($created) ? data_get($created, 'id') : null;
        if (! is_string($orderId) || (string) data_get($created, 'user_id') !== $expectedAccount
            || data_get($created, 'type') !== 'point' || data_get($created, 'external_reference') !== $reference) {
            $this->error('Created virtual order failed account/type/reference validation.');

            return self::FAILURE;
        }

        $http->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->post('/v1/orders/'.rawurlencode($orderId).'/events', ['status' => $status])->throw();
        $deadline = now()->addSeconds(max(1, min(60, (int) $this->option('wait'))));
        do {
            usleep(1_000_000);
            $order = $http->get('/v1/orders/'.rawurlencode($orderId))->throw()->json();
            $current = is_array($order) ? (string) data_get($order, 'status') : '';
            if ($current === $status || ($status === 'canceled' && $current === 'cancelled')) {
                $this->line('POINT_VIRTUAL_UAT='.json_encode([
                    'order_id' => $orderId,
                    'requested_status' => $status,
                    'observed_status' => $current,
                    'account_match' => (string) data_get($order, 'user_id') === $expectedAccount,
                    'type_match' => data_get($order, 'type') === 'point',
                    'reference_match' => data_get($order, 'external_reference') === $reference,
                ], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
        } while (now()->lt($deadline));

        $this->error('The virtual order did not reach the requested state inside the documented asynchronous window.');

        return self::FAILURE;
    }

    private function http(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.mercadopago.com')
            ->acceptJson()->asJson()->withToken($token)->connectTimeout(5)->timeout(15);
    }
}
