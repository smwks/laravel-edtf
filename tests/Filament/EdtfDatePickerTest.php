<?php

use Filament\Actions\Action;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use Smwks\LaravelEdtf\Casts\AsEdtf;
use Smwks\LaravelEdtf\Filament\EdtfDatePicker;
use Smwks\LaravelEdtf\Filament\EdtfHelperSchema;
use Smwks\LaravelEdtf\Precision;

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

    public array $modal = [];

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

function mountedEdtfSchema(): Schema
{
    return (new EdtfFilamentTestLivewireComponent)->getSchema('form');
}

function edtfBelowContent(EdtfDatePicker $field): array
{
    return $field->getChildSchema(EdtfDatePicker::BELOW_CONTENT_SCHEMA_KEY)
        ->getFlatComponents(withActions: true, withHidden: true);
}

function edtfSuggestionAction(EdtfDatePicker $field): ?Action
{
    return collect(edtfBelowContent($field))
        ->first(fn ($component) => $component instanceof Action
            && $component->getName() === 'applyEdtfSuggestion');
}

function edtfBelowContentText(EdtfDatePicker $field): array
{
    return collect(edtfBelowContent($field))
        ->filter(fn ($component) => $component instanceof Text)
        ->map(fn ($text) => (string) $text->getContent())
        ->values()
        ->all();
}

test('the field is a single leaf input, not a container of sub-fields', function () {
    $schema = mountedEdtfSchema();

    $paths = collect($schema->getFlatFields(withHidden: true))
        ->map(fn ($field) => $field->getStatePath())
        ->values()
        ->all();

    expect($paths)->toBe(['data.born_on']);
});

test('a plain fully-specified value dehydrates to both columns', function (string $input, string $date, string $edtf) {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $livewire->data = ['born_on' => $input];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBe($date);
    expect($state['data']['born_on_edtf'])->toBe($edtf);
})->with([
    'year only' => ['1926', '1926-01-01', '1926'],
    'year and month' => ['1937-11', '1937-11-01', '1937-11'],
    'full date' => ['1937-11-25', '1937-11-25', '1937-11-25'],
]);

test('a fuzzy value dehydrates its best-guess date and its raw EDTF', function (string $input, string $date, string $edtf) {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $livewire->data = ['born_on' => $input];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBe($date);
    expect($state['data']['born_on_edtf'])->toBe($edtf);
})->with([
    'uncertain year' => ['1984?', '1984-01-01', '1984?'],
    'approximate year' => ['1926~', '1926-01-01', '1926~'],
    'unspecified decade' => ['192X', '1920-01-01', '192X'],
    'per-component qualifiers' => ['?2004-06-~11', '2004-06-11', '?2004-06-~11'],
]);

test('an empty field dehydrates null to both columns', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $livewire->data = ['born_on' => ''];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data'])->toHaveKey('born_on');
    expect($state['data']['born_on'])->toBeNull();
    expect($state['data']['born_on_edtf'])->toBeNull();
});

test('an empty field passes validation because the date is optional', function () {
    $schema = mountedEdtfSchema();
    $schema->fill(['born_on' => '']);

    expect($schema->validate())->toBeArray();
});

test('a malformed or out-of-scope value fails validation', function (string $input) {
    $schema = mountedEdtfSchema();
    $schema->fill(['born_on' => $input]);

    expect(fn () => $schema->validate())
        ->toThrow(ValidationException::class);
})->with(['1964/2008', '[1667,1668]', '2005-02-29', 'garbage']);

test('hydrating from a saved record shows the raw EDTF string in the box', function (string $edtf) {
    $record = EdtfFilamentTestModel::create(['born_on_edtf' => $edtf]);
    $record->born_on = $record->born_on_edtf->bestGuess()->format('Y-m-d');
    $record->save();

    $fresh = EdtfFilamentTestModel::find($record->id);

    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $schema->fill($fresh->attributesToArray());

    expect($livewire->data['born_on'])->toBe($edtf);
})->with(['1926', '1937-11', '1937-11-25', '1926~', '192X']);

test('a hydrated record re-dehydrates to the same stored columns', function (string $edtf, string $date) {
    $record = EdtfFilamentTestModel::create(['born_on_edtf' => $edtf]);
    $record->born_on = $record->born_on_edtf->bestGuess()->format('Y-m-d');
    $record->save();

    $fresh = EdtfFilamentTestModel::find($record->id);

    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $schema->fill($fresh->attributesToArray());

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBe($date);
    expect($state['data']['born_on_edtf'])->toBe($edtf);

    $fresh->update($state['data']);
    $reloaded = EdtfFilamentTestModel::find($record->id);

    expect($reloaded->born_on->format('Y-m-d'))->toBe($date);
    expect($reloaded->born_on_edtf->raw())->toBe($edtf);
})->with([
    'year only' => ['1926', '1926-01-01'],
    'year and month' => ['1937-11', '1937-11-01'],
    'full date' => ['1937-11-25', '1937-11-25'],
    'uncertain year' => ['1984?', '1984-01-01'],
    'unspecified decade' => ['192X', '1920-01-01'],
]);

test('hydrating a record with no stored date leaves the field empty', function () {
    $record = EdtfFilamentTestModel::create([]);

    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $schema->fill($record->fresh()->attributesToArray());

    expect(data_get($livewire->data, 'born_on'))->toBeNull();

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    expect($state['data']['born_on'])->toBeNull();
    expect($state['data']['born_on_edtf'])->toBeNull();
});

test('saving through the model round-trips a fuzzy value', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');
    $livewire->data = ['born_on' => '1984?'];

    $state = ['data' => $livewire->data];
    $schema->dehydrateState($state);

    $model = EdtfFilamentTestModel::create($state['data']);
    $fresh = EdtfFilamentTestModel::find($model->id);

    expect($fresh->born_on->format('Y-m-d'))->toBe('1984-01-01');
    expect($fresh->born_on_edtf->raw())->toBe('1984?');
});

test('the field exposes a suffix action that opens the helper modal', function () {
    $field = EdtfDatePicker::make('born_on');

    $actions = collect($field->getSuffixActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($actions)->toContain('edtfHelper');
});

test('the helper assembles a qualified value from modal data', function () {
    $data = EdtfHelperSchema::preFill(null);
    $data['kind'] = 'year';
    $data['year'] = '1926';
    $data['certainty'] = '~';

    expect(EdtfHelperSchema::assemble($data))->toBe('1926~');
});

test('invoking the helper action writes the assembled value into the field state', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    expect($field)->toBeInstanceOf(EdtfDatePicker::class);

    $action = collect($field->getSuffixActions())
        ->first(fn ($suffixAction) => $suffixAction->getName() === 'edtfHelper');

    $action->call([
        'data' => ['kind' => 'year', 'year' => 1926, 'certainty' => '~'],
        'component' => $field,
    ]);

    expect(data_get($livewire->data, 'born_on'))->toBe('1926~');
});

test('expectedPrecision stores the precision from an enum or a string', function () {
    $fromEnum = EdtfDatePicker::make('born_on')->expectedPrecision(Precision::Year);
    $fromString = EdtfDatePicker::make('born_on')->expectedPrecision('day');

    expect($fromEnum->getExpectedPrecision())->toBe(Precision::Year);
    expect($fromString->getExpectedPrecision())->toBe(Precision::Day);
    expect(EdtfDatePicker::make('born_on')->getExpectedPrecision())->toBeNull();
});

test('currentGuess returns a coerced value for rescuable input and null otherwise', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('1940/01/12');
    expect($field->currentGuess())->toBe('1940-01-12');

    $field->state('1926');
    expect($field->currentGuess())->toBeNull();

    $field->state('total garbage');
    expect($field->currentGuess())->toBeNull();
});

test('the helper modal schema reached through the lazy closure honours the field expected precision', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->expectedPrecision(Precision::Month);

    $action = collect($field->getSuffixActions())
        ->first(fn ($suffixAction) => $suffixAction->getName() === 'edtfHelper');

    $resolvedSchema = $action->getSchema(Schema::make($livewire));

    $kind = collect($resolvedSchema->getComponents(withHidden: true))
        ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'kind');

    expect(array_key_first($kind->getOptions()))->toBe('month_year');
});

test('the below-content suggestion applies the guess to the field', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('Jan 12, 1940');

    $action = edtfSuggestionAction($field);

    expect($action)->not->toBeNull()
        ->and($action->getLabel())->toBe('Use "1940-01-12"');

    $action->call();

    expect(data_get($livewire->data, 'born_on'))->toBe('1940-01-12');
});

test('the below-content feedback shows a danger message and a suggestion for an invalid guessable value', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('5/8/22');

    expect(edtfBelowContentText($field))->toContain('Not a supported date format.')
        ->and(edtfSuggestionAction($field)?->getLabel())->toBe('Use "2022-05-08"');
});

test('the below-content feedback shows the danger message with no suggestion for an unguessable value', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('garblewarble');

    expect(edtfBelowContentText($field))->toContain('Not a supported date format.')
        ->and(edtfSuggestionAction($field))->toBeNull();
});

test('the below-content feedback shows the humanized reading for a valid value', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('1937-11-25');

    expect(edtfBelowContentText($field))->toBe(['25 November 1937'])
        ->and(edtfSuggestionAction($field))->toBeNull();
});

test('the below-content feedback is empty for an empty value', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('');

    expect($field->getChildSchema(EdtfDatePicker::BELOW_CONTENT_SCHEMA_KEY))->toBeNull();
});

function fillEdtfHelperModal(EdtfDatePicker $field, EdtfFilamentTestLivewireComponent $livewire): array
{
    $helper = collect($field->getSuffixActions())
        ->first(fn ($suffixAction) => $suffixAction->getName() === 'edtfHelper');

    $modalSchema = $helper->getSchema(Schema::make($livewire)->statePath('modal'));
    ($helper->getMountUsing())($helper, $modalSchema);

    return $livewire->modal;
}

test('opening the helper with an invalid but guessable value pre-fills the modal from the guess', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('5/5/25');

    $modal = fillEdtfHelperModal($field, $livewire);

    expect($modal['kind'])->toBe('exact')
        ->and((int) $modal['year'])->toBe(2025)
        ->and((int) $modal['month'])->toBe(5)
        ->and((int) $modal['day'])->toBe(5);
});

test('opening the helper with a valid value still pre-fills from the value itself', function () {
    $livewire = new EdtfFilamentTestLivewireComponent;
    $schema = $livewire->getSchema('form');

    $field = collect($schema->getFlatFields(withHidden: true))
        ->first(fn ($flatField) => $flatField instanceof EdtfDatePicker);

    $field->state('1926~');

    $modal = fillEdtfHelperModal($field, $livewire);

    expect($modal['kind'])->toBe('year')
        ->and((int) $modal['year'])->toBe(1926)
        ->and($modal['certainty'])->toBe('~');
});
