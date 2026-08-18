<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class PaymentEvidenceScanner
{
    public function assertSafe(UploadedFile $upload): void
    {
        $path = $upload->getRealPath();
        $sample = file_get_contents($path, false, null, 0, 4096);
        if (! is_string($sample)
            || str_contains(strtoupper($sample), 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')
            || str_contains(strtolower($sample), '<?php')) {
            throw ValidationException::withMessages(['evidence' => 'The evidence file failed the security scan.']);
        }
    }
}
