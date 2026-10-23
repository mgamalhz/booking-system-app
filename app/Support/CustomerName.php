<?php

namespace App\Support;

use InvalidArgumentException;

final class CustomerName
{
    /** Split once: the remainder is preserved, not guessed to be a surname. */
    public static function split(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2);
        if ($parts === false || ($parts[0] ?? '') === '') {
            throw new InvalidArgumentException('Customer name is empty or invalid UTF-8.');
        }

        return ['given_name' => $parts[0], 'family_name' => $parts[1] ?? ''];
    }

    public static function matches(object $row, array $parts): bool
    {
        return $row->given_name === $parts['given_name'] && $row->family_name === $parts['family_name'];
    }
}
