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

### Displaying a human-readable date

There is no built-in humanizer. `Edtf` deliberately exposes the parts and lets
you format to taste — the important thing is to format *against the precision*,
so a year- or month-precision value never prints a filler day or month:

```php
use Smwks\LaravelEdtf\Edtf;
use Smwks\LaravelEdtf\Precision;

function humanizeEdtf(Edtf $edtf): string
{
    $date = $edtf->bestGuess();

    $text = match ($edtf->precision()) {
        Precision::Day   => $date->format('F j, Y'), // March 3, 2021
        Precision::Month => $date->format('F Y'),    // March 2021
        Precision::Year  => $date->format('Y'),      // 2021
    };

    if ($edtf->isApproximate()) {
        $text = "c. {$text}";
    }

    if ($edtf->isUncertain()) {
        $text .= '?';
    }

    return $text;
}
```

`bestGuess()` is anchored to the start of the precision (`2021-03` →
`2021-03-01`, `2021` → `2021-01-01`), so it is safe to `format()` once the
`match` has picked the right mask. For unspecified digits (`192X`) it collapses
to the low end; show the span with `min()`/`max()` instead:

```php
$edtf->min()->format('Y').'–'.$edtf->max()->format('Y'); // 2010–2019
```

### Filament

`EdtfDatePicker` replaces a plain `DatePicker`. One control picks the precision
and takes what's known, and on save it writes both columns from that single
input — `{name}` from `bestGuess()` and `{name}_edtf` from the full value:

```php
use Smwks\LaravelEdtf\Filament\EdtfDatePicker;

EdtfDatePicker::make('born_on')
```

The field's sub-inputs are stock `Select`/`TextInput` components — it can
hydrate and display any value the `Parser` accepts, but it can only *save*
plain, fully-specified values (`1926`, `1926-02`, `1937-11-25`). Qualifiers
(`1926~`) and unspecified digits (`192X`) are preserved on read but are not
yet re-editable through this field; assign them directly on the model when
you need them. A qualifier-aware UI is a planned follow-up.

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
