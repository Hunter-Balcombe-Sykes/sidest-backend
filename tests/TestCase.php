<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // BaseModel forces every Eloquent model onto the 'pgsql' connection.
        // Without this redirect, every model query in tests tries to reach the
        // real Supabase host via DB_HOST and fails with DNS errors. Point the
        // 'pgsql' connection at the same in-memory SQLite the test suite uses,
        // so models still resolve their forced connection but it goes nowhere
        // dangerous.
        config(['database.connections.pgsql' => array_merge(
            config('database.connections.sqlite'),
            ['name' => 'pgsql']
        )]);

        // Also make pgsql the DEFAULT connection in tests, so unqualified calls
        // like DB::table('foo') and DB::statement(...) hit the same PDO handle
        // that BaseModel-forced models use. Without this, ATTACH DATABASE and
        // CREATE TABLE statements run on a different SQLite handle than the
        // models, so models can't see the tables.
        config(['database.default' => 'pgsql']);

        DB::purge('pgsql');
    }
}
