<?php

use Smwks\LaravelEdtf\Rules\EdtfRule;

function failuresFor(?string $value, bool $verbose = false): array
{
    $rule = new EdtfRule;

    if ($verbose) {
        $rule->verbose();
    }

    $messages = [];
    $rule->validate('born_on', $value, function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    return $messages;
}

test('an empty value passes because the date is optional', function () {
    expect(failuresFor(null))->toBe([]);
    expect(failuresFor(''))->toBe([]);
});

test('in-scope EDTF values pass', function (string $value) {
    expect(failuresFor($value))->toBe([]);
})->with([
    '1926',
    '1926-02',
    '1937-11-25',
    '1984?',
    '1926~',
    '1926%',
    '192X',
    '1XXX-12',
    '?2004-06-~11',
]);

test('malformed or out-of-scope values fail', function (string $value) {
    expect(failuresFor($value))->not->toBe([]);
})->with([
    '1964/2008',
    '[1667,1668]',
    '2001-34',
    'Y-17E7',
    '2005-02-29',
    'garbage',
]);

test('the default message is generic, verbose exposes the parser detail', function () {
    expect(failuresFor('2005-02-29'))->toBe(['The :attribute is not a supported date format.']);
    expect(failuresFor('2005-02-29', verbose: true)[0])->toContain('Not a real calendar date');
});

test('a non-string value fails', function () {
    $rule = new EdtfRule;
    $messages = [];
    $rule->validate('born_on', ['not', 'a', 'string'], function (string $m) use (&$messages) {
        $messages[] = $m;
    });

    expect($messages)->not->toBe([]);
});
