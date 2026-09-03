<?php

namespace Smwks\LaravelEdtf\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Smwks\LaravelEdtf\Edtf;

/**
 * @implements CastsAttributes<?Edtf, Edtf|string|null>
 */
class AsEdtf implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Edtf
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return Edtf::parse($decoded['edtf']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $edtf = $value instanceof Edtf ? $value : Edtf::parse($value);

        return json_encode($edtf);
    }

    /**
     * Serialize the cast value for `attributesToArray()` / `toArray()` so it
     * is a plain, JSON-safe structure rather than an `Edtf` object, which
     * Livewire cannot hold in component state.
     *
     * @return array{edtf: string, min: string, max: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value instanceof Edtf ? $value->jsonSerialize() : null;
    }
}
