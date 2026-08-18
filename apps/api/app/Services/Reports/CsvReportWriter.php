<?php

namespace App\Services\Reports;

use App\Contracts\Reports\ReportWriter;
use RuntimeException;

final class CsvReportWriter implements ReportWriter
{
    public function write(array $columns, iterable $rows): array
    {
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to open CSV stream.');
        }
        fputcsv($stream, array_values($columns), ',', '"', '');
        $count = 0;
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($key) => SpreadsheetSafety::value($row[$key] ?? null), array_keys($columns)), ',', '"', '');
            $count++;
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        if (! is_string($bytes)) {
            throw new RuntimeException('Unable to read CSV stream.');
        }

        return ['bytes' => $bytes, 'row_count' => $count];
    }
}
