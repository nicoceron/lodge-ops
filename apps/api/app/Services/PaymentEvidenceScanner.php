<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class PaymentEvidenceScanner
{
    public function assertSafe(UploadedFile $upload): void
    {
        abort_unless((bool) config('front_desk_tenders.evidence_scanner_available', false), 503, 'Payment evidence scanning is temporarily unavailable.');
        $path = $upload->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;
        if (! is_string($contents) || $contents === '' || strlen($contents) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['evidence' => 'The evidence file failed the security scan.']);
        }
        $lower = strtolower($contents);
        foreach (['eicar-standard-antivirus-test-file', '<?php', '<script', '<html', 'javascript:', '/javascript', '/launch', '/embeddedfile', '/openaction', '/aa '] as $needle) {
            if (str_contains($lower, $needle)) {
                throw ValidationException::withMessages(['evidence' => 'The evidence file failed the security scan.']);
            }
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $signatureOk = match ($mime) {
            'application/pdf' => $this->validPdf($contents, $path),
            'image/png' => $this->validPng($contents),
            'image/jpeg' => $this->validJpeg($contents),
            default => false,
        };
        if (! $signatureOk) {
            throw ValidationException::withMessages(['evidence' => 'The evidence content does not match an allowed PDF or image type.']);
        }
    }

    private function validPdf(string $contents, string $path): bool
    {
        if (preg_match('/^%PDF-1\.[0-7](?:\r?\n|\r)/', $contents) !== 1
            || preg_match('/%%EOF\s*\z/', $contents) !== 1
            || ! str_contains($contents, '/Type /Catalog')
            || ! str_contains($contents, '/Type /Pages')
            || ! str_contains($contents, '/Type /Page')
            || ! str_contains($contents, 'trailer')
            || preg_match('/trailer\s*<<[^>]*\/Root\s+\d+\s+\d+\s+R/s', $contents) !== 1) {
            return false;
        }

        preg_match_all('/(?:^|[\r\n])\d+\s+\d+\s+obj\b/', $contents, $objects);
        preg_match_all('/\bendobj\b/', $contents, $ends);

        if (count($objects[0]) === 0 || count($objects[0]) !== count($ends[0])) {
            return false;
        }

        $binary = trim((string) config('front_desk_tenders.evidence_pdf_parser_binary', 'pdfinfo'));
        $executable = $binary === '' ? null : (new ExecutableFinder)->find($binary);
        if ($executable === null) {
            abort(503, 'Payment evidence PDF parsing is temporarily unavailable.');
        }
        try {
            $process = new Process([$executable, '-f', '1', '-l', '1', $path]);
            $process->setTimeout(10)->mustRun();
        } catch (ProcessFailedException) {
            return false;
        } catch (Throwable) {
            abort(503, 'Payment evidence PDF parsing is temporarily unavailable.');
        }

        return preg_match('/^Pages:\s+[1-9]\d*$/m', $process->getOutput()) === 1
            && preg_match('/^Encrypted:\s+no$/mi', $process->getOutput()) === 1;
    }

    private function validJpeg(string $contents): bool
    {
        if (! str_starts_with($contents, "\xff\xd8\xff") || ! str_ends_with($contents, "\xff\xd9")) {
            return false;
        }
        $info = @getimagesizefromstring($contents);
        $image = @imagecreatefromstring($contents);
        if ($image === false || $info === false || $info[2] !== IMAGETYPE_JPEG) {
            if ($image !== false) {
                imagedestroy($image);
            }

            return false;
        }
        imagedestroy($image);

        return true;
    }

    private function validPng(string $contents): bool
    {
        if (! str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            return false;
        }
        $offset = 8;
        $length = strlen($contents);
        $seenData = false;
        $chunkIndex = 0;
        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($contents, $offset, 4))[1];
            if ($chunkLength > $length - $offset - 12) {
                return false;
            }
            $type = substr($contents, $offset + 4, 4);
            $data = substr($contents, $offset + 8, $chunkLength);
            $storedCrc = unpack('N', substr($contents, $offset + 8 + $chunkLength, 4))[1];
            $calculatedCrc = (int) sprintf('%u', crc32($type.$data));
            if ($storedCrc !== $calculatedCrc) {
                return false;
            }
            if ($chunkIndex === 0 && ($type !== 'IHDR' || $chunkLength !== 13)) {
                return false;
            }
            if ($chunkIndex > 0 && $type === 'IHDR') {
                return false;
            }
            $seenData = $seenData || $type === 'IDAT';
            $offset += 12 + $chunkLength;
            if ($type === 'IEND') {
                return $chunkLength === 0 && $offset === $length && $seenData;
            }
            $chunkIndex++;
        }

        return false;
    }
}
