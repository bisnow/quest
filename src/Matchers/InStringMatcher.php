<?php

declare(strict_types=1);

namespace Quest\Matchers;

class InStringMatcher extends BaseMatcher
{
    protected string $operator = 'LIKE';

    public function formatSearchString(string $value) : string
    {
        return "%$value%";
    }
}
