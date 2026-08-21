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

    public const SECOND_RESOURCE_CODE = 'GUIDE-SPARE-ASSIGN-2';

    public const SECOND_RESOURCE_NAME = 'Spare fishing guide 2';

    public function run(): void
    {
        $this->withDemoTenant(function (Property $property): void {
            $guideCategory = ResourceCategory::query()
                ->where('property_id', $property->id)
                ->where('slug', 'guide')
                ->firstOrFail();

            foreach ($this->definitions() as $definition) {
                Resource::query()->updateOrCreate(
                    ['property_id' => $property->id, 'code' => $definition['code']],
                    [
                        'category_id' => $guideCategory->id,
                        'name' => $definition['name'],
                        'capacity' => $definition['capacity'],
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
            }
        });
    }

    public static function reverse(): void
    {
        (new self)->withDemoTenant(function (): void {
            foreach (array_reverse(self::definitions()) as $definition) {
                $resource = Resource::query()->where('code', $definition['code'])->first();
                if ($resource === null) {
                    continue;
                }

                if ($resource->allocations()->where('status', '!=', AllocationStatus::Released)->exists()) {
                    throw new LogicException('Spare assign guide has active allocations and cannot be reversed.');
                }

                $resource->allocations()->delete();
                $resource->delete();
            }
        });
    }

    /** @return list<array{code: string, name: string, capacity: int}> */
    private static function definitions(): array
    {
        return [
            ['code' => self::RESOURCE_CODE, 'name' => self::RESOURCE_NAME, 'capacity' => 2],
            ['code' => self::SECOND_RESOURCE_CODE, 'name' => self::SECOND_RESOURCE_NAME, 'capacity' => 1],
        ];
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
