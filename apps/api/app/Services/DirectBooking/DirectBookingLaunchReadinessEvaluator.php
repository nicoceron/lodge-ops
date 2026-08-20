<?php

namespace App\Services\DirectBooking;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\DirectBooking\DirectBookingReadinessReport;
use App\Enums\DirectBookingPaymentMethod;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\IntegrationConnection;
use App\Models\RatePlan;
use Throwable;

final class DirectBookingLaunchReadinessEvaluator
{
    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly DirectBookingPublicUrl $publicUrls,
    ) {}

    public function evaluate(DirectBookingPropertySetting $setting): DirectBookingReadinessReport
    {
        $reasons = [];
        $property = $setting->property;
        if (! $setting->direct_booking_enabled) {
            $reasons[] = 'feature_disabled';
        }
        if (! $property->is_active) {
            $reasons[] = 'property_inactive';
        }
        if ($setting->public_slug === '' || $setting->supported_locales === [] || $setting->supported_currencies === []) {
            $reasons[] = 'locale_or_currency_missing';
        }
        if (empty($setting->accessible_fallback_url)
            || ! $this->publicUrls->isSafeHttps($setting->accessible_fallback_url)) {
            $reasons[] = 'accessible_bot_fallback_missing';
        }

        $requiredKinds = [
            DirectBookingPublicationKind::Property,
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
        ];
        foreach ($setting->supported_locales as $locale) {
            foreach ($requiredKinds as $kind) {
                $publication = $this->published($setting->property_id, $locale, $kind);
                if ($publication === null || trim($publication->title) === ''
                    || ($kind !== DirectBookingPublicationKind::Property && trim((string) $publication->body) === '')) {
                    $reasons[] = "publication_missing:{$locale}:{$kind->value}";

                    continue;
                }
                if ($kind === DirectBookingPublicationKind::Property
                    && ! $this->validMedia($publication)) {
                    $reasons[] = "property_media_missing:{$locale}";
                }
            }
        }

        $items = DirectBookingPublicItem::query()
            ->where('property_id', $setting->property_id)->where('is_enabled', true)->get();
        if ($items->isEmpty()) {
            $reasons[] = 'bookable_projection_missing';
        }
        foreach ($items as $item) {
            foreach ($setting->supported_locales as $locale) {
                $publication = DirectBookingPublication::query()
                    ->where('property_id', $setting->property_id)
                    ->where('public_item_id', $item->id)
                    ->where('kind', $item->kind === 'category'
                        ? DirectBookingPublicationKind::Category
                        : DirectBookingPublicationKind::Program)
                    ->where('locale', $locale)
                    ->where('state', DirectBookingPublicationState::Published)
                    ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                    ->first();
                if ($publication === null || trim($publication->title) === '' || trim((string) $publication->summary) === ''
                    || ! $this->validMedia($publication)) {
                    $reasons[] = "item_publication_missing:{$item->public_key}:{$locale}";
                }
            }
        }

        foreach ($setting->supported_currencies as $currency) {
            $hasCommercialRule = RatePlan::query()
                ->where('property_id', $setting->property_id)
                ->where('currency', $currency)
                ->where('state', 'published')
                ->where('is_active', true)
                ->whereHas('rules')
                ->exists();
            if (! $hasCommercialRule) {
                $reasons[] = "commercial_rules_missing:{$currency}";
            }
            $capabilities = DirectBookingPaymentCapability::query()
                ->where('property_id', $setting->property_id)
                ->where('currency', $currency)->where('is_enabled', true)
                ->with(['providerConnection', 'localizedInstructions.publication'])->get();
            if ($capabilities->isEmpty()) {
                $reasons[] = "payment_capability_missing:{$currency}";
            }
            foreach ($capabilities as $capability) {
                if ($capability->method === DirectBookingPaymentMethod::HostedCheckout
                    && ($capability->providerConnection === null
                        || $capability->providerConnection->type !== 'payment'
                        || $capability->providerConnection->status !== 'connected'
                        || empty($capability->providerConnection->secret_reference)
                        || trim((string) data_get($capability->providerConnection->configuration, 'provider_account')) === ''
                        || strtoupper((string) data_get($capability->providerConnection->configuration, 'charge_currency')) !== $currency
                        || ! $this->gatewaySupported($capability->providerConnection))) {
                    $reasons[] = "hosted_checkout_not_ready:{$currency}";
                }
                if ($capability->method === DirectBookingPaymentMethod::ManualBankTransfer) {
                    foreach ($setting->supported_locales as $locale) {
                        $instructions = $capability->localizedInstructions->firstWhere('locale', $locale)?->publication;
                        if ($instructions === null
                            || $instructions->property_id !== $setting->property_id
                            || $instructions->kind !== DirectBookingPublicationKind::BankTransferInstructions
                            || $instructions->locale !== $locale
                            || $instructions->state !== DirectBookingPublicationState::Published
                            || ($instructions->effective_at !== null && $instructions->effective_at->isFuture())
                            || trim($instructions->title) === '' || trim((string) $instructions->body) === '') {
                            $reasons[] = "bank_instructions_missing:{$currency}:{$locale}";
                        }
                    }
                }
            }
        }

        return new DirectBookingReadinessReport($reasons === [], array_values(array_unique($reasons)));
    }

    private function published(string $propertyId, string $locale, DirectBookingPublicationKind $kind): ?DirectBookingPublication
    {
        return DirectBookingPublication::query()->with('media')
            ->where('property_id', $propertyId)
            ->whereNull('public_item_id')
            ->where('locale', $locale)
            ->where('kind', $kind)
            ->where('state', DirectBookingPublicationState::Published)
            ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->first();
    }

    private function validMedia(DirectBookingPublication $publication): bool
    {
        return $publication->media->isNotEmpty() && $publication->media->every(fn ($media): bool => trim($media->alt_text) !== ''
            && (str_starts_with($media->media_reference, 'public-media://')
                || $this->publicUrls->isSafeHttps($media->media_reference))
        );
    }

    private function gatewaySupported(IntegrationConnection $connection): bool
    {
        try {
            $this->gateways->for($connection);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
