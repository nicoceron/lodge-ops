<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plan_services', function (Blueprint $table): void {
            $table->char('direct_booking_public_key', 26)->nullable()->after('catalog_item_id');
            $table->unique(['tenant_id', 'direct_booking_public_key'], 'rate_plan_services_direct_public_unique');
        });

        DB::table('rate_plan_services')->whereNull('direct_booking_public_key')->orderBy('id')->eachById(
            fn (object $service) => DB::table('rate_plan_services')->where('id', $service->id)->update([
                'direct_booking_public_key' => (string) Str::ulid(),
            ]),
            100,
            'id',
        );
    }

    public function down(): void
    {
        Schema::table('rate_plan_services', function (Blueprint $table): void {
            $table->dropUnique('rate_plan_services_direct_public_unique');
            $table->dropColumn('direct_booking_public_key');
        });
    }
};
