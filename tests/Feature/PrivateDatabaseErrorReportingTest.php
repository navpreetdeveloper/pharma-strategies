<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PrivateDatabaseErrorReportingTest extends TestCase
{
    public function test_database_error_reporting_does_not_log_sql_or_private_bindings(): void
    {
        $error = new QueryException('mysql', 'insert into messages (body) values (?)', ['Synthetic private body'], new \PDOException('Synthetic database failure'));
        Log::shouldReceive('error')->once()->with('Database operation failed.', ['exception_type' => QueryException::class, 'sql_state' => '0']);
        app(ExceptionHandler::class)->report($error);
    }
}
