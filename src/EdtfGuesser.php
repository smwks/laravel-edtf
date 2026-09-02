<?php

namespace Smwks\LaravelEdtf;

use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;

class EdtfGuesser
{
    /** @var array<int, string> */
    protected const APPROX_WORDS = ['circa', 'ca.', 'ca', 'c.', 'about', 'around', 'approximately', 'approx.', 'approx'];

    /** @var array<int, string> */
    protected const UNCERTAIN_WORDS = ['uncertain', 'probably', 'possibly', 'perhaps', 'maybe'];

    /** @var array<int, string> */
    protected const VAGUE_WORDS = ['early', 'mid', 'late'];

    /** @var array<string, int> */
    protected const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'sept' => 9,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    public static function guess(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '' || self::parses($trimmed)) {
            return null;
        }

        $working = mb_strtolower($trimmed);
        $mark = '';

        foreach (self::APPROX_WORDS as $word) {
            $pattern = '/(?:^|\s)'.preg_quote($word, '/').'(?=\s|$)/';

            if (preg_match($pattern, $working) === 1) {
                $working = (string) preg_replace($pattern, ' ', $working, 1);
                $mark = '~';
            }
        }

        foreach (self::UNCERTAIN_WORDS as $word) {
            $pattern = '/(?:^|\s)'.preg_quote($word, '/').'(?=\s|$)/';

            if (preg_match($pattern, $working) === 1) {
                $working = (string) preg_replace($pattern, ' ', $working, 1);
                $mark = '?';
            }
        }

        $working = ltrim(trim($working));

        if (str_starts_with($working, '~')) {
            $working = ltrim($working, '~ ');
            $mark = '~';
        }

        foreach (self::VAGUE_WORDS as $word) {
            $working = (string) preg_replace('/(?:^|\s)'.preg_quote($word, '/').'(?=\s|$)/', ' ', $working);
        }

        $working = trim($working);

        $decadeOrCentury = self::decadeOrCentury($working);

        if ($decadeOrCentury !== null) {
            return self::validate($decadeOrCentury.$mark);
        }

        [$working, $knownMonth] = self::extractMonthName($working);

        $numeric = $knownMonth !== null
            ? self::numericWithKnownMonth($working, $knownMonth)
            : self::numericEdtf($working);

        if ($numeric === null) {
            return null;
        }

        return self::validate($numeric.$mark);
    }

    protected static function parses(string $value): bool
    {
        try {
            (new Parser)->parse($value);
        } catch (InvalidEdtfException) {
            return false;
        }

        return true;
    }

    protected static function validate(string $candidate): ?string
    {
        return self::parses($candidate) ? $candidate : null;
    }

    protected static function decadeOrCentury(string $working): ?string
    {
        if (preg_match('/^(?:the\s+)?(\d{3})0\'?s$/', $working, $m) === 1) {
            return $m[1].'X';
        }

        if (preg_match('/^(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)\s+century$/', $working, $m) === 1) {
            $number = (int) $m[1] - 1;

            return str_pad((string) $number, 2, '0', STR_PAD_LEFT).'XX';
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    protected static function extractMonthName(string $working): array
    {
        foreach (self::MONTHS as $name => $number) {
            $pattern = '/(?:^|\s)'.preg_quote($name, '/').'(?=\s|$|,)/';

            if (preg_match($pattern, $working) === 1) {
                return [trim((string) preg_replace($pattern, ' ', $working, 1)), $number];
            }
        }

        return [$working, null];
    }

    protected static function numericWithKnownMonth(string $working, int $month): ?string
    {
        $tokens = self::digitTokens($working);

        if ($tokens === null) {
            return null;
        }

        $year = self::pluck($tokens, fn (string $t): bool => strlen($t) === 4)
            ?? self::pluck($tokens, fn (string $t): bool => (int) $t > 31);

        if ($year === null && count($tokens) === 2 && strlen($tokens[1]) <= 2) {
            $year = self::expandTwoDigitYear($tokens[1]);
            $tokens = [$tokens[0]];
        }

        if ($year === null) {
            return null;
        }

        $month = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        if ($tokens === []) {
            return "{$year}-{$month}";
        }

        if (count($tokens) === 1 && (int) $tokens[0] >= 1 && (int) $tokens[0] <= 31) {
            return "{$year}-{$month}-".str_pad($tokens[0], 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    protected static function numericEdtf(string $working): ?string
    {
        $tokens = self::digitTokens($working);

        if ($tokens === null || $tokens === []) {
            return null;
        }

        if (count($tokens) === 1) {
            return strlen($tokens[0]) === 4 ? $tokens[0] : null;
        }

        if (count($tokens) === 2) {
            $year = self::pluck($tokens, fn (string $t): bool => strlen($t) === 4);

            if ($year === null) {
                return null;
            }

            $month = (int) $tokens[0];

            if ($month < 1 || $month > 12) {
                return null;
            }

            return "{$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        }

        if (count($tokens) === 3) {
            $year = self::pluck($tokens, fn (string $t): bool => strlen($t) === 4);

            if ($year === null && strlen($tokens[2]) <= 2) {
                $year = self::expandTwoDigitYear($tokens[2]);
                $tokens = [$tokens[0], $tokens[1]];
            }

            $year ??= self::pluck($tokens, fn (string $t): bool => (int) $t > 31);

            if ($year === null) {
                return null;
            }

            [$a, $b] = $tokens;

            if ((int) $a > 12 && (int) $b <= 12) {
                [$month, $day] = [(int) $b, (int) $a];
            } else {
                [$month, $day] = [(int) $a, (int) $b];
            }

            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }

            return sprintf('%s-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * Expands a one- or two-digit year with the strtotime pivot: 00–69
     * become 2000–2069, 70–99 become 1970–1999.
     */
    protected static function expandTwoDigitYear(string $digits): string
    {
        $year = (int) $digits;

        return (string) ($year <= 69 ? 2000 + $year : 1900 + $year);
    }

    /**
     * @return array<int, string>|null
     */
    protected static function digitTokens(string $working): ?array
    {
        $tokens = preg_split('/[\/.\-\s,]+/', trim($working), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            if (! ctype_digit($token)) {
                return null;
            }
        }

        return array_values($tokens);
    }

    /**
     * Removes and returns the first token matching $matches; mutates $tokens.
     *
     * @param  array<int, string>  $tokens
     */
    protected static function pluck(array &$tokens, \Closure $matches): ?string
    {
        foreach ($tokens as $index => $token) {
            if ($matches($token)) {
                unset($tokens[$index]);
                $tokens = array_values($tokens);

                return $token;
            }
        }

        return null;
    }
}
