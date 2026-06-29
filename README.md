# Event Loading Core (Design Task)

This project contains a minimal implementation of a **distributed-safe event loading core** for collecting immutable events from multiple sources into centralized storage.

It focuses on:
- clear interfaces for source access, storage, and coordination
- a main round-robin loader flow
- conflict-free parallel execution across multiple loader instances

## Implemented Components

### Domain and Contracts
- `src/EventLoading/Event.php` – immutable event model (`id` + payload)
- `src/EventLoading/EventSourceInterface.php` – fetch events after last known ID
- `src/EventLoading/EventStoreInterface.php` – persist fetched events
- `src/EventLoading/SourcePollingCoordinatorInterface.php` – distributed coordination contract:
  - exclusive source polling token
  - global minimum interval enforcement
  - cursor commit / release
- `src/EventLoading/EventLoaderInterface.php` – loader contract

### Loader Core
- `src/EventLoading/RoundRobinEventLoader.php`

Behavior:
- infinite loop (`run(): never`) with round-robin source polling
- max request size capped at 1000 events
- at least 200ms between requests to the same source (via coordinator contract)
- no duplicate event transport in parallel mode (cursor + exclusive acquisition)
- source/network failures are logged and skipped

## Concurrency Design

`RoundRobinEventLoader` is stateless regarding distributed coordination.  
All cross-instance guarantees are delegated to `SourcePollingCoordinatorInterface`, which should be backed by a shared mechanism (for example DB row locks, Redis locks, etc.).

Expected coordinator guarantees:
1. only one loader instance can poll a given source at a time
2. global per-source next-allowed-request timestamp is respected
3. last stored event ID (cursor) is atomically updated on commit

With these guarantees, the same `(source, event_id)` is never requested over network more than once.

## Demo

A runnable demo is provided:
- `bin/event-loader-demo.php`

It uses in-memory stubs for:
- source coordinator
- event sources
- event store
- logger

The demo simulates two loader instances working in parallel and prints stored IDs.

## Run

From project root:

```bash
php bin/event-loader-demo.php
```

If you run from Windows with WSL:

```powershell
wsl -d Ubuntu-24.04 sh -lc 'cd /path/to/project && php bin/event-loader-demo.php'
```

## Expected Output (example)

```text
[WARNING] Source "beta" is unavailable: Temporary network error
Stored unique events:
- alpha: 1, 2, 3, 4, 5, 6
- beta: 1, 2, 3, 4
```

The warning demonstrates source failure handling; final lists demonstrate that events are stored without duplicates.
