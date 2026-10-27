<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\exceptions\StripeNotConfiguredException;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\App;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * The only class that instantiates a Stripe client.
 *
 * The API version is pinned here so responses look the same whichever stripe-php
 * major Composer resolved. Everything else in the plugin asks this service for
 * the client, the mode or a key.
 */
class Stripe extends Component
{
    public const API_VERSION = '2026-09-30.endive';
    public const STRIPE_JS_URL = 'https://js.stripe.com/endive/stripe.js';

    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    /**
     * Services and methods the plugin calls, as `service.path => methods`. Every
     * one exists in each stripe-php major from 15 to 22; a unit test checks the
     * installed SDK against this list.
     *
     * @var array<string, list<string>>
     */
    public const SDK_SURFACE = [
        'checkout->sessions' => ['create', 'retrieve', 'expire'],
        'paymentIntents' => ['retrieve'],
        'refunds' => ['create'],
        'subscriptions' => ['retrieve', 'update', 'cancel'],
        'invoices' => ['retrieve'],
        'customers' => ['create', 'update', 'delete'],
        'products' => ['create', 'update'],
        'coupons' => ['create', 'delete'],
        'billingPortal->sessions' => ['create'],
        'webhookEndpoints' => ['create', 'retrieve', 'delete'],
        'events' => ['all'],
    ];

    private ?StripeClient $client = null;
    private ?string $clientKey = null;

    public function secretKey(): ?string
    {
        return $this->parse($this->settings()->stripeSecretKey);
    }

    public function publishableKey(): ?string
    {
        return $this->parse($this->settings()->stripePublishableKey);
    }

    public function webhookSecret(): ?string
    {
        return $this->parse($this->settings()->stripeWebhookSecret);
    }

    /**
     * `test` or `live`, from the secret key's prefix. Null when no usable key is set.
     */
    public function mode(): ?string
    {
        return self::modeFromKey($this->secretKey());
    }

    public static function modeFromKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return match (true) {
            str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => self::MODE_TEST,
            str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => self::MODE_LIVE,
            default => null,
        };
    }

    public function isTestMode(): bool
    {
        return $this->mode() === self::MODE_TEST;
    }

    /**
     * What the control panel shows about the configuration. Never includes a key.
     *
     * @return array{mode: ?string, secretKey: string, publishableKey: string, webhookSecret: string, problems: list<string>}
     */
    public function status(): array
    {
        $secret = $this->secretKey();
        $publishable = $this->publishableKey();
        $problems = [];

        $secretMode = self::modeFromKey($secret);
        $publishableMode = match (true) {
            $publishable === null => null,
            str_starts_with($publishable, 'pk_test_') => self::MODE_TEST,
            str_starts_with($publishable, 'pk_live_') => self::MODE_LIVE,
            default => null,
        };

        if ($secret !== null && $secretMode === null) {
            $problems[] = 'The secret key does not start with sk_test_, sk_live_, rk_test_ or rk_live_.';
        }

        if ($publishable !== null && $publishableMode === null) {
            $problems[] = 'The publishable key does not start with pk_test_ or pk_live_.';
        }

        if ($secretMode !== null && $publishableMode !== null && $secretMode !== $publishableMode) {
            $problems[] = 'The secret key is a ' . $secretMode . ' key but the publishable key is a ' . $publishableMode . ' key.';
        }

        return [
            'mode' => $secretMode,
            'secretKey' => $secret === null ? 'missing' : ($secretMode === null ? 'invalid' : 'set'),
            'publishableKey' => $publishable === null ? 'missing' : ($publishableMode === null ? 'invalid' : 'set'),
            'webhookSecret' => $this->webhookSecret() === null ? 'missing' : 'set',
            'problems' => $problems,
        ];
    }

    /**
     * @throws StripeNotConfiguredException when there is no usable secret key
     */
    public function client(): StripeClient
    {
        $key = $this->secretKey();

        if ($key === null || self::modeFromKey($key) === null) {
            throw new StripeNotConfiguredException('Stripe is not configured: set a secret key in the Small Change settings.');
        }

        $apiBase = $this->parse($this->settings()->stripeApiBase);
        $signature = $key . '|' . $apiBase;

        // Rebuilt when the key or API base changes, which only happens in tests and after a settings save.
        if ($this->client === null || $this->clientKey !== $signature) {
            $config = [
                'api_key' => $key,
                'stripe_version' => self::API_VERSION,
            ];

            if ($apiBase !== null) {
                $config['api_base'] = rtrim($apiBase, '/');
            }

            // stripe-php 15 rejects this key. The global Stripe::setMaxNetworkRetries() would
            // also reach other plugins using the SDK, so older majors simply don't retry.
            if (method_exists(StripeClient::class, 'getMaxNetworkRetries')) {
                $config['max_network_retries'] = 2;
            }

            $this->client = new StripeClient($config);
            $this->clientKey = $signature;
        }

        return $this->client;
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return Plugin::getInstance()->getSettings();
    }

    private function parse(string $value): ?string
    {
        $parsed = App::parseEnv($value);

        return is_string($parsed) && trim($parsed) !== '' ? trim($parsed) : null;
    }

    // The calls below are the plugin's whole use of the SDK's services. Everything else goes
    // through them, so tests replace this component and no test touches the network.

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): StripeObject
    {
        return $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * @param string[] $expand
     * @throws ApiErrorException
     */
    public function retrieveCheckoutSession(string $id, array $expand = []): StripeObject
    {
        return $this->client()->checkout->sessions->retrieve($id, $expand ? ['expand' => $expand] : []);
    }

    /**
     * Ends a session early. A session that has already completed or expired is not an error, and Stripe being
     * unreachable must not stop a basket from being edited: the session runs out on its own within a day.
     */
    public function expireCheckoutSession(string $id): void
    {
        try {
            $this->client()->checkout->sessions->expire($id);
        } catch (ApiErrorException $e) {
            Craft::warning('Checkout Session ' . $id . ' was not expired: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @param string[] $expand
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent(string $id, array $expand = []): StripeObject
    {
        return $this->client()->paymentIntents->retrieve($id, $expand ? ['expand' => $expand] : []);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createRefund(array $params, string $idempotencyKey): StripeObject
    {
        return $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * @param string[] $expand
     * @throws ApiErrorException
     */
    public function retrieveSubscription(string $id, array $expand = []): StripeObject
    {
        return $this->client()->subscriptions->retrieve($id, $expand ? ['expand' => $expand] : []);
    }

    /**
     * Ends a subscription now. Already-ended ones are not an error.
     *
     * @throws ApiErrorException
     */
    public function cancelSubscription(string $id): StripeObject
    {
        return $this->client()->subscriptions->cancel($id);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function updateSubscription(string $id, array $params): StripeObject
    {
        return $this->client()->subscriptions->update($id, $params);
    }

    /**
     * @param string[] $expand
     * @throws ApiErrorException
     */
    public function retrieveInvoice(string $id, array $expand = []): StripeObject
    {
        return $this->client()->invoices->retrieve($id, $expand ? ['expand' => $expand] : []);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createProduct(array $params, string $idempotencyKey): StripeObject
    {
        return $this->client()->products->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function updateProduct(string $id, array $params): StripeObject
    {
        return $this->client()->products->update($id, $params);
    }

    /**
     * A Customer Portal session, where a donor updates their card or cancels a recurring donation.
     *
     * @throws ApiErrorException
     */
    public function createPortalSession(string $stripeCustomerId, string $returnUrl): StripeObject
    {
        return $this->client()->billingPortal->sessions->create(['customer' => $stripeCustomerId, 'return_url' => $returnUrl]);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createWebhookEndpoint(array $params): StripeObject
    {
        return $this->client()->webhookEndpoints->create($params);
    }

    /**
     * @throws ApiErrorException
     */
    public function retrieveWebhookEndpoint(string $id): StripeObject
    {
        return $this->client()->webhookEndpoints->retrieve($id);
    }

    /**
     * @throws ApiErrorException
     */
    public function deleteWebhookEndpoint(string $id): void
    {
        $this->client()->webhookEndpoints->delete($id);
    }

    /**
     * Events Stripe still holds (it keeps 30 days), oldest first.
     *
     * @param array<string, mixed> $params
     * @return \Stripe\Event[]
     * @throws ApiErrorException
     */
    public function listEvents(array $params): array
    {
        $events = [];

        foreach ($this->client()->events->all($params + ['limit' => 100])->autoPagingIterator() as $event) {
            $events[] = $event;
        }

        return array_reverse($events);
    }

    /**
     * Removes the customer at Stripe. Used only when erasing a person's data, and only when they have nothing active.
     */
    public function deleteCustomer(string $id): void
    {
        try {
            $this->client()->customers->delete($id);
        } catch (InvalidRequestException $e) {
            Craft::info('Customer ' . $id . ' was not deleted: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Creates the one-use coupon that carries an order's discount into Checkout. A coupon with the same ID
     * already existing means an earlier attempt got this far, which is fine.
     *
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createCoupon(array $params, string $idempotencyKey): void
    {
        try {
            $this->client()->coupons->create($params, ['idempotency_key' => $idempotencyKey]);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() !== 'resource_already_exists') {
                throw $e;
            }
        }
    }

    /**
     * Best effort: an unused coupon is harmless, so a failure to tidy it away is only logged.
     */
    public function deleteCoupon(string $id): void
    {
        try {
            $this->client()->coupons->delete($id);
        } catch (ApiErrorException $e) {
            Craft::info('Coupon ' . $id . ' was not deleted: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createCustomer(array $params, string $idempotencyKey): StripeObject
    {
        return $this->client()->customers->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function updateCustomer(string $id, array $params): StripeObject
    {
        return $this->client()->customers->update($id, $params);
    }
}
