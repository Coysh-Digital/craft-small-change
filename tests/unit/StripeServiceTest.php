<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\exceptions\StripeNotConfiguredException;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Stripe;
use Stripe\StripeClient;

final class StripeServiceTest extends Unit
{
    private Stripe $stripe;
    private Settings $settings;

    protected function _before(): void
    {
        $plugin = Plugin::getInstance();
        $this->stripe = $plugin->stripe;
        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $this->settings = $settings;
    }

    /**
     * @dataProvider keyModes
     */
    public function testModeComesFromTheSecretKeyPrefix(?string $key, ?string $mode): void
    {
        $this->assertSame($mode, Stripe::modeFromKey($key));
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function keyModes(): array
    {
        return [
            'test' => ['sk_test_abc', Stripe::MODE_TEST],
            'live' => ['sk_live_abc', Stripe::MODE_LIVE],
            'restricted test' => ['rk_test_abc', Stripe::MODE_TEST],
            'restricted live' => ['rk_live_abc', Stripe::MODE_LIVE],
            'publishable is not a secret' => ['pk_test_abc', null],
            'garbage' => ['nope', null],
            'none' => [null, null],
        ];
    }

    public function testUnsetKeysReadAsMissing(): void
    {
        $this->settings->stripeSecretKey = '';
        $this->settings->stripePublishableKey = '';
        $this->settings->stripeWebhookSecret = '';

        $status = $this->stripe->status();

        $this->assertNull($status['mode']);
        $this->assertSame('missing', $status['secretKey']);
        $this->assertSame('missing', $status['publishableKey']);
        $this->assertSame('missing', $status['webhookSecret']);
        $this->assertSame([], $status['problems']);
    }

    public function testMismatchedKeyModesAreReported(): void
    {
        $this->settings->stripeSecretKey = 'sk_live_abc';
        $this->settings->stripePublishableKey = 'pk_test_abc';

        $status = $this->stripe->status();

        $this->assertSame(Stripe::MODE_LIVE, $status['mode']);
        $this->assertCount(1, $status['problems']);
        $this->assertStringContainsString('live key but the publishable key is a test key', $status['problems'][0]);
    }

    public function testStatusNeverContainsAKey(): void
    {
        $this->settings->stripeSecretKey = 'sk_test_supersecretvalue';
        $this->settings->stripeWebhookSecret = 'whsec_supersecretvalue';

        $this->assertStringNotContainsString('supersecretvalue', (string) json_encode($this->stripe->status()));
    }

    public function testClientRefusesToBuildWithoutAUsableKey(): void
    {
        $this->settings->stripeSecretKey = '';
        $this->expectException(StripeNotConfiguredException::class);
        $this->stripe->client();
    }

    public function testClientPinsTheApiVersion(): void
    {
        $this->settings->stripeSecretKey = 'sk_test_abc';

        $client = $this->stripe->client();

        // Only newer SDK majors have a getter, so read the config every major keeps.
        $config = (new \ReflectionProperty(\Stripe\BaseStripeClient::class, 'config'))->getValue($client);
        $this->assertSame('2026-09-30.endive', $config['stripe_version']);

        // Older SDK majors have no per-client retry setting.
        if (method_exists($client, 'getMaxNetworkRetries')) {
            $this->assertSame(2, $client->getMaxNetworkRetries());
        }
    }

    public function testTheClientCanBePointedAtAStripeMock(): void
    {
        $this->settings->stripeSecretKey = 'sk_test_abc';
        $this->settings->stripeApiBase = 'http://localhost:12111/';

        $client = $this->stripe->client();

        $this->assertSame('http://localhost:12111', $client->getApiBase());

        $this->settings->stripeApiBase = '';
        $this->assertNotSame('http://localhost:12111', $this->stripe->client()->getApiBase());
    }

    public function testClientIsRebuiltWhenTheKeyChanges(): void
    {
        $this->settings->stripeSecretKey = 'sk_test_one';
        $first = $this->stripe->client();
        $this->assertSame($first, $this->stripe->client());

        $this->settings->stripeSecretKey = 'sk_test_two';
        $this->assertNotSame($first, $this->stripe->client());
    }

    /**
     * Every service and method the plugin calls must exist in whichever stripe-php
     * major is installed; CI runs this against the lowest and the latest.
     */
    public function testInstalledSdkHasEverythingThePluginUses(): void
    {
        $client = new StripeClient(['api_key' => 'sk_test_abc', 'stripe_version' => Stripe::API_VERSION]);

        foreach (Stripe::SDK_SURFACE as $path => $methods) {
            $service = $client;
            foreach (explode('->', $path) as $segment) {
                $service = $service->$segment;
            }

            foreach ($methods as $method) {
                $this->assertTrue(method_exists($service, $method), "{$path}->{$method}() is missing from the installed stripe-php");
            }
        }

        // Listing events pages through the result with this.
        $this->assertTrue(method_exists(\Stripe\Collection::class, 'autoPagingIterator'));
        $this->assertTrue(method_exists(\Stripe\Webhook::class, 'constructEvent'));
        $this->assertTrue(method_exists(\Stripe\Event::class, 'constructFrom'));
        $this->assertTrue(class_exists(\Stripe\Exception\SignatureVerificationException::class));
        $this->assertTrue(class_exists(\Stripe\Exception\ApiErrorException::class));
    }
}
