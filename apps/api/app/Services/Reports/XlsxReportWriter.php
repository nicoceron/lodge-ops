<?php

namespace App\Services\Reports;

use App\Contracts\Reports\ReportWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

final class XlsxReportWriter implements ReportWriter
{
    public function write(array $columns, iterable $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lodge-report-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate XLSX temporary file.');
        }
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(array_values($columns)));
            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($key) => SpreadsheetSafety::value($row[$key] ?? null), array_keys($columns))));
                $count++;
            }
            $writer->close();
            $bytes = file_get_contents($path);
            if (! is_string($bytes) || ! str_starts_with($bytes, "PK\x03\x04")) {
                throw new RuntimeException('XLSX writer produced invalid bytes.');
            }

            return ['bytes' => $bytes, 'row_count' => $count];
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
