<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Transport-layer tests (index.php). The front controller's security-relevant
 * guards — method enforcement, the JSON-only CSRF guard, and unknown-endpoint
 * handling — are asserted by calling dispatch() directly with manipulated
 * superglobals. index.php only runs main() under a web SAPI, so requiring it
 * here (cli SAPI) just defines its functions.
 *
 * These guards run before the request body is read, so no php://input mocking
 * is needed.
 */
final class TransportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../index.php';
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['CONTENT_TYPE']);
    }

    private function assertApiError(int $status, string $slug, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected ApiException $slug ($status), none thrown");
        } catch (ApiException $e) {
            $this->assertSame($status, $e->status, 'HTTP status');
            $this->assertSame($slug, $e->slug, 'error slug');
        }
    }

    public function testUnknownEndpointIs404(): void
    {
        $this->assertApiError(404, 'unknown_endpoint', function () {
            dispatch('does_not_exist', 'GET');
        });
    }

    public function testWrongMethodIs405(): void
    {
        // create_room is POST-only; a GET must be rejected before any handler runs.
        $this->assertApiError(405, 'method_not_allowed', function () {
            dispatch('create_room', 'GET');
        });
    }

    public function testNonJsonPostIsRejectedAsCsrfGuard(): void
    {
        // The CSRF guard: a mutating POST without application/json Content-Type
        // is refused with 415 (a forged cross-origin form POST can't set it).
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $this->assertApiError(415, 'unsupported_media_type', function () {
            dispatch('create_room', 'POST');
        });
    }

    public function testMissingContentTypeOnPostIsRejected(): void
    {
        unset($_SERVER['CONTENT_TYPE']);
        $this->assertApiError(415, 'unsupported_media_type', function () {
            dispatch('vote', 'POST');
        });
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendJsonFallsBackTo500OnInvalidUtf8(): void
    {
        // Invalid UTF-8 (a lone 0x80 byte, e.g. a mangled participant name)
        // makes json_encode return false. send_json must emit the standard 500
        // envelope rather than an empty 200 body. Redirect error_log to a temp
        // file so its diagnostic line doesn't trip failOnRisky via stderr.
        $log = tempnam(sys_get_temp_dir(), 'pp_log_');
        ini_set('error_log', $log);
        ob_start();
        send_json(200, ['name' => "bad\x80name"]);
        $out = ob_get_clean();
        ini_restore('error_log');
        unlink($log);

        $this->assertSame('{"error":"Internal error","code":"internal_error"}', $out);
        $this->assertSame(500, http_response_code());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendJsonEmitsValidBodyForUnicode(): void
    {
        // The coffee deck value must round-trip unescaped (PLAN §9).
        ob_start();
        send_json(200, ['value' => '☕']);
        $out = ob_get_clean();

        $this->assertSame('{"value":"☕"}', $out);
        $this->assertSame(200, http_response_code());
    }
}
