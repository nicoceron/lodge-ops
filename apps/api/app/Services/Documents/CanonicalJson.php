<?php

namespace App\Services\Documents;

use BackedEnum;
use DateTimeInterface;

final class CanonicalJson
{
    public function encode(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function checksum(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
