<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $property_id
 * @property string $provider
 * @property string $account_id
 * @property string $secret_ref
 * @property list<string> $webhook_secret_refs
 * @property string $from_email
 * @property string $from_name
 * @property string|null $reply_to_email
 * @property list<string> $allowed_sender_domains
 * @property CarbonImmutable|null $verified_at
 */
class CommunicationProviderConnection extends TenantModel
{
    public function setEndpointKeyAttribute(string $value): void
    {
        $this->attributes['endpoint_key_hash'] = hash('sha256', trim($value));
    }

    protected function casts(): array
    {
        return [
            'webhook_secret_refs' => 'array',
            'allowed_sender_domains' => 'array',
            'is_enabled' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function deliveryEvents(): HasMany
    {
        return $this->hasMany(CommunicationDeliveryEvent::class);
    }
}
