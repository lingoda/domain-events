<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class OutboxTransportFactory implements TransportFactoryInterface
{
    private ManagerRegistry $managerRegistry;

    public function __construct(ManagerRegistry $managerRegistry)
    {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * @param array<string, mixed>  $options
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $components = parse_url($dsn);
        if (false === $components) {
            throw new InvalidArgumentException(sprintf('The given Outbox Messenger DSN "%s" is invalid.', $dsn));
        }

        if (!isset($components['host'])) {
            throw new InvalidArgumentException(sprintf('Missing host segment in the DSN "%s".', $dsn));
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->managerRegistry->getManager($components['host']);

        return new OutboxTransport($entityManager);
    }

    /**
     * @param array<string, mixed>  $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'outbox://');
    }
}
