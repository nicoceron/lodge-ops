<?php

namespace App\Models\Concerns;

use App\Services\Payments\SensitivePaymentDataGuard;
use Illuminate\Database\Eloquent\Model;

trait RejectsSensitivePaymentData
{
    public static function bootRejectsSensitivePaymentData(): void
    {
        static::saving(function (Model $model): void {
            $values = [];
            foreach (array_keys($model->getDirty()) as $attribute) {
                $value = $model->getAttribute($attribute);
                if (is_string($value) && in_array($value[0] ?? '', ['{', '['], true)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $value = $decoded;
                    }
                }
                $values[$attribute] = $value;
            }
            app(SensitivePaymentDataGuard::class)->assertSafe(
                $values,
                class_basename($model),
            );
        });
    }
}
