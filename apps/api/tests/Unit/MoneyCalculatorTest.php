<?php

namespace Tests\Unit;

use App\Services\MoneyCalculator;
use PHPUnit\Framework\TestCase;

class MoneyCalculatorTest extends TestCase
{
    public function test_line_amount_uses_integer_half_up_rounding(): void
    {
        $calculator = new MoneyCalculator;

        $this->assertSame(250, $calculator->lineAmount(100, 2500));
        $this->assertSame(34, $calculator->lineAmount(101, 333));
        $this->assertSame(-34, $calculator->lineAmount(-101, 333));
    }

    public function test_sum_keeps_minor_units_exact(): void
    {
        $this->assertSame(900719925474099, (new MoneyCalculator)->sum([900719925474000, 99]));
    }
}
