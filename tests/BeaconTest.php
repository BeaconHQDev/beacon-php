<?php

namespace BeaconHQ\Tests;

use BeaconHQ\Beacon;
use PHPUnit\Framework\TestCase;

class BeaconTest extends TestCase
{
    protected function setUp(): void
    {
        Beacon::reset();
    }

    protected function tearDown(): void
    {
        Beacon::reset();
    }

    // -------------------------------------------------------------------------
    // DSN parsing
    // -------------------------------------------------------------------------

    public function test_valid_https_dsn_builds_correct_ingest_url(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured['url'] = $url;
        });

        Beacon::init(['dsn' => 'https://pub_abc123@api.beaconhq.dev/proj-xyz']);
        Beacon::captureMessage('hello');

        $this->assertStringStartsWith('https://api.beaconhq.dev/api/ingest', $captured['url']);
        $this->assertStringContainsString('key=pub_abc123', $captured['url']);
        $this->assertStringContainsString('project=proj-xyz', $captured['url']);
    }

    public function test_http_dsn_is_accepted(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured['url'] = $url;
        });

        Beacon::init(['dsn' => 'http://pub_key99@localhost/local-project']);
        Beacon::captureMessage('test');

        $this->assertStringStartsWith('http://localhost/api/ingest', $captured['url']);
        $this->assertStringContainsString('key=pub_key99', $captured['url']);
        $this->assertStringContainsString('project=local-project', $captured['url']);
    }

    public function test_invalid_dsn_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid DSN/');

        Beacon::init(['dsn' => 'not-a-valid-dsn']);
    }

    public function test_missing_dsn_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dsn is required/');

        Beacon::init([]);
    }

    public function test_dsn_key_is_url_encoded(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured['url'] = $url;
        });

        // Key with special characters
        Beacon::init(['dsn' => 'https://pub_key+special@api.beaconhq.dev/proj']);
        Beacon::captureMessage('test');

        $this->assertStringContainsString('key=pub_key%2Bspecial', $captured['url']);
    }

    // -------------------------------------------------------------------------
    // init
    // -------------------------------------------------------------------------

    public function test_init_sets_initialized_state(): void
    {
        $called = false;
        Beacon::_setTransport(function () use (&$called) {
            $called = true;
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::captureMessage('ping');

        $this->assertTrue($called, 'Transport should be called after init');
    }

    public function test_init_sets_release(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p', 'release' => 'v2.1.0']);
        Beacon::captureMessage('test');

        $this->assertEquals('v2.1.0', $captured['release']);
    }

    public function test_init_sets_environment(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p', 'environment' => 'staging']);
        Beacon::captureMessage('test');

        $this->assertEquals('staging', $captured['environment']);
    }

    public function test_init_defaults_environment_to_production(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::captureMessage('test');

        $this->assertEquals('production', $captured['environment']);
    }

    // -------------------------------------------------------------------------
    // setUser
    // -------------------------------------------------------------------------

    public function test_set_user_sets_all_fields(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setUser('user-123', 'alice@example.com', 'Alice');
        Beacon::captureMessage('test');

        $this->assertEquals('user-123', $captured['user_id_ext']);
        $this->assertEquals('alice@example.com', $captured['user_email']);
        $this->assertEquals('Alice', $captured['user_name']);
    }

    public function test_set_user_omits_null_fields(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setUser(email: 'bob@example.com');
        Beacon::captureMessage('test');

        $this->assertNull($captured['user_id_ext']);
        $this->assertEquals('bob@example.com', $captured['user_email']);
        $this->assertNull($captured['user_name']);
    }

    public function test_set_user_clears_on_reset(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setUser('u1', 'u@example.com', 'User');

        Beacon::reset();

        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });
        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::captureMessage('after reset');

        $this->assertNull($captured['user_id_ext']);
        $this->assertNull($captured['user_email']);
        $this->assertNull($captured['user_name']);
    }

    // -------------------------------------------------------------------------
    // setTag
    // -------------------------------------------------------------------------

    public function test_set_tag_attaches_to_payload(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setTag('server', 'web-01');
        Beacon::captureMessage('test');

        $this->assertEquals('web-01', $captured['tags']['server']);
    }

    public function test_set_tag_overwrites_existing(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setTag('server', 'web-01');
        Beacon::setTag('server', 'web-02');
        Beacon::captureMessage('test');

        $this->assertEquals('web-02', $captured['tags']['server']);
    }

    public function test_set_tag_supports_multiple_tags(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setTag('region', 'us-east');
        Beacon::setTag('tier', 'premium');
        Beacon::captureMessage('test');

        $this->assertEquals('us-east', $captured['tags']['region']);
        $this->assertEquals('premium', $captured['tags']['tier']);
    }

    // -------------------------------------------------------------------------
    // captureException
    // -------------------------------------------------------------------------

    public function test_capture_exception_sends_correct_type_and_message(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);

        $e = new \RuntimeException('Something broke');
        Beacon::captureException($e);

        $this->assertEquals(\RuntimeException::class, $captured['type']);
        $this->assertEquals('Something broke', $captured['message']);
        $this->assertEquals('error', $captured['level']);
    }

    public function test_capture_exception_includes_stack_trace(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);

        $e = new \RuntimeException('Stack test');
        Beacon::captureException($e);

        $this->assertNotEmpty($captured['stack']);
        $this->assertStringContainsString('#0', $captured['stack']);
    }

    public function test_capture_exception_includes_extra(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);

        $e = new \RuntimeException('With extra');
        Beacon::captureException($e, ['request_id' => 'abc-123']);

        $this->assertEquals('abc-123', $captured['extra']['request_id']);
    }

    public function test_capture_exception_is_noop_before_init(): void
    {
        $called = false;
        Beacon::_setTransport(function () use (&$called) {
            $called = true;
        });

        // Do NOT call init
        Beacon::captureException(new \RuntimeException('noop'));

        $this->assertFalse($called, 'Transport should not be called before init');
    }

    public function test_capture_exception_handles_nested_exception_class(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);

        $e = new \InvalidArgumentException('Bad arg');
        Beacon::captureException($e);

        $this->assertEquals(\InvalidArgumentException::class, $captured['type']);
    }

    // -------------------------------------------------------------------------
    // captureMessage
    // -------------------------------------------------------------------------

    public function test_capture_message_defaults_to_info_level(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::captureMessage('Something happened');

        $this->assertEquals('info', $captured['level']);
        $this->assertEquals('Message', $captured['type']);
        $this->assertEquals('Something happened', $captured['message']);
    }

    public function test_capture_message_respects_custom_level(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::captureMessage('A warning', 'warning');

        $this->assertEquals('warning', $captured['level']);
    }

    public function test_capture_message_is_noop_before_init(): void
    {
        $called = false;
        Beacon::_setTransport(function () use (&$called) {
            $called = true;
        });

        Beacon::captureMessage('noop');

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // beforeSend
    // -------------------------------------------------------------------------

    public function test_before_send_can_drop_event_by_returning_null(): void
    {
        $called = false;
        Beacon::_setTransport(function () use (&$called) {
            $called = true;
        });

        Beacon::init([
            'dsn' => 'https://pub_k@api.beaconhq.dev/p',
            'before_send' => fn ($payload) => null,
        ]);

        Beacon::captureMessage('dropped');

        $this->assertFalse($called, 'Transport should not be called when before_send returns null');
    }

    public function test_before_send_can_modify_event(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init([
            'dsn' => 'https://pub_k@api.beaconhq.dev/p',
            'before_send' => function ($payload) {
                $payload['message'] = 'modified';
                return $payload;
            },
        ]);

        Beacon::captureMessage('original');

        $this->assertEquals('modified', $captured['message']);
    }

    public function test_crashing_before_send_does_not_drop_event(): void
    {
        $called = false;
        Beacon::_setTransport(function () use (&$called) {
            $called = true;
        });

        Beacon::init([
            'dsn' => 'https://pub_k@api.beaconhq.dev/p',
            'before_send' => function ($payload) {
                throw new \RuntimeException('before_send exploded');
            },
        ]);

        Beacon::captureMessage('should still send');

        $this->assertTrue($called, 'Transport should still be called when before_send throws');
    }

    // -------------------------------------------------------------------------
    // Transport / payload shape
    // -------------------------------------------------------------------------

    public function test_transport_receives_correct_url(): void
    {
        $capturedUrl = null;
        Beacon::_setTransport(function (string $url, string $body) use (&$capturedUrl) {
            $capturedUrl = $url;
        });

        Beacon::init(['dsn' => 'https://pub_mykey@api.beaconhq.dev/myproject']);
        Beacon::captureMessage('url test');

        $this->assertEquals(
            'https://api.beaconhq.dev/api/ingest?key=pub_mykey&project=myproject',
            $capturedUrl
        );
    }

    public function test_transport_body_is_valid_json(): void
    {
        $capturedBody = null;
        Beacon::_setTransport(function (string $url, string $body) use (&$capturedBody) {
            $capturedBody = $body;
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::captureMessage('json test');

        $decoded = json_decode($capturedBody, true);
        $this->assertNotNull($decoded, 'Body must be valid JSON');
        $this->assertIsArray($decoded);
    }

    public function test_payload_contains_all_required_fields(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init([
            'dsn' => 'https://pub_k@api.beaconhq.dev/p',
            'release' => '1.2.3',
            'environment' => 'test',
        ]);
        Beacon::captureMessage('full payload');

        $requiredFields = ['type', 'message', 'level', 'stack', 'release', 'environment', 'sdk_version'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $captured, "Missing field: {$field}");
        }

        $this->assertEquals('1.2.3', $captured['release']);
        $this->assertEquals('test', $captured['environment']);
        $this->assertNotEmpty($captured['sdk_version']);
    }

    public function test_payload_includes_user_fields(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setUser('ext-001', 'test@example.com', 'Test User');
        Beacon::captureMessage('user fields');

        $this->assertArrayHasKey('user_id_ext', $captured);
        $this->assertArrayHasKey('user_email', $captured);
        $this->assertArrayHasKey('user_name', $captured);
    }

    public function test_payload_includes_tags_and_extra(): void
    {
        $captured = [];
        Beacon::_setTransport(function (string $url, string $body) use (&$captured) {
            $captured = json_decode($body, true);
        });

        Beacon::init(['dsn' => 'https://pub_k@api.beaconhq.dev/p']);
        Beacon::setTag('env', 'test');
        Beacon::captureMessage('tags', 'info', ['key' => 'value']);

        $this->assertIsArray($captured['tags']);
        $this->assertIsArray($captured['extra']);
        $this->assertEquals('test', $captured['tags']['env']);
        $this->assertEquals('value', $captured['extra']['key']);
    }
}
