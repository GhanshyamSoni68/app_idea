<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** While an account is waiting to be deleted, sign-in is limited to cancelling and exporting (docs/04 users.status). */
class BlockWhilePendingDeletion
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === 'pending_deletion') {
            throw new ApiException('deletion_pending', 'This account is waiting to be deleted. You can cancel the deletion or export your data from Settings.', 403);
        }

        return $next($request);
    }
}
