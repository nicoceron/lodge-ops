<?php

use App\Exceptions\AllocationConflictException;
use App\Exceptions\CommercialWorkflowException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Middleware\EnsureIdempotentCommand;
use App\Http\Middleware\ResolveGuestPortalSession;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'idempotent' => EnsureIdempotentCommand::class,
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
    })->create();
