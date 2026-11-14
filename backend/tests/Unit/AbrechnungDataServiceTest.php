<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\AbrechnungDataService;
use App\Services\ControllingAggregationService;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AbrechnungDataServiceTest extends TestCase
{
    public function test_customer_price_priority_is_order_then_article_master(): void
    {
        $expression = ControllingAggregationService::customerPriceExpr();

        $jsonPrice = strpos($expression, 'ctrl_be.artikel_preise');
        $orderPrice = strpos($expression, 'ctrl_be.kundenpreis');
        $masterPrice = strpos($expression, 'a.preis_kunde');

        $this->assertIsInt($jsonPrice);
        $this->assertIsInt($orderPrice);
        $this->assertIsInt($masterPrice);
        $this->assertLessThan($orderPrice, $jsonPrice);
        $this->assertLessThan($masterPrice, $orderPrice);
    }

    public function test_order_assignments_require_an_active_tenant(): void
    {
        TenantService::reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is not active');

        AbrechnungDataService::confirmedOrderAssignments('B-TEST');
    }

    public function test_nu_cost_is_zero_without_a_nu_assignment(): void
    {
        $expression = ControllingAggregationService::nuCostExpr();

        $this->assertStringContainsString('e.nu_id IS NOT NULL', $expression);
        $this->assertStringContainsString('ELSE 0', $expression);
    }

    public function test_order_assignments_use_the_controlling_calculation_contract(): void
    {
        $queries = [];

        $tenantConnection = Mockery::mock();
        $tenantConnection->shouldReceive('reconnect')->zeroOrMoreTimes();
        $schemaBuilder = Mockery::mock();
        $schemaBuilder->shouldReceive('hasColumn')->zeroOrMoreTimes()->andReturn(true);
        $schemaBuilder->shouldReceive('getConnection')->zeroOrMoreTimes()->andReturn($tenantConnection);
        DB::shouldReceive('connection')->zeroOrMoreTimes()->with('tenant')->andReturnSelf();
        DB::shouldReceive('purge')->zeroOrMoreTimes();
        DB::shouldReceive('reconnect')->zeroOrMoreTimes();
        DB::shouldReceive('getSchemaBuilder')->zeroOrMoreTimes()->andReturn($schemaBuilder);
        DB::shouldReceive('select')->twice()->andReturnUsing(function (string $sql, array $bindings) use (&$queries) {
            $queries[] = compact('sql', 'bindings');
            return str_contains($sql, 'FROM nu_position_zuweisungen')
                ? [(object) ['nu_worker_id' => 1]]
                : [(object) ['dienst_nr' => 'MA001']];
        });

        $tenant = new Tenant();
        $tenant->forceFill(['id' => 54, 'database' => 'tenant_test']);
        TenantService::switchToTenant($tenant);

        $result = AbrechnungDataService::confirmedOrderAssignments('B-TEST');

        $this->assertSame('MA001', $result['workers'][0]->dienst_nr);
        $this->assertSame(1, $result['nu_workers'][0]->nu_worker_id);
        $this->assertCount(2, $queries);

        foreach ($queries as $query) {
            $this->assertSame(['B-TEST'], $query['bindings']);
            $this->assertStringContainsString("e.hms_bestaetigt = 'ja'", $query['sql']);
            $this->assertStringContainsString('AS kundenstunden', $query['sql']);
            $this->assertStringContainsString('AS einnahmen', $query['sql']);
            $this->assertStringContainsString('AS aufschlag', $query['sql']);
            $this->assertStringContainsString('ctrl_be.artikel_preise', $query['sql']);
        }
    }
}
