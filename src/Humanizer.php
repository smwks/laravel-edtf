<?php

namespace Smwks\LaravelEdtf;

use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;

class Humanizer
{
    protected const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public static function toReadable(string|Edtf $value): string
    {
        return self::render($value, sometime: false);
    }

    public static function toApproximateReadable(string|Edtf $value): string
    {
        return self::render($value, sometime: true);
    }

    protected static function render(string|Edtf $value, bool $sometime): string
    {
        $value = $value instanceof Edtf ? $value->raw() : $value;
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        try {
            $parsed = (new Parser)->parse($value);
        } catch (InvalidEdtfException) {
            return '';
        }

        $components = $parsed['components'];
        $certainty = $parsed['certainty'];

        $phrase = $sometime
            ? self::sometimePhrase($components)
            : self::basePhrase($components);

        if (self::isPartiallyQualified($certainty)) {
            return "{$phrase} (partially qualified)";
        }

        $mark = self::uniformMark($certainty);
        $approximate = in_array($mark, ['~', '%'], true);
        $uncertain = in_array($mark, ['?', '%'], true);

        if ($approximate) {
            $phrase = $sometime
                ? self::approximateSometime($phrase)
                : "circa {$phrase}";
        }

        if ($uncertain) {
            $phrase = "{$phrase} (uncertain)";
        }

        return $phrase;
    }

    protected static function approximateSometime(string $phrase): string
    {
        foreach (['sometime during ', 'sometime in '] as $prefix) {
            if (str_starts_with($phrase, $prefix)) {
                return 'sometime around '.substr($phrase, strlen($prefix));
            }
        }

        return "around {$phrase}";
    }

    /**
     * @param  array<string, array{digits: string, individual: ?string, group: ?string}>  $components
     */
    protected static function sometimePhrase(array $components): string
    {
        $yearDigits = $components['year']['digits'];
        $yearText = self::yearPhrase($yearDigits);
        $yearHasX = str_contains($yearDigits, 'X');

        $monthKnown = isset($components['month']) && ! str_contains($components['month']['digits'], 'X');
        $dayKnown = isset($components['day']) && ! str_contains($components['day']['digits'], 'X');

        if (! $monthKnown) {
            return $yearHasX
                ? "sometime in {$yearText}"
                : "sometime during {$yearText}";
        }

        $month = self::MONTHS[(int) $components['month']['digits']];

        if (! $dayKnown) {
            return "sometime in {$month} {$yearText}";
        }

        $day = (int) $components['day']['digits'];

        return "{$day} {$month} {$yearText}";
    }

    /**
     * @param  array<string, array{digits: string, individual: ?string, group: ?string}>  $components
     */
    protected static function basePhrase(array $components): string
    {
        $yearDigits = $components['year']['digits'];

        $monthKnown = isset($components['month']) && ! str_contains($components['month']['digits'], 'X');
        $dayKnown = isset($components['day']) && ! str_contains($components['day']['digits'], 'X');

        if (! $monthKnown) {
            return self::yearPhrase($yearDigits);
        }

        $month = self::MONTHS[(int) $components['month']['digits']];
        $yearText = self::yearPhrase($yearDigits);

        if (! $dayKnown) {
            return "{$month} {$yearText}";
        }

        $day = (int) $components['day']['digits'];

        return "{$day} {$month} {$yearText}";
    }

    protected static function yearPhrase(string $digits): string
    {
        if (! str_contains($digits, 'X')) {
            return (string) (int) $digits;
        }

        if (str_ends_with($digits, 'XX') && ! str_contains(substr($digits, 0, 2), 'X')) {
            $century = ((int) substr($digits, 0, 2)) + 1;

            return 'the '.self::ordinal($century).' century';
        }

        if (str_ends_with($digits, 'X') && substr_count($digits, 'X') === 1) {
            return 'the '.substr($digits, 0, 3).'0s';
        }

        return $digits;
    }

    public static function ordinal(int $number): string
    {
        $mod100 = $number % 100;

        if ($mod100 >= 11 && $mod100 <= 13) {
            return "{$number}th";
        }

        return $number.match ($number % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * @param  array<string, ?string>  $certainty
     */
    protected static function isPartiallyQualified(array $certainty): bool
    {
        $marks = array_values($certainty);
        $set = array_values(array_filter($marks, fn (?string $mark): bool => $mark !== null && $mark !== ''));

        if ($set === []) {
            return false;
        }

        if (count($set) !== count($marks)) {
            return true;
        }

        return count(array_unique($set)) > 1;
    }

    /**
     * @param  array<string, ?string>  $certainty
     */
    protected static function uniformMark(array $certainty): string
    {
        $set = array_values(array_filter(
            array_values($certainty),
            fn (?string $mark): bool => $mark !== null && $mark !== '',
        ));

        return $set === [] ? '' : $set[0];
    }
}
