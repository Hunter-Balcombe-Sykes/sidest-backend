<?php

namespace App\Models\Core\Staff;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CometStaff extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'core.comet_staff';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'role',
        'primary_email',
        'name',
        'phone',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
