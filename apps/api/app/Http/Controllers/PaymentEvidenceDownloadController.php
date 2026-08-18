<?php

namespace App\Http\Controllers;

use App\Models\GuestPaymentEvidence;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentEvidenceDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(string $tenant, GuestPaymentEvidence $evidence): StreamedResponse
    {
        $this->authorize('download', $evidence);
        abort_unless(Storage::disk('local')->exists($evidence->storage_path), 404);

        return Storage::disk('local')->download($evidence->storage_path, $evidence->file_name, [
            'Content-Type' => $evidence->content_type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
