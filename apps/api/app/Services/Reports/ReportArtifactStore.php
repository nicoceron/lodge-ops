<?php

namespace App\Services\Reports;

use App\Enums\ReportExportFormat;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReportArtifactStore
{
    /** @return array{disk:string,path:string,file_name:string,mime_type:string,size_bytes:int,checksum:string} */
    public function put(string $tenantId, string $exportId, ReportExportFormat $format, string $bytes, string $kind): array
    {
        if ($bytes === '') {
            throw new DomainException('The report writer returned an empty artifact.');
        }
        $extension = $format->value;
        if ($format === ReportExportFormat::Xlsx && ! str_starts_with($bytes, "PK\x03\x04")) {
            throw new DomainException('The report writer returned an invalid XLSX artifact.');
        }
        $diskName = (string) config('documents.disk');
        $disk = Storage::disk($diskName);
        $temporary = "tenants/{$tenantId}/tmp/".Str::uuid().'.'.$extension;
        $final = "tenants/{$tenantId}/reports/{$exportId}.{$extension}";
        try {
            if (! $disk->put($temporary, $bytes, ['visibility' => 'private']) || ! $disk->move($temporary, $final)) {
                throw new DomainException('Unable to store the report artifact.');
            }
            $stored = $disk->get($final);
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $stored))) {
                throw new DomainException('Stored report integrity verification failed.');
            }
        } catch (\Throwable $exception) {
            $disk->delete([$temporary, $final]);
            throw $exception;
        }

        return ['disk' => $diskName, 'path' => $final, 'file_name' => Str::slug($kind).'-report.'.$extension,
            'mime_type' => $format === ReportExportFormat::Csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes)];
    }

    public function verifiedBytes(string $diskName, string $path, string $checksum): string
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            throw new DomainException('The report object is unavailable.');
        }
        $bytes = $disk->get($path);
        if (! hash_equals($checksum, hash('sha256', $bytes))) {
            throw new DomainException('The report object failed integrity verification.');
        }

        return $bytes;
    }

    public function delete(string $diskName, string $path): void
    {
        Storage::disk($diskName)->delete($path);
    }
}
