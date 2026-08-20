<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\GuestPortalTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class IssueUatGuestPortalToken extends Command
{
    protected $signature = 'uat:issue-guest-token {confirmation=RSV-DEMO-001}';

    protected $description = 'Issue a unique one-time guest token for a local deterministic UAT journey.';

    public function handle(GuestPortalTokenService $tokens, TenantContext $context): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('UAT tokens may only be issued in local or testing environments.');

            return self::FAILURE;
        }
        $reservation = Reservation::withoutGlobalScopes()
            ->where('confirmation_number', (string) $this->argument('confirmation'))->firstOrFail();
        $context->clear();
        try {
            $context->set(Tenant::query()->findOrFail($reservation->tenant_id));
            $scoped = Reservation::query()->with('primaryGuest')->findOrFail($reservation->id);
            if ($scoped->primaryGuest === null) {
                $this->error('The UAT reservation has no primary guest.');

                return self::FAILURE;
            }
            $issued = $tokens->issue($scoped, $scoped->primaryGuest);
            $this->line($issued['token']);

            return self::SUCCESS;
        } finally {
            $context->clear();
        }
    }
}
