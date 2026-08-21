<?php

namespace App\Console\Commands;

use App\Events\IntegrationSchedulerHeartbeat;
use App\Jobs\ExecuteIntegrationRunJob;
use App\Jobs\ProcessIntegrationEventJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecordIntegrationHeartbeat extends Command
{
    protected $signature = 'integrations:heartbeat';

    protected $description = 'Emit the provider-neutral integration scheduler heartbeat and safe backlog gauges.';

    public function handle(): int
    {
        $staleBefore = now()->subMinutes(2);
        DB::table('integration_sync_run_items')
            ->where('status', 'processing')->where('started_at', '<=', $staleBefore)
            ->update([
                'status' => 'retryable',
                'available_at' => now(),
                'last_error' => 'Recovered after an expired integration worker lease.',
                'updated_at' => now(),
            ]);
        $staleEvents = DB::table('integration_events')
            ->where('disposition', 'processing')->where('updated_at', '<=', $staleBefore)
            ->orderBy('updated_at')->limit(100)->get(['tenant_id', 'id']);
        foreach ($staleEvents as $event) {
            $recovered = DB::table('integration_events')->where('id', $event->id)
                ->where('disposition', 'processing')->where('updated_at', '<=', $staleBefore)
                ->update([
                    'disposition' => 'retryable',
                    'last_error' => 'Recovered after an expired integration worker lease.',
                    'updated_at' => now(),
                ]);
            if ($recovered === 1) {
                ProcessIntegrationEventJob::dispatch($event->tenant_id, $event->id)->onQueue('integrations');
            }
        }

        $scopes = DB::table('integration_connections')->select(['tenant_id', 'property_id'])->distinct()->get();
        foreach ($scopes as $scope) {
            $scopeRuns = DB::table('integration_sync_runs')->where('tenant_id', $scope->tenant_id)
                ->where('property_id', $scope->property_id);
            $snapshot = [
                'occurred_at' => now()->toIso8601String(),
                'queued_runs' => (clone $scopeRuns)->whereIn('status', ['queued', 'running', 'blocked'])->count(),
                'backlog_items' => DB::table('integration_sync_run_items')->where('tenant_id', $scope->tenant_id)
                    ->where('property_id', $scope->property_id)->whereIn('status', ['pending', 'retryable', 'processing'])->count(),
                'open_dead_letters' => DB::table('integration_dead_letters')->where('tenant_id', $scope->tenant_id)
                    ->where('property_id', $scope->property_id)->where('status', 'open')->count(),
            ];
            $scopeKey = $scope->property_id ?? 'global';
            Cache::put("integration:scheduler-heartbeat:{$scope->tenant_id}:{$scopeKey}", $snapshot, now()->addMinutes(5));
            event(new IntegrationSchedulerHeartbeat(
                $scope->tenant_id,
                $scope->property_id,
                $snapshot['occurred_at'],
                $snapshot['queued_runs'],
                $snapshot['backlog_items'],
                $snapshot['open_dead_letters'],
            ));
        }

        DB::table('integration_sync_runs')
            ->where(function ($query): void {
                $query->where('status', 'queued')
                    ->orWhere(function ($running): void {
                        $running->where('status', 'running')->where('page_in_progress', true)
                            ->where(fn ($lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()));
                    });
            })
            ->orderBy('created_at')->limit(100)->get(['tenant_id', 'id'])
            ->each(fn ($run) => ExecuteIntegrationRunJob::dispatch($run->tenant_id, $run->id)->onQueue('integrations'));

        $this->components->info('Integration heartbeat emitted.');

        return self::SUCCESS;
    }
}
