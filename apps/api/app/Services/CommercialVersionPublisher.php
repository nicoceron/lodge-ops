<?php

namespace App\Services;

use App\Models\CommercialPromotion;
use App\Models\RatePlan;
use App\Models\TaxRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommercialVersionPublisher
{
    public function publishRatePlan(RatePlan $target, ?int $actorId): RatePlan
    {
        app(RatePlanPublicationValidator::class)->validate($target);

        $published = $this->publish($target, ['property_id', 'name', 'currency'], $actorId, 'approved_by', true);

        return $published;
    }

    public function publishTaxRule(TaxRule $target, ?int $actorId): TaxRule
    {
        if ($target->calculation_type === 'fixed' && ! preg_match('/^[A-Z]{3}$/', (string) $target->currency)) {
            throw ValidationException::withMessages(['currency' => 'Fixed tax inputs require an explicit ISO currency.']);
        }

        $published = $this->publish($target, ['property_id', 'name'], $actorId, 'approved_by', true);

        return $published;
    }

    public function publishPromotion(CommercialPromotion $target, ?int $actorId): CommercialPromotion
    {
        if ($target->approval_owner_id === null) {
            $target->approval_owner_id = $actorId;
            $target->save();
        }

        $published = $this->publish($target, ['property_id', 'name'], $actorId, null, false);

        return $published;
    }

    /**
     * @template T of Model
     *
     * @param  T  $target
     * @param  list<string>  $identity
     * @return T
     */
    private function publish(Model $target, array $identity, ?int $actorId, ?string $actorColumn, bool $hasActive): Model
    {
        return DB::transaction(function () use ($target, $identity, $actorId, $actorColumn, $hasActive): Model {
            $query = $target->newQuery();
            foreach ($identity as $column) {
                $value = $target->getAttribute($column);
                $value === null ? $query->whereNull($column) : $query->where($column, $value);
            }
            $versions = $query->orderBy('id')->lockForUpdate()->get();
            $locked = $versions->first(fn (Model $version): bool => $version->getKey() === $target->getKey())
                ?? $target->newQuery()->lockForUpdate()->findOrFail($target->getKey());
            if ($locked->getAttribute('state') !== 'draft') {
                throw ValidationException::withMessages(['state' => 'Only a draft commercial version can be published.']);
            }
            foreach ($versions->where('state', 'published')->where($target->getKeyName(), '!=', $locked->getKey()) as $current) {
                $current->setAttribute('state', 'retired');
                $current->setAttribute('retired_at', now());
                if ($hasActive) {
                    $current->setAttribute('is_active', false);
                }
                $current->save();
            }
            $locked->setAttribute('state', 'published');
            $locked->setAttribute('published_at', now());
            $locked->setAttribute('retired_at', null);
            if ($hasActive) {
                $locked->setAttribute('is_active', true);
            }
            if ($actorColumn !== null) {
                $locked->setAttribute($actorColumn, $actorId);
            }
            $locked->save();

            return $locked;
        }, 3);
    }
}
