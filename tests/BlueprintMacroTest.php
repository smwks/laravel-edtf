<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Schema::dropIfExists('edtf_macro_test');
});

test('the edtf() Blueprint macro adds both the plain date column and the _edtf json column', function () {
    Schema::create('edtf_macro_test', function (Blueprint $table) {
        $table->id();
        $table->edtf('born_on');
    });

    $columns = Schema::getColumnListing('edtf_macro_test');

    expect($columns)->toContain('born_on');
    expect($columns)->toContain('born_on_edtf');
});
