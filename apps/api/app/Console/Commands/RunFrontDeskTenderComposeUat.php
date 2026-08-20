<?php

namespace App\Console\Commands;

use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Models\CashShift;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\FolioService;
use App\Services\Payments\CloseCashShift;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class RunFrontDeskTenderComposeUat extends Command
{
    protected $signature = 'payments:front-desk-compose-uat
        {reservation? : Existing UAT reservation UUID to receive a synthetic credit adjustment}
        {--credit=0 : Positive minor-unit credit adjustment for the existing UAT reservation}
        {--revoke-token= : Revoke the temporary UAT API token after the journey}';

    protected $description = 'Prepare deterministic local data for the authenticated P3-06B Compose browser journey.';

    public function handle(FolioService $folio, CloseCashShift $closeShift): int
    {
        if (! app()->environment('local')) {
            $this->error('The front-desk tender Compose UAT is restricted to the local environment.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $property = Property::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('created_at')->firstOrFail();
        $membership = Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', MembershipRole::Administrator)
            ->orderBy('id')
            ->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
        $property->update(['settings' => [
            ...($property->settings ?? []),
            'cash_variance_threshold_minor' => 0,
        ]]);

        $revokeTokenId = $this->option('revoke-token');
        if (is_string($revokeTokenId) && $revokeTokenId !== '') {
            PersonalAccessToken::query()->whereKey($revokeTokenId)->where('tokenable_id', $membership->user_id)->delete();
            $this->line('FRONT_DESK_UAT_TOKEN_REVOKED=1');

            return self::SUCCESS;
        }

        $reservationId = $this->argument('reservation');
        if (is_string($reservationId) && $reservationId !== '') {
            $creditMinor = (int) $this->option('credit');
            if ($creditMinor <= 0) {
                $this->error('An existing UAT reservation requires a positive --credit value.');

                return self::FAILURE;
            }
            $reservation = Reservation::query()->whereKey($reservationId)->firstOrFail();
            $folio->append(
                $reservation,
                FolioLineType::Adjustment,
                'P3-06B deterministic UAT guest credit',
                1000,
                -$creditMinor,
                $membership->user_id,
            );
            $this->line('FRONT_DESK_UAT='.json_encode($this->descriptor($tenant, $property, $reservation, $folio, $creditMinor), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $guest = Guest::query()->orderBy('created_at')->firstOrFail();
        CashShift::query()
            ->where('property_id', $property->id)
            ->where('cashier_id', $membership->user_id)
            ->where('currency', 'USD')
            ->where('state', 'open')
            ->get()
            ->each(fn (CashShift $shift) => $closeShift->handle(
                $membership->user,
                $shift,
                $shift->currentExpectedMinor(),
                'Closed by the deterministic local UAT reset.',
                'front-desk-uat-reset:'.$shift->id,
            ));
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'subtotal_minor' => 100_000,
            'tax_minor' => 0,
            'total_minor' => 100_000,
            'source' => 'front-desk-compose-uat',
        ]);
        $token = $membership->user->createToken('front-desk-compose-uat');
        $descriptor = [
            ...$this->descriptor($tenant, $property, $reservation, $folio, 0),
            'access_token' => $token->plainTextToken,
            'access_token_id' => $token->accessToken->getKey(),
        ];
        $handoff = (string) Str::uuid();
        $handoffPath = sys_get_temp_dir().'/inn-front-desk-uat-'.$handoff.'.json';
        if (file_put_contents($handoffPath, json_encode($descriptor, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! chmod($handoffPath, 0600)) {
            $token->accessToken->delete();
            @unlink($handoffPath);
            throw new RuntimeException('The front-desk UAT handoff could not be written securely.');
        }
        $this->line('FRONT_DESK_UAT_HANDLE='.$handoff);

        return self::SUCCESS;
    }

    /** @return array<string, int|string> */
    private function descriptor(Tenant $tenant, Property $property, Reservation $reservation, FolioService $folio, int $creditMinor): array
    {
        return [
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'currency' => $reservation->currency,
            'total_minor' => $reservation->total_minor,
            'credit_minor' => $creditMinor,
            'balance_minor' => $folio->summary($reservation)['balance_minor'],
        ];
    }
}
