<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exact public command responses are encrypted because begin/recovery responses
 * contain raw credentials that may never be persisted in plaintext.
 *
 * @property string $idempotency_key
 * @property string $command
 * @property string $request_checksum
 * @property int|null $status_code
 * @property string|null $response_body_encrypted
 * @property array<string, string>|null $response_headers
 * @property CarbonImmutable $lease_expires_at
 * @property CarbonImmutable $expires_at
 */
class DirectBookingCommandResponse extends TenantModel
{
    protected $hidden = ['response_body_encrypted', 'request_checksum'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'response_body_encrypted' => 'encrypted',
            'response_headers' => 'array',
            'lease_expires_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
