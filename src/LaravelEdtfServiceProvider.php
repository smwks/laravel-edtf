<?php

namespace Smwks\LaravelEdtf;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class LaravelEdtfServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blueprint::macro('edtf', function (string $name) {
            /** @var Blueprint $this */
            $this->date($name)->nullable();
            $this->json("{$name}_edtf")->nullable();
        });
    }
}
