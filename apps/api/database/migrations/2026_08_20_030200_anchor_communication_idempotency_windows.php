<?php

use App\Enums\CommunicationPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->timestampTz('delivery_idempotency_started_at')->nullable()->after('failed_at');
            $table->timestampTz('delivery_idempotency_expires_at')->nullable()->after('delivery_idempotency_started_at');
            $table->index(
                ['tenant_id', 'delivery_idempotency_expires_at'],
                'communication_idempotency_expiry_idx',
            );
        });

        DB::table('communications')->orderBy('id')->each(function (object $communication): void {
            $firstRequestedAt = DB::table('delivery_attempts')
                ->where('communication_id', $communication->id)
                ->orderBy('attempted_at')->value('attempted_at');
            if ($firstRequestedAt === null) {
                return;
            }

            $startedAt = CarbonImmutable::parse((string) $firstRequestedAt)->utc();
            DB::table('communications')->where('id', $communication->id)->update([
                'delivery_idempotency_started_at' => $startedAt,
                'delivery_idempotency_expires_at' => $startedAt->addHours(
                    (int) config('communications.provider.idempotency_window_hours', 24),
                ),
            ]);
        });

        DB::table('automation_rules')
            ->where('trigger', 'reservation.arrival_approaching')
            ->orderBy('id')
            ->each(function (object $rule): void {
                $actions = json_decode((string) $rule->actions, true);
                if (! is_array($actions)) {
                    return;
                }

                $changed = false;
                foreach ($actions as &$action) {
                    if (is_array($action) && in_array($action['type'] ?? null, ['communication', 'queue_communication'], true)) {
                        $action['purpose'] = CommunicationPurpose::PreArrival->value;
                        $changed = true;
                    }
                }
                unset($action);

                if ($changed) {
                    DB::table('automation_rules')->where('id', $rule->id)->update([
                        'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->dropIndex('communication_idempotency_expiry_idx');
            $table->dropColumn(['delivery_idempotency_started_at', 'delivery_idempotency_expires_at']);
        });
    }
};
