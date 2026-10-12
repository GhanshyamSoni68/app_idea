<?php

use App\Support\ApiException;
use App\Support\RequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        // Guests and signed-in learners share one origin; the SPA reads the XSRF-TOKEN cookie.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('v1/*') || $request->expectsJson());

        $envelope = function (Request $request, string $code, string $message, int $status, bool $retryable = false, ?array $details = null, ?array $fields = null) {
            return response()->json(['error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $request->attributes->get('request_id'),
                'retryable' => $retryable,
                'details' => $details,
                'fields' => $fields,
            ]], $status);
        };

        // Only /v1 responses use the standard envelope (docs/02 §5.4).
        $exceptions->render(function (ApiException $e, Request $request) use ($envelope) {
            return $envelope($request, $e->errorCode, $e->getMessage(), $e->status, $e->retryable, $e->details, $e->fields);
        });
        $exceptions->render(function (ValidationException $e, Request $request) use ($envelope) {
            if (! $request->is('v1/*')) {
                return null;
            }
            $fields = [];
            foreach ($e->errors() as $field => $messages) {
                $fields[] = ['field' => $field, 'code' => 'invalid', 'message' => $messages[0]];
            }

            return $envelope($request, 'validation_failed', 'Some of the details need another look.', 422, false, null, $fields);
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope) {
            return $request->is('v1/*') ? $envelope($request, 'unauthenticated', 'Please sign in to continue.', 401) : null;
        });
        // Cross-owner access returns 404, never 403, so existence is not revealed (docs/02).
        $exceptions->render(function (AuthorizationException|ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($envelope) {
            return $request->is('v1/*') ? $envelope($request, 'not_found', 'That item was not found.', 404) : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($envelope) {
            return $request->is('v1/*') ? $envelope($request, 'rate_limited', 'Too many requests. Please wait a moment and try again.', 429, true) : null;
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($envelope) {
            if (! $request->is('v1/*')) {
                return null;
            }
            $status = $e->getStatusCode();
            $code = $status === 419 ? 'csrf_mismatch' : 'http_'.$status;

            return $envelope($request, $code, $status === 419 ? 'Your session expired. Please reload the page.' : 'The request could not be completed.', $status, $status >= 500);
        });
    })->create();
