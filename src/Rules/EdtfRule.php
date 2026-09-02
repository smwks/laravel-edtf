<?php

namespace Smwks\LaravelEdtf\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;
use Smwks\LaravelEdtf\Parser;

class EdtfRule implements ValidationRule
{
    protected bool $verbose = false;

    public function verbose(bool $verbose = true): static
    {
        $this->verbose = $verbose;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute is not a supported date format.');

            return;
        }

        try {
            (new Parser)->parse($value);
        } catch (InvalidEdtfException $exception) {
            $fail($this->verbose
                ? $exception->getMessage()
                : 'The :attribute is not a supported date format.');
        }
    }
}
