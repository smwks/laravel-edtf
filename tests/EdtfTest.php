<?php

use Smwks\LaravelEdtf\Edtf;
use Smwks\LaravelEdtf\Precision;

test('a plain year has year precision and spans the whole year', function () {
    $edtf = Edtf::parse('1926');

    expect($edtf->precision())->toBe(Precision::Year);
    expect($edtf->min()->format('Y-m-d'))->toBe('1926-01-01');
    expect($edtf->max()->format('Y-m-d'))->toBe('1926-12-31');
    expect($edtf->bestGuess()->format('Y-m-d'))->toBe('1926-01-01');
    expect($edtf->isUncertain())->toBeFalse();
    expect($edtf->isApproximate())->toBeFalse();
});

test('a year and month has month precision and spans the whole month, respecting February in a non-leap year', function () {
    $edtf = Edtf::parse('1926-02');

    expect($edtf->precision())->toBe(Precision::Month);
    expect($edtf->min()->format('Y-m-d'))->toBe('1926-02-01');
    expect($edtf->max()->format('Y-m-d'))->toBe('1926-02-28');
    expect($edtf->bestGuess()->format('Y-m-d'))->toBe('1926-02-01');
});

test('a year and month spans the whole month, respecting February in a leap year', function () {
    $edtf = Edtf::parse('1924-02');

    expect($edtf->max()->format('Y-m-d'))->toBe('1924-02-29');
});

test('a full date has day precision and an exact single-day min/max/bestGuess', function () {
    $edtf = Edtf::parse('1937-11-25');

    expect($edtf->precision())->toBe(Precision::Day);
    expect($edtf->min()->format('Y-m-d'))->toBe('1937-11-25');
    expect($edtf->max()->format('Y-m-d'))->toBe('1937-11-25');
    expect($edtf->bestGuess()->format('Y-m-d'))->toBe('1937-11-25');
});

test('an unspecified decade narrows to that decade\'s ten years', function () {
    $edtf = Edtf::parse('192X');

    expect($edtf->min()->format('Y-m-d'))->toBe('1920-01-01');
    expect($edtf->max()->format('Y-m-d'))->toBe('1929-12-31');
    expect($edtf->bestGuess()->format('Y-m-d'))->toBe('1920-01-01');
});

test('an unspecified century narrows to that century\'s hundred years', function () {
    $edtf = Edtf::parse('19XX');

    expect($edtf->min()->format('Y-m-d'))->toBe('1900-01-01');
    expect($edtf->max()->format('Y-m-d'))->toBe('1999-12-31');
});

test('a year and month with no day spans every day in that month', function () {
    $edtf = Edtf::parse('1937-11');

    expect($edtf->min()->format('Y-m-d'))->toBe('1937-11-01');
    expect($edtf->max()->format('Y-m-d'))->toBe('1937-11-30');
});

test('approximate marks isApproximate but not isUncertain', function () {
    $edtf = Edtf::parse('1926~');

    expect($edtf->isApproximate())->toBeTrue();
    expect($edtf->isUncertain())->toBeFalse();
});

test('uncertain marks isUncertain but not isApproximate', function () {
    $edtf = Edtf::parse('1926?');

    expect($edtf->isUncertain())->toBeTrue();
    expect($edtf->isApproximate())->toBeFalse();
});

test('a group qualifier that propagates to the year makes the whole value uncertain', function () {
    $edtf = Edtf::parse('2004-06?-11');

    expect($edtf->isUncertain())->toBeTrue();
});

test('raw returns the original EDTF string unchanged', function () {
    expect(Edtf::parse('1926~')->raw())->toBe('1926~');
});

test('min is never later than max, for every precision and qualifier combination', function (string $value) {
    $edtf = Edtf::parse($value);

    expect($edtf->min()->getTimestamp())->toBeLessThanOrEqual($edtf->max()->getTimestamp());
    expect($edtf->bestGuess()->getTimestamp())->toBeGreaterThanOrEqual($edtf->min()->getTimestamp());
    expect($edtf->bestGuess()->getTimestamp())->toBeLessThanOrEqual($edtf->max()->getTimestamp());
})->with([
    '1926',
    '1926?',
    '1926~',
    '1926%',
    '192X',
    '19XX',
    '1XXX',
    '1926-02',
    '1924-02',
    '1937-11',
    '1XXX-12',
    '2004?-06',
    '2004-06?',
    '?2004-06',
    '1937-11-25',
    '2004-02-29',
    '2000-02-29',
    '2004?-06-11',
    '2004-06?-11',
    '?2004-06-~11',
    '2004-06-11%',
    '19XX-06-11',
    '2004-XX-11',
    '2004-06-XX',
    '2004-02-3X',
    '2005-02-3X',
]);

test('jsonSerialize returns the edtf/min/max shape', function () {
    $edtf = Edtf::parse('1926');

    expect($edtf->jsonSerialize())->toBe([
        'edtf' => '1926',
        'min' => '1926-01-01',
        'max' => '1926-12-31',
    ]);
    expect(json_encode($edtf))->toBe('{"edtf":"1926","min":"1926-01-01","max":"1926-12-31"}');
});
