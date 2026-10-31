<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Database-specific SQL for time bucketing. CampusNet targets MySQL; SQLite (used by
 * the automated tests) is supported so the same queries run in both.
 *
 * Column names are internal constants, never user input, and are validated anyway.
 * Bucket sizes are cast to int and written into the SQL text on purpose: with native
 * prepared statements MySQL treats "?" in SELECT and GROUP BY as different expressions.
 *
 * Timestamps must be stored in UTC (APP_TIMEZONE=UTC and the MySQL connection set to
 * timezone +00:00, see the setup notes) so that the epoch seconds are correct.
 */
final class SqlDialect
{
    public static function epochExpression(string $driver, string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException("Invalid column name [{$column}].");
        }

        return match ($driver) {
            'sqlite' => "CAST(strftime('%s', {$column}) AS INTEGER)",
            'mysql', 'mariadb' => "UNIX_TIMESTAMP({$column})",
            default => throw new InvalidArgumentException("Unsupported database driver [{$driver}] for time bucketing."),
        };
    }

    public static function bucketExpression(string $driver, string $column, int $seconds): string
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Bucket size must be at least one second.');
        }

        $epoch = self::epochExpression($driver, $column);

        return "({$epoch} - ({$epoch} % {$seconds}))";
    }

    public static function bucket(string $column, int $seconds): string
    {
        return self::bucketExpression(DB::connection()->getDriverName(), $column, $seconds);
    }
}