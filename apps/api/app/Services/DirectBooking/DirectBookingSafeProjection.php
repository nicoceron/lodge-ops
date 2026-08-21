<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\RatePlan;
use App\Models\RatePlanService;

final class DirectBookingSafeProjection
{
    /** @return array<string, mixed> */
    public function property(DirectBookingPropertySetting $setting, string $locale): array
    {
        $publication = DirectBookingPublication::query()
            ->with('media')->where('property_id', $setting->property_id)->whereNull('public_item_id')
            ->where('kind', DirectBookingPublicationKind::Property)
            ->where('locale', $locale)
            ->where('state', DirectBookingPublicationState::Published)
            ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->firstOrFail();
        $items = DirectBookingPublicItem::query()
            ->where('property_id', $setting->property_id)->where('is_enabled', true)
            ->with(['publications' => fn ($query) => $query
                ->with('media')->where('locale', $locale)->where('state', DirectBookingPublicationState::Published)
                ->whereIn('kind', [DirectBookingPublicationKind::Category, DirectBookingPublicationKind::Program])
                ->where(fn ($published) => $published->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                ->orderByDesc('effective_at')->orderByDesc('version')->orderBy('id')])
            ->orderBy('sort_order')->get();
        $capabilities = DirectBookingPaymentCapability::query()
            ->where('property_id', $setting->property_id)
            ->where('is_enabled', true)
            ->whereIn('currency', $setting->supported_currencies)
            ->where(function ($query) use ($locale): void {
                $query->where('method', 'hosted_checkout')
                    ->orWhereHas('localizedInstructions', fn ($instructions) => $instructions
                        ->where('locale', $locale)
                        ->whereHas('publication', fn ($publication) => $publication
                            ->where('kind', DirectBookingPublicationKind::BankTransferInstructions)
                            ->where('locale', $locale)
                            ->where('state', DirectBookingPublicationState::Published)
                            ->where(fn ($published) => $published->whereNull('effective_at')->orWhere('effective_at', '<=', now()))));
            })->orderBy('currency')->orderBy('method')->get();
        $optionalServices = RatePlan::query()
            ->where('property_id', $setting->property_id)
            ->where('state', 'published')->where('is_active', true)
            ->whereIn('currency', $setting->supported_currencies)
            ->with(['services' => fn ($query) => $query
                ->where('selection_type', 'optional')->where('is_active', true)
                ->whereNotNull('direct_booking_public_key')->with('catalogItem')])
            ->orderBy('currency')->orderByDesc('version')->get()
            ->flatMap(fn (RatePlan $plan) => $plan->services
                ->filter(fn (RatePlanService $service): bool => $service->catalogItem->is_active
                    && $service->catalogItem->currency === $plan->currency)
                ->map(fn (RatePlanService $service): array => $this->optionalService($service, $plan->currency)))
            ->unique('key')->values()->all();

        return [
            'slug' => $setting->public_slug,
            'name' => $publication->title,
            'summary' => $publication->summary,
            'locale' => $locale,
            'timezone' => $setting->property->timezone,
            'supported_locales' => $setting->supported_locales,
            'supported_currencies' => $setting->supported_currencies,
            'accessible_fallback_url' => $setting->accessible_fallback_url,
            'media' => $this->media($publication->media),
            'bookables' => $items->map(function ($item): array {
                $expectedKind = $item->kind === 'category'
                    ? DirectBookingPublicationKind::Category
                    : DirectBookingPublicationKind::Program;
                $copy = $item->publications->first(
                    fn (DirectBookingPublication $candidate): bool => $candidate->kind === $expectedKind,
                );

                return [
                    'key' => $item->public_key,
                    'kind' => $item->kind,
                    'name' => $copy?->title,
                    'summary' => $copy?->summary,
                    'media' => $copy === null ? [] : $this->media($copy->media),
                ];
            })->values()->all(),
            'optional_services' => $optionalServices,
            'payment_capabilities' => $capabilities->map(fn ($capability): array => [
                'method' => $capability->method->value,
                'currency' => $capability->currency,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function optionalService(RatePlanService $service, string $currency): array
    {
        return [
            'key' => $service->direct_booking_public_key,
            'name' => $service->catalogItem->name,
            'description' => null,
            'pricing' => [
                'unit_amount' => [
                    'amount_minor' => $service->amount_minor ?? $service->catalogItem->price_minor,
                    'currency' => strtoupper($currency),
                ],
                'quantity_basis' => $service->quantity_basis,
                'default_quantity' => $service->default_quantity,
                'maximum_quantity' => $service->maximum_quantity,
            ],
            'applicability' => 'selected_rate_plan',
        ];
    }

    /**
     * @param  array{categories: list<array<string, mixed>>, resources?: list<array<string, mixed>>, programs?: list<array<string, mixed>>}  $availability
     * @return list<array{key: string, kind: string, bookable: bool}>
     */
    public function availability(DirectBookingPropertySetting $setting, array $availability): array
    {
        $categories = collect($availability['categories'])->keyBy('id');
        $programs = collect($availability['programs'] ?? [])->keyBy('id');

        return DirectBookingPublicItem::query()
            ->where('property_id', $setting->property_id)
            ->where('is_enabled', true)->orderBy('sort_order')->get()
            ->map(fn ($item): array => [
                'key' => $item->public_key,
                'kind' => $item->kind,
                'bookable' => (bool) data_get(
                    $item->kind === 'category' ? $categories->get($item->resource_category_id) : $programs->get($item->program_id),
                    'available',
                    false,
                ),
            ])->values()->all();
    }

    /** @return list<array{key: string, url: string, mime_type: string, alt: string, width: int|null, height: int|null}> */
    private function media(iterable $media): array
    {
        return collect($media)->map(fn ($asset): array => [
            'key' => $asset->public_key,
            'url' => $asset->media_reference,
            'mime_type' => $asset->mime_type,
            'alt' => $asset->alt_text,
            'width' => $asset->width,
            'height' => $asset->height,
        ])->values()->all();
    }
}
