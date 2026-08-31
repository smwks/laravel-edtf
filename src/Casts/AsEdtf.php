<?php

namespace Smwks\LaravelEdtf\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Smwks\LaravelEdtf\Edtf;

/**
 * @implements CastsAttributes<?Edtf, Edtf|string|null>
 */
class AsEdtf implements CastsAttributes
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
}
