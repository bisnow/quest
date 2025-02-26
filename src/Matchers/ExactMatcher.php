<?php

declare(strict_types=1);

namespace Quest\Matchers;

class ExactMatcher extends BaseMatcher
{
    protected string $operator = '=';
}
