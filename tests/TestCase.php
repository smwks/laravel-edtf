<?php

namespace Smwks\LaravelEdtf\Tests;

use Filament\Forms\FormsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Smwks\LaravelEdtf\LaravelEdtfServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelEdtfServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            FormsServiceProvider::class,
            SchemasServiceProvider::class,
        ];
    }
}
