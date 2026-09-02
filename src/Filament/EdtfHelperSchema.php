<?php

namespace Smwks\LaravelEdtf\Filament;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;
use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;
use Smwks\LaravelEdtf\Humanizer;
use Smwks\LaravelEdtf\Parser;
use Smwks\LaravelEdtf\Precision;

class EdtfHelperSchema
{
    /** @var array<string, string> */
    public const KINDS = [
        'exact' => 'Exact date',
        'month_year' => 'Month & year',
        'year' => 'Year only',
        'decade' => 'Decade',
        'century' => 'Century / wider',
    ];

    /** @var array<string, string> */
    public const CERTAINTY = [
        '' => 'Exact',
        '~' => 'Approximate',
        '?' => 'Uncertain',
        '%' => 'Both',
    ];

    protected const YEAR_KINDS = ['exact', 'month_year', 'year'];

    protected const MARKS = ['', '~', '?', '%'];

    /**
     * @return array<int, Component>
     */
    public static function schema(?Precision $expectedPrecision = null): array
    {
        $needsYear = fn (Get $get): bool => in_array($get('kind'), self::YEAR_KINDS, true);

        $matchedKind = $expectedPrecision === null ? null : match ($expectedPrecision) {
            Precision::Year => 'year',
            Precision::Month => 'month_year',
            Precision::Day => 'exact',
        };

        $kindOptions = $matchedKind === null
            ? self::KINDS
            : [$matchedKind => self::KINDS[$matchedKind]] + self::KINDS;

        return [
            ToggleButtons::make('kind')
                ->options($kindOptions)
                ->default($matchedKind ?? 'year')
                ->inline()
                ->live()
                ->required(),

            Group::make([
                TextInput::make('year')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(9999)
                    ->live(onBlur: true)
                    ->visible($needsYear)
                    ->required($needsYear),

                TextInput::make('month')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(12)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => in_array($get('kind'), ['exact', 'month_year'], true))
                    ->required(fn (Get $get): bool => in_array($get('kind'), ['exact', 'month_year'], true)),

                TextInput::make('day')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(31)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => $get('kind') === 'exact')
                    ->required(fn (Get $get): bool => $get('kind') === 'exact'),

                Select::make('decade')
                    ->options(self::decadeOptions())
                    ->live()
                    ->visible(fn (Get $get): bool => $get('kind') === 'decade')
                    ->required(fn (Get $get): bool => $get('kind') === 'decade'),

                Select::make('century')
                    ->options(self::centuryOptions())
                    ->live()
                    ->visible(fn (Get $get): bool => $get('kind') === 'century')
                    ->required(fn (Get $get): bool => $get('kind') === 'century'),

                Select::make('certainty')
                    ->label('Certainty')
                    ->options(self::CERTAINTY)
                    ->default('')
                    ->live()
                    ->selectablePlaceholder(false)
                    ->visible(fn (Get $get): bool => ! ($get('advanced') && in_array($get('kind'), self::YEAR_KINDS, true))),

                Toggle::make('advanced')
                    ->label('Qualify individual parts')
                    ->live()
                    ->visible($needsYear),
            ])
                // Height sized so the exact-date layout (year + month + day +
                // certainty + advanced toggle) fits without the modal resizing
                // when Kind changes. Verified against the rendered modal.
                ->extraAttributes(['style' => 'min-block-size: 24rem']),

            ...self::advancedControls('year', $needsYear),
            ...self::advancedControls('month', fn (Get $get): bool => in_array($get('kind'), ['exact', 'month_year'], true)),
            ...self::advancedControls('day', fn (Get $get): bool => $get('kind') === 'exact'),

            Text::make(fn (Get $get): string => self::previewLine(
                self::assemble(self::normalizeFormData($get)),
                $expectedPrecision,
            ))
                ->fontFamily(FontFamily::Mono)
                ->color(fn (Get $get): ?string => self::assembledValueIsInvalid($get) ? 'danger' : null),
        ];
    }

    public static function previewLine(string $assembled, ?Precision $expectedPrecision): string
    {
        if ($assembled === '') {
            return 'Fill in the fields above to build a date.';
        }

        try {
            (new Parser)->parse($assembled);
        } catch (InvalidEdtfException) {
            return "{$assembled} — not a valid date";
        }

        $readable = $expectedPrecision !== null
            ? Humanizer::toApproximateReadable($assembled)
            : Humanizer::toReadable($assembled);

        return $readable === '' ? $assembled : "{$assembled} — {$readable}";
    }

    protected static function assembledValueIsInvalid(Get $get): bool
    {
        $assembled = self::assemble(self::normalizeFormData($get));

        if ($assembled === '') {
            return false;
        }

        try {
            (new Parser)->parse($assembled);
        } catch (InvalidEdtfException) {
            return true;
        }

        return false;
    }

    /**
     * The modal `Get` closure yields values one key at a time; collect the
     * keys `assemble()` reads into the array shape it expects.
     *
     * @return array<string, mixed>
     */
    protected static function normalizeFormData(Get $get): array
    {
        $keys = [
            'kind', 'year', 'month', 'day', 'decade', 'century', 'certainty', 'advanced',
            'year_certainty', 'year_unknown', 'month_certainty', 'month_unknown', 'day_certainty', 'day_unknown',
        ];

        $data = [];

        foreach ($keys as $key) {
            $data[$key] = $get($key);
        }

        return $data;
    }

    /**
     * @return array<int, Component>
     */
    protected static function advancedControls(string $name, \Closure $kindMatches): array
    {
        $visible = fn (Get $get): bool => $get('advanced') && $kindMatches($get);

        return [
            Select::make("{$name}_certainty")
                ->label(ucfirst($name).' certainty')
                ->options(self::CERTAINTY)
                ->default('')
                ->selectablePlaceholder(false)
                ->visible($visible),

            Toggle::make("{$name}_unknown")
                ->label(ucfirst($name).' is unknown')
                ->visible($visible),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function decadeOptions(): array
    {
        $options = [];

        for ($start = 1000; $start <= 2020; $start += 10) {
            $options[(string) $start] = "{$start}s";
        }

        return array_reverse($options, preserve_keys: true);
    }

    /**
     * @return array<string, string>
     */
    protected static function centuryOptions(): array
    {
        $options = [];

        for ($start = 1000; $start <= 2000; $start += 100) {
            $number = intdiv($start, 100) + 1;
            $options[(string) $start] = "{$start}s (".Humanizer::ordinal($number).' century)';
        }

        return array_reverse($options, preserve_keys: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assemble(array $data): string
    {
        $kind = $data['kind'] ?? 'year';
        $advanced = (bool) ($data['advanced'] ?? false);

        $components = self::componentDigits($kind, $data);

        if ($components === null) {
            return '';
        }

        if ($advanced && in_array($kind, self::YEAR_KINDS, true)) {
            foreach ($components as $name => $digits) {
                if ($data[$name.'_unknown'] ?? false) {
                    $components[$name] = str_repeat('X', strlen($digits));
                }

                $mark = self::normalizeMark($data[$name.'_certainty'] ?? '');

                if ($mark !== '') {
                    $components[$name] = $mark.$components[$name];
                }
            }

            return implode('-', $components);
        }

        $parts = array_values($components);
        $mark = self::normalizeMark($data['certainty'] ?? '');

        if ($mark !== '') {
            $parts[count($parts) - 1] .= $mark;
        }

        return implode('-', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>|null
     */
    protected static function componentDigits(string $kind, array $data): ?array
    {
        if ($kind === 'decade') {
            $decade = self::digitsOnly($data['decade'] ?? '');

            if (strlen($decade) < 3) {
                return null;
            }

            return ['year' => substr(str_pad($decade, 4, '0', STR_PAD_LEFT), 0, 3).'X'];
        }

        if ($kind === 'century') {
            $century = self::digitsOnly($data['century'] ?? '');

            if (strlen($century) < 2) {
                return null;
            }

            return ['year' => substr(str_pad($century, 4, '0', STR_PAD_LEFT), 0, 2).'XX'];
        }

        $year = self::digitsOnly($data['year'] ?? '');

        if ($year === '') {
            return null;
        }

        $components = ['year' => str_pad($year, 4, '0', STR_PAD_LEFT)];

        if (in_array($kind, ['exact', 'month_year'], true)) {
            $month = self::digitsOnly($data['month'] ?? '');

            if ($month === '') {
                return null;
            }

            $components['month'] = str_pad($month, 2, '0', STR_PAD_LEFT);
        }

        if ($kind === 'exact') {
            $day = self::digitsOnly($data['day'] ?? '');

            if ($day === '') {
                return null;
            }

            $components['day'] = str_pad($day, 2, '0', STR_PAD_LEFT);
        }

        return $components;
    }

    /**
     * @return array<string, mixed>
     */
    public static function preFill(mixed $current): array
    {
        $defaults = [
            'kind' => 'year',
            'year' => null,
            'month' => null,
            'day' => null,
            'decade' => null,
            'century' => null,
            'certainty' => '',
            'advanced' => false,
            'year_certainty' => '',
            'year_unknown' => false,
            'month_certainty' => '',
            'month_unknown' => false,
            'day_certainty' => '',
            'day_unknown' => false,
        ];

        if (! is_string($current) || trim($current) === '') {
            return $defaults;
        }

        try {
            $parsed = (new Parser)->parse(trim($current));
        } catch (InvalidEdtfException) {
            return $defaults;
        }

        $components = $parsed['components'];
        $certainty = $parsed['certainty'];
        $yearDigits = $components['year']['digits'];
        $hasMonth = isset($components['month']);
        $hasDay = isset($components['day']);

        $kind = match (true) {
            ! $hasMonth && ! $hasDay && str_ends_with($yearDigits, 'XX') && ! str_contains(substr($yearDigits, 0, 2), 'X') => 'century',
            ! $hasMonth && ! $hasDay && str_ends_with($yearDigits, 'X') && substr_count($yearDigits, 'X') === 1 => 'decade',
            $hasDay => 'exact',
            $hasMonth => 'month_year',
            default => 'year',
        };

        $result = $defaults;
        $result['kind'] = $kind;

        if ($kind === 'century') {
            $result['century'] = substr($yearDigits, 0, 2).'00';
        } elseif ($kind === 'decade') {
            $result['decade'] = substr($yearDigits, 0, 3).'0';
        } else {
            $result['year'] = $yearDigits;

            if ($hasMonth) {
                $result['month'] = (string) (int) $components['month']['digits'];
            }

            if ($hasDay) {
                $result['day'] = (string) (int) $components['day']['digits'];
            }
        }

        self::applyCertainty($result, $components, $certainty, $kind);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array{digits: string, individual: ?string, group: ?string}>  $components
     * @param  array<string, ?string>  $certainty
     */
    protected static function applyCertainty(array &$result, array $components, array $certainty, string $kind): void
    {
        $marks = array_values($certainty);
        $set = array_values(array_filter($marks, fn (?string $mark): bool => $mark !== null && $mark !== ''));

        $unknownBeyondKind = false;

        foreach ($components as $component) {
            if (str_contains($component['digits'], 'X')) {
                $unknownBeyondKind = true;
            }
        }

        if (in_array($kind, ['decade', 'century'], true)) {
            $unknownBeyondKind = false;
        }

        $uniform = count($set) === count($marks) && count(array_unique($set)) === 1;

        if (! $unknownBeyondKind && ($set === [] || $uniform)) {
            $result['certainty'] = $set[0] ?? '';
            $result['advanced'] = false;

            return;
        }

        $result['advanced'] = true;

        foreach (['year', 'month', 'day'] as $name) {
            if (! isset($components[$name])) {
                continue;
            }

            $result[$name.'_certainty'] = $certainty[$name] ?? '';
            $result[$name.'_unknown'] = str_contains($components[$name]['digits'], 'X');
        }
    }

    protected static function digitsOnly(mixed $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    protected static function normalizeMark(mixed $mark): string
    {
        return in_array($mark, self::MARKS, true) ? (string) $mark : '';
    }
}
