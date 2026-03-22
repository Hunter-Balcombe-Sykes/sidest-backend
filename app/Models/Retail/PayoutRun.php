<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutRun extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.payout_runs';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'period_start',
        'period_end',
        'scheduled_for',
        'executed_at',
        'status',
        'total_cents',
        'currency_code',
        'external_reference',
        'notes',
        'created_by_professional_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'scheduled_for' => 'datetime',
        'executed_at' => 'datetime',
        'total_cents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function createdByProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'created_by_professional_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class, 'payout_run_id');
    }
}
