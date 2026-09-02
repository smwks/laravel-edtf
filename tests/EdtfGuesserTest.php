<?php

use Smwks\LaravelEdtf\EdtfGuesser;
use Smwks\LaravelEdtf\Parser;

test('it returns null when the input is already valid EDTF', function (string $valid) {
    expect(EdtfGuesser::guess($valid))->toBeNull();
})->with(['1926', '1937-11', '1937-11-25', '1984?', '1926~', '192X', '1XXX-12', '?2004-06-~11', '~1950', '~2004-06-11', '  1940  ', '1926 ']);

test('it returns null for empty or unrescuable input', function (string $junk) {
    expect(EdtfGuesser::guess($junk))->toBeNull();
})->with(['', '   ', 'hello', 'not a date', '1940-02-31', '99/99/9999']);

test('it coerces separator, order and padding variants', function (string $input, string $expected) {
    expect(EdtfGuesser::guess($input))->toBe($expected);
})->with([
    'slashes' => ['1940/01/12', '1940-01-12'],
    'dots and missing padding' => ['1940.1.2', '1940-01-02'],
    'first value can be a month' => ['12-06-1940', '1940-12-06'],
    'first value cannot be a month' => ['13-6-1940', '1940-06-13'],
    'both below 12' => ['5-6-1940', '1940-05-06'],
    'year and month, year first' => ['1940/3', '1940-03'],
    'year and month, month first' => ['3/1940', '1940-03'],
]);

test('it expands a two-digit year with the strtotime pivot', function (string $input, string $expected) {
    expect(EdtfGuesser::guess($input))->toBe($expected);
})->with([
    'M/D/YY, 21st century' => ['5/8/22', '2022-05-08'],
    'M/D/YY, pivot lower bound stays 20xx' => ['5/8/69', '2069-05-08'],
    'M/D/YY, pivot upper bound flips to 19xx' => ['5/8/70', '1970-05-08'],
    'M/D/YY, 20th century' => ['12/25/99', '1999-12-25'],
    'D-M-YY with dashes' => ['8-5-22', '2022-08-05'],
    'ambiguous mid-range year' => ['1/2/34', '2034-01-02'],
    'month name plus two-digit year' => ['May 8, 22', '2022-05-08'],
    'four-digit year still wins over a short trailing token' => ['5/8/1922', '1922-05-08'],
]);

test('it does not invent a year from a bare month and day', function (string $input) {
    expect(EdtfGuesser::guess($input))->toBeNull();
})->with(['January 12', 'Jan 8']);

test('it coerces decade and century words', function (string $input, string $expected) {
    expect(EdtfGuesser::guess($input))->toBe($expected);
})->with([
    'decade' => ['1950s', '195X'],
    'decade with the' => ['the 1960s', '196X'],
    'decade with apostrophe' => ["1920's", '192X'],
    'early is dropped' => ['early 1990s', '199X'],
    'mid is dropped' => ['mid 1980s', '198X'],
    'century' => ['20th century', '19XX'],
    'century 21st' => ['21st century', '20XX'],
    'century with the' => ['the 19th century', '18XX'],
]);

test('it coerces qualifier words', function (string $input, string $expected) {
    expect(EdtfGuesser::guess($input))->toBe($expected);
})->with([
    'circa' => ['circa 1950', '1950~'],
    'c. abbreviation' => ['c. 1950', '1950~'],
    'ca. abbreviation' => ['ca. 1950', '1950~'],
    'about' => ['about 1950', '1950~'],
    'probably' => ['probably 1984', '1984?'],
    'possibly with month' => ['possibly 1984-06', '1984-06?'],
    'circa a full date' => ['circa 1940/01/12', '1940-01-12~'],
]);

test('it coerces English month names', function (string $input, string $expected) {
    expect(EdtfGuesser::guess($input))->toBe($expected);
})->with([
    'abbrev, comma, day first token' => ['Jan 12, 1940', '1940-01-12'],
    'day month year' => ['12 January 1940', '1940-01-12'],
    'month and year only' => ['January 1940', '1940-01'],
    'full month name mid string' => ['12 Feb 1940', '1940-02-12'],
    'may is a month' => ['May 1940', '1940-05'],
]);

test('every non-null guess parses through the Parser', function (string $input) {
    $guess = EdtfGuesser::guess($input);

    expect($guess)->not->toBeNull();
    expect(fn () => (new Parser)->parse($guess))->not->toThrow(Exception::class);
})->with(['1940/01/12', 'circa 1950', 'the 1960s', '20th century', 'Jan 12, 1940', 'probably 1984']);
