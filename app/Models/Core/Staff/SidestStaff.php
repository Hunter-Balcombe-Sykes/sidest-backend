<?php

namespace App\Models\Core\Staff;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SidestStaff extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'core.sidest_staff';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $hidden = [
        'auth_user_id',
    ];

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
