<?php
declare(strict_types=1);

/**
 * Front controller / transport layer (PLAN §3a). The ONLY file that knows about
 * HTTP: query params, request bodies, methods, status codes, JSON encoding,
 * static files. It contains no business logic — it parses input, calls one
 * api.php handler, and encodes the result. Swapping runtimes or adding SSE
 * later means rewriting only this file.
 */

require_once __DIR__ . '/api.php';

/**
 * Route table: api name => [http method, handler]. Method enforcement is a
 * transport concern, so it lives here rather than in the domain layer.
 */
const ROUTES = [
    'create_room' => ['POST', 'api_create_room'],
    'join'        => ['POST', 'api_join'],
    'state'       => ['GET',  'api_state'],
    'vote'        => ['POST', 'api_vote'],
    'reveal'      => ['POST', 'api_reveal'],
    'new_round'   => ['POST', 'api_new_round'],
    'set_topic'   => ['POST', 'api_set_topic'],
    'recap'       => ['GET',  'api_recap'],
];

// Run as front controller under any web SAPI (apache2handler, fpm-fcgi,
// cli-server). The plain `cli` SAPI is PHPUnit requiring this file to test
// dispatch() directly, so skip the implicit run there.
if (PHP_SAPI !== 'cli') {
    main();
}

function main(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $apiName = $_GET['api'] ?? null;

    // No api param: this is the SPA shell (or, under the dev built-in server, a
    // static asset we let the server handle).
    if ($apiName === null) {
        serve_shell_or_static();
        return;
    }

    try {
        $body = dispatch((string) $apiName, $method);
        send_json(200, $body);
    } catch (ApiException $e) {
        send_json($e->status, ['error' => $e->getMessage(), 'code' => $e->slug]);
    } catch (\Throwable $e) {
        // Last-resort: never leak internals to the wire.
        error_log('planning-poker: ' . $e->getMessage());
        send_json(500, ['error' => 'Internal error', 'code' => 'internal_error']);
    }
}

/**
 * Validate method + Content-Type, assemble the request array, and invoke the
 * matching handler.
 *
 * @return array<string,mixed>
 */
function dispatch(string $apiName, string $method): array
{
    if (!isset(ROUTES[$apiName])) {
        throw new ApiException(404, 'unknown_endpoint', 'Unknown endpoint');
    }
    [$wantMethod, $handler] = ROUTES[$apiName];

    if ($method !== $wantMethod) {
        throw new ApiException(405, 'method_not_allowed', "Use $wantMethod for $apiName");
    }

    $req = $_GET;

    if ($method === 'POST') {
        // CSRF guard (PLAN §5, §9): mutating endpoints accept JSON only. A
        // forged cross-origin form POST can't set this Content-Type without a
        // preflight, and we use no cookies for identity.
        $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ctype, 'application/json') !== 0) {
            throw new ApiException(415, 'unsupported_media_type', 'Content-Type must be application/json');
        }
        $req = array_merge($req, parse_json_body());
    }

    return $handler($req);
}

/** @return array<string,mixed> */
function parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiException(400, 'invalid_json', 'Request body is not valid JSON');
    }
    return $data;
}

/** @param array<string,mixed> $body */
function send_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Responses carry the caller's token (query param) and their own vote — keep
    // them out of shared/proxy caches (PLAN §9).
    header('Cache-Control: no-store');
    // UTF-8 end to end — deck values include ☕ (PLAN §9).
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Serve the SPA shell for `GET /`. Under the dev built-in server, return false
 * for real static assets so the server serves them directly; on Apache those
 * never reach this file.
 */
function serve_shell_or_static(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // Built-in dev server: serve only known front-end assets directly. Never
    // hand back PHP source, the data/ dir, or the SQLite file — that mirrors the
    // Apache deploy (data/ outside web root + .htaccess), so dev isn't
    // misleadingly more permissive than prod.
    if (php_sapi_name() === 'cli-server' && $path !== '/') {
        $allowed = ['html', 'js', 'css', 'svg', 'ico', 'png', 'jpg', 'webp', 'woff2'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $file = __DIR__ . $path;
        if (strpos($path, '/data/') !== 0
            && in_array($ext, $allowed, true)
            && is_file($file)
        ) {
            return false;
        }
        // Anything else (PHP source, /data/, unknown paths) falls through to the
        // shell — no source or DB bytes are served.
    }

    $shell = __DIR__ . '/app.html';
    if (is_file($shell)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($shell);
    } else {
        // Phase A: frontend (Plan B) not built yet.
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Planning Poker API is running. Frontend (app.html) not yet built.\n";
    }
    return true;
}
