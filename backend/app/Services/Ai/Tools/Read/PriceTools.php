<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PriceTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'artikel_preise' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('artikel_preise',
                'Artikel- und Preisliste des Mandanten (Suche optional nach Name oder Bereich).',
                [
                    'query'   => ['type' => 'string', 'description' => 'optional, Teil des Artikel- oder Bereichsnamens'],
                    'limit'   => ['type' => 'integer'],
                ], []),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'artikel_preise' => $this->artikelPreise($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function artikelPreise(array $a): array
    {
        $limit = max(1, min(30, (int) ($a['limit'] ?? 10)));
        $q     = trim((string) ($a['query'] ?? ''));

        $where  = '1=1';
        $params = [];
        if ($q !== '') {
            $where  .= ' AND (artikel_name LIKE ? OR bereich LIKE ? OR modell LIKE ?)';
            $params  = ["%$q%","%$q%","%$q%"];
        }
        [$scope, $scopeParams] = BereichScope::artikelPredicate(request()->user(), 'artikel');
        $where .= " AND {$scope}";
        $params = array_merge($params, $scopeParams);

        $rows = DB::connection('tenant')->select("
            SELECT artikel_id, artikel_name, bereich, modell, einheit,
                   preis_kunde, nu_preis
              FROM artikel
             WHERE {$where}
          ORDER BY artikel_name ASC
             LIMIT {$limit}
        ", $params);

        return [
            'anzahl'  => count($rows),
            'artikel' => array_map(fn($r) => [
                'name'        => $r->artikel_name,
                'bereich'     => $r->bereich,
                'modell'      => $r->modell,
                'einheit'     => $r->einheit,
                'preis_kunde' => $r->preis_kunde !== null ? (float) $r->preis_kunde : null,
                'nu_preis'    => $r->nu_preis    !== null ? (float) $r->nu_preis    : null,
            ], $rows),
        ];
    }
}
