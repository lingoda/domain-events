# Domain Events Bundle

## Installation

```bash
composer req lingoda/domain-events
```

### Bundle configuration

```yaml
# config/packages/domain_events.yaml

lingoda_domain_events:
    message_bus_name: 'event.bus'

    // default is false, you can turn on event publishing on Kernel, Console and WorkerMessageHandledEvent events, usefull in test environment
    enable_event_publisher: true
```

Records currently claimed by the messenger transport are skipped by this publisher and by
`ReplaceableDomainEvent` replacement, so the two mechanisms cannot publish the same event
twice. One consequence: if a worker dies holding a claim, a replacement event will not
supersede that record until its lease expires, and both may end up relayed.

## Usage
**IMPORTANT NOTE:** Never record domain events in doctrine lifecycle hooks!

Example of simple User entity that triggers a Domain Event.

### Sample Domain Event

```php

use Lingoda\DomainEventsBundle\Domain\Model\DomainEvent;
use Lingoda\DomainEventsBundle\Domain\Model\Traits\DomainEventTrait;

/**
 * Sample domain event
 */
class UserCreatedEvent implements DomainEvent
{
    use DomainEventTrait;

    private string $username;

    public function __construct(string $entityId, string $username)
    {
        $this->username = $username;
        $this->init($entityId);
    }

    public function getUsername(): string
    {
        return $this->username;
    }
}
```

### Sample User entity that records the event

```php
use Lingoda\DomainEventsBundle\Domain\Model\DomainEventAware;
use Lingoda\DomainEventsBundle\Domain\Model\Traits\EventRecorderTrait;
use Symfony\Component\Uid\Ulid;

// DomainEventAware interface is a helper that brings RecordsEvents and ContainsEvents together
class User implements DomainEventAware
{
    // helper trait for event recording
    use EventRecorderTrait;

    private Ulid $id;
    private string $username;

    public function __construct(string $username)
    {
        $this->id = new Ulid();
        $this->username = $username;

        $this->recordEvent(new UserCreatedEvent(
            $this->id->toRfc4122(),
            $username
        ));
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function setId(Ulid $id): void
    {
        $this->id = $id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }
}
```

### In action

```php

// create the entity will record the domain event
$user = new User('john-doe');

$entityManager->persist($user);

/**
 * When we flush the changes PersistDomainEventsSubscriber will kick in and create a OutboxRecord entity containing
 * the domain event in it that will be stored within the same transaction together with the User entity
 */
$entityManager->flush();

// Later on the PublishDomainEventsSubscriber will publish via Messenger all unpublished Domain Event from OutboxRecord
// database on the following events KernelEvents::TERMINATE, ConsoleEvents::TERMINATE or WorkerMessageHandledEvent
```

### Dispatching domain events with Messenger Worker

First configure the outbox messenger transport

```yaml
framework:
  messenger:
    transports:
      outbox:
        dsn: 'outbox://default' // the host part is the doctrine enity mananager name, this case default

    routing:
      Lingoda\DomainEventsBundle\Infra\Symfony\Messenger\OutboxMessage: outbox
```

#### Skip Locked

When running multiple consumers concurrently, you can enable `SKIP LOCKED` to avoid row contention.
Instead of consumers blocking each other on locked rows, each consumer will skip already-locked rows and pick the next available one.

This requires MySQL 8.0+ or PostgreSQL 9.5+.

Enable it via the DSN:

```yaml
framework:
  messenger:
    transports:
      outbox:
        dsn: 'outbox://default?skip_locked=true'
```

Or via transport options:

```yaml
framework:
  messenger:
    transports:
      outbox:
        dsn: 'outbox://default'
        options:
          skip_locked: true
```

After that we can consume the Outbox table and dispatch domain events from it with the below command

```bash
bin/console messenger:consume outbox
```

#### Batching

By default the transport claims **one** record per database transaction, so throughput is
bound by the commit fsync and round-trip of every single event. Raising `batch_size` claims
that many records in one transaction instead, cutting the number of commits by roughly
`batch_size`.

```yaml
framework:
  messenger:
    transports:
      outbox:
        dsn: 'outbox://default?skip_locked=true&batch_size=100&ack_flush=10&consumer_bus=outbox.bus'
```

All options can equally be given as transport options:

```yaml
framework:
  messenger:
    transports:
      outbox:
        dsn: 'outbox://default'
        options:
          skip_locked: true
          batch_size: 100
          ack_flush: 10
          consumer_bus: 'outbox.bus'
```

| Option | Default | Description |
| --- | --- | --- |
| `skip_locked` | `false` | As above. **Strongly recommended** with `batch_size` > 1: a batched `FOR UPDATE` holds more rows for longer, so concurrent consumers contend and deadlock far more without it. |
| `batch_size` | `1` | How many records to claim per transaction. Must be >= 1. |
| `prune` | `false` | `true` deletes records once they are confirmed instead of stamping `publishedOn`, which removes the need for `OutboxStore::purgePublishedEvents()` housekeeping. |
| `consumer_bus` | none | Name of the bus that should handle the consumed `OutboxMessage`. Set this to a middleware-light bus to skip the default bus's `doctrine_ping_connection` and `doctrine_close_connection` middleware on every message. The bus still has to exist in your app, and the handler is registered on all buses already. |
| `lease` | `300` | Seconds a claim hides a record from other workers. Must comfortably exceed the time one batch takes to relay, or a live worker's records can be claimed a second time. |
| `ack_flush` | `0` | Confirm every N records instead of once per batch. Caps how many records an abrupt crash can replay, at the cost of one extra commit per flush. `0` means once per batch. |

#### Delivery guarantees

**A record is only recorded as published once the worker confirms the domain event was
handled** - which, for an asynchronously routed event, means it reached the broker. Claiming
is separate and reversible: `claimedAt` hides a record from other workers without asserting
anything about publication.

That gives three distinct outcomes:

| | result |
| --- | --- |
| Worker stops gracefully mid-batch (SIGTERM on deploy, `--time-limit`, `--memory-limit`, `messenger:stop-workers`) | Confirmed records are published, the rest give up their claim immediately. **Nothing lost, nothing replayed.** |
| Publishing fails (broker down, handler throws) | The record keeps its claim and is retried once the lease expires. Nothing is deleted. |
| Worker is killed outright (SIGKILL, OOM killer, power loss) | Nothing is lost. Records published but not yet confirmed are **replayed** once the lease expires - at most `ack_flush` of them, or the whole batch if `ack_flush` is 0. |

So delivery is **at least once**: your handlers must be idempotent. There is no way around
this - the database and the broker cannot share a transaction, so a crash between publishing
an event and recording that fact must either lose the event or replay it, and this transport
chooses to replay.

A replay is byte-identical to the original, and the envelope carries an `OutboxRecordIdStamp`
whose id is stable across replays, so consumers can deduplicate on it.

Note that a permanently failing event is retried every lease window indefinitely and keeps
showing up in `getMessageCount()`. That is deliberate - it is visible rather than silently
discarded - but it does mean a poison event shows as a backlog that never drains.

`prune: true` only changes what confirming a record does - delete it rather than stamp
`publishedOn`. It is safe at any `batch_size`, and the trade is that a confirmed event leaves
no row behind to inspect.

#### Upgrading from 2.x

1. **Run `doctrine:migrations:diff`.** `OutboxRecord` gains a nullable `claimedAt` column and
   an `idx_unpublished_claimed_occurred` index. `publishedOn` keeps its meaning, so
   `OutboxStore::publish()`, `purgePublishedEvents()` and `LockableEventPublisher` are
   unaffected.
2. **Make your handlers idempotent.** Delivery is at least once now (see above). Deduplicate on
   `OutboxRecordIdStamp`, whose id is stable across replays.
3. **A failing event no longer disappears.** `reject()` used to delete the record; it now keeps
   its claim and retries every lease window, so a poison event shows up as a backlog that never
   drains rather than as silent data loss.
4. **Retune for throughput.** Confirming publication is a second write, so at the default
   `batch_size: 1` a message costs two commits where 2.x cost one. Batching pays that back, and
   `ack_flush` caps replays independently of batch size:

   | config | commits per message | max replayed on a hard kill |
   | --- | --- | --- |
   | 2.x | 1.0 | none - but up to 1 event *lost* per crash |
   | `batch_size=1` (default) | 2.0 | 1 |
   | `batch_size=10&ack_flush=1` | 1.1 | 1 |
   | `batch_size=100&ack_flush=1` | 1.01 | 1 |
   | `batch_size=100&ack_flush=10` | 0.11 | 10 |

   `?skip_locked=true&batch_size=100&ack_flush=10` is a good starting point; use `ack_flush=1`
   if you would rather hold replays to a single record. The default is deliberately the
   slowest row - raising `batch_size` widens `FOR UPDATE` to that many rows, which needs
   `skip_locked` (MySQL 8+ / PostgreSQL 9.5+) to avoid contention between workers.
5. **Check for direct repository calls.** `fetchNextRecordForUpdate()` and `deleteRecord()` are
   gone; `getRecordCount()` and `createAvailableMessagesQueryBuilder()` now take the lease
   cut-off.

### Scheduling events

We can schedule Domain Events to be published in the future

```php

// let's say we have AskForUserFeedbackEvent the following event that should be triggered 2 weeks after user registration
// and send a followup email to the user

// we could schedule this like follow

$askForUserFeedbackEvent = new AskForUserFeedbackEvent($user->getId());
$askForUserFeedbackEvent->setOccuredAt(
    new CarbonImmutable('+2 weeks')
);

$user->recordEvent($askForUserFeedbackEvent);

// this will be stored in OutboxRecord table and unpublished until the due date
```

### Replacing/Re-scheduling events in the event_store

We can replace/re-schedule unpublished events by implementing the `ReplaceableEventInterface` for the Domain Event
If you implement this interface, before the `OutboxRecord` persister stores a new domain event, it will check if there is
any previously stored but unpublished events from the same entity id, if yes it will delete them and add the new one only.

### Enriching Domain Events

While domain events should be immutable, sometimes it's inevitable that you need to enrich with additional information
but you don't want to assign at creation time because the service is not accessible inside the entity.

You can listen to the `PreAppendEvent` in a subscriber/listener that is dispatched right before the Domain Event gets
persisted. At this point you can enrich with additional information.

Simple example would be injecting and actorId which corresponds to the user id that is currently interacting with the app.

## Testing

### Install dev dependencies

```bash
# Install dev dependecies
composer install --dev
```

### Run tests

```bash
vendor/bin/phpunit
vendor/bin/phpspec run
```

## TODO

- Add functional tests
- Add instructions for doctrine mapping and routing DomainEvent
- Fix issues around Carbon serialization
