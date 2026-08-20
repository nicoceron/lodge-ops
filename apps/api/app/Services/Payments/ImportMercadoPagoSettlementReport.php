<?php

namespace App\Services\Payments;

use App\Data\Payments\ProviderPayment;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\IntegrationConnection;
use App\Models\PaymentAttempt;
use App\Models\SettlementReportImport;
use App\Models\SettlementReportRow;
use App\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use SplFileObject;

final class ImportMercadoPagoSettlementReport
{
    private const REPORT_TYPES = ['account_money', 'released_money'];

    public function __construct(private readonly RecordSettlementRevision $settlements) {}

    /** @param array<string, bool|int|string|null> $metadata */
    public function handle(IntegrationConnection $connection, string $path, string $reportType, string $providerReportId, bool $isFixture = false, array $metadata = []): int
    {
        if (! in_array($reportType, self::REPORT_TYPES, true)) {
            throw new DomainException('The settlement report type must be account_money or released_money.');
        }
        if ($providerReportId === '' || ! is_file($path) || ! is_readable($path)) {
            throw new DomainException('The settlement report identity or file is invalid.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new DomainException('The settlement report could not be read.');
        }
        $fileChecksum = hash('sha256', $raw);
        $rows = $this->read($path, $reportType);

        return DB::transaction(function () use ($connection, $path, $reportType, $providerReportId, $isFixture, $metadata, $fileChecksum, $rows): int {
            Tenant::query()->whereKey($connection->tenant_id)->lockForUpdate()->firstOrFail();
            $identity = ['provider' => 'mercado_pago', 'environment' => (string) data_get($connection->configuration, 'environment'), 'provider_account' => (string) data_get($connection->configuration, 'provider_account'), 'report_type' => $reportType, 'provider_report_id' => $providerReportId];
            $previous = SettlementReportImport::query()->where($identity)->orderByDesc('revision')->first();
            $existing = SettlementReportImport::query()->where($identity)->where('file_checksum', $fileChecksum)->first();
            if ($existing !== null) {
                return $existing->row_count;
            }
            $nextRevision = $previous === null ? 1 : $previous->revision + 1;
            $import = SettlementReportImport::query()->create([
                'integration_connection_id' => $connection->id,
                ...$identity,
                'revision' => $nextRevision,
                'file_name' => basename($path),
                'file_checksum' => $fileChecksum,
                'report_metadata' => $metadata,
                'is_fixture' => $isFixture,
                'row_count' => count($rows),
                'imported_at' => now(),
            ]);

            /** @var list<array{attempt: PaymentAttempt|null, state: string, kind: string, row: array<string, string>, attributes: array<string, mixed>}> $prepared */
            $prepared = [];
            $occurrences = [];
            foreach ($rows as $row) {
                $canonical = array_intersect_key($row, array_flip($this->allowedColumns($reportType)));
                ksort($canonical);
                $checksum = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
                $occurrence = ($occurrences[$checksum] ?? 0) + 1;
                $occurrences[$checksum] = $occurrence;
                $kind = $this->kind($reportType, $canonical);
                $sourceId = $this->value($canonical, 'SOURCE_ID');
                $externalReference = $this->value($canonical, 'EXTERNAL_REFERENCE');
                $currency = strtoupper((string) ($this->value($canonical, 'CURRENCY') ?? $this->value($canonical, 'TRANSACTION_CURRENCY') ?? $this->value($canonical, 'SETTLEMENT_CURRENCY')));
                [$attempt, $state] = $this->match($connection, $reportType, $canonical, $kind, $sourceId, $externalReference, $currency);
                $prepared[] = [
                    'attempt' => $attempt,
                    'state' => $state,
                    'kind' => $kind,
                    'row' => $canonical,
                    'attributes' => [
                        'settlement_report_import_id' => $import->id,
                        'payment_attempt_id' => $attempt?->id,
                        'property_id' => $attempt?->property_id,
                        'row_identity' => hash('sha256', $checksum.'|'.$occurrence),
                        'occurrence' => $occurrence,
                        'source_id' => $sourceId,
                        'external_reference' => $externalReference,
                        'currency' => $currency === '' ? null : $currency,
                        'row_kind' => $kind,
                        'canonical_checksum' => $checksum,
                        'canonical_row' => $canonical,
                        'recorded_at' => now(),
                    ],
                ];
            }

            /** @var array<string, list<int>> $attemptRows */
            $attemptRows = [];
            foreach ($prepared as $index => $item) {
                if ($item['attempt'] !== null) {
                    $attemptRows[$item['attempt']->id][] = $index;
                }
            }

            /** @var array<string, list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}>> $applied */
            $applied = [];
            foreach ($attemptRows as $attemptId => $indexes) {
                $attempt = $prepared[$indexes[0]]['attempt'];
                if ($attempt === null) {
                    continue;
                }
                $group = array_map(
                    fn (int $index): array => [
                        'attempt' => $attempt,
                        'kind' => $prepared[$index]['kind'],
                        'row' => $prepared[$index]['row'],
                    ],
                    $indexes,
                );
                $hasMismatch = collect($indexes)->contains(fn (int $index): bool => $prepared[$index]['state'] !== 'applied');
                if ($hasMismatch || ! $this->validFinancialGroup($reportType, $group, $attempt)) {
                    foreach ($indexes as $index) {
                        $prepared[$index]['state'] = 'mismatched';
                    }

                    continue;
                }
                $applied[$attemptId] = $group;
            }

            foreach ($prepared as $item) {
                $ledger = new SettlementReportRow;
                $ledger->fill([
                    ...$item['attributes'],
                    'application_state' => $item['state'],
                ]);
                $ledger->save();
            }

            foreach ($applied as $group) {
                $attempt = $group[0]['attempt'];
                $settlement = $reportType === 'account_money' ? $this->accountMoneyFacts($group, $import) : $this->releasedMoneyFacts($group, $import);
                $entry = $this->settlements->handle($attempt, new ProviderPayment(
                    (string) $attempt->provider_payment_id,
                    $attempt->external_reference,
                    'approved',
                    null,
                    (int) $settlement['gross_minor'],
                    $attempt->charge_currency,
                    $attempt->provider_account,
                    $settlement,
                ));
                if ($previous !== null) {
                    $entry->update(['reconciliation_state' => 'variance', 'resolution_reason' => 'Provider report identity was reissued with different file bytes.']);
                }
            }

            return count($rows);
        }, 3);
    }

    /** @return list<array<string, string>> */
    private function read(string $path, string $reportType): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $first = is_array($lines) ? ($lines[0] ?? '') : '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl($delimiter);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $header = array_map(fn (mixed $value): string => trim((string) $value), $file->fgetcsv());
        if (isset($header[0])) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }
        $required = $reportType === 'account_money'
            ? ['USER_ID', 'SITE', 'TRANSACTION_TYPE', 'SOURCE_ID', 'EXTERNAL_REFERENCE', 'TRANSACTION_AMOUNT', 'TRANSACTION_CURRENCY', 'FEE_AMOUNT', 'SETTLEMENT_NET_AMOUNT', 'SETTLEMENT_CURRENCY', 'SETTLEMENT_DATE', 'MONEY_RELEASE_DATE', 'IS_RELEASED']
            : ['RECORD_TYPE', 'DESCRIPTION', 'SOURCE_ID', 'EXTERNAL_REFERENCE', 'CURRENCY', 'GROSS_AMOUNT', 'MP_FEE_AMOUNT', 'NET_CREDIT_AMOUNT', 'NET_DEBIT_AMOUNT', 'DATE'];
        if (array_diff($required, $header) !== []) {
            throw new DomainException('The settlement report is missing mandatory official columns.');
        }

        $rows = [];
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            if (! is_array($values) || array_filter($values, fn (mixed $value): bool => $value !== null && trim((string) $value) !== '') === []) {
                continue;
            }
            if (count($values) !== count($header)) {
                throw new DomainException('A settlement report row has a different column count than the header.');
            }
            $combined = array_combine($header, array_map(fn (mixed $value): string => trim((string) $value), $values));
            $rows[] = $combined;
        }

        return $rows;
    }

    /** @param array<string, string> $row @return array{PaymentAttempt|null, string} */
    private function match(IntegrationConnection $connection, string $reportType, array $row, string $kind, ?string $sourceId, ?string $externalReference, string $currency): array
    {
        if (in_array($kind, ['PAYOUT', 'WITHDRAWAL', 'WITHDRAWAL_CANCEL'], true) || str_starts_with($kind, 'ACCOUNT_')) {
            return [null, 'account_level'];
        }
        if ($sourceId === null) {
            return [null, 'unmatched'];
        }
        $attempt = PaymentAttempt::query()->where('integration_connection_id', $connection->id)
            ->where('provider', 'mercado_pago')->where('environment', data_get($connection->configuration, 'environment'))
            ->where('provider_account', data_get($connection->configuration, 'provider_account'))->where('provider_payment_id', $sourceId)->first();
        if ($attempt === null) {
            return [null, 'unmatched'];
        }
        $supportedKinds = $reportType === 'account_money'
            ? ['SETTLEMENT', 'REFUND', 'CHARGEBACK', 'DISPUTE']
            : ['PAYMENT', 'REFUND', 'CHARGEBACK', 'DISPUTE', 'TAX', 'TAX_CANCEL', 'WITHHOLDING', 'WITHHOLDING_CANCEL'];
        if (! in_array($kind, $supportedKinds, true)) {
            return [$attempt, 'mismatched'];
        }
        $reportedAccount = $this->value($row, 'USER_ID');
        if (($reportedAccount !== null && $reportedAccount !== $attempt->provider_account)
            || ($externalReference !== null && $externalReference !== $attempt->external_reference)
            || $currency !== $attempt->charge_currency) {
            return [$attempt, 'mismatched'];
        }
        if ($reportType === 'account_money' && $kind === 'SETTLEMENT'
            && $this->minor($row['TRANSACTION_AMOUNT'] ?? '', false) !== $attempt->charge_amount_minor) {
            return [$attempt, 'mismatched'];
        }
        if ($reportType === 'released_money' && $kind === 'PAYMENT') {
            $gross = abs((int) $this->minor($row['GROSS_AMOUNT'] ?? '', false));
            if ($gross === 0 || $gross > $attempt->charge_amount_minor) {
                return [$attempt, 'mismatched'];
            }
        }

        return [$attempt, 'applied'];
    }

    /** @param list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}> $group */
    private function validFinancialGroup(string $reportType, array $group, PaymentAttempt $attempt): bool
    {
        $primaryKind = $reportType === 'account_money' ? 'SETTLEMENT' : 'PAYMENT';
        $primaryCount = collect($group)->filter(fn (array $item): bool => $item['kind'] === $primaryKind)->count();
        if ($primaryCount === 0 || ($reportType === 'account_money' && $primaryCount !== 1)) {
            return false;
        }
        if ($reportType === 'released_money') {
            $releasedGross = $this->sumKinds($group, ['PAYMENT'], 'GROSS_AMOUNT');
            if ($releasedGross === 0 || $releasedGross > $attempt->charge_amount_minor) {
                return false;
            }
        }
        $refundAndChargeback = $reportType === 'account_money'
            ? $this->sumKinds($group, ['REFUND', 'CHARGEBACK', 'DISPUTE'], 'TRANSACTION_AMOUNT')
            : $this->sumKinds($group, ['REFUND', 'CHARGEBACK', 'DISPUTE'], 'GROSS_AMOUNT');
        $tax = $reportType === 'released_money'
            ? max(0, (int) ($this->releasedMoneyDeduction($group, ['TAX', 'TAX_CANCEL']) ?? 0))
            : 0;
        $withholding = $reportType === 'released_money'
            ? max(0, (int) ($this->releasedMoneyDeduction($group, ['WITHHOLDING', 'WITHHOLDING_CANCEL']) ?? 0))
            : 0;
        if ($reportType === 'account_money') {
            $primary = collect($group)->first(fn (array $item): bool => $item['kind'] === 'SETTLEMENT');
            if ($primary === null
                || abs((int) ($this->minor($primary['row']['FEE_AMOUNT'] ?? '') ?? 0)) > $attempt->charge_amount_minor
                || abs((int) ($this->minor($primary['row']['TAXES_AMOUNT'] ?? '') ?? 0)) > $attempt->charge_amount_minor
                || abs((int) ($this->minor($primary['row']['FINANCING_FEE_AMOUNT'] ?? '') ?? 0)) > $attempt->charge_amount_minor) {
                return false;
            }
        }

        return $refundAndChargeback + $tax + $withholding <= $attempt->charge_amount_minor;
    }

    /** @param array<string, string> $row */
    private function kind(string $reportType, array $row): string
    {
        if ($reportType === 'account_money') {
            return strtoupper((string) $this->value($row, 'TRANSACTION_TYPE'));
        }
        $recordType = strtolower((string) $this->value($row, 'RECORD_TYPE'));
        if ($recordType !== 'release') {
            return 'ACCOUNT_'.strtoupper($recordType === '' ? 'UNKNOWN' : $recordType);
        }
        $description = strtolower((string) $this->value($row, 'DESCRIPTION'));

        return match (true) {
            $description === 'payment' => 'PAYMENT',
            $description === 'refund' => 'REFUND',
            $description === 'chargeback' => 'CHARGEBACK',
            $description === 'dispute' => 'DISPUTE',
            $description === 'payout' => 'PAYOUT',
            $description === 'withdrawal_cancel' => 'WITHDRAWAL_CANCEL',
            $description === 'withdrawal' => 'WITHDRAWAL',
            (str_starts_with($description, 'tax_withdholding') || str_starts_with($description, 'tax_withholding')) && str_ends_with($description, '_cancel') => 'WITHHOLDING_CANCEL',
            str_starts_with($description, 'tax_withdholding') || str_starts_with($description, 'tax_withholding') => 'WITHHOLDING',
            str_starts_with($description, 'tax_') && str_ends_with($description, '_cancel') => 'TAX_CANCEL',
            str_starts_with($description, 'tax_') => 'TAX',
            default => 'RELEASE_OTHER',
        };
    }

    /** @param list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}> $group @return array<string, int|string|null|bool|array<mixed>> */
    private function accountMoneyFacts(array $group, SettlementReportImport $import): array
    {
        $settlement = collect($group)->first(fn (array $item): bool => $item['kind'] === 'SETTLEMENT') ?? $group[0];
        $refund = $this->sumKinds($group, ['REFUND'], 'TRANSACTION_AMOUNT');
        $chargeback = $this->sumKinds($group, ['CHARGEBACK', 'DISPUTE'], 'TRANSACTION_AMOUNT');

        return [
            'fact_source' => 'account_money_report', 'report_import_id' => $import->id, 'report_revision' => $import->revision,
            'file_checksum' => $import->file_checksum, 'net_is_authoritative' => true,
            'gross_minor' => $this->minor($settlement['row']['TRANSACTION_AMOUNT'], false),
            'fee_minor' => abs((int) $this->minor($settlement['row']['FEE_AMOUNT'], false)),
            'tax_minor' => null,
            'withholding_minor' => $this->minor($settlement['row']['TAXES_AMOUNT'] ?? ''),
            'financing_minor' => $this->minor($settlement['row']['FINANCING_FEE_AMOUNT'] ?? ''),
            'refunded_minor' => $refund === 0 ? null : $refund, 'chargeback_minor' => $chargeback === 0 ? null : $chargeback,
            'net_minor' => $this->sum($group, 'SETTLEMENT_NET_AMOUNT'), 'settlement_currency' => $settlement['row']['SETTLEMENT_CURRENCY'],
            'settlement_identity' => null, 'settlement_date' => $settlement['row']['SETTLEMENT_DATE'],
            'settlement_status' => in_array(strtolower($settlement['row']['IS_RELEASED']), ['true', 'yes', '1'], true) ? 'released' : 'pending_release',
            'expected_release_at' => $this->value($settlement['row'], 'MONEY_RELEASE_DATE'),
            'payout_identity' => null, 'payout_date' => null, 'payout_status' => null, 'rows' => array_column($group, 'row'),
        ];
    }

    /** @param list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}> $group @return array<string, int|string|null|bool|array<mixed>> */
    private function releasedMoneyFacts(array $group, SettlementReportImport $import): array
    {
        $payment = collect($group)->first(fn (array $item): bool => in_array($item['kind'], ['PAYMENT', 'SETTLEMENT'], true)) ?? $group[0];

        return [
            'fact_source' => 'released_money_report', 'report_import_id' => $import->id, 'report_revision' => $import->revision,
            'file_checksum' => $import->file_checksum, 'net_is_authoritative' => true,
            'gross_minor' => $this->sumKinds($group, ['PAYMENT'], 'GROSS_AMOUNT'),
            'fee_minor' => $this->sumKinds($group, ['PAYMENT'], 'MP_FEE_AMOUNT'),
            'tax_minor' => $this->releasedMoneyDeduction($group, ['TAX', 'TAX_CANCEL']),
            'withholding_minor' => $this->releasedMoneyDeduction($group, ['WITHHOLDING', 'WITHHOLDING_CANCEL']),
            'financing_minor' => $this->sumKinds($group, ['PAYMENT'], 'FINANCING_FEE_AMOUNT') ?: null,
            'refunded_minor' => $this->sumKinds($group, ['REFUND'], 'GROSS_AMOUNT') ?: null,
            'chargeback_minor' => $this->sumKinds($group, ['CHARGEBACK', 'DISPUTE'], 'GROSS_AMOUNT') ?: null,
            'net_minor' => $this->sum($group, 'NET_CREDIT_AMOUNT') - $this->sum($group, 'NET_DEBIT_AMOUNT'),
            'settlement_currency' => $payment['row']['CURRENCY'], 'settlement_identity' => null,
            'settlement_date' => $payment['row']['DATE'], 'settlement_status' => 'released',
            'payout_identity' => null, 'payout_date' => null, 'payout_status' => null, 'rows' => array_column($group, 'row'),
        ];
    }

    /** @param list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}> $group @param list<string> $kinds */
    private function sumKinds(array $group, array $kinds, string $field): int
    {
        return abs($this->sum(array_values(array_filter($group, fn (array $item): bool => in_array($item['kind'], $kinds, true))), $field));
    }

    /** @param list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}> $group @param list<string> $kinds */
    private function releasedMoneyDeduction(array $group, array $kinds): ?int
    {
        $movements = array_values(array_filter(
            $group,
            fn (array $item): bool => in_array($item['kind'], $kinds, true),
        ));
        if ($movements === []) {
            return null;
        }
        $amount = $this->sum($movements, 'NET_DEBIT_AMOUNT') - $this->sum($movements, 'NET_CREDIT_AMOUNT');

        return $amount === 0 ? null : $amount;
    }

    /** @param list<array{attempt: PaymentAttempt, kind: string, row: array<string, string>}> $group */
    private function sum(array $group, string $field): int
    {
        $total = 0;
        foreach ($group as $item) {
            $total += (int) ($this->minor($item['row'][$field] ?? '') ?? 0);
        }

        return $total;
    }

    private function minor(string $major, bool $nullable = true): ?int
    {
        if ($major === '') {
            if ($nullable) {
                return null;
            }
            throw new DomainException('A mandatory settlement money value is empty.');
        }
        try {
            return BigDecimal::of($major)->multipliedBy(100)->toScale(0, RoundingMode::Unnecessary)->toInt();
        } catch (\Throwable $exception) {
            throw new DomainException('A settlement money value is not an exact two-decimal amount.', previous: $exception);
        }
    }

    /** @param array<string, string> $row */
    private function value(array $row, string $key): ?string
    {
        return isset($row[$key]) && $row[$key] !== '' ? $row[$key] : null;
    }

    /** @return list<string> */
    private function allowedColumns(string $reportType): array
    {
        return $reportType === 'account_money'
            ? ['USER_ID', 'SITE', 'TRANSACTION_TYPE', 'SOURCE_ID', 'EXTERNAL_REFERENCE', 'TRANSACTION_AMOUNT', 'TRANSACTION_CURRENCY', 'FEE_AMOUNT', 'SETTLEMENT_NET_AMOUNT', 'SETTLEMENT_CURRENCY', 'SETTLEMENT_DATE', 'REAL_AMOUNT', 'MONEY_RELEASE_DATE', 'IS_RELEASED', 'FINANCING_FEE_AMOUNT', 'TAXES_AMOUNT', 'TAX_DETAIL', 'TAXES_DISAGGREGATED']
            : ['DATE', 'SOURCE_ID', 'EXTERNAL_REFERENCE', 'RECORD_TYPE', 'DESCRIPTION', 'NET_CREDIT_AMOUNT', 'NET_DEBIT_AMOUNT', 'GROSS_AMOUNT', 'MP_FEE_AMOUNT', 'FINANCING_FEE_AMOUNT', 'TAXES_AMOUNT', 'CURRENCY'];
    }
}
