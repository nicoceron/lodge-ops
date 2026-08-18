<?php

namespace App\Services\Documents;

use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class DocumentArtifactStore
{
    /** @return array{disk:string,path:string,file_name:string,mime_type:string,size_bytes:int,checksum:string} */
    public function put(string $tenantId, string $requestId, string $bytes, string $suggestedName): array
    {
        $this->validatePdf($bytes);
        $this->validatePdfParser($bytes);
        $diskName = (string) config('documents.disk');
        $disk = Storage::disk($diskName);
        $temporary = "tenants/{$tenantId}/tmp/".Str::uuid().'.pdf';
        $final = "tenants/{$tenantId}/documents/{$requestId}.pdf";

        try {
            if (! $disk->put($temporary, $bytes, ['visibility' => 'private'])) {
                throw new DomainException('Unable to write the temporary PDF object.');
            }
            if (! $disk->move($temporary, $final)) {
                throw new DomainException('Unable to promote the PDF object.');
            }
            $stored = $disk->get($final);
            $this->validatePdf($stored);
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $stored))) {
                throw new DomainException('Stored PDF integrity verification failed.');
            }
        } catch (\Throwable $exception) {
            $disk->delete([$temporary, $final]);
            throw $exception;
        }

        return [
            'disk' => $diskName,
            'path' => $final,
            'file_name' => $this->safeName($suggestedName),
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ];
    }

    public function verifiedBytes(string $diskName, string $path, string $checksum): string
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            throw new DomainException('The document object is unavailable.');
        }
        $bytes = $disk->get($path);
        $this->validatePdf($bytes);
        if (! hash_equals($checksum, hash('sha256', $bytes))) {
            throw new DomainException('The document object failed integrity verification.');
        }

        return $bytes;
    }

    public function delete(string $diskName, string $path): void
    {
        Storage::disk($diskName)->delete($path);
    }

    private function validatePdf(string $bytes): void
    {
        if (strlen($bytes) < 100 || ! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF')) {
            throw new DomainException('Renderer returned invalid PDF bytes.');
        }
    }

    private function validatePdfParser(string $bytes): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lodge-pdf-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new DomainException('Unable to allocate PDF validation input.');
        }

        try {
            $process = new Process([(string) config('documents.pdfinfo_binary', 'pdfinfo'), $path]);
            $process->setTimeout(15)->run();
            if (! $process->isSuccessful()) {
                throw new DomainException('Renderer returned a PDF that could not be parsed.');
            }
        } finally {
            @unlink($path);
        }
    }

    private function safeName(string $name): string
    {
        $name = Str::slug(pathinfo($name, PATHINFO_FILENAME));

        return ($name !== '' ? $name : 'document').'.pdf';
    }
}
