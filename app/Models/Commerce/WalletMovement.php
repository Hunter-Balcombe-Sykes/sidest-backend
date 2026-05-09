<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// AUSTRAC-grade ledger of every credit and debit against a professional's wallet.
// Append-only by convention — rows are never updated after insertion.
// Uniqueness is enforced at the DB layer via idempotency_key so re-delivered
// webhook/job events are safely ignored.
class WalletMovement extends BaseModel
{
    use HasFactory;
    use HasUuids;

    protected $table = 'commerce.wallet_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    // All writes are server-side via app_backend (BYPASSRLS). Use forceFill() at callsites.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata'     => 'array',
            'occurred_at'  => 'datetime',
            'created_at'   => 'datetime',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'related_payout_id');
    }
}
