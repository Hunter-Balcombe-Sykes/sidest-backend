<?php

namespace App\Models\Views;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AllSiteData extends Model
{
    use HasFactory;

    protected $table = 'core.all_site_data';
    protected $primaryKey = 'site_id';

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    // view = read-only (keep it safe)
    protected $guarded = [];

    protected $casts = [
        'site_settings'   => 'array',
        'theme_config'    => 'array',
        'blocks'          => 'array',
        'is_published'    => 'boolean',
        'site_created_at' => 'datetime',
        'site_updated_at' => 'datetime',
    ];
}
