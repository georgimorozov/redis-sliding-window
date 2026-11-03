<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Support;

use Predis\Client;
use Predis\Command\CommandInterface;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Parameters;
use Predis\Response\Error;

/**
 * A real Predis Client over a connection that plays scripted steps. A step is a reply value, a
 * Predis\Response\Error (an error reply), or a closure that receives the connection and returns the
 * \Throwable to throw.
 */
final class FakePredis
{
    /** @var list<list<string>> */
    public array $sent = [];
    public readonly Client $client;
    public readonly NodeConnectionInterface $connection;

    /**
     * @param list<mixed> $steps
     * @param array<string, mixed> $options
     */
    public function __construct(private array $steps = [], array $options = [])
    {
        $connection = new class ($this) implements NodeConnectionInterface {
            public function __construct(private readonly FakePredis $fake) {}

            public function __toString(): string
            {
                return '127.0.0.1:6379';
            }

            public function getResource(): mixed
            {
                return null;
            }

            public function getParameters(): Parameters
            {
                return new Parameters();
            }

            public function getClientId(): ?int
            {
                return null;
            }

            public function addConnectCommand(CommandInterface $command): void {}

            public function read(): mixed
            {
                return null;
            }

            public function write(string $buffer): void {}

            public function hasDataToRead(): bool
            {
                return false;
            }

            public function connect(): void {}

            public function disconnect(): void {}

            public function isConnected(): bool
            {
                return true;
            }

            public function writeRequest(CommandInterface $command): void {}

            public function readResponse(CommandInterface $command): mixed
            {
                return null;
            }

            public function executeCommand(CommandInterface $command): mixed
            {
                return $this->fake->play($command, $this);
            }
        };
        $this->connection = $connection;
        $this->client = new Client($connection, $options);
    }

    /** @internal called by the fake connection */
    public function play(CommandInterface $command, NodeConnectionInterface $connection): mixed
    {
        $sent = [$command->getId()];
        foreach ($command->getArguments() as $argument) {
            $sent[] = is_scalar($argument) ? (string) $argument : '?';
        }
        $this->sent[] = $sent;
        if ($this->steps === []) {
            throw new \LogicException('FakePredis: no step left for ' . $command->getId());
        }
        $step = array_shift($this->steps);
        if ($step instanceof \Closure) {
            $error = $step($connection);
            if (!$error instanceof \Throwable) {
                throw new \LogicException('FakePredis: a closure step must return a Throwable');
            }
            throw $error;
        }

        return $step;
    }

    public static function serverError(string $reply): Error
    {
        return new Error($reply);
    }
}
