<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\AccountingJournalExportPort;
use App\Contracts\Integrations\InboundWebhookPort;
use App\Contracts\Integrations\OutboundWebhookPort;
use App\Contracts\Integrations\ReservationsImportPort;
use App\Data\Integrations\IntegrationHealthResult;
use App\Models\IntegrationConnection;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationSyncRunItem;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class IntegrationHealthService
{
    public function __construct(private readonly CapabilityPortRegistry $ports) {}

    public function test(IntegrationConnection $connection, string $capability): IntegrationHealthResult
    {
        if (! in_array($capability, $connection->capabilities ?? [], true)) {
            throw new DomainException('The connection does not grant this capability.');
        }
        $started = hrtime(true);
        try {
            $port = $this->ports->for($connection, $capability);
            $result = match (true) {
                $port instanceof ReservationsImportPort => $port->test($connection),
                $port instanceof AccountingJournalExportPort => $port->test($connection),
                $port instanceof OutboundWebhookPort => $port->test($connection),
                $port instanceof InboundWebhookPort => $port->test($connection),
                default => throw new DomainException('This capability has no connection-test contract.'),
            };
            $connection->update([
                'health_status' => $result->healthy ? 'healthy' : 'degraded',
                'lag_seconds' => $result->lagSeconds,
                'last_success_at' => $result->healthy ? now() : $connection->last_success_at,
                'last_error_at' => $result->healthy ? $connection->last_error_at : now(),
                'last_error' => $result->healthy ? null : SafeIntegrationError::from($result->safeMessage ?? 'Connection test failed.'),
            ]);

            return $result;
        } catch (Throwable $exception) {
            $connection->update(['health_status' => 'degraded', 'last_error_at' => now(), 'last_error' => SafeIntegrationError::from($exception)]);
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function snapshot(IntegrationConnection $connection): array
    {
        $items = IntegrationSyncRunItem::query()->whereHas('run', fn ($query) => $query->where('integration_connection_id', $connection->id));

        return [
            'connection_id' => $connection->id,
            'health_status' => $connection->health_status,
            'enabled' => $connection->is_enabled,
            'lag_seconds' => $connection->lag_seconds,
            'last_success_at' => $connection->last_success_at,
            'last_error_at' => $connection->last_error_at,
            'last_event_at' => $connection->last_event_at,
            'backlog' => (clone $items)->whereIn('status', ['pending', 'retryable', 'processing'])->count(),
            'success_count' => (clone $items)->where('status', 'succeeded')->count(),
            'error_count' => (clone $items)->where('status', 'dead_letter')->count(),
            'average_latency_ms' => (int) round((float) ((clone $items)->whereNotNull('latency_ms')->avg('latency_ms') ?? 0)),
            'dead_letters' => IntegrationDeadLetter::query()->where('integration_connection_id', $connection->id)->where('status', 'open')->count(),
            'circuit_open' => $connection->circuit_opened_at?->isFuture() ?? false,
            'throttled_until' => $connection->throttled_until,
            'scheduler_heartbeat' => Cache::get('integration:scheduler-heartbeat:'.$connection->tenant_id.':'.($connection->property_id ?? 'global')),
        ];
    }
}
