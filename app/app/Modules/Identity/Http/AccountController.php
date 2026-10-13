<?php

namespace App\Modules\Identity\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\DeletionService;
use App\Modules\Identity\ExportService;
use App\Support\ApiException;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/** Account export and deletion (F09). Both stay available during the deletion grace period. */
class AccountController extends Controller
{
    public function __construct(private ExportService $exports, private DeletionService $deletion) {}

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        return Idempotency::run($request, (string) $user->id, 'export_request', [], fn () => [202, ['export' => $this->exports->request($user)]]);
    }

    public function exportStatus(Request $request, string $id): JsonResponse
    {
        return response()->json(['export' => $this->exports->status($request->user(), $id)]);
    }

    public function exportDownload(Request $request, string $id): Response
    {
        [$name, $bytes] = $this->exports->download($request->user(), $id);

        return response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => "attachment; filename=\"{$name}\"", 'Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        // A fresh credential check before an irreversible action (docs/09 C04). GitHub-only accounts have no password.
        if ($user->password_hash !== null && ! Hash::check((string) $request->input('password', ''), $user->password_hash)) {
            throw new ApiException('password_incorrect', 'That password is not right. Enter your current password to confirm.', 422);
        }

        return Idempotency::run($request, (string) $user->id, 'account_delete', ['confirmed' => true], fn () => [202, ['deletion' => $this->deletion->request($user)]]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->deletion->cancel($request->user());

        return response()->json(['cancelled' => true]);
    }
}
