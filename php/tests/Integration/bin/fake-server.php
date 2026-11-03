<?php

declare(strict_types=1);

// A fake server for phpredis reconnect tests: php fake-server.php <port> <auth-lost|select-lost|auth-refused>.
// Connection 1 answers AUTH and SELECT with +OK, then closes. Connection 2 (phpredis reconnecting) gets, for
// its AUTH or SELECT: no reply and a closed socket (…-lost), or -WRONGPASS (auth-refused). Prints "ready".

[, $port, $mode] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "fake-server: {$errstr}\n");
    exit(1);
}
echo "ready\n";

/**
 * @param resource $connection
 * @return null|list<string> one RESP command
 */
function readCommand($connection): ?array
{
    $line = fgets($connection);
    if ($line === false || $line[0] !== '*') {
        return null;
    }
    $parts = [];
    for ($i = (int) substr($line, 1); $i > 0; $i--) {
        $length = (int) substr((string) fgets($connection), 1);
        $parts[] = substr((string) stream_get_contents($connection, $length + 2), 0, $length);
    }

    return $parts;
}

for ($n = 1; $n <= 2; $n++) {
    $connection = stream_socket_accept($server, 30);
    if ($connection === false) {
        exit(1);
    }
    while (($command = readCommand($connection)) !== null) {
        $id = strtoupper($command[0]);
        if ($n === 1) {
            fwrite($connection, "+OK\r\n");
            if ($id === ($mode === 'select-lost' ? 'SELECT' : 'AUTH')) {
                usleep(100_000);
                break;
            }
            continue;
        }
        if ($id === 'AUTH' && $mode === 'auth-refused') {
            fwrite($connection, "-WRONGPASS invalid username-password pair or user is disabled.\r\n");
            continue;
        }
        if (($id === 'AUTH' && $mode === 'auth-lost') || ($id === 'SELECT' && $mode === 'select-lost')) {
            break;
        }
        fwrite($connection, "-ERR unexpected {$id}\r\n");
    }
    fclose($connection);
}
