<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class PaymentEvidenceScanner
{
    public function assertSafe(UploadedFile $upload): void
    {
        abort_unless((bool) config('front_desk_tenders.evidence_scanner_available', true), 503, 'Payment evidence scanning is temporarily unavailable.');
        $path = $upload->getRealPath();
        $sample = file_get_contents($path, false, null, 0, 4096);
        if (! is_string($sample)
            || str_contains(strtoupper($sample), 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')
            || str_contains(strtolower($sample), '<?php')
            || str_contains(strtolower($sample), '<script')
            || str_contains(strtolower($sample), '<html')) {
            throw ValidationException::withMessages(['evidence' => 'The evidence file failed the security scan.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $signatureOk = match ($mime) {
            'application/pdf' => str_starts_with($sample, '%PDF-'),
            'image/png' => str_starts_with($sample, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($sample, "\xff\xd8\xff"),
            default => false,
        };
        if (! $signatureOk) {
            throw ValidationException::withMessages(['evidence' => 'The evidence content does not match an allowed PDF or image type.']);
        }
    }
}
