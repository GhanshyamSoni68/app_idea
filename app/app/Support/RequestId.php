<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Every response carries X-Request-Id (docs/02 §5.1). */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) ($request->headers->get('X-Request-Id') ?: 'req_'.Str::lower(Str::random(16)));
        $id = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $id), 0, 64) ?: 'req_'.Str::lower(Str::random(16));
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
