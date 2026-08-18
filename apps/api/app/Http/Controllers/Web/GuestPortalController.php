<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\GuestPortalStorageException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuestPortal\AcknowledgeDocumentRequest;
use App\Http\Requests\GuestPortal\StoreGuestSurveyRequest;
use App\Http\Requests\GuestPortal\StorePaymentEvidenceRequest;
use App\Http\Requests\GuestPortal\UpdatePreArrivalRequest;
use App\Http\Resources\GuestPortalFolioResource;
use App\Http\Resources\GuestPortalReservationResource;
use App\Models\GuestPortalAccessToken;
use App\Services\GuestPortalService;
use App\Services\GuestPortalTokenService;
use DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class GuestPortalController extends Controller
{
    public function access(Request $request, string $token, GuestPortalTokenService $tokens): RedirectResponse
    {
        try {
            $exchange = $tokens->exchange($token);
        } catch (AuthenticationException) {
            return redirect()->route('guest.portal.unavailable');
        }

        $request->session()->regenerate();
        $request->session()->put('guest_portal_session_token', $exchange['token']);

        return redirect()
            ->route('guest.portal.home')
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }

    public function home(Request $request, GuestPortalService $portal): Response
    {
        return $this->page('guest.home', $this->portalData($request, $portal));
    }

    public function preArrival(Request $request, GuestPortalService $portal): Response
    {
        return $this->page('guest.pre-arrival', $this->portalData($request, $portal));
    }

    public function updatePreArrival(UpdatePreArrivalRequest $request, GuestPortalService $portal): RedirectResponse
    {
        $portal->updatePreArrival($this->accessToken($request), $request->validated());

        return redirect()->route('guest.portal.pre-arrival')->with('success', 'Pre-arrival details saved.');
    }

    public function documents(Request $request, GuestPortalService $portal): Response
    {
        return $this->page('guest.documents', $this->portalData($request, $portal));
    }

    public function acknowledge(AcknowledgeDocumentRequest $request, GuestPortalService $portal): RedirectResponse
    {
        try {
            $portal->acknowledge(
                $this->accessToken($request),
                $request->validated(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DomainException $exception) {
            return redirect()->route('guest.portal.documents')->with('error', $exception->getMessage());
        }

        return redirect()->route('guest.portal.documents')->with('success', 'Document acknowledged.');
    }

    public function payments(Request $request, GuestPortalService $portal): Response
    {
        return $this->page('guest.payments', $this->portalData($request, $portal));
    }

    public function storePaymentEvidence(
        StorePaymentEvidenceRequest $request,
        GuestPortalService $portal,
    ): RedirectResponse {
        $upload = $request->file('evidence');
        abort_unless($upload instanceof UploadedFile, 422, 'Invalid evidence file.');

        try {
            $portal->storePaymentEvidence($this->accessToken($request), $upload, $request->validated());
        } catch (GuestPortalStorageException $exception) {
            return redirect()->route('guest.portal.payments')->with('error', $exception->getMessage());
        }

        return redirect()->route('guest.portal.payments')->with('success', 'Payment evidence submitted for review.');
    }

    public function folio(Request $request, GuestPortalService $portal): Response
    {
        $data = $this->portalData($request, $portal);
        $data['folio'] = (new GuestPortalFolioResource($portal->folio($this->accessToken($request))))->resolve($request);

        return $this->page('guest.folio', $data);
    }

    public function survey(Request $request, GuestPortalService $portal): Response
    {
        return $this->page('guest.survey', $this->portalData($request, $portal));
    }

    public function storeSurvey(StoreGuestSurveyRequest $request, GuestPortalService $portal): RedirectResponse
    {
        try {
            $portal->storeSurvey($this->accessToken($request), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->route('guest.portal.survey')->with('error', $exception->getMessage());
        }

        return redirect()->route('guest.portal.survey')->with('success', 'Thank you. Your feedback was submitted.');
    }

    public function logout(Request $request, GuestPortalTokenService $tokens): RedirectResponse
    {
        $access = $request->attributes->get('guest_portal_access');

        if ($access instanceof GuestPortalAccessToken) {
            $tokens->revoke($access);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function unavailable(): Response
    {
        return response()
            ->view('guest.unavailable')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** @return array<string, mixed> */
    private function portalData(Request $request, GuestPortalService $portal): array
    {
        return (new GuestPortalReservationResource($portal->reservation($this->accessToken($request))))->resolve($request);
    }

    private function accessToken(Request $request): GuestPortalAccessToken
    {
        $access = $request->attributes->get('guest_portal_access');

        abort_unless($access instanceof GuestPortalAccessToken, 401);

        return $access;
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        return response()
            ->view($view, $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
