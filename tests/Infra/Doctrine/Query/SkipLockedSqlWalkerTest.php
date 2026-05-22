<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Infra\Doctrine\Query;

use Lingoda\DomainEventsBundle\Infra\Doctrine\Query\SkipLockedSqlWalker;
use PHPUnit\Framework\TestCase;

final class SkipLockedSqlWalkerTest extends TestCase
{
    public function testAppendsSkipLockedWhenSqlContainsForUpdate(): void
    {
        self::assertSame(
            'SELECT * FROM outbox WHERE published_on IS NULL LIMIT 1 FOR UPDATE SKIP LOCKED',
            SkipLockedSqlWalker::appendSkipLockedHint(
                'SELECT * FROM outbox WHERE published_on IS NULL LIMIT 1 FOR UPDATE',
            ),
        );
    }

    public function testLeavesSqlUntouchedWhenForUpdateNotPresent(): void
    {
        self::assertSame(
            'SELECT * FROM outbox WHERE published_on IS NULL LIMIT 1',
            SkipLockedSqlWalker::appendSkipLockedHint(
                'SELECT * FROM outbox WHERE published_on IS NULL LIMIT 1',
            ),
        );
    }

    public function testLeavesNonSelectStatementsUntouched(): void
    {
        self::assertSame('', SkipLockedSqlWalker::appendSkipLockedHint(''));
        self::assertSame(
            'DELETE FROM outbox WHERE id = 1',
            SkipLockedSqlWalker::appendSkipLockedHint('DELETE FROM outbox WHERE id = 1'),
        );
    }
}
