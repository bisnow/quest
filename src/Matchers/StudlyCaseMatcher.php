<?php

declare(strict_types=1);

namespace Quest\Matchers;

class StudlyCaseMatcher extends BaseMatcher
{
    protected string $operator = 'LIKE BINARY';

    public function buildQueryString(string $field, string $value) : string
    {
        return "IF(CHAR_LENGTH(TRIM($field)) = CHAR_LENGTH(REPLACE(TRIM($field), ' ', '')) AND " .
               "$field {$this->operator} '{$this->formatSearchString($value)}', {$this->multiplier}, 0)";
    }

    public function formatSearchString(string $value) : string
    {
        $results = [];

        preg_match_all('/./u', mb_strtoupper($value), $results);

        return implode('%', $results[0]) . '%';
    }
}
