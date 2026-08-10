<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'reservation_id'])
                : $table->dropForeign('proposals_tenant_reservation_fk');
            $table->dropForeign(['reservation_id']);
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->foreignUuid('reservation_id')->nullable()->change();
            $table->string('reference', 80)->nullable()->after('reservation_id');
            $table->foreignUuid('property_id')->nullable()->after('reference');
            $table->foreignUuid('primary_guest_id')->nullable()->after('property_id');
            $table->timestampTz('starts_at')->nullable()->after('primary_guest_id');
            $table->timestampTz('ends_at')->nullable()->after('starts_at');
            $table->unsignedSmallInteger('adults')->default(1)->after('ends_at');
            $table->unsignedSmallInteger('children')->default(0)->after('adults');
            $table->bigInteger('tax_minor')->default(0)->after('total_minor');
            $table->timestampTz('sent_at')->nullable()->after('expires_at');
            $table->timestampTz('converted_at')->nullable()->after('accepted_at');
            $table->foreignId('created_by')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
        });

        DB::table('proposals')->orderBy('id')->each(function (object $proposal): void {
            $reservation = DB::table('reservations')->where('id', $proposal->reservation_id)->first();
            if ($reservation === null) {
                return;
            }

            DB::table('proposals')->where('id', $proposal->id)->update([
                'reference' => 'Q-'.strtoupper(substr(str_replace('-', '', $proposal->id), 0, 12)),
                'property_id' => $reservation->property_id,
                'primary_guest_id' => $reservation->primary_guest_id,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'tax_minor' => $reservation->tax_minor,
            ]);
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'reference', 'version'], 'proposals_reference_version_unique');
            $table->index(['tenant_id', 'status', 'expires_at'], 'proposals_pipeline_idx');
            $table->foreign('reservation_id')->references('id')->on('reservations')->nullOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('primary_guest_id')->references('id')->on('guests')->nullOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'proposals_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'property_id'], 'proposals_tenant_property_fk')
                ->references(['tenant_id', 'id'])->on('properties');
            $table->foreign(['tenant_id', 'primary_guest_id'], 'proposals_tenant_guest_fk')
                ->references(['tenant_id', 'id'])->on('guests');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->text('evidence_url')->nullable()->after('provider_reference');
            $table->text('evidence_note')->nullable()->after('evidence_url');
            $table->foreignId('recorded_by')->nullable()->after('evidence_note')->constrained('users')->nullOnDelete();
            $table->timestampTz('reconciled_at')->nullable()->after('processed_at');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('reversed_at')->nullable()->after('reconciled_by');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->timestampTz('waived_at')->nullable()->after('paid_at');
            $table->foreignId('waived_by')->nullable()->after('waived_at')->constrained('users')->nullOnDelete();
            $table->text('waiver_reason')->nullable()->after('waived_by');
        });

        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->foreignUuid('reverses_folio_line_id')->nullable()->after('payment_id');
            $table->foreignId('created_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'reverses_folio_line_id'], 'folio_single_reversal_unique');
            $table->foreign('reverses_folio_line_id')->references('id')->on('folio_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reverses_folio_line_id'], 'folio_reversal_tenant_fk')
                ->references(['tenant_id', 'id'])->on('folio_lines');
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table): void {
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['tenant_id', 'reverses_folio_line_id'])
                : $table->dropForeign('folio_reversal_tenant_fk');
            $table->dropForeign(['reverses_folio_line_id']);
            $table->dropForeign(['created_by']);
            $table->dropUnique('folio_single_reversal_unique');
            $table->dropColumn(['reverses_folio_line_id', 'created_by']);
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropForeign(['waived_by']);
            $table->dropColumn(['waived_at', 'waived_by', 'waiver_reason']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['recorded_by']);
            $table->dropForeign(['reconciled_by']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'evidence_url',
                'evidence_note',
                'recorded_by',
                'reconciled_at',
                'reconciled_by',
                'reversed_at',
                'reversed_by',
                'reversal_reason',
            ]);
        });

        Schema::table('proposals', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['tenant_id', 'property_id']);
                $table->dropForeign(['tenant_id', 'primary_guest_id']);
                $table->dropForeign(['tenant_id', 'reservation_id']);
            } else {
                $table->dropForeign('proposals_tenant_property_fk');
                $table->dropForeign('proposals_tenant_guest_fk');
                $table->dropForeign('proposals_tenant_reservation_fk');
            }
            $table->dropForeign(['reservation_id']);
            $table->dropForeign(['property_id']);
            $table->dropForeign(['primary_guest_id']);
            $table->dropForeign(['created_by']);
            $table->dropUnique('proposals_reference_version_unique');
            $table->dropIndex('proposals_pipeline_idx');
            $table->dropColumn([
                'reference',
                'property_id',
                'primary_guest_id',
                'starts_at',
                'ends_at',
                'adults',
                'children',
                'tax_minor',
                'sent_at',
                'converted_at',
                'created_by',
            ]);
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->foreignUuid('reservation_id')->nullable(false)->change();
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'proposals_tenant_reservation_fk')
                ->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });
    }
};
