<?php

use App\Enums\DirectBookingErrorCode;
use App\Exceptions\AllocationConflictException;
use App\Exceptions\CommercialWorkflowException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Middleware\EnsureDirectBookingIdempotency;
use App\Http\Middleware\EnsureIdempotentCommand;
use App\Http\Middleware\ResolveDirectBookingProperty;
use App\Http\Middleware\ResolveGuestPortalSession;
use App\Http\Middleware\ResolveTenant;
use App\Http\Responses\DirectBookingErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Configure the exact local reverse-proxy address at deployment time.
        // Never trust an entire private network for forwarded host or scheme headers.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
        )));
        $middleware->trustProxies($trustedProxies);
        $middleware->alias([
            'idempotent' => EnsureIdempotentCommand::class,
            'direct-booking.idempotent' => EnsureDirectBookingIdempotency::class,
            'direct-booking.property' => ResolveDirectBookingProperty::class,
            'guest.portal' => ResolveGuestPortalSession::class,
            'tenant' => ResolveTenant::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(fn (AllocationConflictException $exception, Request $request) => $request->is('api/*')
            ? response()->json([
                'message' => $exception->getMessage(),
                'conflict' => ['resource_id' => $exception->resourceId, 'conflicting_id' => $exception->conflictingId],
            ], 409)
            : null);
        $exceptions->render(fn (InvalidStatusTransitionException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $exception->getMessage()], 409)
            : null);
        $exceptions->render(fn (CommercialWorkflowException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $exception->getMessage()], 409)
            : null);
        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*')
            ? DirectBookingErrorResponse::make($request, DirectBookingErrorCode::NotFound)
            : null);
        $exceptions->render(fn (MethodNotAllowedHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json([
                'error' => [
                    'code' => 'method_not_allowed',
                    'message' => 'The requested HTTP method is not supported for this endpoint.',
                    'correlation_id' => $request->header('X-Correlation-ID') ?? (string) Str::uuid(),
                    'retryable' => false,
                ],
            ], 405, ['Cache-Control' => 'no-store, private'])
            : null);
    })->create();
