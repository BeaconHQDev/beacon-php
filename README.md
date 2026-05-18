# beacon-php

Official PHP SDK for [Beacon](https://beaconhq.dev) — self-hosted JavaScript & PHP error tracking.

## Installation

```bash
composer require beaconhq/beacon-php
```

**Requirements:** PHP >= 8.1, ext-curl

## Quick Start

```php
use BeaconHQ\Beacon;

Beacon::init([
    'dsn'         => 'https://pub_YOUR_KEY@api.beaconhq.dev/YOUR_PROJECT_ID',
    'release'     => '1.0.0',          // optional
    'environment' => 'production',     // optional, default: 'production'
]);
```

After calling `init`, Beacon automatically installs a global exception handler that captures any uncaught `Throwable`.

## Usage

### Capture an Exception

```php
try {
    riskyOperation();
} catch (\Throwable $e) {
    Beacon::captureException($e);
    // or re-throw — the global handler will catch it too
}
```

### Capture a Message

```php
Beacon::captureMessage('Payment gateway timed out', 'warning');
// Levels: debug, info, warning, error, fatal
```

### Set User Context

```php
Beacon::setUser(
    id: 'user-123',
    email: 'alice@example.com',
    name: 'Alice',
);
```

Pass `null` for any field to omit it. All subsequent events will include the user context until reset.

### Set Tags

Tags let you filter events in the Beacon dashboard:

```php
Beacon::setTag('server', 'web-01');
Beacon::setTag('region', 'us-east-1');
```

### beforeSend Hook

Inspect or modify every event before it is sent. Return `null` to drop the event entirely.

```php
Beacon::init([
    'dsn' => '...',
    'before_send' => function (array $payload): ?array {
        // Drop events from local dev
        if ($payload['environment'] === 'local') {
            return null;
        }

        // Scrub sensitive data
        unset($payload['extra']['password']);

        return $payload;
    },
]);
```

## Fatal Error Capture

`Beacon::init` registers a `register_shutdown_function` that captures PHP fatal errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`) automatically. No additional setup required.

## DSN Format

```
https://pub_KEY@api.beaconhq.dev/PROJECT_ID
```

Find your DSN in the Beacon dashboard under **Project Settings → API Keys**.

## License

MIT
