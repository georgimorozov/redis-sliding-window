<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Internal;

use Predis\ClientInterface;
use Predis\Command\Processor\KeyPrefixProcessor;
use Predis\Command\RawCommand;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\RelayConnection;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;
use Predis\TimeoutException;

/**
 * Predis 3 (docs/design.md, the failure-policy table's Predis row).
 *
 * @internal
 */
final class PredisAdapter implements Adapter
{
    private const SETUP_REPLY = 'Failed: ';
    private const SSL_CONTEXT = 'Error while setting SSL context options';

    public function __construct(private readonly ClientInterface $client)
    {
        $connection = $client->getConnection();
        if (!$connection instanceof NodeConnectionInterface || $connection instanceof RelayConnection) {
            throw new \InvalidArgumentException(
                'redis-sliding-window: the Predis client must use one node (no cluster, replication or Relay connection)',
            );
        }
        $options = $client->getOptions();
        if (isset($options->prefix) && !($options->prefix instanceof KeyPrefixProcessor && $options->prefix->getPrefix() === '')) {
            throw new \InvalidArgumentException('redis-sliding-window: the Predis client must not set a key prefix');
        }
    }

    public function call(array $command): mixed
    {
        $id = array_shift($command);
        try {
            $reply = $this->client->executeCommand(new RawCommand($id, $command));
        } catch (StreamInitException $error) {
            // The socket could not be opened, so nothing was sent: retry once.
            // Never retried: timeouts and lost replies, which may follow a command the server ran.
            if ($this->classify($error) === null) {
                throw $error;
            }
            $reply = $this->client->executeCommand(new RawCommand($id, $command));
        }
        if ($reply instanceof ErrorInterface) {
            throw new ServerException($reply->getMessage());
        }

        return $reply;
    }

    public function classify(\Throwable $error): ?string
    {
        if ($error instanceof ServerException) {
            return ReplyCode::classify($error->getMessage());
        }
        if ($error instanceof TimeoutException) {
            return 'timeout';
        }
        if ($error instanceof ConnectionException) {
            $message = $error->getMessage();
            if (str_starts_with($message, self::SETUP_REPLY)) {
                return ReplyCode::classify(substr($message, strlen(self::SETUP_REPLY)), false);
            }

            return 'connection';
        }
        if ($error instanceof StreamInitException) {
            return str_starts_with($error->getMessage(), self::SSL_CONTEXT) ? null : 'connection';
        }

        return null;
    }
}
