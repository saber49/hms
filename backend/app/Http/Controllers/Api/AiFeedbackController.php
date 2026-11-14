<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thumbs-up/down feedback on individual AI assistant replies.
 *
 *  POST /api/ai/feedback   { rating: "up"|"down", question?, answer?,
 *                            comment?, tools_used?: string[] }
 *
 * Stored per-tenant in `ai_feedback` (see the tenant migration). Auth +
 * tenant isolation come from the surrounding route group — we only add
 * input validation and excerpt truncation here so a hostile client
 * can't dump megabytes of text into the table.
 */
class AiFeedbackController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $data = $request->validate([
            'rating'       => 'required|in:up,down',
            'comment'      => 'nullable|string|max:2000',
            'question'     => 'nullable|string|max:10000',
            'answer'       => 'nullable|string|max:20000',
            'tools_used'   => 'nullable|array|max:20',
            'tools_used.*' => 'string|max:64',
        ]);

        // Graceful no-op on tenants whose DB hasn't been migrated yet —
        // the widget shouldn't surface an error for a telemetry call.
        if (!Schema::connection('tenant')->hasTable('ai_feedback')) {
            return response()->json(['success' => true, 'stored' => false]);
        }

        DB::connection('tenant')->table('ai_feedback')->insert([
            'user_id'    => (int) $user->id,
            'user_name'  => trim((string) ($user->name ?? $user->email ?? '')) ?: null,
            'rating'     => $data['rating'],
            'comment'    => isset($data['comment']) ? mb_substr($data['comment'], 0, 2000) : null,
            'question'   => isset($data['question']) ? mb_substr($data['question'], 0, 1000) : null,
            'answer'     => isset($data['answer']) ? mb_substr($data['answer'], 0, 2000) : null,
            'tools_used' => !empty($data['tools_used'])
                ? json_encode(array_values($data['tools_used']), JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'stored' => true]);
    }
}
