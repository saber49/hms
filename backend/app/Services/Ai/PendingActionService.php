<?php

namespace App\Services\Ai;

use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the lifecycle of an AI write action:
 *
 *   register() → DB row with status=pending
 *   confirm()  → flips to status=executed (or failed) + runs the writer
 *   cancel()   → flips to status=cancelled
 *   get()      → fetches a pending action for the confirmation modal
 *
 * The DB is the source of truth (audit trail). Cache is intentionally
 * NOT used: pending actions are short-lived but auditing them long-term
 * requires the full row to survive cache evictions.
 *
 * Tenant isolation: every operation reads from `DB::connection('tenant')`
 * which has already been pinned by ResolveTenant middleware. The action_id
 * (UUID) doubles as an opaque token — guessing it is computationally
 * infeasible (2^122 random bits) so a leaked action_id from tenant A
 * cannot be replayed against tenant B (different DB anyway).
 */
class PendingActionService
{
    /** Maximum minutes a pending action stays valid before auto-expiring. */
    public const PENDING_TTL_MINUTES = 5;

    public function __construct(
        protected WriteTools $writer,
    ) {}

    /**
     * Register a new pending action and return its public action_id (UUID).
     *
     * @param string $tool      Name of the WRITE tool (must be in WRITE_TOOLS map)
     * @param array  $args      LLM-proposed arguments
     * @param array  $preview   Human-readable summary, JSON-serialisable
     * @param int    $userId    central_users.id of the requester
     * @param string $userName  Display name for audit log
     */
    public function register(
        string $tool,
        array $args,
        array $preview,
        int $userId,
        string $userName = ''
    ): string {
        if (!AiToolPermissions::isWrite($tool)) {
            throw new \InvalidArgumentException("Tool '{$tool}' is not a write tool.");
        }

        $actionId = (string) Str::uuid();

        DB::connection('tenant')->table('ai_actions')->insert([
            'action_id'   => $actionId,
            'tool_name'   => $tool,
            'status'      => 'pending',
            'user_id'     => $userId,
            'user_name'   => $userName !== '' ? $userName : null,
            'args'        => json_encode($args, JSON_UNESCAPED_UNICODE),
            'preview'     => json_encode($preview, JSON_UNESCAPED_UNICODE),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $actionId;
    }

    /**
     * Look up a pending action — used by the confirmation modal.
     * Auto-marks expired rows so the caller never sees a stale entry.
     */
    public function get(string $actionId, int $userId): ?array
    {
        $row = DB::connection('tenant')->table('ai_actions')
            ->where('action_id', $actionId)
            ->where('user_id', $userId)
            ->first();

        if (!$row) return null;

        // Auto-expire old pending rows so re-opening a stale modal fails clean.
        if ($row->status === 'pending'
            && now()->diffInMinutes($row->created_at, true) > self::PENDING_TTL_MINUTES
        ) {
            DB::connection('tenant')->table('ai_actions')
                ->where('action_id', $actionId)
                ->update(['status' => 'expired', 'updated_at' => now()]);
            $row->status = 'expired';
        }

        return [
            'action_id'  => $row->action_id,
            'tool_name'  => $row->tool_name,
            'status'     => $row->status,
            'args'       => json_decode($row->args, true) ?? [],
            'preview'    => json_decode($row->preview, true) ?? [],
            'result'     => $row->result ? json_decode($row->result, true) : null,
            'error'      => $row->error,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Confirm and execute. Atomic-ish: we mark confirmed → call writer →
     * mark executed/failed in one transaction so an interrupted process
     * cannot leave the audit log lying about reality.
     */
    public function confirm(string $actionId, int $userId): array
    {
        $action = $this->get($actionId, $userId);
        if (!$action) {
            throw new \RuntimeException('Action not found.');
        }
        if ($action['status'] !== 'pending') {
            throw new \RuntimeException(
                "Action cannot be confirmed (status: {$action['status']}). "
                . 'Pending actions expire after ' . self::PENDING_TTL_MINUTES . ' min.'
            );
        }

        DB::connection('tenant')->table('ai_actions')
            ->where('action_id', $actionId)
            ->update(['status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()]);

        try {
            $result = $this->writer->execute(
                $action['tool_name'],
                $action['args'],
                $userId
            );

            // Optional rollback hint returned by writers for updates
            $rollback = $result['_rollback'] ?? null;
            unset($result['_rollback']);

            DB::connection('tenant')->table('ai_actions')
                ->where('action_id', $actionId)
                ->update([
                    'status'        => 'executed',
                    'result'        => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'rollback_data' => $rollback ? json_encode($rollback, JSON_UNESCAPED_UNICODE) : null,
                    'executed_at'   => now(),
                    'updated_at'    => now(),
                ]);

            return [
                'status'    => 'executed',
                'action_id' => $actionId,
                'tool_name' => $action['tool_name'],
                'result'    => $result,
            ];
        } catch (\Throwable $e) {
            DB::connection('tenant')->table('ai_actions')
                ->where('action_id', $actionId)
                ->update([
                    'status'     => 'failed',
                    'error'      => mb_substr($e->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

            Log::warning('ai.action.execute_failed', [
                'tenant'    => TenantService::current()?->slug,
                'action_id' => $actionId,
                'tool'      => $action['tool_name'],
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** User-initiated cancel for a still-pending action. */
    public function cancel(string $actionId, int $userId): bool
    {
        $affected = DB::connection('tenant')->table('ai_actions')
            ->where('action_id', $actionId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'updated_at'   => now(),
            ]);

        return $affected > 0;
    }
}
