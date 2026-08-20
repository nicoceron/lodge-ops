<?php

namespace App\Services\DirectBooking;

use App\Data\DirectBooking\DirectBookingReadinessReport;
use App\Enums\DirectBookingPaymentMethod;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\RatePlan;

final class DirectBookingLaunchReadinessEvaluator
{
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
        if ($setting->bot_verification_required && empty($setting->accessible_fallback_url)) {
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
                    && ($publication->media->isEmpty() || $publication->media->contains(fn ($media): bool => trim($media->alt_text) === ''))) {
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
                    ->where('locale', $locale)
                    ->where('state', DirectBookingPublicationState::Published)
                    ->first();
                if ($publication === null || $publication->media->isEmpty()) {
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
                ->where('currency', $currency)->where('is_enabled', true)->with(['providerConnection', 'instructionsPublication'])->get();
            if ($capabilities->isEmpty()) {
                $reasons[] = "payment_capability_missing:{$currency}";
            }
            foreach ($capabilities as $capability) {
                if ($capability->method === DirectBookingPaymentMethod::HostedCheckout
                    && ($capability->providerConnection === null
                        || $capability->providerConnection->type !== 'payment'
                        || $capability->providerConnection->status !== 'connected'
                        || empty($capability->providerConnection->secret_reference))) {
                    $reasons[] = "hosted_checkout_not_ready:{$currency}";
                }
                if ($capability->method === DirectBookingPaymentMethod::ManualBankTransfer) {
                    foreach ($setting->supported_locales as $locale) {
                        if ($this->published($setting->property_id, $locale, DirectBookingPublicationKind::BankTransferInstructions) === null) {
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
}
