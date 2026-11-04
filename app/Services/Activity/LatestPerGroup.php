<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "The newest row of each parent" in one query, for any number of parents: the filtered rows are reduced to
 * MAX(id) per group, so a list of any length reads its latest updates without a query per row.
 */
final class LatestPerGroup
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows  already filtered to the rows that may be shown
     * @return Builder<TModel>
     */
    public static function newest(Builder $rows, string $group): Builder
    {
        $model = $rows->getModel();
        $key = $model->getQualifiedKeyName();
        $newestIds = $rows->toBase()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->selectRaw('MAX('.$key.')')
            ->groupBy($group);

        return $model->newQuery()->whereIn($key, $newestIds);
    }
}
