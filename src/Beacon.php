<?php

namespace BeaconHQ;

class Beacon
{
    private static ?string $ingestUrl = null;
    private static ?string $release = null;
    private static ?string $url = null;
    private static ?string $userAgent = null;
    private static string $environment = 'production';
    private static string $sdkVersion = '1.0.0';
    private static bool $initialized = false;
    private static array $user = [];
    private static array $tags = [];
    private static $beforeSend = null;
    private static $transport = null;
    private static $previousExceptionHandler = null;

    /**
     * Initialize the Beacon SDK.
     *
     * DSN format: https://pub_KEY@api.beaconhq.dev/PROJECT_ID
     */
    public static function init(array $options): void
    {
        $dsn = $options['dsn'] ?? throw new \InvalidArgumentException('[Beacon] dsn is required');

        if (!preg_match('#^(https?)://([^@]+)@([^/]+)/(.+)$#', $dsn, $m)) {
            throw new \InvalidArgumentException("[Beacon] Invalid DSN: {$dsn}");
        }

        [, $scheme, $key, $host, $projectId] = $m;

        self::$ingestUrl = "{$scheme}://{$host}/api/ingest"
            . '?key=' . rawurlencode($key)
            . '&project=' . rawurlencode($projectId);

        self::$release = $options['release'] ?? null;
        self::$environment = $options['environment'] ?? 'production';
        self::$beforeSend = $options['before_send'] ?? null;
        self::$initialized = true;

        self::installExceptionHandler();
    }

    /**
     * Set the current user context.
     */
    public static function setUser(?string $id = null, ?string $email = null, ?string $name = null): void
    {
        self::$user = array_filter(
            compact('id', 'email', 'name'),
            fn ($v) => $v !== null
        );
    }

    /**
     * Set the HTTP request context to attach to subsequent events.
     */
    public static function setContext(?string $url = null, ?string $userAgent = null): void
    {
        self::$url = $url;
        self::$userAgent = $userAgent;
    }

    /**
     * Set a tag to attach to all subsequent events.
     */
    public static function setTag(string $key, string $value): void
    {
        self::$tags[$key] = $value;
    }

    /**
     * Capture a Throwable and send it to Beacon.
     */
    public static function captureException(\Throwable $e, array $extra = []): void
    {
        if (!self::$initialized) {
            return;
        }

        self::send(self::buildPayload(
            get_class($e),
            $e->getMessage(),
            'error',
            $e->getTraceAsString(),
            $extra
        ));
    }

    /**
     * Capture a message and send it to Beacon.
     */
    public static function captureMessage(string $message, string $level = 'info', array $extra = []): void
    {
        if (!self::$initialized) {
            return;
        }

        self::send(self::buildPayload('Message', $message, $level, '', $extra));
    }

    /**
     * Install the global exception and fatal error handlers.
     */
    private static function installExceptionHandler(): void
    {
        $previous = set_exception_handler(function (\Throwable $e) use (&$previous) {
            self::captureException($e);
            if ($previous) {
                $previous($e);
            }
        });
        self::$previousExceptionHandler = $previous;

        // Catch fatal errors via shutdown function
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                if (!self::$initialized) {
                    return;
                }
                self::send(self::buildPayload(
                    'FatalError',
                    $error['message'],
                    'fatal',
                    "#{$error['file']}({$error['line']}): {$error['message']}"
                ));
            }
        });
    }

    /**
     * Build the event payload array.
     */
    private static function buildPayload(
        string $type,
        string $message,
        string $level,
        string $stack,
        array $extra = []
    ): array {
        return [
            'type' => $type,
            'message' => $message,
            'level' => $level,
            'stack' => $stack,
            'release' => self::$release,
            'environment' => self::$environment,
            'sdk_version' => self::$sdkVersion,
            'user_id_ext' => self::$user['id'] ?? null,
            'user_email' => self::$user['email'] ?? null,
            'user_name' => self::$user['name'] ?? null,
            'tags' => self::$tags,
            'extra' => $extra,
            'url' => self::$url,
            'user_agent' => self::$userAgent,
        ];
    }

    /**
     * Send a payload to the Beacon ingest endpoint.
     */
    private static function send(array $payload): void
    {
        if (!self::$ingestUrl) {
            return;
        }

        if (self::$beforeSend !== null) {
            try {
                $payload = (self::$beforeSend)($payload);
            } catch (\Throwable) {
                // crashing before_send must not drop the event
            }
        }

        if ($payload === null) {
            return;
        }

        $body = json_encode($payload);

        if (self::$transport !== null) {
            (self::$transport)(self::$ingestUrl, $body);
            return;
        }

        $ch = curl_init(self::$ingestUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Override the HTTP transport for testing.
     * The callable receives (string $url, string $jsonBody).
     */
    public static function _setTransport(?callable $fn): void
    {
        self::$transport = $fn;
    }

    /**
     * Reset all static state. For testing only.
     */
    public static function reset(): void
    {
        // Restore the previous exception handler if we installed one
        if (self::$initialized) {
            restore_exception_handler();
        }

        self::$ingestUrl = null;
        self::$release = null;
        self::$environment = 'production';
        self::$initialized = false;
        self::$user = [];
        self::$tags = [];
        self::$beforeSend = null;
        self::$transport = null;
        self::$previousExceptionHandler = null;
        self::$url = null;
        self::$userAgent = null;
    }
}
