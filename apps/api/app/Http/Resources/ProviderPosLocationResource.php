<?php

namespace App\Http\Resources;

use App\Models\ProviderPosLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProviderPosLocation */
class ProviderPosLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'integration_connection_id' => $this->integration_connection_id,
            'provider_store_id' => $this->provider_store_id,
            'external_pos_id' => $this->external_pos_id,
            'display_name' => $this->display_name,
            'qr_mode' => $this->qr_mode,
            'is_enabled' => $this->is_enabled,
            'health_state' => $this->health_state,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_successful_order_at' => $this->last_successful_order_at?->toIso8601String(),
            'replaced_by_id' => $this->replaced_by_id,
        ];
    }
}
