<?php

namespace App\Console\Commands;

use App\Models\PaymentRequest;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ExpirePaymentRequests extends Command
{
    protected $signature = 'payments:expire-requests {--tenant=}';

    protected $description = 'Expire open payment requests whose immutable expiry has passed.';

    public function handle(): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->get();
        $count = 0;
        foreach ($tenants as $tenant) {
            app(TenantContext::class)->set($tenant);
            $count += PaymentRequest::query()->whereIn('state', ['open', 'processing'])->where('expires_at', '<=', now())->update(['state' => 'expired']);
        }
        $this->info("Expired {$count} payment request(s).");

        return self::SUCCESS;
    }
}
