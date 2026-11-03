<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Laravel;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `sliding-window:<limiter>`: one hit per request, keyed by the signed-in user's id or
 * else the client IP. A denied request throws ThrottleRequestsException, which Laravel renders as a 429
 * with Retry-After.
 */
final class LimitRequests
{
    public function __construct(private readonly Limiters $limiters) {}

    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next, string $limiter): Response
    {
        $result = $this->limiters->get($limiter)->hit(self::identifier($request));
        if (!$result->allowed) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => (string) $result->retryAfterSeconds(),
            ]);
        }

        return $next($request);
    }

    private static function identifier(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            $id = $user->getAuthIdentifier();
            if (is_scalar($id)) {
                return 'user:' . $id;
            }
        }

        return 'ip:' . ($request->ip() ?? 'unknown');
    }
}
