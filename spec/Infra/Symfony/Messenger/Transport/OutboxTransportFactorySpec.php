<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Entity\OutboxRecord;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Repository\OutboxRecordRepository;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransport;
use Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport\OutboxTransportFactory;
use PhpSpec\ObjectBehavior;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class OutboxTransportFactorySpec extends ObjectBehavior
{
    function let(
        ManagerRegistry $managerRegistry,
        EntityManagerInterface $entityManager,
        OutboxRecordRepository $outboxRecordRepository
    ) {
        $this->beConstructedWith($managerRegistry);

        $managerRegistry->getManager('default')->willReturn($entityManager);
        $entityManager->getRepository(OutboxRecord::class)->willReturn($outboxRecordRepository);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(OutboxTransportFactory::class);
    }

    function it_only_supports_the_outbox_dsn()
    {
        $this->supports('outbox://default', [])->shouldBe(true);
        $this->supports('outbox://default?skip_locked=true', [])->shouldBe(true);

        $this->supports('doctrine://default', [])->shouldBe(false);
        $this->supports('sync://', [])->shouldBe(false);
    }

    function it_creates_a_transport_from_the_bare_dsn(SerializerInterface $serializer)
    {
        $this->createTransport('outbox://default', [], $serializer)
            ->shouldBeAnInstanceOf(OutboxTransport::class)
        ;
    }

    function it_reads_the_options_from_the_dsn_query_string(
        SerializerInterface $serializer,
        ManagerRegistry $managerRegistry
    ) {
        $dsn = 'outbox://default?skip_locked=true&batch_size=50&prune=false&consumer_bus=outbox.bus';

        $this->createTransport($dsn, [], $serializer)
            ->shouldBeAnInstanceOf(OutboxTransport::class)
        ;

        $managerRegistry->getManager('default')->shouldHaveBeenCalled();
    }

    function it_reads_the_options_from_the_transport_options(SerializerInterface $serializer)
    {
        $options = [
            'skip_locked' => true,
            'batch_size' => 50,
            'prune' => false,
            'consumer_bus' => 'outbox.bus',
        ];

        $this->createTransport('outbox://default', $options, $serializer)
            ->shouldBeAnInstanceOf(OutboxTransport::class)
        ;
    }

    function it_can_prune_a_batch_now_that_deletion_happens_on_ack(SerializerInterface $serializer)
    {
        // records are only deleted once confirmed, so pruning a batch loses nothing
        $this->createTransport('outbox://default?prune=true&batch_size=100', [], $serializer)
            ->shouldBeAnInstanceOf(OutboxTransport::class)
        ;
    }

    function it_throws_on_an_unusable_lease(SerializerInterface $serializer)
    {
        foreach (['0', '-1', 'soon'] as $lease) {
            $this->shouldThrow(InvalidArgumentException::class)
                ->during('createTransport', ["outbox://default?lease={$lease}", [], $serializer])
            ;
        }
    }

    function it_throws_on_an_unusable_ack_flush(SerializerInterface $serializer)
    {
        $this->shouldThrow(InvalidArgumentException::class)
            ->during('createTransport', ['outbox://default?ack_flush=-1', [], $serializer])
        ;

        // 0 means confirm once per batch
        $this->createTransport('outbox://default?ack_flush=0', [], $serializer)
            ->shouldBeAnInstanceOf(OutboxTransport::class)
        ;
    }

    function it_throws_on_a_missing_host(SerializerInterface $serializer)
    {
        $this->shouldThrow(InvalidArgumentException::class)
            ->during('createTransport', ['outbox://', [], $serializer])
        ;
    }

    function it_throws_on_an_unusable_batch_size(SerializerInterface $serializer)
    {
        foreach (['0', '-1', 'many', '1.5'] as $batchSize) {
            $this->shouldThrow(InvalidArgumentException::class)
                ->during('createTransport', ["outbox://default?batch_size={$batchSize}", [], $serializer])
            ;
        }
    }

    function it_throws_on_an_empty_consumer_bus(SerializerInterface $serializer)
    {
        $this->shouldThrow(InvalidArgumentException::class)
            ->during('createTransport', ['outbox://default?consumer_bus=', [], $serializer])
        ;
    }
}
