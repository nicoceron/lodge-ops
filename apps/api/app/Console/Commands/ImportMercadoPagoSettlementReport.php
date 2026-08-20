<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Payments\ImportMercadoPagoSettlementReport as Importer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ImportMercadoPagoSettlementReport extends Command
{
    protected $signature = 'payments:import-settlement-report {connection : Payment integration UUID} {path : Account Money or Released Money CSV path} {--report= : account_money or released_money} {--provider-report-id= : Provider report/list identity} {--fixture : Mark a deterministic non-provider fixture import}';

    protected $description = 'Import an account-scoped Mercado Pago settlement report as immutable settlement revisions.';

    public function handle(Importer $importer): int
    {
        $connection = IntegrationConnection::withoutGlobalScopes()->findOrFail($this->argument('connection'));
        app(TenantContext::class)->set(Tenant::query()->findOrFail($connection->tenant_id));
        $count = $importer->handle(
            $connection,
            (string) $this->argument('path'),
            (string) $this->option('report'),
            (string) $this->option('provider-report-id'),
            (bool) $this->option('fixture'),
        );
        $this->info("Imported {$count} settlement report row(s).");

        return self::SUCCESS;
    }
}
