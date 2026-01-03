<?php

namespace App\Models\Views;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PublicSitePayload extends Model
{
    use HasFactory;

    protected $table = 'core.public_site_payload';
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
