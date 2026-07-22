<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Tests;

use Carbon\Doctrine\CarbonImmutableType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Lingoda\DomainEventsBundle\LingodaDomainEventsBundle;
use PHPUnit\Framework\TestCase;

/**
 * Boots a real EntityManager against an in-memory sqlite database with the
 * bundle entities' schema created, for tests that exercise actual queries.
 */
abstract class InMemoryDatabaseTestCase extends TestCase
{
    protected EntityManager $entityManager;

    protected function setUp(): void
    {
        new LingodaDomainEventsBundle(); // registers the byte_object DBAL type
        if (!Type::hasType('carbon_immutable')) {
            Type::addType('carbon_immutable', CarbonImmutableType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [\dirname(__DIR__) . '/src/Infra/Doctrine/Entity'],
            true,
        );
        // native lazy objects need PHP >= 8.4; on older PHP the ORM falls back
        // to var-exporter LazyGhost proxies without any opt-in
        if (\PHP_VERSION_ID >= 80400 && \method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        (new SchemaTool($this->entityManager))
            ->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }
}
