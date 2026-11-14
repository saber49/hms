<?php

namespace App\Services\Ai\Tools\Contracts;

/**
 * A write-capable AI tool provider. preview() renders the confirmation
 * modal content (NO writes allowed); execute() performs the change and
 * is only reached after explicit user confirmation via
 * PendingActionService.
 */
interface WriteToolsProvider
{
    /** JSON-schema fragments (OpenAI function format) this provider owns. */
    public function schemas(): array;

    /** True if $name is one of this provider's tools. */
    public function handles(string $name): bool;

    /** Human-readable preview for the confirmation modal. No DB writes. */
    public function preview(string $name, array $args): array;

    /** Perform the write. May return a `_rollback` snapshot key. */
    public function execute(string $name, array $args, int $userId): array;
}
