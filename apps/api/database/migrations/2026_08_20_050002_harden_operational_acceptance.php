<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillCompanionOrder();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operational_task_events DROP CONSTRAINT IF EXISTS operational_task_events_operational_task_id_foreign');
            DB::statement('ALTER TABLE operational_task_events DROP CONSTRAINT IF EXISTS task_events_tenant_task_fk');
            DB::statement('ALTER TABLE operational_task_events ADD CONSTRAINT operational_task_events_operational_task_id_foreign FOREIGN KEY (operational_task_id) REFERENCES operational_tasks(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE operational_task_events ADD CONSTRAINT task_events_tenant_task_fk FOREIGN KEY (tenant_id, operational_task_id) REFERENCES operational_tasks(tenant_id, id) ON DELETE RESTRICT');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operational_task_events DROP CONSTRAINT IF EXISTS operational_task_events_operational_task_id_foreign');
            DB::statement('ALTER TABLE operational_task_events DROP CONSTRAINT IF EXISTS task_events_tenant_task_fk');
            DB::statement('ALTER TABLE operational_task_events ADD CONSTRAINT operational_task_events_operational_task_id_foreign FOREIGN KEY (operational_task_id) REFERENCES operational_tasks(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE operational_task_events ADD CONSTRAINT task_events_tenant_task_fk FOREIGN KEY (tenant_id, operational_task_id) REFERENCES operational_tasks(tenant_id, id) ON DELETE CASCADE');
        }
    }

    private function backfillCompanionOrder(): void
    {
        $currentReservation = null;
        $sortOrder = 0;
        DB::table('reservation_guests')
            ->orderBy('tenant_id')->orderBy('reservation_id')
            ->orderByRaw("case when role = 'primary' then 0 else 1 end")
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'tenant_id', 'reservation_id'])
            ->each(function (object $pivot) use (&$currentReservation, &$sortOrder): void {
                $key = $pivot->tenant_id.'|'.$pivot->reservation_id;
                if ($key !== $currentReservation) {
                    $currentReservation = $key;
                    $sortOrder = 0;
                }
                DB::table('reservation_guests')->where('id', $pivot->id)->update(['sort_order' => $sortOrder++]);
            });
    }
};
