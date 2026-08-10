<?php

namespace App\Services;

class SafeCsvExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public function export(array $headers, iterable $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, array_map($this->sanitize(...), $headers), escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (mixed $value): string => $this->sanitize((string) ($value ?? '')), $row), escape: '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    private function sanitize(string $value): string
    {
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'{$value}";
        }

        return $value;
    }
}
