<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\V1\GuestPortalController as GuestPortalApiController;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuestPortal\AcknowledgeDocumentRequest;
use App\Http\Requests\GuestPortal\StoreGuestSurveyRequest;
use App\Http\Requests\GuestPortal\StorePaymentEvidenceRequest;
use App\Http\Requests\GuestPortal\UpdatePreArrivalRequest;
use App\Models\GuestPortalAccessToken;
use App\Services\GuestPortalTokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function home(Request $request, GuestPortalApiController $portal): Response
    {
        return $this->page('guest.home', $this->portalData($request, $portal));
    }

    public function preArrival(Request $request, GuestPortalApiController $portal): Response
    {
        return $this->page('guest.pre-arrival', $this->portalData($request, $portal));
    }

    public function updatePreArrival(UpdatePreArrivalRequest $request, GuestPortalApiController $portal): RedirectResponse
    {
        $portal->updatePreArrival($request);

        return redirect()->route('guest.portal.pre-arrival')->with('success', 'Pre-arrival details saved.');
    }

    public function documents(Request $request, GuestPortalApiController $portal): Response
    {
        return $this->page('guest.documents', $this->portalData($request, $portal));
    }

    public function acknowledge(AcknowledgeDocumentRequest $request, GuestPortalApiController $portal): RedirectResponse
    {
        return $this->mutationRedirect(
            $portal->acknowledge($request),
            'guest.portal.documents',
            'Document acknowledged.',
        );
    }

    public function payments(Request $request, GuestPortalApiController $portal): Response
    {
        return $this->page('guest.payments', $this->portalData($request, $portal));
    }

    public function storePaymentEvidence(StorePaymentEvidenceRequest $request, GuestPortalApiController $portal): RedirectResponse
    {
        return $this->mutationRedirect(
            $portal->storePaymentEvidence($request),
            'guest.portal.payments',
            'Payment evidence submitted for review.',
        );
    }

    public function folio(Request $request, GuestPortalApiController $portal): Response
    {
        $data = $this->portalData($request, $portal);
        $data['folio'] = $portal->folio($request)->resolve($request);

        return $this->page('guest.folio', $data);
    }

    public function survey(Request $request, GuestPortalApiController $portal): Response
    {
        return $this->page('guest.survey', $this->portalData($request, $portal));
    }

    public function storeSurvey(StoreGuestSurveyRequest $request, GuestPortalApiController $portal): RedirectResponse
    {
        return $this->mutationRedirect(
            $portal->storeSurvey($request),
            'guest.portal.survey',
            'Thank you. Your feedback was submitted.',
        );
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
    private function portalData(Request $request, GuestPortalApiController $portal): array
    {
        return $portal->show($request)->resolve($request);
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

    private function mutationRedirect(JsonResponse $response, string $route, string $success): RedirectResponse
    {
        if ($response->isSuccessful()) {
            return redirect()->route($route)->with('success', $success);
        }

        $payload = json_decode((string) $response->getContent(), true);
        $message = is_array($payload) && is_string($payload['message'] ?? null)
            ? $payload['message']
            : 'Unable to complete this request.';

        return redirect()->route($route)->with('error', $message);
    }
}
