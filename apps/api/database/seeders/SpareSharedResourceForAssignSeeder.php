<?php

namespace Database\Seeders;

use App\Enums\AllocationStatus;
use App\Models\Membership;
use App\Models\Property;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use LogicException;

class SpareSharedResourceForAssignSeeder extends Seeder
{
    public const RESOURCE_CODE = 'GUIDE-SPARE-ASSIGN';

    public const RESOURCE_NAME = 'Spare fishing guide';

    public function run(): void
    {
        $this->withDemoTenant(function (Property $property): void {
            $guideCategory = ResourceCategory::query()
                ->where('property_id', $property->id)
                ->where('slug', 'guide')
                ->firstOrFail();

            Resource::query()->updateOrCreate(
                ['property_id' => $property->id, 'code' => self::RESOURCE_CODE],
                [
                    'category_id' => $guideCategory->id,
                    'name' => self::RESOURCE_NAME,
                    'capacity' => 2,
                    'user_id' => null,
                    'attributes' => [
                        'capabilities' => ['fishing', 'hunting'],
                        'languages' => ['en', 'es'],
                    ],
                    'is_buyout' => false,
                    'housekeeping_status' => null,
                    'is_active' => true,
                ],
            );
        });
    }

    public static function reverse(): void
    {
        (new self)->withDemoTenant(function (): void {
            $resource = Resource::query()->where('code', self::RESOURCE_CODE)->first();
            if ($resource === null) {
                return;
            }

            if ($resource->allocations()->where('status', '!=', AllocationStatus::Released)->exists()) {
                throw new LogicException('Spare assign guide has active allocations and cannot be reversed.');
            }

            $resource->allocations()->delete();
            $resource->delete();
        });
    }

    /** @param  callable(Property): void  $callback */
    private function withDemoTenant(callable $callback): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $context = app(TenantContext::class);
        $previousTenant = $context->check() ? $context->tenant() : null;
        $previousMembership = $context->membership();
        $membership = Membership::query()
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('email', 'admin@example.com'))
            ->first();
        $context->set($tenant, $membership);

        try {
            $callback(Property::query()->where('code', 'MAIN')->firstOrFail());
        } finally {
            if ($previousTenant instanceof Tenant) {
                $context->set($previousTenant, $previousMembership);
            } else {
                $context->clear();
            }
        }
    }
}
