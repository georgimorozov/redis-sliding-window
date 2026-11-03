<?php

declare(strict_types=1);

// Smoke: a PHP app behind the built-in web server answers 200, 200, then 429 with a Retry-After of 59 to 60
// seconds, with Predis and with phpredis.

require __DIR__ . '/clients.php';

foreach (['predis', 'phpredis'] as $kind) {
    $port = random_int(20000, 40000);
    $env = getenv() + ['RSW_SMOKE_CLIENT' => $kind, 'RSW_SMOKE_NAME' => 'smoke-' . bin2hex(random_bytes(4))];
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/app.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__, $env);
    check(is_resource($server), "{$kind}: php -S did not start");
    try {
        $statuses = [];
        $retryAfter = null;
        for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $port) === false; $attempt++) {
            usleep(100_000);
        }
        for ($i = 0; $i < 3; $i++) {
            $body = file_get_contents("http://127.0.0.1:{$port}/", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
            check($body !== false, "{$kind}: request {$i} failed");
            $headers = $http_response_header;
            preg_match('#^HTTP/\S+ (\d{3})#', $headers[0], $match);
            $statuses[] = (int) $match[1];
            foreach ($headers as $header) {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($header, 12));
                }
            }
        }
        check($statuses === [200, 200, 429], "{$kind}: statuses " . implode(', ', $statuses));
        check($retryAfter !== null && preg_match('/^\d+$/', $retryAfter) === 1 && (int) $retryAfter >= 59 && (int) $retryAfter <= 60, "{$kind}: Retry-After " . var_export($retryAfter, true));
        echo "http-429 ({$kind}): statuses " . implode(', ', $statuses) . "; Retry-After {$retryAfter}\n";
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
}
