<?php

namespace App\Models;

use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Services\Documents\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $property_id
 * @property string|null $public_item_id
 * @property DirectBookingPublicationKind $kind
 * @property DirectBookingPublicationState $state
 * @property string $locale
 * @property int $version
 * @property string $title
 * @property string|null $summary
 * @property string|null $body
 * @property array<string, mixed>|null $content
 * @property string $checksum
 * @property CarbonImmutable|null $effective_at
 * @property-read Collection<int, DirectBookingPublicMedia> $media
 */
class DirectBookingPublication extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $publication): void {
            if ($publication->public_item_id !== null) {
                $item = DirectBookingPublicItem::query()
                    ->whereKey($publication->public_item_id)
                    ->where('property_id', $publication->property_id)
                    ->first();
                $expectedKind = $item?->kind === 'category'
                    ? DirectBookingPublicationKind::Category
                    : DirectBookingPublicationKind::Program;
                if ($item === null || $publication->kind !== $expectedKind) {
                    throw new LogicException('Published item copy must match the item kind, property, and tenant.');
                }
            }
            if ($publication->exists && $publication->getOriginal('state') === DirectBookingPublicationState::Published->value) {
                $allowed = ['state', 'retired_at', 'updated_at'];
                if (array_diff(array_keys($publication->getDirty()), $allowed) !== []) {
                    throw new LogicException('Published direct-booking content is immutable; publish a new version.');
                }
            }
            $publication->checksum = app(CanonicalJson::class)->checksum([
                'property_id' => $publication->property_id,
                'public_item_id' => $publication->public_item_id,
                'kind' => $publication->kind->value,
                'locale' => $publication->locale,
                'version' => (int) $publication->version,
                'title' => $publication->title,
                'summary' => $publication->summary,
                'body' => $publication->body,
                'content' => $publication->content,
                'effective_at' => $publication->effective_at,
            ]);
        });
        static::deleting(fn (self $publication) => $publication->state === DirectBookingPublicationState::Draft
            ?: throw new LogicException('Published direct-booking content cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'kind' => DirectBookingPublicationKind::class,
            'state' => DirectBookingPublicationState::class,
            'version' => 'integer',
            'content' => 'array',
            'effective_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function publicItem(): BelongsTo
    {
        return $this->belongsTo(DirectBookingPublicItem::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(DirectBookingPublicMedia::class, 'publication_id')->orderBy('sort_order');
    }
}
