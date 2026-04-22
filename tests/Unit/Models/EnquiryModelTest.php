<?php

use App\Models\Core\Site\Enquiry;

it('uses the site.enquiries table', function () {
    expect((new Enquiry)->getTable())->toBe('site.enquiries');
});

it('casts read_at and timestamps to datetime', function () {
    $casts = (new Enquiry)->getCasts();

    expect($casts['read_at'])->toBe('datetime');
    expect($casts['created_at'])->toBe('datetime');
    expect($casts['updated_at'])->toBe('datetime');
    expect($casts['deleted_at'])->toBe('datetime');
});

it('uses UUID keys and soft deletes', function () {
    $model = new Enquiry;

    expect($model->incrementing)->toBeFalse();
    expect($model->getKeyType())->toBe('string');
    expect(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model)))->toBeTrue();
});
