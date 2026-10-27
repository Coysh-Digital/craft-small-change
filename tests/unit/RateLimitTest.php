<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\helpers\RateLimit;
use Craft;
use craft\helpers\App;
use craft\web\Request;

final class RateLimitTest extends Unit
{
    private ?Request $originalRequest = null;

    protected function _before(): void
    {
        Craft::$app->getCache()->flush();
        $this->originalRequest = Craft::$app->getRequest();
    }

    protected function _after(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
    }

    /**
     * A fresh request, since Craft remembers the address it worked out for the current one.
     */
    private function requestFrom(string $forwardedFor): void
    {
        /** @var Request $request */
        $request = Craft::createObject(App::webRequestConfig());
        $request->getHeaders()->set('X-Forwarded-For', $forwardedFor);
        Craft::$app->set('request', $request);
    }

    public function testOneVisitorIsHeldToTheLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->requestFrom('203.0.113.7');
            $this->assertFalse(RateLimit::exceeded('t1', 3, 60));
        }

        $this->requestFrom('203.0.113.7');
        $this->assertTrue(RateLimit::exceeded('t1', 3, 60));
    }

    public function testAChangingForwardedAddressStillMeetsACeiling(): void
    {
        // The forwarded header is the client's to choose; the connection's own address is not.
        for ($i = 1; $i <= 3 * RateLimit::REMOTE_MULTIPLIER; $i++) {
            $this->requestFrom('198.51.100.' . $i);
            $this->assertFalse(RateLimit::exceeded('t2', 3, 60), "attempt $i");
        }

        $this->requestFrom('198.51.100.251');
        $this->assertTrue(RateLimit::exceeded('t2', 3, 60));
    }

    public function testBucketsAreSeparate(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->requestFrom('203.0.113.9');
            RateLimit::exceeded('t3', 3, 60);
        }

        $this->requestFrom('203.0.113.9');
        $this->assertTrue(RateLimit::exceeded('t3', 3, 60));
        $this->assertFalse(RateLimit::exceeded('t4', 3, 60));
    }
}
