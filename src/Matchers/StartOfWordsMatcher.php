<?php

declare(strict_types=1);

namespace Quest\Matchers;

class StartOfWordsMatcher extends BaseMatcher
{
    protected string $operator = 'LIKE';

    public function formatSearchString(string $value) : string
    {
        return implode('% ', explode(' ', $value)) . '%';
    }
}
