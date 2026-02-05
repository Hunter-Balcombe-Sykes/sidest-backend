<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'core.themes';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'name',
        'description',
        'config',
        'is_default',
    ];

    protected $casts = [
        'config'      => 'array',
        'is_default'  => 'boolean',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'theme_id');
    }
}
