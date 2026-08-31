<?php

namespace Smwks\LaravelEdtf;

use DateTimeImmutable;
use JsonSerializable;

final class Edtf implements JsonSerializable
{
    /**
     * @param  array<string, array{digits: string, individual: ?string, group: ?string}>  $components
     * @param  array<string, ?string>  $certainty
     */
    private function __construct(
        private readonly string $raw,
        private readonly array $components,
        private readonly array $certainty,
    ) {}

    public static function parse(string $value): self
    {
        $parsed = (new Parser)->parse($value);

        return new self($value, $parsed['components'], $parsed['certainty']);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function precision(): Precision
    {
        return match (true) {
            isset($this->components['day']) => Precision::Day,
            isset($this->components['month']) => Precision::Month,
            default => Precision::Year,
        };
    }

    public function isUncertain(): bool
    {
        foreach ($this->certainty as $mark) {
            if ($mark === '?' || $mark === '%') {
                return true;
            }
        }

        return false;
    }

    public function isApproximate(): bool
    {
        foreach ($this->certainty as $mark) {
            if ($mark === '~' || $mark === '%') {
                return true;
            }
        }

        return false;
    }

    public function min(): DateTimeImmutable
    {
        return $this->bounds()['min'];
    }

    public function max(): DateTimeImmutable
    {
        return $this->bounds()['max'];
    }

    public function bestGuess(): DateTimeImmutable
    {
        return $this->bounds()['bestGuess'];
    }

    /**
     * @return array{min: DateTimeImmutable, max: DateTimeImmutable, bestGuess: DateTimeImmutable}
     */
    private function bounds(): array
    {
        [$yearMin, $yearMax] = $this->digitRange('year', 0, 9999);

        if (isset($this->components['month'])) {
            [$monthMin, $monthMax] = $this->digitRange('month', 1, 12);
        } else {
            [$monthMin, $monthMax] = [1, 12];
        }

        if (isset($this->components['day'])) {
            [$dayMin, $dayMax] = $this->digitRange('day', 1, 31);
            $dayMin = min($dayMin, $this->lastDayOfMonth($yearMin, $monthMin));
            $dayMax = min($dayMax, $this->lastDayOfMonth($yearMax, $monthMax));
        } else {
            $dayMin = 1;
            $dayMax = $this->lastDayOfMonth($yearMax, $monthMax);
        }

        $min = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $yearMin, $monthMin, $dayMin));
        $max = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $yearMax, $monthMax, $dayMax));

        $bestGuess = match ($this->precision()) {
            Precision::Day => $min,
            Precision::Month => new DateTimeImmutable(sprintf('%04d-%02d-01', $yearMin, $monthMin)),
            Precision::Year => new DateTimeImmutable(sprintf('%04d-01-01', $yearMin)),
        };

        return ['min' => $min, 'max' => $max, 'bestGuess' => $bestGuess];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function digitRange(string $component, int $fallbackMin, int $fallbackMax): array
    {
        $digits = $this->components[$component]['digits'];

        if (! str_contains($digits, 'X')) {
            $value = (int) $digits;

            return [$value, $value];
        }

        $min = (int) str_replace('X', '0', $digits);
        $max = (int) str_replace('X', '9', $digits);

        return [max($min, $fallbackMin), min($max, $fallbackMax)];
    }

    private function lastDayOfMonth(int $year, int $month): int
    {
        return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('d');
    }

    /**
     * @return array{edtf: string, min: string, max: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'edtf' => $this->raw,
            'min' => $this->min()->format('Y-m-d'),
            'max' => $this->max()->format('Y-m-d'),
        ];
    }
}
