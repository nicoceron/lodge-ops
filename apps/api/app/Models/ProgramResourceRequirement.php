<?php

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ResourceType $resource_type
 * @property int $minimum_quantity
 * @property int|null $guests_per_resource
 * @property array<int, string>|null $capabilities
 * @property array<int, string>|null $languages
 */
class ProgramResourceRequirement extends TenantModel
{
    protected function casts(): array
    {
        return [
            'resource_type' => ResourceType::class,
            'minimum_quantity' => 'integer',
            'guests_per_resource' => 'integer',
            'capabilities' => 'array',
            'languages' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function quantityForParty(int $partySize): int
    {
        $ratioQuantity = $this->guests_per_resource
            ? (int) ceil(max(1, $partySize) / $this->guests_per_resource)
            : 0;

        return max($this->minimum_quantity, $ratioQuantity);
    }
}
