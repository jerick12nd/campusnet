<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SqlDialect;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SqlDialectTest extends TestCase
{
    public function test_mysql_buckets_use_unix_timestamp(): void
    {
        $this->assertSame(
            '(UNIX_TIMESTAMP(sampled_at) - (UNIX_TIMESTAMP(sampled_at) % 900))',
            SqlDialect::bucketExpression('mysql', 'sampled_at', 900),
        );
        $this->assertSame('UNIX_TIMESTAMP(sampled_at)', SqlDialect::epochExpression('mariadb', 'sampled_at'));
    }

    public function test_sqlite_buckets_use_strftime(): void
    {
        $this->assertSame(
            "(CAST(strftime('%s', sampled_at) AS INTEGER) - (CAST(strftime('%s', sampled_at) AS INTEGER) % 60))",
            SqlDialect::bucketExpression('sqlite', 'sampled_at', 60),
        );
    }

    public function test_the_bucket_size_is_written_as_a_number_not_a_placeholder(): void
    {
        $sql = SqlDialect::bucketExpression('mysql', 'sampled_at', 21600);

        $this->assertStringContainsString('% 21600)', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function test_a_qualified_column_is_accepted(): void
    {
        $this->assertSame('UNIX_TIMESTAMP(traffic_samples.sampled_at)', SqlDialect::epochExpression('mysql', 'traffic_samples.sampled_at'));
    }

    public function test_suspicious_column_names_are_rejected(): void
    {
        foreach (['sampled_at; DROP TABLE users', "a' OR '1'='1", 'a b', '', '1abc', 'a.b.c', 'a)--'] as $column) {
            try {
                SqlDialect::epochExpression('mysql', $column);
                $this->fail("Column [{$column}] should have been rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid column name', $e->getMessage());
            }
        }
    }

    public function test_unsupported_databases_are_rejected_instead_of_guessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver [pgsql]');

        SqlDialect::bucketExpression('pgsql', 'sampled_at', 60);
    }

    public function test_the_bucket_size_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlDialect::bucketExpression('mysql', 'sampled_at', 0);
    }
}