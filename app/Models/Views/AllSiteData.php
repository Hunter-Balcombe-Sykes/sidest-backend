<?php

namespace App\Models\Views;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// V2: Read-only database view that denormalizes a site with its theme, blocks, and settings into a single row for efficient dashboard queries.
class AllSiteData extends BaseModel
{
    use HasFactory;

    protected $table = 'site.all_site_data';
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
