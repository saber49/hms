<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\ToolRegistry;

/**
 * Facade for the AI assistant's read tools.
 *
 * The actual tool implementations live in per-domain providers under
 * `app/Services/Ai/Tools/Read/` and are aggregated by ToolRegistry.
 * This class keeps the historic public API (schemas / writeSchemas /
 * schemasForUser / execute) so AiAssistantController and friends do not
 * need to know about the registry layout.
 *
 * Tenant isolation
 * ----------------
 * Every query the providers run uses the `tenant` DB connection —
 * pinned by ResolveTenant + tenant.boundary middleware. The model
 * cannot pass a tenant_id; it sees only the caller's tenant data.
 *
 * Read/write split
 * ----------------
 * execute() dispatches READ tools only. Write tools never execute here
 * — the controller turns them into pending actions which run through
 * WriteTools (confirmation flow) after the user approves.
 */
class AiTools
{
    public function __construct(
        private readonly ToolRegistry $registry = new ToolRegistry(),
    ) {}

    /** READ tool schemas (OpenAI function format). */
    public function schemas(): array
    {
        return $this->registry->readSchemas();
    }

    /** WRITE tool schemas — gated per user via schemasForUser(). */
    public function writeSchemas(): array
    {
        return $this->registry->writeSchemas();
    }

    /** Read + write schemas filtered to the caller's permissions. */
    public function schemasForUser($user): array
    {
        return $this->registry->schemasForUser($user);
    }

    /**
     * Dispatch a READ tool by name. Returns an array that will be
     * JSON-encoded back to the LLM as the tool result. Never throws —
     * failures become structured error objects.
     */
    public function execute(string $name, array $args): array
    {
        return $this->registry->executeRead($name, $args);
    }
}
