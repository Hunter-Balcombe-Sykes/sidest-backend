<?php

namespace App\Models\Core\Staff;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// V2: Internal Partna staff member. Linked to a Supabase auth user; role-based access for admin operations.
class PartnaStaff extends BaseModel
{
    use HasFactory, HasUuids;

    const ROLE_SUPPORT = 'support';

    const ROLE_ADMIN = 'admin';

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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
