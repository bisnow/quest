<?php

declare(strict_types=1);

namespace Quest\Macros;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;

class OrderByFuzzy
{
    /**
     * Construct a fuzzy search expression.
     *
     * @param  Builder  $builder
     * @param  array<int,string>|string  $fields
     *
     * @return Builder
     */
    public static function make(Builder $builder, array|string $fields): Builder
    {
        $fields = Arr::wrap($fields);

        foreach ($fields as $field) {
            $builder->orderBy('fuzzy_relevance_' . str_replace('.', '_', $field), 'desc');
        }

        return $builder;
    }
}
