<?php

declare(strict_types=1);

namespace Quest\Matchers;

class AcronymMatcher extends BaseMatcher
{
    protected string $operator = 'LIKE';

    public function formatSearchString(string $value) : string
    {
        $results = [];

        preg_match_all('/./u', mb_strtoupper($value), $results);

        return implode('% ', $results[0]) . '%';
    }
}
