<?php

namespace Database\Seeders;

use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Tenant;
use App\Services\Integrations\EndpointKeyService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Sets up a launch-ready direct-booking COP property on the demo tenant
 * for user-testing validation of the direct-booking-api milestone.
 *
 * Reversible via DirectBookingUatSeeder::reverse().
 */
class DirectBookingUatSeeder extends Seeder
{
    private const TENANT_ID = '11111111-1111-4111-8111-111111111111';

    private const PROPERTY_ID = '01a02aa0-8647-7173-8316-10d054cd8d95';

    private const CABIN_CATEGORY_ID = '01a02aa0-8c51-73d3-817d-7a872d5e2b33';

    private const DEPOSIT_POLICY_ID = '01a02aa0-8c71-720d-8888-05b18d188da9';

    private const CANCELLATION_POLICY_ID = '01a02aa0-8c73-733b-a5f7-e66c87017a27';

    private const PUBLIC_SLUG = 'estancia-viento-sur';

    public function run(): void
    {
        $tenant = Tenant::query()->findOrFail(self::TENANT_ID);
        $membership = Membership::withoutGlobalScopes()->where('tenant_id', self::TENANT_ID)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $propertyId = self::PROPERTY_ID;
        $tenantId = self::TENANT_ID;
        $categoryId = self::CABIN_CATEGORY_ID;

        // 1. Property settings
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $propertyId,
            'public_slug' => self::PUBLIC_SLUG,
            'direct_booking_enabled' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'COP',
            'supported_currencies' => ['COP'],
            'bot_verification_required' => false,
            'accessible_fallback_url' => 'https://book.example.test/contact',
            'session_ttl_minutes' => 30,
            'initial_hold_minutes' => 15,
            'checkout_extension_minutes' => 10,
            'maximum_hold_minutes' => 30,
            'retention_days' => 90,
        ]);

        // 2. Public item (category)
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $propertyId,
            'kind' => 'category',
            'resource_category_id' => $categoryId,
            'is_enabled' => true,
            'sort_order' => 0,
        ]);

        // 3. Publications
        $propertyPub = $this->publication($propertyId, DirectBookingPublicationKind::Property);
        $this->media($propertyPub);
        $categoryPub = $this->publication($propertyId, DirectBookingPublicationKind::Category, $item->id);
        $this->media($categoryPub);

        foreach ([
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
        ] as $kind) {
            $this->publication($propertyId, $kind);
        }

        $instructions = $this->publication($propertyId, DirectBookingPublicationKind::BankTransferInstructions);

        // 4. Payment capability: manual_bank_transfer
        $manualCap = DirectBookingPaymentCapability::query()->create([
            'property_id' => $propertyId,
            'currency' => 'COP',
            'method' => 'manual_bank_transfer',
            'is_enabled' => true,
            'instructions_publication_id' => $instructions->id,
        ]);
        DirectBookingPaymentInstruction::query()->create([
            'property_id' => $propertyId,
            'direct_booking_payment_capability_id' => $manualCap->id,
            'publication_id' => $instructions->id,
            'locale' => 'en',
        ]);

        // 5. Published COP rate plan
        $plan = RatePlan::query()->create([
            'property_id' => $propertyId,
            'name' => 'Public COP UAT rate',
            'currency' => 'COP',
            'state' => 'draft',
            'is_active' => true,
            'deposit_policy_id' => self::DEPOSIT_POLICY_ID,
            'cancellation_policy_id' => self::CANCELLATION_POLICY_ID,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $categoryId,
            'amount_minor' => 40_000,
            'adult_amount_minor' => 40_000,
            'child_amount_minor' => 20_000,
            'infant_amount_minor' => 0,
            'price_type' => 'per_night',
            'minimum_stay' => 1,
            'closed_to_arrival' => false,
            'closed_to_departure' => false,
            'stop_sell' => false,
            'priority' => 0,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);

        // 6. Integration connection: Checkout Pro, MCO, COP, deterministic_fixture
        $connection = IntegrationConnection::query()->create([
            'property_id' => $propertyId,
            'name' => 'COP UAT Checkout Pro',
            'type' => 'payment',
            'provider' => 'mercado_pago',
            'product' => 'checkout_pro',
            'external_account_id' => 'seller-mco-uat',
            'environment' => 'sandbox',
            'status' => 'connected',
            'is_enabled' => true,
            'capabilities' => ['payment.hosted_checkout'],
            'configuration' => [
                'charge_currency' => 'COP',
                'site' => 'MCO',
                'return_url_base' => 'http://localhost:8000',
                'webhook_secret_reference' => 'env:MERCADO_PAGO_WEBHOOK_SECRET',
                'transport' => 'deterministic_fixture',
                'fixture' => [
                    'preference_id' => 'pref-uat-cop',
                ],
            ],
            'secret_reference' => 'env:MERCADO_PAGO_ACCESS_TOKEN',
            'configuration_version' => 1,
        ]);
        $connection->connectionCapabilities()->create([
            'capability' => 'payment.hosted_checkout',
            'direction' => 'outbound',
            'state' => 'enabled',
            'configuration_version' => 1,
        ]);

        // Payment capability: hosted_checkout
        DirectBookingPaymentCapability::query()->create([
            'property_id' => $propertyId,
            'currency' => 'COP',
            'method' => 'hosted_checkout',
            'is_enabled' => true,
            'provider_connection_id' => $connection->id,
        ]);

        // 7. Rotate webhook key
        $endpointKeys = app(EndpointKeyService::class);
        $keyResult = $endpointKeys->rotate($connection, 0, $membership->user_id, 'DirectBookingUatSeeder webhook key');
        $connection->forceFill([
            // Keep the generated key available to fresh API processes in this
            // local-only seed. The raw value is encrypted and never committed.
            'legacy_endpoint_key_ciphertext' => Crypt::encryptString($keyResult['key']),
        ])->save();
        $connection->refresh();

        // Write webhook key to a file for flow validators to read
        $keyFile = storage_path('app/private/direct-booking-uat-webhook-key.txt');
        file_put_contents($keyFile, $keyResult['key']);
        chmod($keyFile, 0600);

        $this->command->info('DirectBookingUatSeeder: property settings, publications, COP rate, Checkout Pro connection created.');
        $this->command->info('Public slug: '.self::PUBLIC_SLUG);
        $this->command->info('Webhook key stored at: '.$keyFile);
        $this->command->info('Public item key: '.$item->public_key);
    }

    public function reverse(): void
    {
        $tenant = Tenant::query()->findOrFail(self::TENANT_ID);
        $membership = Membership::withoutGlobalScopes()->where('tenant_id', self::TENANT_ID)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $propertyId = self::PROPERTY_ID;

        // Clean up in reverse dependency order
        DB::table('direct_booking_payment_instructions')->where('property_id', $propertyId)->delete();
        DB::table('direct_booking_payment_capabilities')->where('property_id', $propertyId)->delete();
        DB::table('integration_connection_capabilities')
            ->whereIn('integration_connection_id', function ($q) use ($propertyId) {
                $q->select('id')->from('integration_connections')->where('property_id', $propertyId)
                    ->where('name', 'COP UAT Checkout Pro');
            })->delete();
        DB::table('integration_endpoint_keys')
            ->whereIn('integration_connection_id', function ($q) use ($propertyId) {
                $q->select('id')->from('integration_connections')->where('property_id', $propertyId)
                    ->where('name', 'COP UAT Checkout Pro');
            })->delete();
        DB::table('integration_connections')->where('property_id', $propertyId)
            ->where('name', 'COP UAT Checkout Pro')->delete();
        DB::table('rate_rules')
            ->whereIn('rate_plan_id', function ($q) use ($propertyId) {
                $q->select('id')->from('rate_plans')->where('property_id', $propertyId)
                    ->where('name', 'Public COP UAT rate');
            })->delete();
        DB::table('rate_plans')->where('property_id', $propertyId)
            ->where('name', 'Public COP UAT rate')->delete();
        DB::table('direct_booking_public_media')
            ->whereIn('publication_id', function ($q) use ($propertyId) {
                $q->select('id')->from('direct_booking_publications')->where('property_id', $propertyId);
            })->delete();
        DB::table('direct_booking_publications')->where('property_id', $propertyId)->delete();
        DB::table('direct_booking_public_items')->where('property_id', $propertyId)->delete();
        DB::table('direct_booking_property_settings')->where('property_id', $propertyId)->delete();

        @unlink(storage_path('app/private/direct-booking-uat-webhook-key.txt'));

        $this->command->info('DirectBookingUatSeeder: reversed.');
    }

    private function publication(string $propertyId, DirectBookingPublicationKind $kind, ?string $itemId = null): DirectBookingPublication
    {
        return DirectBookingPublication::query()->create([
            'property_id' => $propertyId,
            'public_item_id' => $itemId,
            'kind' => $kind,
            'locale' => 'en',
            'version' => 1,
            'state' => DirectBookingPublicationState::Published,
            'title' => ucfirst(str_replace('_', ' ', $kind->value)),
            'summary' => 'Safe public summary.',
            'body' => 'Published content.',
            'effective_at' => now()->subMinute(),
            'published_at' => now(),
        ]);
    }

    private function media(DirectBookingPublication $publication): void
    {
        DirectBookingPublicMedia::query()->create([
            'publication_id' => $publication->id,
            'media_reference' => 'public-media://direct-booking/test.webp',
            'mime_type' => 'image/webp',
            'alt_text' => 'Accessible property image',
            'width' => 1200,
            'height' => 800,
        ]);
    }
}
