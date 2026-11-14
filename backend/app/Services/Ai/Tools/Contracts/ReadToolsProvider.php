<?php

namespace App\Services\Ai\Tools\Contracts;

/**
 * A read-only AI tool provider. Implementations must never write to
 * the database — writes belong in WriteToolsProvider where they get
 * the pending-action confirmation flow for free.
 */
interface ReadToolsProvider
{
    /** JSON-schema fragments (OpenAI function format) this provider owns. */
    public function schemas(): array;

    /** True if $name is one of this provider's tools. */
    public function handles(string $name): bool;

    /** Execute a read tool. Returns a JSON-serialisable result array. */
    public function execute(string $name, array $args): array;
}
