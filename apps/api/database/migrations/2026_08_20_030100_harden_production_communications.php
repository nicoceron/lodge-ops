<?php

use App\Enums\CommunicationPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->timestampTz('status_occurred_at')->nullable()->after('status');
            $table->unsignedSmallInteger('status_precedence')->default(0)->after('status_occurred_at');
        });
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->timestampTz('status_occurred_at')->nullable()->after('status');
            $table->unsignedSmallInteger('status_precedence')->default(0)->after('status_occurred_at');
        });
        Schema::table('communication_delivery_events', function (Blueprint $table): void {
            $table->foreignUuid('property_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'property_id', 'received_at'], 'communication_delivery_event_property_idx');
        });
        DB::table('communication_delivery_events')->orderBy('id')->each(function (object $event): void {
            $propertyId = DB::table('communication_provider_connections')
                ->where('id', $event->communication_provider_connection_id)->value('property_id');
            DB::table('communication_delivery_events')->where('id', $event->id)->update(['property_id' => $propertyId]);
        });

        Schema::table('communication_suppressions', function (Blueprint $table): void {
            $table->string('scope_key', 80)->default('*')->after('property_id');
        });
        DB::table('communication_suppressions')->orderBy('id')->each(function (object $suppression): void {
            DB::table('communication_suppressions')->where('id', $suppression->id)
                ->update(['scope_key' => $suppression->property_id ?: '*']);
        });
        Schema::table('communication_suppressions', function (Blueprint $table): void {
            $table->dropUnique('communication_suppressions_tenant_id_channel_recipient_hash_unique');
            $table->unique(
                ['tenant_id', 'scope_key', 'channel', 'recipient_hash'],
                'communication_suppression_scope_unique',
            );
        });

        Schema::table('communication_provider_connections', function (Blueprint $table): void {
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('verification_checked_at')->nullable()->after('verified_by');
            $table->string('verification_reference', 190)->nullable()->after('verification_checked_at');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_system_admin')->default(false)->after('remember_token');
        });

        Schema::create('communication_purpose_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('purpose', 32);
            $table->string('version', 80);
            $table->string('classification', 40);
            $table->boolean('requires_consent');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('approved_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_note');
            $table->timestamps();
            $table->unique(['purpose', 'version'], 'communication_purpose_policy_version_unique');
            $table->index(['purpose', 'is_active'], 'communication_purpose_policy_active_idx');
        });

        $consentPurposes = [CommunicationPurpose::Survey->value, CommunicationPurpose::Marketing->value];
        foreach (CommunicationPurpose::cases() as $purpose) {
            DB::table('communication_purpose_policies')->insert([
                'id' => (string) Str::uuid(),
                'purpose' => $purpose->value,
                'version' => '2026-08-20-v1',
                'classification' => in_array($purpose->value, $consentPurposes, true)
                    ? 'optional_guest_message' : (str_starts_with($purpose->value, 'internal_') ? 'internal_operational' : 'service_message'),
                'requires_consent' => in_array($purpose->value, $consentPurposes, true),
                'is_active' => true,
                'approved_at' => now(),
                'approval_note' => 'Approved fixed-purpose production communication policy baseline.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_purpose_policies');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_system_admin'));
        Schema::table('communication_provider_connections', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_by', 'verification_checked_at', 'verification_reference']);
        });
        DB::table('communication_suppressions')->select('tenant_id', 'channel', 'recipient_hash')
            ->groupBy('tenant_id', 'channel', 'recipient_hash')->havingRaw('count(*) > 1')
            ->get()->each(function (object $duplicate): void {
                $ids = DB::table('communication_suppressions')
                    ->where('tenant_id', $duplicate->tenant_id)->where('channel', $duplicate->channel)
                    ->where('recipient_hash', $duplicate->recipient_hash)
                    ->orderByRaw("case when scope_key = '*' then 0 else 1 end")->orderBy('id')->pluck('id');
                DB::table('communication_suppressions')->whereIn('id', $ids->slice(1))->delete();
            });
        Schema::table('communication_suppressions', function (Blueprint $table): void {
            $table->dropUnique('communication_suppression_scope_unique');
            $table->unique(['tenant_id', 'channel', 'recipient_hash']);
            $table->dropColumn('scope_key');
        });
        Schema::table('communication_delivery_events', function (Blueprint $table): void {
            $table->dropIndex('communication_delivery_event_property_idx');
            $table->dropForeign(['property_id']);
            $table->dropColumn('property_id');
        });
        Schema::table('delivery_attempts', fn (Blueprint $table) => $table->dropColumn(['status_occurred_at', 'status_precedence']));
        Schema::table('communications', fn (Blueprint $table) => $table->dropColumn(['status_occurred_at', 'status_precedence']));
    }
};
