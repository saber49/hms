<?php

namespace Tests\Unit;

use App\Services\AbsenceCreditService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AbsenceCreditServiceTest extends TestCase
{
    public function test_manual_hours_override_paid_absence_credit(): void
    {
        $credit = new ReflectionMethod(AbsenceCreditService::class, 'applyManualCredit');

        $this->assertSame(['manuell', 6.0, 6.0], $credit->invoke(null, 6.0, 'plan', 8.0, true));
        $this->assertSame(['plan', 8.0, 8.0], $credit->invoke(null, null, 'plan', 8.0, true));
        $this->assertSame(['manuell', 0.0, 0.0], $credit->invoke(null, 0.0, 'plan', 8.0, true));
    }

    public function test_unpaid_and_worked_days_can_record_time_without_paid_credit(): void
    {
        $credit = new ReflectionMethod(AbsenceCreditService::class, 'applyManualCredit');

        $this->assertSame(['unbezahlt', 0.0, 8.0], $credit->invoke(null, 8.0, 'unbezahlt', 0.0, false));
        $this->assertSame(['gearbeitet', 0.0, 4.0], $credit->invoke(null, 4.0, 'gearbeitet', 0.0, true));
        $this->assertSame(['wartezeit', 0.0, 8.0], $credit->invoke(null, 8.0, 'wartezeit', 0.0, true));
    }
}
