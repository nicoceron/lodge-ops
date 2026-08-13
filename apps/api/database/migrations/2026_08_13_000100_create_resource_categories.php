<?php

use App\Enums\ResourceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name');
            $table->string('slug', 40);
            $table->boolean('counts_as_stay')->default(false);
            $table->unsignedInteger('default_capacity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'property_id', 'slug'], 'resource_categories_property_slug_unique');
            $table->index(['tenant_id', 'property_id', 'kind', 'sort_order'], 'resource_categories_kind_idx');
            $table->foreign(['tenant_id', 'property_id'], 'resource_categories_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->cascadeOnDelete();
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->foreignUuid('category_id')->nullable()->after('property_id');
        });

        Schema::table('program_resource_requirements', function (Blueprint $table): void {
            $table->foreignUuid('resource_category_id')->nullable()->after('program_id');
        });

        $this->backfill();

        Schema::table('resources', function (Blueprint $table): void {
            $table->uuid('category_id')->nullable(false)->change();
        });

        Schema::table('program_resource_requirements', function (Blueprint $table): void {
            $table->uuid('resource_category_id')->nullable(false)->change();
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'category_id'], 'resources_tenant_category_fk')
                ->references(['tenant_id', 'id'])->on('resource_categories')->restrictOnDelete();
            $table->index(['tenant_id', 'category_id'], 'resources_tenant_category_idx');
        });

        Schema::table('program_resource_requirements', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'resource_category_id'], 'program_requirements_tenant_category_fk')
                ->references(['tenant_id', 'id'])->on('resource_categories')->restrictOnDelete();
            $table->dropColumn('resource_type');
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'property_id', 'type']);
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->string('type', 32)->default('room')->after('code');
            $table->index(['tenant_id', 'property_id', 'type']);
        });

        Schema::table('program_resource_requirements', function (Blueprint $table): void {
            $table->string('resource_type', 32)->default('room')->after('program_id');
        });

        foreach (DB::table('resources')->select('id', 'category_id')->cursor() as $resource) {
            $slug = DB::table('resource_categories')->where('id', $resource->category_id)->value('slug');
            DB::table('resources')->where('id', $resource->id)->update(['type' => $slug ?: 'room']);
        }

        foreach (DB::table('program_resource_requirements')->select('id', 'resource_category_id')->cursor() as $requirement) {
            $slug = DB::table('resource_categories')->where('id', $requirement->resource_category_id)->value('slug');
            DB::table('program_resource_requirements')->where('id', $requirement->id)->update(['resource_type' => $slug ?: 'room']);
        }

        Schema::table('resources', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'category_id'])
                : $table->dropForeign('resources_tenant_category_fk');
            $table->dropIndex('resources_tenant_category_idx');
            $table->dropColumn('category_id');
        });

        Schema::table('program_resource_requirements', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'resource_category_id'])
                : $table->dropForeign('program_requirements_tenant_category_fk');
            $table->dropColumn('resource_category_id');
        });

        Schema::dropIfExists('resource_categories');
    }

    private function backfill(): void
    {
        $categoryIds = [];
        $slugsByProperty = [];

        foreach (DB::table('resources')->select('property_id', 'type')->cursor() as $resource) {
            $slugsByProperty[$resource->property_id][$this->legacySlug((string) $resource->type)] = true;
        }

        foreach (DB::table('program_resource_requirements as requirements')
            ->join('programs', 'programs.id', '=', 'requirements.program_id')
            ->select('programs.property_id', 'requirements.resource_type')
            ->cursor() as $requirement) {
            $slugsByProperty[$requirement->property_id][$this->legacySlug((string) $requirement->resource_type)] = true;
        }

        foreach (DB::table('properties')->select('id', 'tenant_id')->cursor() as $property) {
            $slugs = array_keys($slugsByProperty[$property->id] ?? []);
            foreach ($slugs as $sortOrder => $slug) {
                $definition = $this->legacyDefinition($slug);
                $id = (string) str()->uuid();
                DB::table('resource_categories')->insert([
                    'id' => $id,
                    'tenant_id' => $property->tenant_id,
                    'property_id' => $property->id,
                    'kind' => $definition['kind']->value,
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'counts_as_stay' => $definition['counts_as_stay'],
                    'default_capacity' => $definition['default_capacity'],
                    'sort_order' => ($sortOrder + 1) * 10,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $categoryIds[$property->id][$definition['slug']] = $id;
            }
        }

        foreach (DB::table('resources')->select('id', 'property_id', 'type')->cursor() as $resource) {
            $slug = $this->legacySlug((string) $resource->type);
            $categoryId = $categoryIds[$resource->property_id][$slug] ?? null;
            if ($categoryId === null) {
                continue;
            }
            DB::table('resources')->where('id', $resource->id)->update(['category_id' => $categoryId]);
        }

        foreach (DB::table('program_resource_requirements as requirements')
            ->join('programs', 'programs.id', '=', 'requirements.program_id')
            ->select('requirements.id', 'programs.property_id', 'requirements.resource_type')
            ->cursor() as $requirement) {
            $slug = $this->legacySlug((string) $requirement->resource_type);
            $categoryId = $categoryIds[$requirement->property_id][$slug] ?? null;
            if ($categoryId === null) {
                continue;
            }
            DB::table('program_resource_requirements')->where('id', $requirement->id)->update([
                'resource_category_id' => $categoryId,
            ]);
        }
    }

    private function legacySlug(string $type): string
    {
        $slug = str($type)->slug()->toString();

        return $slug !== '' ? $slug : 'resource';
    }

    /** @return array{kind: ResourceKind, slug: string, name: string, counts_as_stay: bool, default_capacity: int} */
    private function legacyDefinition(string $slug): array
    {
        $kind = match ($slug) {
            'room', 'venue', ResourceKind::Place->value => ResourceKind::Place,
            'guide', 'staff', ResourceKind::Crew->value => ResourceKind::Crew,
            default => ResourceKind::Asset,
        };

        return [
            'kind' => $kind,
            'slug' => $slug,
            'name' => str($slug)->headline()->toString(),
            'counts_as_stay' => in_array($slug, ['room', ResourceKind::Place->value], true),
            'default_capacity' => match ($slug) {
                'room' => 2,
                'boat' => 3,
                'vehicle' => 4,
                default => 1,
            },
        ];
    }
};
