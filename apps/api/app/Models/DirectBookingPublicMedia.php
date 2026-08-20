<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property string $public_key
 * @property string $media_reference
 * @property string $mime_type
 * @property string $alt_text
 * @property int|null $width
 * @property int|null $height
 */
class DirectBookingPublicMedia extends TenantModel
{
    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            $media->public_key ??= (string) Str::ulid();
        });
        static::saving(function (self $media): void {
            if (trim($media->alt_text) === '') {
                throw new LogicException('Published media requires meaningful alt text.');
            }
            if (! preg_match('#^(public-media://[A-Za-z0-9._/-]+|https://[A-Za-z0-9.-]+/[A-Za-z0-9._~/%-]+)$#', $media->media_reference)) {
                throw new LogicException('Public media must use an approved opaque public-media reference or a query-free HTTPS asset URL.');
            }
        });
    }

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'sort_order' => 'integer'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(DirectBookingPublication::class, 'publication_id');
    }
}
