<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @implements TransportFactoryInterface<OutboxTransport>
 */
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

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        $skipLocked = filter_var(
            $options['skip_locked'] ?? $query['skip_locked'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        $prune = filter_var(
            $options['prune'] ?? $query['prune'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        $batchSize = filter_var(
            $options['batch_size'] ?? $query['batch_size'] ?? 1,
            FILTER_VALIDATE_INT,
        );
        if (false === $batchSize || $batchSize < 1) {
            throw new InvalidArgumentException(
                sprintf('The "batch_size" option of the DSN "%s" must be an integer greater than 0.', $dsn)
            );
        }

        $consumerBus = $options['consumer_bus'] ?? $query['consumer_bus'] ?? null;
        if (null !== $consumerBus && (!\is_string($consumerBus) || '' === $consumerBus)) {
            throw new InvalidArgumentException(
                sprintf('The "consumer_bus" option of the DSN "%s" must be a non-empty string.', $dsn)
            );
        }

        $leaseSeconds = filter_var(
            $options['lease'] ?? $query['lease'] ?? 300,
            FILTER_VALIDATE_INT,
        );
        if (false === $leaseSeconds || $leaseSeconds < 1) {
            throw new InvalidArgumentException(
                sprintf('The "lease" option of the DSN "%s" must be a number of seconds greater than 0.', $dsn)
            );
        }

        $ackFlush = filter_var(
            $options['ack_flush'] ?? $query['ack_flush'] ?? 0,
            FILTER_VALIDATE_INT,
        );
        if (false === $ackFlush || $ackFlush < 0) {
            throw new InvalidArgumentException(
                sprintf('The "ack_flush" option of the DSN "%s" must be 0 or a positive integer.', $dsn)
            );
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->managerRegistry->getManager($components['host']);

        return new OutboxTransport(
            $entityManager,
            $skipLocked,
            $batchSize,
            $prune,
            $consumerBus,
            $leaseSeconds,
            $ackFlush,
        );
    }

    /**
     * @param array<string, mixed>  $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'outbox://');
    }
}
