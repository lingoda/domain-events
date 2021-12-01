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

## Usage

Example of simple User entity that triggers a Domain Event

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

After that we can consume the Outbox table and dispatch domain events from it with the below command

```bash
bin/console messenger:consume outbox
```

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

### Run PHPUnit tests

```bash
vendor/bin/phpunit
```

## TODO

-   Add functional tests
-   Improve OutboxTransportFactory with additional options in the DSN
-
