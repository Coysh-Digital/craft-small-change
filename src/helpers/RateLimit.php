<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\helpers;

use Craft;
use craft\web\Request as WebRequest;

/**
 * A small fixed-window counter per address, for the public actions that create orders or call Stripe.
 *
 * Craft takes the visitor's address from forwarded headers, which is right behind a proxy and is also something a
 * client can choose for itself. So every attempt is counted twice: once for the visitor's address and once, with a
 * higher ceiling that allows for a proxy serving many people, for the address the connection actually came from.
 */
final class RateLimit
{
    /**
     * How much more one connecting address may do than one visitor.
     */
    public const REMOTE_MULTIPLIER = 20;

    /**
     * Counts an attempt and returns whether it is over the limit. The window starts at the first attempt
     * and later ones don't extend it.
     */
    public static function exceeded(string $bucket, int $max, int $windowSeconds): bool
    {
        $request = Craft::$app->getRequest();

        // Only web requests have a visitor to count.
        if (!$request instanceof WebRequest) {
            return false;
        }

        $visitor = (string) $request->getUserIP();
        $remote = (string) $request->getRemoteIP();

        $over = self::count($bucket . ':visitor', $visitor, $max, $windowSeconds);

        if ($remote !== $visitor) {
            $over = self::count($bucket . ':remote', $remote, $max * self::REMOTE_MULTIPLIER, $windowSeconds) || $over;
        }

        return $over;
    }

    private static function count(string $bucket, string $address, int $max, int $windowSeconds): bool
    {
        $cache = Craft::$app->getCache();
        $key = 'smallchange:rate:' . $bucket . ':' . md5($address);
        $state = $cache->get($key);
        $now = time();

        if (!is_array($state) || $state['until'] <= $now) {
            $state = ['count' => 0, 'until' => $now + $windowSeconds];
        }

        if ($state['count'] >= $max) {
            return true;
        }

        $state['count']++;
        $cache->set($key, $state, max(1, $state['until'] - $now));

        return false;
    }
}
