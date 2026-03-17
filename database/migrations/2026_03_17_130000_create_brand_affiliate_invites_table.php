<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS core.brand_affiliate_invites (
                id uuid PRIMARY KEY,
                brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
                token varchar(80) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                invite_type varchar(24) NOT NULL DEFAULT 'generic',
                email varchar(255) NULL,
                email_lc varchar(255) NULL,
                phone varchar(40) NULL,
                first_name varchar(80) NULL,
                last_name varchar(80) NULL,
                message text NULL,
                claimed_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
                accepted_at timestamptz NULL,
                expires_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS brand_affiliate_invites_token_uq ON core.brand_affiliate_invites (token)");
        DB::statement("CREATE INDEX IF NOT EXISTS brand_affiliate_invites_brand_status_idx ON core.brand_affiliate_invites (brand_professional_id, status)");
        DB::statement("CREATE INDEX IF NOT EXISTS brand_affiliate_invites_email_lc_idx ON core.brand_affiliate_invites (email_lc)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS core.brand_affiliate_invites");
    }
};
