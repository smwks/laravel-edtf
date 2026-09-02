# laravel-edtf

Fuzzy and partial historical dates (EDTF) for Eloquent and Filament.

Real source material is often imprecise — "died sometime in 1937", "born around
1926". A plain `date` column can't hold that, so this package stores the fuzzy
value alongside a normalized exact date: every query, sort, and age calculation
that already reads the plain column keeps working, while the imprecision is
preserved instead of guessed away.

## Installation

```bash
composer require smwks/laravel-edtf
```

The service provider is auto-discovered. `filament/forms` is only needed if you
use `EdtfDatePicker`; the rest of the package has no Filament dependency.

## Usage

### Migration

The `edtf()` Blueprint macro adds both columns at once — `{name}` (a nullable
`date`) and `{name}_edtf` (a nullable `json`):

```php
Schema::create('people', function (Blueprint $table) {
    $table->id();
    $table->edtf('born_on');
});
```

### Model

Cast the `_edtf` column and you get an `Edtf` value object back:

```php
use Smwks\LaravelEdtf\Casts\AsEdtf;

protected function casts(): array
{
    return [
        'born_on' => 'date',
        'born_on_edtf' => AsEdtf::class,
    ];
}
```

```php
$person->born_on_edtf = '1926~';

$person->born_on_edtf->raw();          // '1926~'
$person->born_on_edtf->precision();    // Precision::Year
$person->born_on_edtf->isApproximate(); // true
$person->born_on_edtf->min();          // 1926-01-01
$person->born_on_edtf->max();          // 1926-12-31
$person->born_on_edtf->bestGuess();    // 1926-01-01
```

The column stores `{"edtf": "1926~", "min": "1926-01-01", "max": "1926-12-31"}`,
so range queries ("born in the 1920s") filter on real dates rather than parsing
EDTF strings per row.

### Filament

`EdtfDatePicker` replaces a plain `DatePicker`. It renders as a single
text input holding the raw EDTF string, live-validated against the package
`Parser`, with a humanized reading ("circa 1926", "February 1926
(uncertain)") shown beneath it. On save it writes both columns from that
one value — `{name}` from `bestGuess()` and `{name}_edtf` from the full
value:

```php
use Smwks\LaravelEdtf\Filament\EdtfDatePicker;

EdtfDatePicker::make('born_on')
```

A suffix "Date helper" button opens a modal that walks the admin from a
kind of date (exact date, month & year, year only, decade, century) to a
valid EDTF value, with a Certainty control for approximate/uncertain
markers and an advanced disclosure for per-component qualifiers and
unknown digits.

#### Expected precision

`EdtfDatePicker::make('born_on')->expectedPrecision('year')` (or
`->expectedPrecision(Smwks\LaravelEdtf\Precision::Year)`) tells the field the
accuracy you expect. It is a hint, not a lock: the helper modal pre-selects
and fronts the matching Kind (`year` → *Year only*, `month` → *Month & year*,
`day` → *Exact date*) and every other Kind stays available, and the field's
hint line and the modal preview switch to "sometime during 1926" /
"sometime in February 1926" phrasing (`Humanizer::toApproximateReadable()`).

#### Did-you-mean suggestions

When a value typed into the field is not valid EDTF but can be read as a
real date — `1940/01/12`, `5/8/22`, `Jan 12, 1940`, `circa 1950`,
`the 1960s`, `20th century` — a `Use "…"` link appears next to the label
offering the coerced value; one click applies it. The coercion is
`Smwks\LaravelEdtf\EdtfGuesser::guess(string): ?string`, usable on its own.
It is English-only and best-effort: `early`/`mid`/`late` are dropped to the
bare period, spelled-out ordinals and non-English months are not handled,
a two-digit year uses the strtotime pivot (`00`–`69` → `20xx`, `70`–`99` →
`19xx`), and ambiguous all-numeric input is read month-then-day unless the
first value cannot be a month.

An invalid value shows an inline "not a supported date format" error
beneath the field as you type (via `->live()` validation); it clears once
the value parses.

The field can hydrate, display, edit, and **save** any value the `Parser`
accepts — plain dates, qualifiers (`1926~`, `1984?`, `1926%`), unspecified
digits (`192X`, `1XXX-12`), and per-component qualifiers
(`?2004-06-~11`).

The `Smwks\LaravelEdtf\Rules\EdtfRule` validation rule and
`Smwks\LaravelEdtf\Humanizer` are usable outside Filament — `EdtfRule` in
any Laravel validator, `Humanizer::toReadable()` anywhere you display a
stored value.

## EDTF scope

A deliberately bounded subset of the [Library of Congress EDTF
specification](https://www.loc.gov/standards/datetime/) — enough for real
historical person records, not the whole spec:

- **Level 0** — `YYYY`, `YYYY-MM`, `YYYY-MM-DD`.
- **Level 1** — uncertain (`1926?`), approximate (`1926~`), both (`1926%`), and
  unspecified digits (`192X`).
- **Level 2** — group qualification (`2004?-06-11`), individual component
  qualification (`?2004-06-~11`), and unspecified digits anywhere in a component
  (`1XXX-12`).

Out of scope, and rejected with a clear `InvalidEdtfException` rather than
silently guessed at: exponential years (`Y-17E7`), significant digits
(`1950S2`), sets (`[1667,1668]` / `{1667,1668}`), intervals with qualified
components (`2004-06-~01/2004-06-~20`), and sub-year groupings such as seasons
(`2001-34`). Calendar-impossible dates (`2005-02-29`) are rejected too.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
