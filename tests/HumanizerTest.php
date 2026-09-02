<?php

use Smwks\LaravelEdtf\Edtf;
use Smwks\LaravelEdtf\Humanizer;

test('it renders each in-scope shape', function (string $edtf, string $expected) {
    expect(Humanizer::toReadable($edtf))->toBe($expected);
})->with([
    'year only' => ['1926', '1926'],
    'year and month' => ['1926-02', 'February 1926'],
    'full date' => ['1937-11-25', '25 November 1937'],
    'approximate year' => ['1926~', 'circa 1926'],
    'uncertain year' => ['1926?', '1926 (uncertain)'],
    'both year' => ['1926%', 'circa 1926 (uncertain)'],
    'decade' => ['192X', 'the 1920s'],
    'century' => ['19XX', 'the 20th century'],
    'eleventh century' => ['10XX', 'the 11th century'],
    'twelfth century' => ['11XX', 'the 12th century'],
    'uncertain month and year' => ['1926-02?', 'February 1926 (uncertain)'],
    'per-component qualifiers' => ['?2004-06-~11', '11 June 2004 (partially qualified)'],
]);

test('it accepts an Edtf instance and renders it like the raw string', function () {
    expect(Humanizer::toReadable(Edtf::parse('1926~')))->toBe('circa 1926');
});

test('empty and unparseable input return an empty string', function (string $value) {
    expect(Humanizer::toReadable($value))->toBe('');
})->with([
    '',
    '   ',
    'garbage',
    '1964/2008',
    '2005-02-29',
]);

test('the 21st century reads correctly', function () {
    expect(Humanizer::toReadable('20XX'))->toBe('the 21st century');
});

test('toApproximateReadable renders "sometime" phrasing', function (string $edtf, string $expected) {
    expect(Humanizer::toApproximateReadable($edtf))->toBe($expected);
})->with([
    'year only' => ['1926', 'sometime during 1926'],
    'year and month' => ['1926-02', 'sometime in February 1926'],
    'full date is exact' => ['1937-11-25', '25 November 1937'],
    'decade' => ['192X', 'sometime in the 1920s'],
    'century' => ['19XX', 'sometime in the 20th century'],
    'approximate year' => ['1926~', 'sometime around 1926'],
    'uncertain year' => ['1926?', 'sometime during 1926 (uncertain)'],
    'both year' => ['1926%', 'sometime around 1926 (uncertain)'],
    'per-component qualifiers' => ['?2004-06-~11', '11 June 2004 (partially qualified)'],
]);

test('toApproximateReadable returns empty string for empty or unparseable input', function (string $value) {
    expect(Humanizer::toApproximateReadable($value))->toBe('');
})->with(['', '   ', 'garbage', '2005-02-29']);

test('toReadable output is unchanged by the refactor', function (string $edtf, string $expected) {
    expect(Humanizer::toReadable($edtf))->toBe($expected);
})->with([
    'year' => ['1926', '1926'],
    'approximate' => ['1926~', 'circa 1926'],
    'decade' => ['192X', 'the 1920s'],
]);
