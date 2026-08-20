<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;

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
            ->firstOrFail();
        $items = DirectBookingPublicItem::query()
            ->where('property_id', $setting->property_id)->where('is_enabled', true)
            ->with(['publications' => fn ($query) => $query
                ->with('media')->where('locale', $locale)->where('state', DirectBookingPublicationState::Published)])
            ->orderBy('sort_order')->get();

        return [
            'slug' => $setting->public_slug,
            'name' => $publication->title,
            'summary' => $publication->summary,
            'locale' => $locale,
            'timezone' => $setting->property->timezone,
            'supported_locales' => $setting->supported_locales,
            'supported_currencies' => $setting->supported_currencies,
            'media' => $this->media($publication->media),
            'bookables' => $items->map(function ($item): array {
                $copy = $item->publications->first();

                return [
                    'key' => $item->public_key,
                    'kind' => $item->kind,
                    'name' => $copy?->title,
                    'summary' => $copy?->summary,
                    'media' => $copy === null ? [] : $this->media($copy->media),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array{categories: list<array<string, mixed>>, resources: list<array<string, mixed>>}  $availability
     * @return list<array{key: string, bookable: bool}>
     */
    public function availability(DirectBookingPropertySetting $setting, array $availability): array
    {
        $bookability = collect($availability['categories'])->keyBy('id');

        return DirectBookingPublicItem::query()
            ->where('property_id', $setting->property_id)
            ->where('kind', 'category')->where('is_enabled', true)->get()
            ->map(fn ($item): array => [
                'key' => $item->public_key,
                'bookable' => (bool) data_get($bookability->get($item->resource_category_id), 'available', false),
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
