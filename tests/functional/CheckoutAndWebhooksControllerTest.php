<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\functional;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\tests\FakeEmails;
use coyshdigital\smallchange\tests\FakeStripe;
use coyshdigital\smallchange\tests\Fixtures;
use coyshdigital\smallchange\tests\FunctionalTester;
use Craft;

/**
 * The return trip, the client-secret endpoint and the webhook endpoint over HTTP.
 */
final class CheckoutAndWebhooksControllerTest extends Unit
{
    use Fixtures;

    protected FunctionalTester $tester;

    protected function _before(): void
    {
        Craft::$app->getCache()->flush();
        Craft::$container->setSingleton(Stripe::class, FakeStripe::class);
        Craft::$container->setSingleton(Emails::class, FakeEmails::class);
        Craft::$container->set(Settings::class, ['currency' => 'GBP', 'giftAidEnabled' => true, 'charityName' => 'Small Church']);

        $this->useFakes();
        $this->stripe = Craft::$container->get(Stripe::class);
        $this->emails = Craft::$container->get(Emails::class);
        Plugin::getInstance()->set('stripe', $this->stripe);
        Plugin::getInstance()->set('emails', $this->emails);
    }

    protected function _after(): void
    {
        Craft::$container->clear(Stripe::class);
        Craft::$container->clear(Emails::class);
        Craft::$container->clear(Settings::class);
    }

    /**
     * Places an order through the real controller so the "browser" session owns it.
     */
    private function placeOrder(): Order
    {
        $security = Craft::$app->getSecurity();
        $product = $this->makeProduct();

        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', [
            'action' => 'small-change/donations/start',
            'productId' => $product->id,
            'amount' => '25',
            'email' => 'donor@example.test',
            'returnUrl' => $security->hashData('donate/complete'),
            'paymentUrl' => $security->hashData('donate/payment'),
        ]);

        $number = json_decode($this->tester->grabPageSource(), true)['number'];

        return Order::find()->number($number)->one();
    }

    private function location(): string
    {
        return (string) \craft\test\Craft::$instance->client->getInternalResponse()->getHeader('Location');
    }

    private function returnUrlFor(Order $order, ?string $token = null): string
    {
        $token ??= Plugin::getInstance()->orders->createAccessToken($order, 'donate/payment');

        return '/?action=small-change/checkout/complete&t=' . rawurlencode($token) . '&session_id=' . $order->stripeCheckoutSessionId;
    }

    // Return trip ------------------------------------------------------------

    public function testAPaidReturnCompletesTheOrderAndRedirectsWithOnlyTheNumber(): void
    {
        $order = $this->placeOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->tester->stopFollowingRedirects();
        $this->tester->amOnPage($this->returnUrlFor($order));

        $this->tester->seeResponseCodeIs(302);
        $location = $this->location();
        $this->assertStringContainsString('donate/complete', $location);
        $this->assertStringContainsString('number=' . $order->number, $location);
        $this->assertStringNotContainsString('@', $location, 'no personal data in the redirect');
        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);
    }

    public function testAnUnpaidReturnGoesBackToThePaymentForm(): void
    {
        $order = $this->placeOrder();

        $this->tester->stopFollowingRedirects();
        $this->tester->amOnPage($this->returnUrlFor($order));

        $this->tester->seeResponseCodeIs(302);
        $this->assertStringContainsString('donate/payment', $this->location());
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state);
    }

    public function testAForgedReturnGoesHomeAndCompletesNothing(): void
    {
        $order = $this->placeOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->tester->stopFollowingRedirects();
        $this->tester->amOnPage($this->returnUrlFor($order, 'forged-token'));

        $this->tester->seeResponseCodeIs(302);
        $this->assertStringNotContainsString('donate', $this->location());
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state);
    }

    public function testAReturnWithNoParametersIsHarmless(): void
    {
        $this->tester->stopFollowingRedirects();
        $this->tester->amOnPage('/?action=small-change/checkout/complete');

        $this->tester->seeResponseCodeIs(302);
    }

    // Client secret ----------------------------------------------------------

    public function testTheClientSecretIsHandedToTheDonorWhoPlacedTheOrder(): void
    {
        $order = $this->placeOrder();
        $this->tester->sendAjaxPostRequest('/actions/small-change/checkout/client-secret', ['action' => 'small-change/checkout/client-secret', 'number' => $order->number]);

        $this->assertSame('cs_test_1_secret_abc', json_decode($this->tester->grabPageSource(), true)['clientSecret'] ?? null);
    }

    public function testTheClientSecretIsRefusedForAnOrderThisSessionDidNotPlace(): void
    {
        $stranger = $this->makePendingOrder();

        // Someone else placed it: this browser's session knows nothing about it.
        Craft::$app->getSession()->remove('smallChange.recentOrders');
        Craft::$app->getSession()->remove('smallChange.clientSecrets');

        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/checkout/client-secret', ['action' => 'small-change/checkout/client-secret', 'number' => $stranger->number]);

        $this->assertStringNotContainsString('secret', $this->tester->grabPageSource());
        $this->assertArrayNotHasKey('clientSecret', json_decode($this->tester->grabPageSource(), true) ?? []);
    }

    // Webhook endpoint -------------------------------------------------------

    /**
     * @param array<string, mixed> $object
     */
    private function postWebhook(string $type, array $object, ?string $signature = null, ?string $body = null): void
    {
        $body ??= (string) json_encode([
            'id' => 'evt_http_' . bin2hex(random_bytes(4)), 'object' => 'event', 'api_version' => '2026-09-30.endive', 'created' => time(),
            'livemode' => false, 'type' => $type, 'data' => ['object' => $object],
        ]);
        $t = time();
        $signature ??= 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec_test_secret');

        $this->tester->haveHttpHeader('Stripe-Signature', $signature);
        \craft\test\Craft::$instance->_request('POST', '/?action=small-change/webhooks/stripe', [], [], [], $body);
    }

    public function testASignedWebhookCompletesTheOrder(): void
    {
        $order = $this->placeOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->postWebhook('checkout.session.completed', $this->stripe->sessions[$order->stripeCheckoutSessionId]);

        $this->tester->seeResponseCodeIs(200);
        $this->tester->see('Processed.');
        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);
    }

    public function testAnUnsignedOrBadlySignedWebhookIsForbidden(): void
    {
        $order = $this->placeOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->postWebhook('checkout.session.completed', $this->stripe->sessions[$order->stripeCheckoutSessionId], 't=1,v1=deadbeef');

        $this->tester->seeResponseCodeIs(403);
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state);
    }

    public function testAThinWebhookIsRejectedWithTheReason(): void
    {
        $this->postWebhook('x', [], 't=1,v1=x', (string) json_encode(['object' => 'v2.core.event', 'id' => 'evt_thin']));

        $this->tester->seeResponseCodeIs(400);
        $this->tester->see('Snapshot');
    }

    public function testTheWebhookEndpointOnlyAcceptsPost(): void
    {
        $this->tester->sendAjaxGetRequest('/?action=small-change/webhooks/stripe');

        $this->tester->seeResponseCodeIsClientError();
    }
}
