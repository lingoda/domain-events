# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 3.0.0

Outbox records are now only recorded as published once the worker confirms the domain event
was handled - which, for an asynchronously routed event, means it reached the broker. Claiming
a record is separate and reversible, so nothing is lost when a worker stops, fails to publish
or is killed outright. The transport can also claim records in batches, which cuts the number
of commits it needs.

### Breaking

- **Delivery is now at least once.** Previously a record was marked published *before* the
  event was relayed, so a worker killed mid-relay silently lost it. It is now marked after
  confirmation, which means a worker killed between publishing and confirming will relay that
  event again. **Handlers must be idempotent.** Envelopes carry an `OutboxRecordIdStamp` whose
  id is stable across replays to deduplicate on.
- **Schema change**: `OutboxRecord` gains a nullable `claimedAt` column and an
  `idx_unpublished_claimed_occurred` index. Run `doctrine:migrations:diff`. `publishedOn` keeps
  its meaning - a record is published, not merely claimed - so `OutboxStore::publish()`,
  `purgePublishedEvents()` and `LockableEventPublisher` are unaffected.
- `OutboxTransport::reject()` no longer deletes the record. It discarded a domain event that
  had never been published; now the record keeps its claim and is retried once the lease
  expires. A permanently failing event therefore retries indefinitely and stays visible in
  `getMessageCount()` rather than disappearing.
- `OutboxRecordRepository::getRecordCount()` and `createAvailableMessagesQueryBuilder()` now
  require the lease cut-off as an argument.
- Removed `OutboxRecordRepository::fetchNextRecordForUpdate()` (superseded by
  `fetchAvailableRecordsForUpdate()` with a limit of 1) and `deleteRecord()` (superseded by
  `deleteRecords()`).

### Added

- `batch_size` DSN option (default `1`) - claim that many records per transaction instead of
  one, cutting commits, and their fsync plus round-trip, by roughly `batch_size`. Use
  `skip_locked` with it: a batched `FOR UPDATE` holds more rows for longer, so concurrent
  consumers contend and deadlock far more without it. A batch is relayed lazily, so a worker
  told to stop hands back whatever it never relayed.
- `ack_flush` DSN option (default `0`, meaning once per batch) - confirm every N records, which
  caps how many records an abrupt crash can replay.
- `lease` DSN option (default `300` seconds) - how long a claim hides a record from other
  workers. Must comfortably exceed the time one batch takes to relay.
- `prune` DSN option (default `false`) - delete records once confirmed instead of stamping
  `publishedOn`, removing the need for `purgePublishedEvents()` housekeeping.
- `consumer_bus` DSN option (default none) - stamp a `BusNameStamp` so the consumed
  `OutboxMessage` is handled on a middleware-light bus, replacing an app-side
  `WorkerMessageReceivedEvent` listener.
- `OutboxRecordIdStamp`, stamped on the re-dispatched envelope so consumers can deduplicate
  replays. `OutboxMessage` carries the record id to make that possible.
- `OutboxRecordRepository::fetchAvailableRecordsForUpdate()`, `markRecordsPublished()`,
  `releaseRecords()` and `deleteRecords()`.
- `OutboxTransport::__construct()` gains `$batchSize`, `$prune`, `$consumerBus`,
  `$leaseSeconds` and `$ackFlush`. All are optional, so existing callers are unaffected.

### Changed

- `OutboxTransport::get()` no longer calls `flush()` when there is nothing to claim. Previously
  an idle poll flushed *and committed* any unrelated pending changes on the entity manager
  inside the transport's own transaction.
- Claimed records are `detach()`ed after the transaction commits, bounding identity-map growth
  over a worker's lifetime.
