<?php

namespace Lingoda\DomainEventsBundle\Tests\Infra\Doctrine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManagerInterface;
use Dunglas\DoctrineJsonOdm\Bundle\DunglasDoctrineJsonOdmBundle;
use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\OutboxStore;
use Lingoda\DomainEventsBundle\Domain\Model\Traits\DomainEventTrait;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\LingodaDomainEventsBundle;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\HttpKernel\KernelInterface;
use Webmozart\Assert\Assert;

final class DoctrineOutboxStoreTest extends KernelTestCase
{
    private OutboxStore $store;

    private OutboxRecordRepository $repo;

    private Application $application;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        AnnotationReader::addGlobalIgnoredName('mixin');
        AnnotationReader::addGlobalIgnoredName('alias');

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);

        $this->runCommand('doctrine:schema:drop --force');
        $this->runCommand('doctrine:schema:create');

        $this->store = self::getContainer()->get('test.lingoda_domain_events.repository.outbox_store');
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        Assert::isInstanceOf($this->em,EntityManagerInterface::class);

        $this->repo = $this->em->getRepository(OutboxRecord::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2023-06-25 13:15'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    private function runCommand(string $command): void
    {
        $this->application->run(new StringInput($command.' --no-interaction --quiet'));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array{environment?: string, debug?: mixed} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        /** @var TestKernel $kernel */
        $kernel = parent::createKernel($options);
        $kernel->addTestConfig(__DIR__ . '/../../config.yaml');
        $kernel->addTestBundle(LingodaDomainEventsBundle::class);
        $kernel->addTestBundle(DoctrineBundle::class);
        $kernel->addTestBundle(DunglasDoctrineJsonOdmBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    public function testPurgeAll(): void
    {
        $record = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record2 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record3 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record4 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());

        $record->setPublishedOn(CarbonImmutable::parse('2023-05-16 13:00'));
        $record2->setPublishedOn(CarbonImmutable::parse('2023-05-23 11:33'));
        $record3->setPublishedOn(CarbonImmutable::parse('2023-05-26 12:44'));
        $record4->setPublishedOn(CarbonImmutable::parse('2023-06-07 14:53'));

        $this->em->persist($record);
        $this->em->persist($record2);
        $this->em->persist($record3);
        $this->em->persist($record4);

        $this->em->flush();

        $this->store->purgePublishedEvents();

        $this->assertEmpty($this->repo->findBy([]));
    }

    public function testPurgeBeforeDate(): void
    {
        $record = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record2 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record3 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record4 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());

        $record->setPublishedOn(CarbonImmutable::parse('2023-05-16 13:00'));
        $record2->setPublishedOn(CarbonImmutable::parse('2023-05-23 11:33'));
        $record3->setPublishedOn(CarbonImmutable::parse('2023-05-26 12:44'));
        $record3->setPublishedOn(CarbonImmutable::parse('2023-05-26 13:44'));
        $record4->setPublishedOn(CarbonImmutable::parse('2023-06-07 14:53'));


        $this->em->persist($record);
        $this->em->persist($record2);
        $this->em->persist($record3);
        $this->em->persist($record4);

        $this->em->flush();

        $this->store->purgePublishedEvents(CarbonImmutable::parse('2023-05-26 13:44'));

        $result = $this->repo->findBy([]);

        $this->assertCount(2, $result);
        $this->assertEquals($record3, $result[0]);
        $this->assertEquals($record4, $result[1]);
    }

    public function testPurgeInterval(): void
    {
        $record = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record2 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record3 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record4 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record5 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());
        $record6 = new OutboxRecord('40M02TXZ2Y9YZRVHQ2A3VTBB1H', new MockEvent('40M02TXZ2Y9YZRVHQ2A3VTBB1H'), CarbonImmutable::now());

        $record->setPublishedOn(CarbonImmutable::parse('2023-05-16 13:00'));
        $record2->setPublishedOn(CarbonImmutable::parse('2023-05-23 11:33'));
        $record3->setPublishedOn(CarbonImmutable::parse('2023-05-26 12:44'));
        $record4->setPublishedOn(CarbonImmutable::parse('2023-06-07 14:53'));
        $record5->setPublishedOn(CarbonImmutable::parse('2023-06-24 18:14'));
        $record6->setPublishedOn(CarbonImmutable::parse('2023-06-28 12:33'));

        $this->em->persist($record);
        $this->em->persist($record2);
        $this->em->persist($record3);
        $this->em->persist($record4);
        $this->em->persist($record5);
        $this->em->persist($record6);

        $this->em->flush();

        $this->store->purgePublishedEvents(CarbonInterval::days(30));

        $result = $this->repo->findBy([]);

        $this->assertCount(3, $result);

        $this->assertEquals($record4, $result[0]);
        $this->assertEquals($record5, $result[1]);
        $this->assertEquals($record6, $result[2]);
    }
}

/**
 * Mock event for testing...
 */
class MockEvent implements DomainEvent
{
    use DomainEventTrait;
    public function __construct(
        string $entityId,
    ) {
        $this->init($entityId);
    }
}