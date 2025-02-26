<?php

declare(strict_types=1);

namespace Quest\Macros;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Quest\Matchers\AcronymMatcher;
use Quest\Matchers\ConsecutiveCharactersMatcher;
use Quest\Matchers\ExactMatcher;
use Quest\Matchers\InStringMatcher;
use Quest\Matchers\StartOfStringMatcher;
use Quest\Matchers\StartOfWordsMatcher;
use Quest\Matchers\StudlyCaseMatcher;
use Quest\Matchers\TimesInStringMatcher;

class WhereFuzzy
{
    /**
     * The weights for the pattern matching classes.
     *
     * @var array<class-string,int>
     */
    protected static array $matchers = [
        ExactMatcher::class                 => 100,
        StartOfStringMatcher::class         => 50,
        AcronymMatcher::class               => 42,
        ConsecutiveCharactersMatcher::class => 40,
        StartOfWordsMatcher::class          => 35,
        StudlyCaseMatcher::class            => 32,
        InStringMatcher::class              => 30,
        TimesInStringMatcher::class         => 8,
    ];

    /**
     * Construct a fuzzy search expression.
     *
     * @param  Builder  $builder
     * @param  string  $field
     * @param  string  $value
     * @param  bool  $sortMatchesFilterRelevance
     * @param  array<int,string>  $disabledMatchers
     *
     * @return Builder
     */
    public static function make(Builder $builder, string $field, string $value, bool $sortMatchesFilterRelevance, array $disabledMatchers): Builder
    {
        $value       = static::escapeValue($value);

        $nativeField = '`' . str_replace('.', '`.`', trim($field, '` ')) . '`';

        if (! is_array($builder->columns) || empty($builder->columns)) {
            $builder->columns = ['*'];
        }

        $builder
            ->addSelect([static::pipeline($field, $nativeField, $value, $disabledMatchers)])
            ->when($sortMatchesFilterRelevance, function (Builder $query) use ($field): void {
                $query->having('fuzzy_relevance_' . str_replace('.', '_', $field), '>', 0);
            });

        static::calculateTotalRelevanceColumn($builder, $sortMatchesFilterRelevance);

        return $builder;
    }

    /**
     * Construct a fuzzy OR search expression.
     *
     * @param  Builder  $builder
     * @param  string  $field
     * @param  string  $value
     * @param  float|int|string|null  $relevance
     * @param  bool  $sortMatchesFilterRelevance
     * @param  array<int,string>  $disabledMatchers
     *
     * @return Builder
     */
    public static function makeOr(
        Builder $builder,
        string $field,
        string $value,
        float|int|string|null $relevance,
        bool $sortMatchesFilterRelevance,
        array $disabledMatchers,
    ): Builder {
        $value       = static::escapeValue($value);
        $nativeField = '`' . str_replace('.', '`.`', trim($field, '` ')) . '`';

        if (! is_array($builder->columns) || empty($builder->columns)) {
            $builder->columns = ['*'];
        }

        $builder->addSelect([static::pipeline($field, $nativeField, $value, $disabledMatchers)])
            ->when($sortMatchesFilterRelevance, function (Builder $query) use ($field, $relevance): void {
                $query->orHaving('fuzzy_relevance_' . str_replace('.', '_', $field), '>', $relevance);
            });

        static::calculateTotalRelevanceColumn($builder, $sortMatchesFilterRelevance);

        return $builder;
    }

    /**
     * Manage relevance columns SUM for total relevance ORDER.
     *
     * Searches all relevance columns and parses the relevance
     * expressions to create the total relevance column
     * and creates the order statement for it.
     */
    protected static function calculateTotalRelevanceColumn(Builder $builder, bool $sortMatchesFilterRelevance): bool
    {
        if (blank($builder->columns)) {
            return false;
        }
        $existingRelevanceColumns = [];
        $sumColumnIdx             = null;

        // search for fuzzy_relevance_* columns and _fuzzy_relevance_ position
        foreach ($builder->columns as $as => $column) {
            if ($column instanceof Expression) {
                /** @var string $columnValue */
                $columnValue = $column->getValue(DB::getQueryGrammar());

                if (stripos($columnValue, 'AS fuzzy_relevance_')) {
                    $matches = [];

                    preg_match('/AS (fuzzy_relevance_.*)$/', $columnValue, $matches);

                    $match = data_get($matches, 1);

                    if (filled($match)) {
                        $existingRelevanceColumns[$as] = $match;
                    }
                } elseif (stripos($columnValue, 'AS _fuzzy_relevance_')) {
                    $sumColumnIdx = $as;
                }
            }
        }

        // glue together all relevance expressions under _fuzzy_relevance_ column
        $relevanceTotalColumn = '';

        /**
         * @var string $as
         * @var string $column
         */
        foreach ($existingRelevanceColumns as $as => $column) {
            /** @var string $subject */
            $subject = $builder->columns[$as]->getValue(DB::getQueryGrammar());

            $relevanceTotalColumn .= (! empty($relevanceTotalColumn) ? ' + ' : '')
                                     . '('
                                     . str_ireplace(' AS ' . $column, '', $subject)
                                     . ')';
        }

        $relevanceTotalColumn .= ' AS _fuzzy_relevance_';

        if (is_null($sumColumnIdx)) {
            // no sum column yet, just add this one
            $builder->addSelect([new Expression($relevanceTotalColumn)]);
        } else {
            // update the existing one
            $builder->columns[$sumColumnIdx] = new Expression($relevanceTotalColumn);
        }

        // only add the _fuzzy_relevance_ ORDER once
        if (
            ! $builder->orders
            || ! in_array(
                '_fuzzy_relevance_',
                array_column($builder->orders, 'column')
            )
        ) {
            $builder->when($sortMatchesFilterRelevance, function (Builder $query): void {
                $query->orderBy('_fuzzy_relevance_', 'desc');
            });
        }

        return true;
    }

    /**
     * Escape value input for fuzzy search.
     */
    protected static function escapeValue(string $value): string
    {
        $value = str_replace(['"', "'", '`'], '', $value);

        return substr(DB::connection()->getPdo()->quote($value), 1, -1);
    }

    /**
     * Execute each of the pattern matching classes to generate the required SQL.
     *
     * @param  string  $field
     * @param  string  $native
     * @param  string  $value
     * @param  array<int,string>  $disabledMatchers
     *
     * @return ExpressionContract
     */
    protected static function pipeline(string $field, string $native, string $value, array $disabledMatchers): ExpressionContract
    {
        $disabledMatchers = preg_filter('/^/', 'Quest\Matchers\\', $disabledMatchers);

        /** @phpstan-ignore-next-line */
        $sql = collect(static::$matchers)->forget($disabledMatchers)->map(
            /** @phpstan-ignore-next-line */
            fn ($multiplier, $matcher) => (new $matcher($multiplier))->buildQueryString("COALESCE($native, '')", $value)
        );

        return DB::raw($sql->implode(' + ') . ' AS fuzzy_relevance_' . str_replace('.', '_', $field));
    }
}
