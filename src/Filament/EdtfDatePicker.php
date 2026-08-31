<?php

namespace Smwks\LaravelEdtf\Filament;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Concerns\CanBeContained;
use Smwks\LaravelEdtf\Edtf;
use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;
use Smwks\LaravelEdtf\Parser;

class EdtfDatePicker extends Field
{
    use CanBeContained;

    protected string $view = 'filament-schemas::components.fieldset';

    public static function make(?string $name = null): static
    {
        $static = parent::make($name);

        $static->schema([
            Select::make('precision')
                ->options(['year' => 'Year', 'month' => 'Month', 'day' => 'Day'])
                ->default('day')
                ->live()
                ->required(),
            TextInput::make('year')
                ->numeric()
                ->required(),
            TextInput::make('month')
                ->numeric()
                ->visible(fn (callable $get) => in_array($get('precision'), ['month', 'day'], true)),
            TextInput::make('day')
                ->numeric()
                ->visible(fn (callable $get) => $get('precision') === 'day'),
        ]);

        $static->afterStateHydrated(static function (EdtfDatePicker $component): void {
            $component->hydrateSubState();
        });

        return $static;
    }

    /**
     * Fans this field's own state out to two dehydrated state-path keys:
     * the plain date column and the `_edtf` column, given the field's own
     * state path as the base for both. An empty field dehydrates null to
     * both, matching the nullable columns the `edtf()` macro creates.
     *
     * @param  mixed  $state  the field's own `array{precision?: ?string, year?: mixed, month?: mixed, day?: mixed}` sub-state, or null when the field is empty
     * @return array<string, ?string>
     */
    public function getStateToDehydrate(mixed $state): array
    {
        $statePath = $this->getStatePath();
        $edtfString = is_array($state) ? $this->buildEdtfString($state) : null;

        if ($edtfString === null) {
            return [
                $statePath => null,
                "{$statePath}_edtf" => null,
            ];
        }

        $edtf = Edtf::parse($edtfString);

        return [
            $statePath => $edtf->bestGuess()->format('Y-m-d'),
            "{$statePath}_edtf" => $edtf->raw(),
        ];
    }

    /**
     * Expands a saved record's `{name}_edtf` (or, failing that, its plain
     * `{name}` date) back into the precision/year/month/day sub-state this
     * field's own schema expects. Filament's default hydration only puts the
     * raw column values on the state path, which the sub-fields cannot read.
     */
    private function hydrateSubState(): void
    {
        $rawState = $this->getRawState();

        if (is_array($rawState) && (array_key_exists('precision', $rawState) || array_key_exists('year', $rawState))) {
            return;
        }

        $edtfString = $this->resolveStoredEdtfString($rawState);

        if ($edtfString === null) {
            return;
        }

        try {
            $components = (new Parser)->parse($edtfString)['components'];
        } catch (InvalidEdtfException) {
            return;
        }

        $this->state([
            'precision' => match (true) {
                isset($components['day']) => 'day',
                isset($components['month']) => 'month',
                default => 'year',
            },
            'year' => $components['year']['digits'],
            'month' => $components['month']['digits'] ?? null,
            'day' => $components['day']['digits'] ?? null,
        ]);
    }

    private function resolveStoredEdtfString(mixed $rawState): ?string
    {
        $stored = data_get($this->getLivewire(), $this->getStatePath().'_edtf');

        if ($stored instanceof Edtf) {
            return $stored->raw();
        }

        if (is_array($stored) && isset($stored['edtf']) && is_string($stored['edtf'])) {
            return $stored['edtf'];
        }

        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);

            if (is_array($decoded) && isset($decoded['edtf']) && is_string($decoded['edtf'])) {
                return $decoded['edtf'];
            }

            return $stored;
        }

        if (is_string($rawState) && $rawState !== '') {
            return substr($rawState, 0, 10);
        }

        return null;
    }

    /**
     * @param  array{precision?: ?string, year?: mixed, month?: mixed, day?: mixed}  $state
     */
    private function buildEdtfString(array $state): ?string
    {
        $rawYear = $state['year'] ?? null;

        if ($rawYear === null || $rawYear === '') {
            return null;
        }

        $precision = $state['precision'] ?? null;
        $year = str_pad((string) $rawYear, 4, '0', STR_PAD_LEFT);
        $rawMonth = $state['month'] ?? null;

        if ($precision === 'year' || $rawMonth === null || $rawMonth === '') {
            return $year;
        }

        $month = str_pad((string) $rawMonth, 2, '0', STR_PAD_LEFT);
        $rawDay = $state['day'] ?? null;

        if ($precision === 'month' || $rawDay === null || $rawDay === '') {
            return "{$year}-{$month}";
        }

        $day = str_pad((string) $rawDay, 2, '0', STR_PAD_LEFT);

        return "{$year}-{$month}-{$day}";
    }
}
