<?php

namespace App\Contracts\Reports;

interface ReportWriter
{
    /** @param array<string, string> $columns @param iterable<array<string, mixed>> $rows @return array{bytes:string,row_count:int} */
    public function write(array $columns, iterable $rows): array;
}
