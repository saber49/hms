<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\AiToolPermissions;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Services\Ai\Tools\Contracts\WriteToolsProvider;

/**
 * Central registry for every AI assistant tool.
 *
 * Providers live in per-domain files:
 *   Tools/Read/*   — read-only tools (analytics, search, exports, …)
 *   Tools/Write/*  — write tools (always go through the pending-action
 *                    confirmation flow; NEVER executed directly)
 *
 * To add a tool: create a provider (or add it to an existing domain
 * provider), list the class below, and add the tool name to
 * AiToolPermissions (READ_TOOLS or WRITE_TOOLS). Tools not listed
 * there are never exposed — a forgotten mapping hides the tool
 * instead of leaking it.
 *
 * Providers are stateless: they resolve `DB::connection('tenant')`
 * per call, so a long-lived Octane worker can hold them forever
 * without leaking tenant state between requests.
 */
final class ToolRegistry
{
    /** @var array<int, class-string<ReadToolsProvider>> */
    private const READ_PROVIDERS = [
        Read\AnalyticsTools::class,
        Read\SearchTools::class,
        Read\DispoTools::class,
        Read\MitarbeiterReadTools::class,
        Read\OrderReadTools::class,
        Read\KundeReadTools::class,
        Read\NuReadTools::class,
        Read\PriceTools::class,
        Read\ExportTools::class,
        Read\SystemTools::class,
    ];

    /** @var array<int, class-string<WriteToolsProvider>> */
    private const WRITE_PROVIDERS = [
        Write\WorkerWriteTools::class,
        Write\KundeWriteTools::class,
        Write\ObjektWriteTools::class,
        Write\ArtikelWriteTools::class,
        Write\BestelleingangWriteTools::class,
        Write\BestellungWriteTools::class,
        Write\EinsatzWriteTools::class,
        Write\NuWriteTools::class,
        Write\ZeitWriteTools::class,
        Write\AbwesenheitWriteTools::class,
    ];

    /** @var array<int, ReadToolsProvider>|null */
    private ?array $readers = null;

    /** @var array<int, WriteToolsProvider>|null */
    private ?array $writers = null;

    /** @return array<int, ReadToolsProvider> */
    private function readers(): array
    {
        return $this->readers ??= array_map(
            fn (string $class) => new $class(),
            self::READ_PROVIDERS
        );
    }

    /** @return array<int, WriteToolsProvider> */
    private function writers(): array
    {
        return $this->writers ??= array_map(
            fn (string $class) => new $class(),
            self::WRITE_PROVIDERS
        );
    }

    /** All read-tool schemas in OpenAI function format. */
    public function readSchemas(): array
    {
        $out = [];
        foreach ($this->readers() as $p) {
            array_push($out, ...$p->schemas());
        }
        return $out;
    }

    /** All write-tool schemas in OpenAI function format. */
    public function writeSchemas(): array
    {
        $out = [];
        foreach ($this->writers() as $p) {
            array_push($out, ...$p->schemas());
        }
        return $out;
    }

    /**
     * Full tool list (read + write) filtered to what this user may
     * invoke. Read tools always pass; write tools are gated by
     * AiToolPermissions::WRITE_TOOLS.
     */
    public function schemasForUser($user): array
    {
        $all = array_merge($this->readSchemas(), $this->writeSchemas());

        return array_values(array_filter($all, function ($schema) use ($user) {
            $name = $schema['function']['name'] ?? '';
            if (in_array($name, AiToolPermissions::READ_TOOLS, true)) {
                return true;
            }
            $perm = AiToolPermissions::permissionFor($name);
            if ($perm === null) {
                return false; // unknown tool — never expose
            }
            return $user
                && method_exists($user, 'hasPermission')
                && $user->hasPermission($perm);
        }));
    }

    /** True if any provider (read or write) owns this tool name. */
    public function isKnown(string $name): bool
    {
        foreach ($this->readers() as $p) {
            if ($p->handles($name)) return true;
        }
        foreach ($this->writers() as $p) {
            if ($p->handles($name)) return true;
        }
        return false;
    }

    /**
     * Dispatch a READ tool. Returns an array that is JSON-encoded back
     * to the LLM; never throws into the chat loop — failures become
     * structured error objects the model can read and explain.
     */
    public function executeRead(string $name, array $args): array
    {
        try {
            foreach ($this->readers() as $p) {
                if ($p->handles($name)) {
                    return $p->execute($name, $args);
                }
            }
            return ['error' => "Unknown tool: {$name}"];
        } catch (\Throwable $e) {
            return [
                'error'  => 'Tool execution failed',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /** Preview for a WRITE tool (confirmation modal). No DB writes. */
    public function previewWrite(string $name, array $args): array
    {
        foreach ($this->writers() as $p) {
            if ($p->handles($name)) {
                return $p->preview($name, $args);
            }
        }
        throw new \InvalidArgumentException("Unknown write tool: {$name}");
    }

    /**
     * Execute a confirmed WRITE tool.
     *
     * Re-checks the write permission AT EXECUTION TIME — the preview
     * gate alone is not enough: a pending action lives up to 5 minutes
     * and the user's permission could have been revoked in between.
     */
    public function executeWrite(string $name, array $args, int $userId): array
    {
        $perm = AiToolPermissions::permissionFor($name);
        if ($perm !== null) {
            $user = \App\Models\CentralUser::find($userId);
            if (!$user || (method_exists($user, 'hasPermission') && !$user->hasPermission($perm))) {
                throw new \InvalidArgumentException("Keine Berechtigung für '{$name}'.");
            }
        }
        foreach ($this->writers() as $p) {
            if ($p->handles($name)) {
                return $p->execute($name, $args, $userId);
            }
        }
        throw new \InvalidArgumentException("Unknown write tool: {$name}");
    }
}
