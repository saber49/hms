<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiToolPermissions;
use App\Services\Ai\PendingActionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * REST endpoints powering the AI assistant's confirmation flow.
 *
 *  GET    /api/ai/actions/{action_id}            preview a pending write
 *  POST   /api/ai/actions/{action_id}/confirm    user-approved → execute
 *  DELETE /api/ai/actions/{action_id}            user-cancelled
 *
 * Tenant + auth are enforced by the surrounding route group
 * (auth:sanctum + ResolveTenant + EnsureTenantBoundary). On top of that:
 *  - We compare the action's user_id with the caller — only the user
 *    who proposed the action can confirm/cancel it.
 *  - For confirm we re-check the permission AT EXECUTE TIME so revoking
 *    a permission between propose and confirm cannot be bypassed.
 */
class AiActionsController extends Controller
{
    public function __construct(
        protected PendingActionService $service,
    ) {}

    /** Inspect a pending action — used by the frontend modal. */
    public function show(Request $request, string $actionId)
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $action = $this->service->get($actionId, (int) $user->id);
        if (!$action) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json($action);
    }

    /** Confirm and execute. Returns the writer's success payload. */
    public function confirm(Request $request, string $actionId)
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $action = $this->service->get($actionId, (int) $user->id);
        if (!$action) return response()->json(['error' => 'not_found'], 404);
        if ($action['status'] !== 'pending') {
            return response()->json([
                'error'  => 'invalid_state',
                'status' => $action['status'],
                'message' => match ($action['status']) {
                    'expired'   => 'Diese Aktion ist abgelaufen. Bitte erneut anfragen.',
                    'cancelled' => 'Diese Aktion wurde bereits abgebrochen.',
                    'executed'  => 'Diese Aktion wurde bereits ausgeführt.',
                    'failed'    => 'Diese Aktion ist beim letzten Versuch fehlgeschlagen.',
                    default     => 'Aktion kann nicht bestätigt werden.',
                },
            ], 409);
        }

        // Re-check permission at execute time (defense in depth).
        $perm = AiToolPermissions::permissionFor($action['tool_name']);
        if ($perm && (!method_exists($user, 'hasPermission') || !$user->hasPermission($perm))) {
            return response()->json([
                'error'    => 'forbidden',
                'required' => $perm,
                'message'  => 'Sie haben keine Berechtigung für diese Aktion.',
            ], 403);
        }

        try {
            $result = $this->service->confirm($actionId, (int) $user->id);
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::warning('ai.actions.confirm.error', [
                'action_id' => $actionId,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'error'   => 'execution_failed',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /** Cancel a pending action — silent no-op if already finalised. */
    public function cancel(Request $request, string $actionId)
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $cancelled = $this->service->cancel($actionId, (int) $user->id);
        return response()->json([
            'cancelled' => $cancelled,
            'message'   => $cancelled
                ? 'Aktion abgebrochen.'
                : 'Aktion war nicht (mehr) im Zustand "pending".',
        ]);
    }
}
