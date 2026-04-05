<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// V2: Records clicks on link blocks within a site. Handles block_id/link_block_id column migration gracefully with runtime column resolution.
class LinkClick extends BaseModel
{
    use HasUuids;

    private static bool $blockForeignKeyResolved = false;
    private static ?string $blockForeignKeyColumn = null;

    protected $table = 'analytics.link_clicks';

    public $incrementing = false;
    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'session_id',
        'visitor_id',
        'ip_hash',
        'user_agent',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public static function resolveBlockForeignKeyColumn(): ?string
    {
        if (self::$blockForeignKeyResolved) {
            return self::$blockForeignKeyColumn;
        }

        self::$blockForeignKeyResolved = true;

        try {
            $columns = DB::table('information_schema.columns')
                ->where('table_schema', 'analytics')
                ->where('table_name', 'link_clicks')
                ->whereIn('column_name', ['block_id', 'link_block_id'])
                ->pluck('column_name')
                ->all();
        } catch (\Throwable) {
            $columns = [];
        }

        if (in_array('block_id', $columns, true)) {
            self::$blockForeignKeyColumn = 'block_id';

            return self::$blockForeignKeyColumn;
        }

        if (in_array('link_block_id', $columns, true)) {
            self::$blockForeignKeyColumn = 'link_block_id';

            return self::$blockForeignKeyColumn;
        }

        self::$blockForeignKeyColumn = null;

        return null;
    }

    public static function blockForeignKeyCandidates(): array
    {
        $resolved = self::resolveBlockForeignKeyColumn();

        if ($resolved === 'block_id') {
            return ['block_id', 'link_block_id'];
        }

        if ($resolved === 'link_block_id') {
            return ['link_block_id', 'block_id'];
        }

        return ['block_id', 'link_block_id'];
    }

    public static function runForBlockForeignKey(callable $callback, mixed $default = null): mixed
    {
        foreach (self::blockForeignKeyCandidates() as $column) {
            try {
                $result = $callback($column);

                self::$blockForeignKeyResolved = true;
                self::$blockForeignKeyColumn = $column;

                return $result;
            } catch (QueryException $e) {
                if (!self::isUndefinedColumnException($e)) {
                    throw $e;
                }
            }
        }

        return $default;
    }

    public static function isUndefinedColumnException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '42703';
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function block(): BelongsTo
    {
        $foreignKey = self::resolveBlockForeignKeyColumn();

        if (!$foreignKey && array_key_exists('link_block_id', $this->attributes)) {
            $foreignKey = 'link_block_id';
        }

        if (!$foreignKey && array_key_exists('block_id', $this->attributes)) {
            $foreignKey = 'block_id';
        }

        return $this->belongsTo(Block::class, $foreignKey ?? 'link_block_id');
    }

}
