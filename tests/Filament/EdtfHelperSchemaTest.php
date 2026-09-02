<?php

use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Smwks\LaravelEdtf\Filament\EdtfHelperSchema;
use Smwks\LaravelEdtf\Parser;
use Smwks\LaravelEdtf\Precision;

test('assemble builds the expected EDTF string', function (array $data, string $expected) {
    expect(EdtfHelperSchema::assemble($data))->toBe($expected);
})->with([
    'year only' => [['kind' => 'year', 'year' => '1926', 'certainty' => ''], '1926'],
    'year, int input, padded' => [['kind' => 'year', 'year' => 926, 'certainty' => ''], '0926'],
    'approximate year' => [['kind' => 'year', 'year' => 1926, 'certainty' => '~'], '1926~'],
    'month and year' => [['kind' => 'month_year', 'year' => '1937', 'month' => '2', 'certainty' => ''], '1937-02'],
    'exact date, padded' => [['kind' => 'exact', 'year' => '1937', 'month' => '11', 'day' => '5', 'certainty' => ''], '1937-11-05'],
    'exact date, uncertain group' => [['kind' => 'exact', 'year' => '1937', 'month' => '11', 'day' => '25', 'certainty' => '?'], '1937-11-25?'],
    'decade' => [['kind' => 'decade', 'decade' => '1920', 'certainty' => ''], '192X'],
    'decade approximate' => [['kind' => 'decade', 'decade' => '1920', 'certainty' => '~'], '192X~'],
    'century' => [['kind' => 'century', 'century' => '1900', 'certainty' => ''], '19XX'],
    'advanced per-component qualifiers' => [[
        'kind' => 'exact', 'year' => '2004', 'month' => '6', 'day' => '11',
        'advanced' => true, 'year_certainty' => '?', 'day_certainty' => '~',
    ], '?2004-06-~11'],
    'advanced unknown day' => [[
        'kind' => 'exact', 'year' => '1937', 'month' => '6', 'day' => '1',
        'advanced' => true, 'day_unknown' => true,
    ], '1937-06-XX'],
]);

test('assemble returns an empty string when a required field is missing', function (array $data) {
    expect(EdtfHelperSchema::assemble($data))->toBe('');
})->with([
    'no year' => [['kind' => 'year', 'year' => '', 'certainty' => '']],
    'no month' => [['kind' => 'month_year', 'year' => '1937', 'month' => '', 'certainty' => '']],
    'no day' => [['kind' => 'exact', 'year' => '1937', 'month' => '11', 'day' => '', 'certainty' => '']],
    'no decade' => [['kind' => 'decade', 'decade' => '', 'certainty' => '']],
]);

test('every non-empty assemble result parses through the Parser', function (array $data) {
    $result = EdtfHelperSchema::assemble($data);

    expect($result)->not->toBe('');
    expect(fn () => (new Parser)->parse($result))->not->toThrow(Exception::class);
})->with([
    [['kind' => 'year', 'year' => '1926', 'certainty' => '~']],
    [['kind' => 'exact', 'year' => '1937', 'month' => '6', 'day' => '1', 'advanced' => true, 'day_unknown' => true]],
    [['kind' => 'exact', 'year' => '2004', 'month' => '6', 'day' => '11', 'advanced' => true, 'year_certainty' => '?', 'day_certainty' => '~']],
    [['kind' => 'century', 'century' => '1900', 'certainty' => '']],
]);

test('preFill returns kind-year defaults for empty or unparseable input', function (mixed $value) {
    $result = EdtfHelperSchema::preFill($value);

    expect($result['kind'])->toBe('year');
    expect($result['year'])->toBeNull();
    expect($result['certainty'])->toBe('');
    expect($result['advanced'])->toBeFalse();
})->with([null, '', '   ', 'garbage', '1964/2008']);

test('preFill maps an EDTF string back to modal form data', function (string $edtf, array $expectedSubset) {
    $result = EdtfHelperSchema::preFill($edtf);

    foreach ($expectedSubset as $key => $value) {
        expect($result[$key])->toBe($value);
    }
})->with([
    'year only' => ['1926', ['kind' => 'year', 'year' => '1926', 'certainty' => '', 'advanced' => false]],
    'month and year' => ['1937-11', ['kind' => 'month_year', 'year' => '1937', 'month' => '11']],
    'full date' => ['1937-11-25', ['kind' => 'exact', 'year' => '1937', 'month' => '11', 'day' => '25']],
    'decade' => ['192X', ['kind' => 'decade', 'decade' => '1920', 'advanced' => false, 'certainty' => '']],
    'century' => ['19XX', ['kind' => 'century', 'century' => '1900', 'advanced' => false]],
    'approximate year' => ['1926~', ['kind' => 'year', 'certainty' => '~', 'advanced' => false]],
    'uncertain month and year' => ['1926-02?', ['kind' => 'month_year', 'certainty' => '?', 'advanced' => false]],
    'per-component' => ['?2004-06-~11', ['kind' => 'exact', 'advanced' => true, 'year_certainty' => '?', 'day_certainty' => '~']],
]);

test('assemble and preFill round-trip the common shapes', function (string $edtf) {
    expect(EdtfHelperSchema::assemble(EdtfHelperSchema::preFill($edtf)))->toBe($edtf);
})->with(['1926', '1937-11', '1937-11-25', '192X', '19XX', '1926~', '1926-02?']);

test('schema is a pill Kind control, a min-height group of date inputs, and a preview last', function () {
    $schema = EdtfHelperSchema::schema();

    expect($schema)->toBeArray()->not->toBeEmpty();

    foreach ($schema as $component) {
        expect($component)->toBeInstanceOf(Component::class);
    }

    expect($schema[0])->toBeInstanceOf(ToggleButtons::class);
    expect($schema[0]->getName())->toBe('kind');

    $group = collect($schema)->first(fn ($c) => $c instanceof Group);
    expect($group)->not->toBeNull();
    expect($group->getExtraAttributes())->toHaveKey('style');

    $topLevelNames = collect($schema)
        ->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null)
        ->filter()
        ->all();
    expect($topLevelNames)->not->toContain('certainty');
    expect($topLevelNames)->not->toContain('advanced');

    expect(end($schema))->toBeInstanceOf(Text::class);
});

test('an expected precision fronts and defaults the matching Kind', function (Precision $precision, string $expectedFirstKey) {
    $schema = EdtfHelperSchema::schema($precision);
    $kind = $schema[0];

    expect(array_key_first($kind->getOptions()))->toBe($expectedFirstKey);
    expect($kind->getDefaultState())->toBe($expectedFirstKey);
    expect($kind->getOptions())->toHaveKeys(array_keys(EdtfHelperSchema::KINDS));
})->with([
    'year' => [Precision::Year, 'year'],
    'month' => [Precision::Month, 'month_year'],
    'day' => [Precision::Day, 'exact'],
]);

test('previewLine uses plain phrasing with no precision and "sometime" phrasing with one', function () {
    expect(EdtfHelperSchema::previewLine('', null))->toBe('Fill in the fields above to build a date.');
    expect(EdtfHelperSchema::previewLine('1937-02-31', null))->toBe('1937-02-31 — not a valid date');
    expect(EdtfHelperSchema::previewLine('1926', null))->toBe('1926 — 1926');
    expect(EdtfHelperSchema::previewLine('1926', Precision::Year))->toBe('1926 — sometime during 1926');
    expect(EdtfHelperSchema::previewLine('1926-02', Precision::Month))->toBe('1926-02 — sometime in February 1926');
});
