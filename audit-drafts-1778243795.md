- [ ] **#BLOT-1** · P2 — LinkClick model carries ~50 lines of column-migration shim that never retires
    - **Where:** app/Models/Analytics/LinkClick.php:19-92
    - **Affects:** Developers maintaining analytics queries; every block() relationship call pays the cost of runtime information_schema introspection plus a static-cache branch.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Determine whether the `analytics.link_clicks` column is now definitively `block_id` or `link_block_id` in production.
        - If the migration is settled, delete `resolveBlockForeignKeyColumn()`, `blockForeignKeyCandidates()`, `runForBlockForeignKey()`, `isUndefinedColumnException()`, the two static properties, and simplify `block()` to a single `belongsTo(Block::class, 'block_id')`.
        - If the column rename hasn't landed yet, schedule the rename migration and a follow-up cleanup PR so the shim has a known expiry.
    - **Technical:** The model uses `information_schema.columns` to detect at runtime whether the foreign key is named `block_id` or `link_block_id`, then caches the result in a static property. It also swallows Postgres error `42703` (undefined column) in `runForBlockForeignKey()` to try the alternate name. This is a transitional shim designed to survive a column rename across deploys — once the rename is complete and every environment agrees on the column name, all of this code becomes dead weight. Every invocation of the `block()` relationship still pays the `resolveBlockForeignKeyColumn()` branch and the prior `information_schema` query is a non-zero overhead on analytics read paths.
    - **Plain English:** Imagine a building with two door numbers for the same room, and the security guard checks a directory every single time someone asks "which door?" before pointing them to the right one. The directory lookup was supposed to be temporary while the building changed its numbering, but if the renumbering is finished, the guard is still wasting time on every visit.
    - **Evidence:**
        ```php
        private static bool $blockForeignKeyResolved = false;
        private static ?string $blockForeignKeyColumn = null;

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
            // ... branch per column found
        }

        public static function isUndefinedColumnException(QueryException $exception): bool
        {
            $sqlState = $exception->errorInfo[0] ?? null;
            return $sqlState === '42703';
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#BLOT-2** · P3 — BrandStoreSettings.theme_id is cast as integer in a UUID-primary-key application
    - **Where:** app/Models/Retail/BrandStoreSettings.php:24-27
    - **Affects:** Any code that reads or writes `theme_id` on brand store settings; the integer type suggests this references a legacy themes table that may no longer exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Search the codebase for any read or write of `BrandStoreSettings.theme_id`.
        - If no live code touches it, drop the column from the `$fillable` array and flag it for schema removal in a future migration.
        - If it is still read, audit what table it joins to — the modern `site.themes` table uses UUIDs via `HasUuids`, so an integer cannot reference it.
    - **Technical:** The modern theme system uses `site.themes` with UUID primary keys (`HasUuids` trait, `$keyType = 'string'`). `BrandStoreSettings.theme_id` is cast as `integer`, meaning it cannot be a foreign key into the current themes table. The model's own docblock says it is "Simplified. Now only holds default_commission_rate and payout_hold_days," yet `theme_id` remains in `$fillable` — the docblock and the code disagree. This is likely a V1 artifact that survived the V2 refactor.
    - **Plain English:** This is like a key on your keyring that doesn't fit any lock in the building anymore. It was for an old door that got replaced, but nobody took it off the ring. Carrying it around causes confusion because someone might try the wrong lock.
    - **Evidence:**
        ```php
        protected $fillable = [
            'professional_id',
            'default_commission_rate',
            'payout_hold_days',
            'theme_id',
            'oxygen_storefront_id',
            'hydrogen_install_confirmed',
        ];

        protected $casts = [
            'default_commission_rate' => 'decimal:2',
            'payout_hold_days' => 'integer',
            'theme_id' => 'integer',
            // ...
        ];
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#BLOT-3** · P3 — Site model defines a virtual `published` accessor/mutator that may be dead code
    - **Where:** app/Models/Core/Site/Site.php:74-88
    - **Affects:** Developers reading the Site model — the `published` virtual attribute shadows `is_published` and the two names invite off-by-one bugs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Grep the codebase for `->published` (the virtual attribute) and `->setPublishedAttribute` usage on Site instances.
        - If nothing references the virtual `published` attribute, delete both `getPublishedAttribute()` and `setPublishedAttribute()`.
        - If callers do use `->published`, standardize them on `->is_published` and then remove the accessor/mutator.
    - **Technical:** Laravel's accessor convention `getPublishedAttribute()` creates a virtual `published` property that reads from `$this->attributes['is_published']`. The setter writes back to `is_published`. This means `$site->published` and `$site->is_published` are two names for the same data — one Eloquent-native, one via custom accessor. If no code hits the `published` name, the accessor is dead surface area. The setter also contains a `filter_var` + fallback chain that silently coerces any truthy/falsy value — masking type errors that would otherwise surface at the model boundary.
    - **Plain English:** There are two light switches on the wall that control the same light. One is clearly labeled "is_published" and the other just says "published." If nobody ever flips the "published" switch, it should be removed so a future electrician doesn't waste time figuring out why there are two.
    - **Evidence:**
        ```php
        public function getPublishedAttribute(): bool
        {
            return (bool) ($this->attributes['is_published'] ?? false);
        }

        public function setPublishedAttribute($value): void
        {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $bool = $bool ?? (bool) $value;
            $this->attributes['is_published'] = $bool;
        }
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#BLOT-4** · P3 — Subscription model hides `stripe_customer_id` and `stripe_subscription_id` but never casts or fills them
    - **Where:** app/Models/Billing/Subscription.php:37-39
    - **Affects:** API serialization (columns are hidden) and any code that reads these columns — their PHP type is undefined without explicit casts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'stripe_customer_id' => 'string'` and `'stripe_subscription_id' => 'string'` to `$casts` so the model guarantees consistent typing regardless of DB driver.
        - If these columns are also never written through Eloquent (only via raw SQL or the Stripe webhook handler), consider documenting that in a comment above `$hidden`.
    - **Technical:** The `$hidden` array prevents `stripe_customer_id` and `stripe_subscription_id` from appearing in JSON/array serialization, which is correct for PII-sensitive Stripe identifiers. However, neither column appears in `$fillable` (so mass-assignment is blocked) nor in `$casts` (so the PHP type is driver-dependent — they could arrive as strings from pgsql or integers from a misconfigured connection). Other string columns in the same model (like `provider`, `status`) are implicitly string-typed only by virtue of Eloquent defaults — the Stripe ID columns deserve the same explicit treatment for consistency and to prevent type-juggling bugs if these values are ever compared or hashed in PHP.
    - **Plain English:** These two columns are like employee ID badges kept in a locked drawer — hidden from visitors, which is good. But nobody wrote down what format the badge numbers are in. They're probably strings, but the system doesn't guarantee it. Adding a label ("these are strings") costs nothing and prevents confusion later.
    - **Evidence:**
        ```php
        protected $hidden = [
            'stripe_customer_id',
            'stripe_subscription_id',
            'provider_payload',
        ];

        protected $casts = [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'provider_payload' => 'array',
        ];
        ```
    - `[DRAFT, confidence: 0.6]`
