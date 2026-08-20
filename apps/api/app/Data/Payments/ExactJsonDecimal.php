<?php

namespace App\Data\Payments;

final readonly class ExactJsonDecimal
{
    public function __construct(public string $value)
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new \InvalidArgumentException('An exact JSON decimal must use plain decimal notation.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
