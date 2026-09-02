<?php

namespace Smwks\LaravelEdtf\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Text;
use Smwks\LaravelEdtf\Edtf;
use Smwks\LaravelEdtf\EdtfGuesser;
use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;
use Smwks\LaravelEdtf\Humanizer;
use Smwks\LaravelEdtf\Parser;
use Smwks\LaravelEdtf\Precision;
use Smwks\LaravelEdtf\Rules\EdtfRule;

class EdtfDatePicker extends TextInput
{
    protected ?Precision $expectedPrecision = null;

    public function expectedPrecision(Precision|string|null $precision): static
    {
        $this->expectedPrecision = is_string($precision) ? Precision::from($precision) : $precision;

        return $this;
    }

    public function getExpectedPrecision(): ?Precision
    {
        return $this->expectedPrecision;
    }

    public function currentGuess(): ?string
    {
        $state = $this->getState();

        return is_string($state) ? EdtfGuesser::guess($state) : null;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->live(debounce: 500)
            ->rule(new EdtfRule)
            ->placeholder('e.g. 1926, 1937-11, 192X, 1926~')
            ->belowContent(fn (?string $state): array => $this->belowContentComponents($state))
            ->suffixAction($this->helperAction());

        $this->afterStateHydrated(static function (EdtfDatePicker $component): void {
            $component->hydrateFromStoredValue();
        });
    }

    /**
     * Feedback rendered under the input: the humanized reading for a value
     * that parses, or a "not a supported format" line plus a one-click
     * "Use …" suggestion for one that does not but can be coerced. Driven by
     * a live parse of the current value, so it survives the modal being
     * opened and closed (which resets Filament's own error bag).
     *
     * @return array<int, Component>
     */
    protected function belowContentComponents(?string $state): array
    {
        if ($state === null || trim($state) === '') {
            return [];
        }

        if ($this->isValidEdtf($state)) {
            $readable = $this->getExpectedPrecision() !== null
                ? Humanizer::toApproximateReadable($state)
                : Humanizer::toReadable($state);

            return $readable === '' ? [] : [Text::make($readable)];
        }

        $row = [Text::make('Not a supported date format.')->color('danger')];

        if (($guess = EdtfGuesser::guess($state)) !== null) {
            $row[] = Action::make('applyEdtfSuggestion')
                ->link()
                ->label('Use "'.$guess.'"')
                ->action(function () use ($guess): void {
                    $this->state($guess);
                });
        }

        return [Flex::make($row)];
    }

    protected function isValidEdtf(string $value): bool
    {
        try {
            (new Parser)->parse($value);
        } catch (InvalidEdtfException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, ?string>
     */
    public function getStateToDehydrate(mixed $state): array
    {
        $statePath = $this->getStatePath();
        $value = is_string($state) ? trim($state) : null;

        if ($value === null || $value === '') {
            return [
                $statePath => null,
                "{$statePath}_edtf" => null,
            ];
        }

        $edtf = Edtf::parse($value);

        return [
            $statePath => $edtf->bestGuess()->format('Y-m-d'),
            "{$statePath}_edtf" => $edtf->raw(),
        ];
    }

    protected function hydrateFromStoredValue(): void
    {
        $edtfString = $this->resolveStoredEdtfString();

        if ($edtfString !== null) {
            $this->state($edtfString);
        }
    }

    protected function resolveStoredEdtfString(): ?string
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

        $rawState = $this->getRawState();

        if (is_string($rawState) && $rawState !== '') {
            return substr($rawState, 0, 10);
        }

        return null;
    }

    protected function helperAction(): Action
    {
        return Action::make('edtfHelper')
            ->label('Date helper')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Build a date')
            ->modalSubmitActionLabel('Apply')
            ->fillForm(fn (EdtfDatePicker $component): array => EdtfHelperSchema::preFill($component->currentGuess() ?? $component->getState()))
            ->schema(fn (): array => EdtfHelperSchema::schema($this->getExpectedPrecision()))
            ->action(function (array $data, EdtfDatePicker $component): void {
                $assembled = EdtfHelperSchema::assemble($data);

                if ($assembled !== '') {
                    $component->state($assembled);
                }
            });
    }
}
