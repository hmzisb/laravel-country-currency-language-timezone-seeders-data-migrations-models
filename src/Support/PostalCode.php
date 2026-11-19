<?php

namespace Hmzisb\ReferenceData\Support;

use InvalidArgumentException;

/**
 * Normalize a postal code and optionally match the supplied legacy pattern.
 */
final class PostalCode
{
    /**
     * Test the normalized input against an unverified catalog pattern.
     *
     * @param  object  $rules  A catalog's postal rules object with regex and normalization fields.
     * @return bool|null Whether the input matches, or null when no pattern is available.
     *
     * @throws \InvalidArgumentException If the rules, pattern, or input are invalid.
     */
    public static function matchesLegacyPattern(string $value, object $rules): ?bool
    {
        $regex = $rules->regex ?? null;

        if ($regex === null || $regex === '') {
            return null;
        }

        if (!is_string($regex) || !is_string($rules->normalization ?? null)) {
            throw new InvalidArgumentException('Postal rules need a string regex and normalization strategy.');
        }

        $value = self::normalize($value, $rules->normalization);
        $result = @preg_match('~'.str_replace('~', '\\~', $regex).'~uD', $value);

        if ($result === false) {
            throw new InvalidArgumentException('The legacy postal pattern is invalid: '.preg_last_error_msg());
        }

        return $result === 1;
    }

    /**
     * Uppercase UTF-8 input and apply the catalog's separator handling strategy.
     *
     * @param  string  $strategy  One of compact, trim, or collapse_spaces.
     *
     * @throws \InvalidArgumentException If the input is invalid UTF-8 or the strategy is unknown.
     */
    public static function normalize(string $value, string $strategy): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Postal input must be valid UTF-8.');
        }

        $value = mb_strtoupper($value, 'UTF-8');

        return match ($strategy) {
            'compact' => preg_replace('/[\p{Z}\s-]+/u', '', $value),
            'trim' => preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value),
            'collapse_spaces' => trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value)),
            default => throw new InvalidArgumentException("Unknown postal normalization strategy: {$strategy}"),
        };
    }
}
