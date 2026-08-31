<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Smwks\LaravelEdtf\Casts\AsEdtf;
use Smwks\LaravelEdtf\Edtf;

class EdtfTestModel extends Model
{
    protected $table = 'edtf_test_models';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'born_on_edtf' => AsEdtf::class,
        ];
    }
}

beforeEach(function () {
    Schema::create('edtf_test_models', function (Blueprint $table) {
        $table->id();
        $table->json('born_on_edtf')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('edtf_test_models');
});

test('setting an EDTF string on the attribute stores it and reading it back gives an Edtf instance', function () {
    $model = EdtfTestModel::create(['born_on_edtf' => '1926~']);

    $fresh = EdtfTestModel::find($model->id);

    expect($fresh->born_on_edtf)->toBeInstanceOf(Edtf::class);
    expect($fresh->born_on_edtf->raw())->toBe('1926~');
    expect($fresh->born_on_edtf->isApproximate())->toBeTrue();
});

test('setting an Edtf instance directly on the attribute also works', function () {
    $model = EdtfTestModel::create(['born_on_edtf' => Edtf::parse('1937-11-25')]);

    $fresh = EdtfTestModel::find($model->id);

    expect($fresh->born_on_edtf->raw())->toBe('1937-11-25');
});

test('a null value round-trips as null', function () {
    $model = EdtfTestModel::create(['born_on_edtf' => null]);

    $fresh = EdtfTestModel::find($model->id);

    expect($fresh->born_on_edtf)->toBeNull();
});

test('the stored JSON contains edtf, min, and max', function () {
    $model = EdtfTestModel::create(['born_on_edtf' => '1926']);

    $raw = DB::table('edtf_test_models')->where('id', $model->id)->value('born_on_edtf');

    expect(json_decode($raw, true))->toBe([
        'edtf' => '1926',
        'min' => '1926-01-01',
        'max' => '1926-12-31',
    ]);
});
