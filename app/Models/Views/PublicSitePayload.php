<?php

namespace App\Models\Views;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// V2: Read-only database view that pre-builds the full public site JSON payload. Single-query fetch for the public site renderer.
class PublicSitePayload extends BaseModel
{
    use HasFactory;

    protected $table = 'site.public_site_payload';
    protected $primaryKey = 'site_id';

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    // Views are read-only
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];
}
