<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE professionals DROP CONSTRAINT IF EXISTS professionals_professional_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE professionals
            ADD CONSTRAINT professionals_professional_type_check
            CHECK (
                professional_type IN (
                    'professional',
                    'influencer',
                    'barber',
                    'hairdresser',
                    'ambassador',
                    'promoter',
                    'brand',
                    'barbershop',
                    'salon'
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE professionals DROP CONSTRAINT IF EXISTS professionals_professional_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE professionals
            ADD CONSTRAINT professionals_professional_type_check
            CHECK (
                professional_type IN (
                    'barber',
                    'hairdresser',
                    'ambassador',
                    'promoter',
                    'brand',
                    'barbershop',
                    'salon'
                )
            )
        SQL);
    }
};
