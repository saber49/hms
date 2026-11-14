<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\ToolRegistry;

/**
 * Facade for the AI assistant's write tools.
 *
 * Implementations live in per-domain providers under
 * `app/Services/Ai/Tools/Write/` and are dispatched by ToolRegistry.
 * This facade preserves the historic preview()/execute() contract used
 * by AiAssistantController (preview → pending action) and
 * PendingActionService (execute after user confirmation).
 *
 * Invariants (unchanged from the monolith era):
 * - All queries go through `DB::connection('tenant')`.
 * - preview() must never write to the database.
 * - execute() only runs after explicit user confirmation.
 */
class WriteTools
{
    public function __construct(
        private readonly ToolRegistry $registry = new ToolRegistry(),
    ) {}

    /** Human-readable preview for the confirmation modal. */
    public function preview(string $name, array $args): array
    {
        return $this->registry->previewWrite($name, $args);
    }

    /** Execute a confirmed write. May return a `_rollback` key. */
    public function execute(string $name, array $args, int $userId): array
    {
        return $this->registry->executeWrite($name, $args, $userId);
    }
}
