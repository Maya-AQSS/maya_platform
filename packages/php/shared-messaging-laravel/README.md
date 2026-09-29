# ceedcv-maya/shared-messaging-laravel

RabbitMQ messaging layer for Laravel: typed event publishers (audit, logs, notifications, alerts), reusable consumer base, retry/DLX handling, resilient retry-on-disconnect.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-messaging-laravel
```

## Quick start

```php
use Maya\Messaging\Publishers\AuditPublisher;

AuditPublisher::dispatch([
    'app' => 'orders',
    'action' => 'create',
    'entity_type' => 'order',
    'entity_id' => $order->id,
    'user_id' => auth()->id(),
]);
```

Configuration:

```env
RABBITMQ_HOST=rabbitmq.example.org
RABBITMQ_USER=guest
RABBITMQ_PASS=guest
```

## Features

### Publishers (Audit, Logs, Notifications, Alerts)

Type-safe message dispatch for cross-app event ingestion. Each publisher validates payload shape and routes to its exchange (`maya.audit`, `maya.logs`, etc.).

### Resilient retry on RabbitMQ disconnect

When RabbitMQ is unavailable, `RetryAmqpPublishJob` buffers failed publishes using Laravel's queue system with exponential backoff:

- **Queue connection**: Configured via `messaging.retry.connection`. Defaults to:
  - `database` in development (if default queue is `rabbitmq`)
  - Your app's default queue in production (typically Redis)
- **Retry policy**: 5 attempts with backoff `[30s, 60s, 120s, 300s, 600s]` (~17 min total)
- **Excluded**: `LogPublisher` (high volume, disk-backed already)

Configure in `config/messaging.php`:

```php
'retry' => [
    'connection' => 'database',  // or 'redis', null for app default
],
```

See [`RetryAmqpPublishJob.php`](src/Jobs/RetryAmqpPublishJob.php) for implementation.

### Consumer base

Reusable `ConsumeQueueCommand` for building app-specific consumers with the same error handling and ACK policy (see `maya_logs`, `maya_audit`, `maya_dashboard`).

## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
