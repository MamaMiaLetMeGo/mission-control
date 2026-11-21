<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_sqlite_waits_for_a_busy_database_and_takes_the_write_lock_when_a_transaction_starts(): void
    {
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
        $this->assertSame(5000, config('database.connections.sqlite.busy_timeout'));
        $this->assertSame(5000, (int) DB::connection('sqlite')->selectOne('pragma busy_timeout')->timeout);
    }
}
