<?php

namespace Smwks\LaravelEdtf;

use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;

final class Parser
{
    private const QUALIFIER = '[?~%]';

    private const COMPONENT_NAMES = ['year', 'month', 'day'];

    private const COMPONENT_LENGTHS = ['year' => 4, 'month' => 2, 'day' => 2];

    private const COMPONENT_RANGES = ['month' => [1, 12], 'day' => [1, 31]];

    /**
     * @return array{
     *     components: array<string, array{digits: string, individual: ?string, group: ?string}>,
     *     certainty: array<string, ?string>,
     * }
     */
    public function parse(string $value): array
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^[\[\{].*[\]\}]$/', $value) || str_contains($value, '/') || str_contains($value, 'E') || preg_match('/S\d+$/', $value)) {
            throw new InvalidEdtfException("Unsupported or malformed EDTF value: {$value}");
        }

        $parts = explode('-', $value);

        if (count($parts) < 1 || count($parts) > 3) {
            throw new InvalidEdtfException("Malformed EDTF value: {$value}");
        }

        $components = [];

        foreach ($parts as $index => $part) {
            $name = self::COMPONENT_NAMES[$index];
            $components[$name] = $this->parseComponent($part, $name);
        }

        $this->assertCalendarDate($components);

        return [
            'components' => $components,
            'certainty' => $this->resolveCertainty($components),
        ];
    }

    /**
     * A value that fully specifies year, month, and day must name a day that
     * really exists in that month of that year. Components carrying an
     * unspecified digit stay open-ended and are left to the bounds logic.
     *
     * @param  array<string, array{digits: string, individual: ?string, group: ?string}>  $components
     */
    private function assertCalendarDate(array $components): void
    {
        if (! isset($components['year'], $components['month'], $components['day'])) {
            return;
        }

        $year = $components['year']['digits'];
        $month = $components['month']['digits'];
        $day = $components['day']['digits'];

        foreach ([$year, $month, $day] as $digits) {
            if (str_contains($digits, 'X')) {
                return;
            }
        }

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidEdtfException("Not a real calendar date: {$year}-{$month}-{$day}");
        }
    }

    /**
     * @return array{digits: string, individual: ?string, group: ?string}
     */
    private function parseComponent(string $part, string $name): array
    {
        $length = self::COMPONENT_LENGTHS[$name];
        $pattern = '/^(?<lead>'.self::QUALIFIER.')?(?<value>[0-9X]{'.$length.'})(?<trail>'.self::QUALIFIER.')?$/';

        if (! preg_match($pattern, $part, $matches, PREG_UNMATCHED_AS_NULL)) {
            throw new InvalidEdtfException("Malformed {$name} component: {$part}");
        }

        $individual = $matches['lead'] ?? null;
        $group = $matches['trail'] ?? null;

        if ($individual !== null && $group !== null) {
            throw new InvalidEdtfException("Component cannot have both an individual and group qualifier: {$part}");
        }

        $digits = $matches['value'];

        if (! str_contains($digits, 'X') && isset(self::COMPONENT_RANGES[$name])) {
            [$min, $max] = self::COMPONENT_RANGES[$name];
            $intValue = (int) $digits;

            if ($intValue < $min || $intValue > $max) {
                throw new InvalidEdtfException("{$name} out of range: {$part}");
            }
        }

        return [
            'digits' => $digits,
            'individual' => $individual,
            'group' => $group,
        ];
    }

    /**
     * A group qualifier on a component applies to that component and every
     * component to its left. An individual qualifier applies only to the
     * component it's attached to. Both are combined per component if both
     * a group qualifier (inherited from a component to the right) and an
     * individual qualifier apply to the same component.
     *
     * @param  array<string, array{digits: string, individual: ?string, group: ?string}>  $components
     * @return array<string, ?string>
     */
    private function resolveCertainty(array $components): array
    {
        $names = array_keys($components);
        $certainty = array_fill_keys($names, null);

        foreach ($names as $index => $name) {
            $group = $components[$name]['group'];

            if ($group === null) {
                continue;
            }

            for ($i = 0; $i <= $index; $i++) {
                $left = $names[$i];
                $certainty[$left] = $this->combine($certainty[$left], $group);
            }
        }

        foreach ($names as $name) {
            $individual = $components[$name]['individual'];

            if ($individual === null) {
                continue;
            }

            $certainty[$name] = $this->combine($certainty[$name], $individual);
        }

        return $certainty;
    }

    private function combine(?string $a, string $b): string
    {
        if ($a === null) {
            return $b;
        }

        return $a === $b ? $a : '%';
    }
}
