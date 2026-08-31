<?php

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DbSchema;
use Livewire\Component as LivewireComponent;
use Smwks\LaravelEdtf\Casts\AsEdtf;
use Smwks\LaravelEdtf\Filament\EdtfDatePicker;

class EdtfFilamentTestModel extends Model
{
    protected $table = 'edtf_filament_test_models';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'born_on' => 'date',
            'born_on_edtf' => AsEdtf::class,
        ];
    }
}

class EdtfFilamentTestLivewireComponent extends LivewireComponent implements HasSchemas
{
    use InteractsWithSchemas;

    public array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([EdtfDatePicker::make('born_on')])
            ->statePath('data');
    }
}

beforeEach(function () {
    DbSchema::create('edtf_filament_test_models', function (Blueprint $table) {
        $table->id();
        $table->edtf('born_on');
    });
});

afterEach(function () {
    DbSchema::dropIfExists('edtf_filament_test_models');
});

test('buildEdtfString builds an EDTF string for each precision', function (array $state, string $expected) {
    $field = EdtfDatePicker::make('born_on');

    $result = (new ReflectionMethod($field, 'buildEdtfString'))->invoke($field, $state);

    expect($result)->toBe($expected);
})->with([
    'year precision' => [['precision' => 'year', 'year' => 1926, 'month' => null, 'day' => null], '1926'],
    'month precision' => [['precision' => 'month', 'year' => 1937, 'month' => 11, 'day' => null], '1937-11'],
    'day precision' => [['precision' => 'day', 'year' => 1937, 'month' => 11, 'day' => 25], '1937-11-25'],
]);

test('the field registers real child fields inside a mounted schema', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $fieldNames = collect($schema->getFlatFields(withHidden: true))
        ->map(fn ($field) => $field->getStatePath())
        ->values()
        ->all();

    expect($fieldNames)->toBe([
        'data.born_on',
        'data.born_on.precision',
        'data.born_on.year',
        'data.born_on.month',
        'data.born_on.day',
    ]);
});

test('the field renders without a fatal error and contains its child inputs', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $html = $schema->toHtml();

    expect($html)->toContain('born_on.precision');
    expect($html)->toContain('born_on.year');
    expect($html)->toContain('born_on.day');
});

test('the field dehydrates both columns through the real Filament dehydration pipeline', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $livewire->data = [
        'born_on' => [
            'precision' => 'month',
            'year' => 1937,
            'month' => 11,
            'day' => null,
        ],
    ];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBe('1937-11-01');
    expect($state['data']['born_on_edtf'])->toBe('1937-11');
});

test('an empty field dehydrates null to both columns instead of a garbage date', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $livewire->data = ['born_on' => ['precision' => 'day']];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data'])->toHaveKey('born_on');
    expect($state['data']['born_on'])->toBeNull();
    expect($state['data']['born_on_edtf'])->toBeNull();
});

test('hydrating from a real saved record expands both columns into the sub-fields', function (string $edtfValue, array $expected) {
    $record = EdtfFilamentTestModel::create(['born_on_edtf' => $edtfValue]);
    $record->born_on = $record->born_on_edtf->bestGuess()->format('Y-m-d');
    $record->save();

    $fresh = EdtfFilamentTestModel::find($record->id);

    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $schema->fill($fresh->attributesToArray());

    expect($livewire->data['born_on'])->toBe($expected);
})->with([
    'year only' => ['1926', ['precision' => 'year', 'year' => '1926', 'month' => null, 'day' => null]],
    'year and month' => ['1937-11', ['precision' => 'month', 'year' => '1937', 'month' => '11', 'day' => null]],
    'full date' => ['1937-11-25', ['precision' => 'day', 'year' => '1937', 'month' => '11', 'day' => '25']],
    'unspecified decade' => ['192X', ['precision' => 'year', 'year' => '192X', 'month' => null, 'day' => null]],
]);

test('a record hydrated from the database re-dehydrates back to the same stored columns', function (string $edtfValue, string $expectedDate) {
    $record = EdtfFilamentTestModel::create(['born_on_edtf' => $edtfValue]);
    $record->born_on = $record->born_on_edtf->bestGuess()->format('Y-m-d');
    $record->save();

    $fresh = EdtfFilamentTestModel::find($record->id);

    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $schema->fill($fresh->attributesToArray());

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBe($expectedDate);
    expect($state['data']['born_on_edtf'])->toBe($edtfValue);

    $fresh->update($state['data']);
    $reloaded = EdtfFilamentTestModel::find($record->id);

    expect($reloaded->born_on->format('Y-m-d'))->toBe($expectedDate);
    expect($reloaded->born_on_edtf->raw())->toBe($edtfValue);
})->with([
    'year only' => ['1926', '1926-01-01'],
    'year and month' => ['1937-11', '1937-11-01'],
    'full date' => ['1937-11-25', '1937-11-25'],
]);

test('hydrating a record with no stored date leaves the field empty', function () {
    $record = EdtfFilamentTestModel::create([]);

    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $schema->fill($record->fresh()->attributesToArray());

    expect(data_get($livewire->data, 'born_on.year'))->toBeNull();

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBeNull();
    expect($state['data']['born_on_edtf'])->toBeNull();
});

test('saving through the model round-trips correctly via the dehydrated schema state', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $livewire->data = [
        'born_on' => [
            'precision' => 'month',
            'year' => 1937,
            'month' => 11,
            'day' => null,
        ],
    ];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    $model = EdtfFilamentTestModel::create($state['data']);
    $fresh = EdtfFilamentTestModel::find($model->id);

    expect($fresh->born_on->format('Y-m-d'))->toBe('1937-11-01');
    expect($fresh->born_on_edtf->raw())->toBe('1937-11');
});
