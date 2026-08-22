<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AddDirectBookingSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'none'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self' https://mercadopago.com https://*.mercadopago.com https://mercadopago.com.co https://*.mercadopago.com.co https://mercadopago.com.ar https://*.mercadopago.com.ar",
            "script-src 'self' https://challenges.cloudflare.com",
            "style-src 'self'",
            "img-src 'self' https: data:",
            "font-src 'self'",
            "connect-src 'self'",
            'frame-src https://challenges.cloudflare.com',
        ]));
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
