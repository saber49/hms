<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Worker-facing notification feed (per-tenant `worker_notifications`).
 *
 * Mirrors NotificationController on the manager side, but keyed by
 * mitarbeiter.id resolved from the worker token via ResolveWorkerToken
 * middleware.
 */
class WorkerAppNotificationController extends Controller
{
    public function index(Request $request)
    {
        $workerId = $request->attributes->get('worker_id');
        $limit = max(1, min(100, (int) $request->query('limit', 30)));

        $rows = DB::connection('tenant')->table('worker_notifications')
            ->where('mitarbeiter_id', $workerId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $unread = DB::connection('tenant')->table('worker_notifications')
            ->where('mitarbeiter_id', $workerId)
            ->where('gelesen', 0)
            ->count();

        return response()->json([
            'notifications' => $rows,
            'unread_count'  => $unread,
        ]);
    }

    public function unreadCount(Request $request)
    {
        $workerId = $request->attributes->get('worker_id');
        $unread = DB::connection('tenant')->table('worker_notifications')
            ->where('mitarbeiter_id', $workerId)
            ->where('gelesen', 0)
            ->count();
        return response()->json(['unread_count' => $unread]);
    }

    public function markRead(Request $request, int $id)
    {
        $workerId = $request->attributes->get('worker_id');
        $affected = DB::connection('tenant')->table('worker_notifications')
            ->where('id', $id)
            ->where('mitarbeiter_id', $workerId)
            ->update(['gelesen' => 1, 'read_at' => now()]);
        return response()->json(['success' => $affected > 0]);
    }

    public function markAllRead(Request $request)
    {
        $workerId = $request->attributes->get('worker_id');
        $affected = DB::connection('tenant')->table('worker_notifications')
            ->where('mitarbeiter_id', $workerId)
            ->where('gelesen', 0)
            ->update(['gelesen' => 1, 'read_at' => now()]);
        return response()->json(['success' => true, 'affected' => $affected]);
    }
}
