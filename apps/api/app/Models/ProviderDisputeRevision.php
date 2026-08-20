<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProviderDisputeRevision extends TenantModel
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'amount_minor' => 'integer',
            'coverage_applied' => 'boolean',
            'documentation_required' => 'boolean',
            'documentation_deadline' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'provider_facts' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Provider dispute revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Provider dispute revisions cannot be deleted.'));
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(ProviderDispute::class, 'provider_dispute_id');
    }

    public function providerEvent(): BelongsTo
    {
        return $this->belongsTo(ProviderEvent::class);
    }
}
