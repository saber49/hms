<?php

namespace Tests\Unit;

use App\Services\Qm\WachbuchCoverage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WachbuchCoverageTest extends TestCase
{
    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Europe/Berlin');
    }

    public function test_two_shifts_cover_the_day_even_if_they_overlap(): void
    {
        $day = $this->at('2026-11-02 06:00');
        $end = $day->addDay();
        $result = WachbuchCoverage::pruefen($day, $end, [
            ['von' => $this->at('2026-11-02 05:55'), 'bis' => $this->at('2026-11-02 18:03')],
            ['von' => $this->at('2026-11-02 17:57'), 'bis' => $this->at('2026-11-03 06:01')],
        ]);
        $this->assertTrue($result['lueckenlos']);
        $this->assertSame([], $result['luecken']);
    }

    public function test_missing_checkout_and_five_minute_gap_are_not_called_continuous(): void
    {
        $day = $this->at('2026-11-02 06:00');
        $result = WachbuchCoverage::pruefen($day, $day->addDay(), [
            ['von' => $day, 'bis' => $this->at('2026-11-02 17:55')],
            ['von' => $this->at('2026-11-02 18:00'), 'bis' => $this->at('2026-11-03 06:00')],
            ['von' => $this->at('2026-11-02 12:00'), 'bis' => null],
        ]);
        $this->assertFalse($result['lueckenlos']);
        $this->assertCount(1, $result['luecken']);
        $this->assertSame(300, strtotime($result['luecken'][0]['bis']) - strtotime($result['luecken'][0]['von']));
    }

    public function test_daylight_saving_uses_local_day_boundaries(): void
    {
        $day = $this->at('2026-10-24 06:00');
        $end = $day->addDay();
        $this->assertSame(25 * 3600, $end->getTimestamp() - $day->getTimestamp());
        $result = WachbuchCoverage::pruefen($day, $end, [['von' => $day, 'bis' => $end]]);
        $this->assertTrue($result['lueckenlos']);
    }
}
