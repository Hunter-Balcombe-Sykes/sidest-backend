<?php

namespace App\Models\Views;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
