<?php

use Smwks\LaravelEdtf\Exceptions\InvalidEdtfException;
use Smwks\LaravelEdtf\Parser;

test('parses a plain year', function () {
    $result = (new Parser)->parse('1926');

    expect($result['components']['year']['digits'])->toBe('1926');
    expect($result['components'])->not->toHaveKey('month');
    expect($result['certainty'])->toBe(['year' => null]);
});

test('parses a year and month', function () {
    $result = (new Parser)->parse('1926-11');

    expect($result['components']['year']['digits'])->toBe('1926');
    expect($result['components']['month']['digits'])->toBe('11');
    expect($result['components'])->not->toHaveKey('day');
});

test('parses a full date', function () {
    $result = (new Parser)->parse('1926-11-25');

    expect($result['components']['year']['digits'])->toBe('1926');
    expect($result['components']['month']['digits'])->toBe('11');
    expect($result['components']['day']['digits'])->toBe('25');
    expect($result['certainty'])->toBe(['year' => null, 'month' => null, 'day' => null]);
});

test('uncertain, approximate, and both qualifiers on a whole year', function () {
    expect((new Parser)->parse('1926?')['certainty'])->toBe(['year' => '?']);
    expect((new Parser)->parse('1926~')['certainty'])->toBe(['year' => '~']);
    expect((new Parser)->parse('1926%')['certainty'])->toBe(['year' => '%']);
});

test('trailing unspecified digits narrow a year without full precision', function () {
    expect((new Parser)->parse('192X')['components']['year']['digits'])->toBe('192X');
    expect((new Parser)->parse('19XX')['components']['year']['digits'])->toBe('19XX');
});

test('unspecified digits can appear anywhere in a component, not just trailing', function () {
    $result = (new Parser)->parse('1XXX-12');

    expect($result['components']['year']['digits'])->toBe('1XXX');
    expect($result['components']['month']['digits'])->toBe('12');
});

test('unspecified digits in month or day skip range validation', function () {
    $result = (new Parser)->parse('2004-X2');

    expect($result['components']['month']['digits'])->toBe('X2');
});

test('a group qualifier on the leftmost component only affects itself', function () {
    $result = (new Parser)->parse('2004?-06-11');

    expect($result['certainty'])->toBe(['year' => '?', 'month' => null, 'day' => null]);
});

test('a group qualifier on a later component propagates left to every component before it', function () {
    $result = (new Parser)->parse('2004-06?-11');

    expect($result['certainty'])->toBe(['year' => '?', 'month' => '?', 'day' => null]);
});

test('individual component qualifiers affect only their own component, independently', function () {
    $result = (new Parser)->parse('?2004-06-~11');

    expect($result['certainty'])->toBe(['year' => '?', 'month' => null, 'day' => '~']);
});

test('a component cannot have both an individual and a group qualifier', function () {
    expect(fn () => (new Parser)->parse('?2004?-06-11'))->toThrow(InvalidEdtfException::class);
});

test('rejects an empty or blank value', function () {
    expect(fn () => (new Parser)->parse(''))->toThrow(InvalidEdtfException::class);
    expect(fn () => (new Parser)->parse('   '))->toThrow(InvalidEdtfException::class);
});

test('rejects garbage input', function () {
    expect(fn () => (new Parser)->parse('not-a-date'))->toThrow(InvalidEdtfException::class);
});

test('rejects a month or day out of range', function () {
    expect(fn () => (new Parser)->parse('2004-13-01'))->toThrow(InvalidEdtfException::class);
    expect(fn () => (new Parser)->parse('2004-01-32'))->toThrow(InvalidEdtfException::class);
});

test('rejects a day that does not exist in that month of that year', function (string $value) {
    expect(fn () => (new Parser)->parse($value))->toThrow(InvalidEdtfException::class);
})->with([
    'February 30th' => '2004-02-30',
    'February 29th in a non-leap year' => '2005-02-29',
    'April 31st' => '2004-04-31',
    'June 31st' => '2004-06-31',
    'November 31st' => '2004-11-31',
]);

test('accepts a real leap day', function (string $value) {
    expect((new Parser)->parse($value)['components']['day']['digits'])->toBe('29');
})->with([
    'leap year' => '2004-02-29',
    'leap century' => '2000-02-29',
]);

test('an unspecified digit in any component skips calendar-date validation', function (string $value) {
    expect((new Parser)->parse($value)['components'])->toHaveKey('day');
})->with([
    'unspecified year' => '200X-02-30',
    'unspecified month' => '2004-0X-31',
    'unspecified day' => '2004-02-3X',
]);

test('rejects sub-year groupings (seasons/quarters) as an out-of-range month', function () {
    expect(fn () => (new Parser)->parse('2001-34'))->toThrow(InvalidEdtfException::class);
});

test('rejects exponential year notation', function () {
    expect(fn () => (new Parser)->parse('Y-17E7'))->toThrow(InvalidEdtfException::class);
});

test('rejects significant digits notation', function () {
    expect(fn () => (new Parser)->parse('1950S2'))->toThrow(InvalidEdtfException::class);
});

test('rejects set notation, one-of and all-of', function () {
    expect(fn () => (new Parser)->parse('[1667,1668,1670..1672]'))->toThrow(InvalidEdtfException::class);
    expect(fn () => (new Parser)->parse('{1667,1668,1670..1672}'))->toThrow(InvalidEdtfException::class);
});

test('rejects an interval with qualified components', function () {
    expect(fn () => (new Parser)->parse('2004-06-~01/2004-06-~20'))->toThrow(InvalidEdtfException::class);
});
