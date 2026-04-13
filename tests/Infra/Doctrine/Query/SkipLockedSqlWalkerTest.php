<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests\Infra\Doctrine\Query;

use Carbon\Doctrine\CarbonImmutableType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Query;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Query\SkipLockedSqlWalker;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Type\ByteObjectType;
use PHPUnit\Framework\TestCase;

final class SkipLockedSqlWalkerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!Type::hasType(ByteObjectType::TYPE)) {
            Type::addType(ByteObjectType::TYPE, ByteObjectType::class);
        }

        if (!Type::hasType('carbon_immutable')) {
            Type::addType('carbon_immutable', CarbonImmutableType::class);
        }
    }

    public function testAppendsSkipLockedForUpdate(): void
    {
        $em = $this->createEntityManager(new MySQLPlatform());

        $query = $em->createQueryBuilder()
            ->select('o')
            ->from(OutboxRecord::class, 'o')
            ->where('o.publishedOn IS NULL')
            ->orderBy('o.occurredAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
        ;

        $query->setHint(Query::HINT_LOCK_MODE, LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, SkipLockedSqlWalker::class);

        $sql = $query->getSQL();
        self::assertIsString($sql);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
    }

    public function testDoesNotAppendSkipLockedWithoutWalker(): void
    {
        $em = $this->createEntityManager(new MySQLPlatform());

        $query = $em->createQueryBuilder()
            ->select('o')
            ->from(OutboxRecord::class, 'o')
            ->where('o.publishedOn IS NULL')
            ->orderBy('o.occurredAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
        ;

        $query->setHint(Query::HINT_LOCK_MODE, LockMode::PESSIMISTIC_WRITE);

        $sql = $query->getSQL();
        self::assertIsString($sql);
        self::assertStringContainsString('FOR UPDATE', $sql);
        self::assertStringNotContainsString('SKIP LOCKED', $sql);
    }

    public function testDoesNotAppendSkipLockedOnSqlite(): void
    {
        $em = $this->createEntityManager(new SqlitePlatform());

        $query = $em->createQueryBuilder()
            ->select('o')
            ->from(OutboxRecord::class, 'o')
            ->where('o.publishedOn IS NULL')
            ->orderBy('o.occurredAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
        ;

        $query->setHint(Query::HINT_LOCK_MODE, LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, SkipLockedSqlWalker::class);

        $sql = $query->getSQL();
        self::assertIsString($sql);
        self::assertStringNotContainsString('SKIP LOCKED', $sql);
    }

    private function createEntityManager(AbstractPlatform $platform): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 4) . '/src/Infra/Doctrine/Entity'],
            true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'platform' => $platform,
        ], $config);

        return new EntityManager($connection, $config);
    }
}
