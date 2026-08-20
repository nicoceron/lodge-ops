<?php

namespace App\Http\Controllers;

use App\Models\GuestPaymentEvidence;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentEvidenceDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(string $tenant, string $evidence): StreamedResponse
    {
        $evidence = GuestPaymentEvidence::query()->findOrFail($evidence);
        $this->authorize('download', $evidence);
        abort_unless(in_array($evidence->scan_state, ['accepted', 'clean'], true)
            && ! in_array($evidence->status->value, ['review_pending', 'rejected'], true), 409);
        $disk = $evidence->disk ?: 'local';
        $key = $evidence->storage_key ?: $evidence->storage_path;
        abort_unless(Storage::disk($disk)->exists($key), 404);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($evidence->original_name ?: $evidence->file_name)) ?: 'payment-evidence';

        return Storage::disk($disk)->download($key, $safeName, [
            'Content-Type' => $evidence->detected_mime ?: $evidence->content_type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
