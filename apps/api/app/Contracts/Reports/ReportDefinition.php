<?php

namespace App\Contracts\Reports;

use App\Enums\ReportExportKind;

interface ReportDefinition
{
    public function kind(): ReportExportKind;

    public function capability(): string;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function normalizeFilters(array $filters, string $timezone): array;

    /** @return array<string, string> */
    public function columns(string $locale): array;

    /** @param array<string, mixed> $filters @return iterable<array<string, bool|float|int|string|null>> */
    public function rows(string $propertyId, array $filters, string $timezone): iterable;
}
