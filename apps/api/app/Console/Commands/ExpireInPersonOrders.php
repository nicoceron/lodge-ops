<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireInPersonOrders extends Command
{
    protected $signature = 'payments:expire-in-person-orders {--tenant=}';

    protected $description = 'Expire local Point/QR Orders whose display/payment window has elapsed.';

    public function handle(): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->get();
        $count = 0;
        foreach ($tenants as $tenant) {
            app(TenantContext::class)->set($tenant);
            PaymentAttempt::query()->where('channel', 'qr')
                ->whereIn('state', ['creating', 'queued', 'at_terminal', 'action_required', 'processing'])
                ->whereNotNull('order_expires_at')->where('order_expires_at', '<=', now())
                ->orderBy('id')->pluck('id')->each(function (string $id) use (&$count): void {
                    DB::transaction(function () use ($id, &$count): void {
                        $attempt = PaymentAttempt::query()->lockForUpdate()->find($id);
                        if ($attempt === null || $attempt->channel !== 'qr' || ! $attempt->state->reusable()
                            || $attempt->order_expires_at === null || $attempt->order_expires_at->isFuture()) {
                            return;
                        }
                        $attempt->update([
                            'state' => 'expired',
                            'qr_data_ciphertext' => null,
                            'last_error' => 'Local QR display/order window expired before authoritative approval.',
                            'last_processed_at' => now(),
                        ]);
                        $attempt->paymentRequest()->where('state', 'processing')->update(['state' => 'expired']);
                        $count++;
                    }, 3);
                });
        }
        $this->info("Expired {$count} in-person order(s).");

        return self::SUCCESS;
    }
}
