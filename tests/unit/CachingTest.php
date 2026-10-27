<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\helpers\Caching;
use coyshdigital\smallchange\tests\Fixtures;
use coyshdigital\smallchange\variables\SmallChangeVariable;
use Craft;
use craft\base\Plugin as BasePlugin;
use ReflectionProperty;

/**
 * A page that shows one visitor's basket or order must never be kept by a cache and handed to the next visitor.
 */
final class CachingTest extends Unit
{
    use Fixtures;

    private ?object $originalRequest = null;

    /** @var array<string, mixed>|null */
    private ?array $originalPlugins = null;

    protected function _before(): void
    {
        $this->useFakes();
        $this->originalRequest = Craft::$app->has('request', true) ? Craft::$app->get('request') : null;
        Craft::$app->set('request', new class() extends \craft\web\Request {
            public function getIsSiteRequest(): bool
            {
                return true;
            }

            public function getIsCpRequest(): bool
            {
                return false;
            }

            public function getIsConsoleRequest(): bool
            {
                return false;
            }
        });
        Craft::$app->getResponse()->getHeaders()->removeAll();
    }

    protected function _after(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }

        if ($this->originalPlugins !== null) {
            $this->pluginRegistry()->setValue(Craft::$app->getPlugins(), $this->originalPlugins);
        }

        Craft::$app->getResponse()->getHeaders()->removeAll();
    }

    private function cacheControl(): string
    {
        return (string) Craft::$app->getResponse()->getHeaders()->get('Cache-Control');
    }

    private function pluginRegistry(): ReflectionProperty
    {
        $property = new ReflectionProperty(Craft::$app->getPlugins(), '_plugins');
        $property->setAccessible(true);

        return $property;
    }

    /**
     * Puts a stand-in for the Blitz plugin in the registry, shaped like the part of Blitz this reads.
     */
    private function installFakeBlitz(mixed $generateCache): void
    {
        $blitz = new class('blitz') extends BasePlugin {
            public function init(): void
            {
            }
        };
        $blitz->set('generateCache', $generateCache);

        $registry = $this->pluginRegistry();
        $this->originalPlugins = $registry->getValue(Craft::$app->getPlugins());
        $registry->setValue(Craft::$app->getPlugins(), $this->originalPlugins + ['blitz' => $blitz]);
    }

    /**
     * @return array<string, array{callable(SmallChangeVariable): mixed}>
     */
    public static function personalReads(): array
    {
        return [
            'the basket' => [static fn(SmallChangeVariable $v) => $v->getCart()],
            'the basket summary' => [static fn(SmallChangeVariable $v) => $v->cartSummary()],
            'an order' => [static fn(SmallChangeVariable $v) => $v->recentOrder('ABCDEFGHJK')],
            'the payment secret' => [static fn(SmallChangeVariable $v) => $v->checkoutClientSecret(null)],
            'the hosted payment link' => [static fn(SmallChangeVariable $v) => $v->checkoutUrl(null)],
            'a list of orders' => [static fn(SmallChangeVariable $v) => $v->orders()],
        ];
    }

    /**
     * @dataProvider personalReads
     * @param callable(SmallChangeVariable): mixed $read
     */
    public function testAPageThatReadsPersonalDataIsNotCacheable(callable $read): void
    {
        $read(new SmallChangeVariable());

        $this->assertStringContainsString('no-store', $this->cacheControl());
    }

    public function testPublicDataLeavesCachingAlone(): void
    {
        $variable = new SmallChangeVariable();
        $variable->products()->all();
        $variable->formatMoney(1000);
        $variable->donationTotal(1);
        $variable->getIsTestMode();

        $this->assertSame('', $this->cacheControl());
    }

    public function testBlitzIsToldToSkipThePage(): void
    {
        $options = (object) ['cachingEnabled' => true];
        $this->installFakeBlitz((object) ['options' => $options]);

        (new SmallChangeVariable())->getCart();

        $this->assertFalse($options->cachingEnabled);
    }

    public function testBlitzIsLeftAloneForPublicPages(): void
    {
        $options = (object) ['cachingEnabled' => true];
        $this->installFakeBlitz((object) ['options' => $options]);

        (new SmallChangeVariable())->products()->all();

        $this->assertTrue($options->cachingEnabled);
    }

    public function testABlitzThatChangedUnderUsDoesNotBreakThePage(): void
    {
        $this->installFakeBlitz(static fn() => throw new \RuntimeException('a different Blitz'));

        Caching::keepPrivate();

        $this->assertStringContainsString('no-store', $this->cacheControl(), 'the headers still protect the page');
    }

    public function testNothingHappensWithoutBlitz(): void
    {
        Caching::keepPrivate();

        $this->assertStringContainsString('no-store', $this->cacheControl());
    }

    public function testTheControlPanelAndConsoleAreLeftAlone(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->getResponse()->getHeaders()->removeAll();

        Caching::keepPrivate();

        $this->assertSame('', $this->cacheControl());
    }
}
