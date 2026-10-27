<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\functional;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Discount;
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
 * The basket and checkout-start endpoints over HTTP, with one browser session across a test.
 */
final class CartControllerTest extends Unit
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
        Craft::$app->getSession()->remove('smallChange.cartNumber');
        $this->tester->haveHttpHeader('Accept', 'application/json');
    }

    protected function _after(): void
    {
        Craft::$container->clear(Stripe::class);
        Craft::$container->clear(Emails::class);
        Craft::$container->clear(Settings::class);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $action, array $params = []): array
    {
        $this->tester->sendAjaxPostRequest('/actions/small-change/' . $action, $params + ['action' => 'small-change/' . $action]);

        return (array) json_decode($this->tester->grabPageSource(), true);
    }

    // The basket -------------------------------------------------------------

    public function testAddingReturnsASummaryOfTheBasket(): void
    {
        $towel = $this->makeShopProduct(1500);
        $response = $this->call('cart/add', ['productId' => $towel->id, 'qty' => 2]);

        $this->tester->seeResponseCodeIs(200);
        $this->assertSame(3000, $response['cart']['total']);
        $this->assertSame(2, $response['cart']['count']);
        $this->assertSame([['productId' => $towel->id, 'description' => 'Tea towel', 'sku' => null, 'kind' => 'physical', 'qty' => 2, 'unitPrice' => 1500, 'subtotal' => 3000]], $response['cart']['lines']);
    }

    public function testTheSummaryNeverIncludesThePersonalOrStripeDetails(): void
    {
        $towel = $this->makeShopProduct(1500);
        $this->call('cart/add', ['productId' => $towel->id]);
        $response = $this->call('cart/update', ['email' => 'secret@example.test', 'customerNote' => 'private note', 'shippingAddress' => ['firstName' => 'Grace', 'addressLine1' => '1 High Street']]);

        $body = $this->tester->grabPageSource();
        foreach (['secret@example.test', 'private note', 'Grace', 'High Street', 'stripe', 'lastIp', 'cs_test'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }
        $this->assertArrayHasKey('total', $response['cart']);
    }

    public function testAddingTheSameProductAgainMerges(): void
    {
        $towel = $this->makeShopProduct(1000);
        $this->call('cart/add', ['productId' => $towel->id, 'qty' => 1]);
        $response = $this->call('cart/add', ['productId' => $towel->id, 'qty' => 2]);

        $this->assertCount(1, $response['cart']['lines']);
        $this->assertSame(3, $response['cart']['lines'][0]['qty']);
    }

    public function testUnknownAndUnavailableProductsAreRefused(): void
    {
        $this->call('cart/add', ['productId' => 99999999]);
        $this->tester->seeResponseCodeIs(400);

        $towel = $this->makeShopProduct(1000);
        $towel->enabled = false;
        Craft::$app->getElements()->saveElement($towel);
        $response = $this->call('cart/add', ['productId' => $towel->id]);
        $this->assertArrayHasKey('items', $response['errors']);
    }

    public function testQuantitiesCanBeChangedAndAProductRemoved(): void
    {
        $towel = $this->makeShopProduct(1000);
        $mug = $this->makeShopProduct(500, 'physical', 'Mug');
        $this->call('cart/add', ['productId' => $towel->id]);
        $this->call('cart/add', ['productId' => $mug->id]);

        $updated = $this->call('cart/update', ['quantities' => [$towel->id => 3]]);
        $this->assertSame(3500, $updated['cart']['total']);

        $removed = $this->call('cart/remove', ['productId' => $mug->id]);
        $this->assertSame(3000, $removed['cart']['total']);
        $this->assertCount(1, $removed['cart']['lines']);
    }

    public function testThePriceCannotBeSetByTheBrowser(): void
    {
        $towel = $this->makeShopProduct(1000);
        $response = $this->call('cart/add', ['productId' => $towel->id, 'unitPrice' => 1, 'price' => 1, 'total' => 1, 'subtotal' => 1]);
        $response = $this->call('cart/update', ['total' => '1', 'itemSubtotal' => '1', 'shippingAmount' => '0', 'discountAmount' => '999', 'quantities' => [$towel->id => 2], 'unitPrice' => '1']);

        $this->assertSame(2000, $response['cart']['total']);
        $this->assertSame(1000, $response['cart']['lines'][0]['unitPrice']);
    }

    public function testADonationJoinsTheBasketWithAValidatedAmount(): void
    {
        $donation = $this->makeProduct([500, 1000], 200, false);

        $bad = $this->call('cart/add', ['productId' => $donation->id, 'amount' => '7']);
        $this->assertArrayHasKey('items', $bad['errors']);

        $good = $this->call('cart/add', ['productId' => $donation->id, 'suggestedAmount' => '10.00']);
        $this->assertSame(1000, $good['cart']['total']);
        $this->assertSame('donation', $good['cart']['lines'][0]['kind']);
    }

    public function testDiscountCodesApplyAndComeOff(): void
    {
        $this->makeDiscount('TEN', Discount::TYPE_PERCENT, 1000);
        $towel = $this->makeShopProduct(2000);
        $this->call('cart/add', ['productId' => $towel->id]);

        $bad = $this->call('cart/apply-discount', ['discountCode' => 'nope']);
        $this->assertSame('That code is not valid.', $bad['errors']['discountCode'][0]);

        $applied = $this->call('cart/apply-discount', ['discountCode' => 'ten']);
        $this->assertSame(1800, $applied['cart']['total']);
        $this->assertSame('TEN', $applied['cart']['discountCode']);

        $removed = $this->call('cart/remove-discount');
        $this->assertSame(2000, $removed['cart']['total']);
    }

    public function testEachBrowserHasItsOwnBasket(): void
    {
        $towel = $this->makeShopProduct(1000);
        $this->call('cart/add', ['productId' => $towel->id]);
        $this->assertSame(1, (int) Order::find()->state('cart')->count());

        // A second visitor, with no session, starts a different basket.
        Craft::$app->getSession()->remove('smallChange.cartNumber');
        $this->tester->resetCookie(Craft::$app->getConfig()->getGeneral()->phpSessionName);
        $response = $this->call('cart/update', []);

        $this->assertSame(0, $response['cart']['count']);
    }

    public function testBasketActionsAreRateLimited(): void
    {
        $towel = $this->makeShopProduct(1000);

        for ($i = 0; $i < 200; $i++) {
            $this->call('cart/update', []);
        }
        $this->tester->seeResponseCodeIs(200);

        $this->call('cart/add', ['productId' => $towel->id]);
        $this->tester->seeResponseCodeIs(429);
    }

    // Starting checkout ------------------------------------------------------

    private function startParams(): array
    {
        $security = Craft::$app->getSecurity();

        return [
            'returnUrl' => $security->hashData('checkout/complete'),
            'paymentUrl' => $security->hashData('checkout/payment'),
            'redirect' => $security->hashData('checkout/payment?number={number}'),
        ];
    }

    public function testCheckoutNeedsSomethingInTheBasket(): void
    {
        $response = $this->call('checkout/start', $this->startParams() + ['email' => 'a@example.test']);

        $this->tester->seeResponseCodeIs(400);
        $this->assertArrayHasKey('items', $response['errors']);
        $this->assertSame([], $this->stripe->sessions);
    }

    public function testCheckoutReportsEverythingThatIsMissing(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $this->call('cart/add', ['productId' => $this->makeShopProduct(1000)->id]);

        $response = $this->call('checkout/start', $this->startParams() + ['email' => 'nope', 'shippingAddress' => ['firstName' => 'Grace']]);

        $this->tester->seeResponseCodeIs(400);
        $this->assertArrayHasKey('email', $response['errors']);
        $this->assertArrayHasKey('shippingAddress.lastName', $response['errors']);
        $this->assertArrayHasKey('shippingAddress.addressLine1', $response['errors']);
        $this->assertArrayHasKey('shippingAddress.postalCode', $response['errors']);
        $this->assertSame([], $this->stripe->sessions, 'nothing is sent to Stripe until the details are complete');
    }

    private function completeDetails(): array
    {
        return $this->startParams() + [
            'email' => 'Buyer@Example.test',
            'shippingAddress' => ['firstName' => 'Grace', 'lastName' => 'Hopper', 'addressLine1' => '1 High Street', 'locality' => 'London', 'postalCode' => 'SW1A 1AA', 'countryCode' => 'GB'],
        ];
    }

    public function testCheckoutStartsASessionForTheRightAmount(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $this->makeDiscount('TEN', Discount::TYPE_PERCENT, 1000);
        $this->call('cart/add', ['productId' => $this->makeShopProduct(2000)->id, 'qty' => 2]);
        $this->call('cart/apply-discount', ['discountCode' => 'TEN']);

        $response = $this->call('checkout/start', $this->completeDetails() + ['total' => '1', 'discountAmount' => '99999']);

        $this->tester->seeResponseCodeIs(200);
        $this->assertSame('checkout/payment?number=' . $response['number'], $response['redirect']);

        $order = Order::find()->number($response['number'])->one();
        $this->assertSame(Order::STATE_PENDING, $order->state);
        $this->assertSame(4000 - 400 + 350, $order->total);
        $this->assertSame($order->total, $this->stripe->sessions[$order->stripeCheckoutSessionId]['amount_total']);
        $this->assertSame('buyer@example.test', $order->email);
        $this->assertSame('checkout/complete', $order->returnUrl);
    }

    public function testTheClientSecretFollowsForTheSameBrowser(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $this->call('cart/add', ['productId' => $this->makeShopProduct(2000)->id]);
        $response = $this->call('checkout/start', $this->completeDetails());

        $secret = $this->call('checkout/client-secret', ['number' => $response['number']]);

        $this->assertStringContainsString('_secret_', $secret['clientSecret']);
    }

    public function testTamperedHiddenValuesAreRefused(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $this->call('cart/add', ['productId' => $this->makeShopProduct(2000)->id]);

        $this->call('checkout/start', ['returnUrl' => 'https://evil.test/', 'email' => 'a@example.test'] + $this->completeDetails());
        $this->tester->seeResponseCodeIs(400);
        $this->assertSame(0, (int) Order::find()->state('pending')->count(), 'nothing was started');

        $this->call('checkout/start', ['paymentUrl' => '//evil.test'] + $this->completeDetails());
        $this->tester->seeResponseCodeIs(400);
        $this->assertSame([], $this->stripe->sessions);
    }

    public function testSubmittingTheDetailsTwiceLeavesAPayableSession(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $this->call('cart/add', ['productId' => $this->makeShopProduct(2000)->id]);
        $first = $this->call('checkout/start', $this->completeDetails());
        $firstSession = Order::find()->number($first['number'])->one()->stripeCheckoutSessionId;

        // The back button, or a second click: the same details again.
        $second = $this->call('checkout/start', $this->completeDetails());
        $this->tester->seeResponseCodeIs(200);

        $order = Order::find()->number($second['number'])->one();
        $this->assertSame('expired', $this->stripe->sessions[$firstSession]['status']);
        $this->assertNotSame($firstSession, $order->stripeCheckoutSessionId);
        $this->assertSame('open', $this->stripe->sessions[$order->stripeCheckoutSessionId]['status']);

        $secret = $this->call('checkout/client-secret', ['number' => $order->number]);
        $this->assertStringContainsString('_secret_', $secret['clientSecret'] ?? '');
    }

    public function testStartingAgainAfterChangingTheBasketNeverLeavesTwoLivePayments(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $towel = $this->makeShopProduct(2000);
        $this->call('cart/add', ['productId' => $towel->id]);
        $first = $this->call('checkout/start', $this->completeDetails());
        $firstSession = Order::find()->number($first['number'])->one()->stripeCheckoutSessionId;

        $this->call('cart/update', ['quantities' => [$towel->id => 3]]);
        $this->assertSame('expired', $this->stripe->sessions[$firstSession]['status']);

        $second = $this->call('checkout/start', $this->completeDetails());
        $this->assertSame($first['number'], $second['number'], 'the same order, now with the new amount');
        $this->assertSame(6350, Order::find()->number($second['number'])->one()->total);
        $this->assertCount(2, $this->stripe->sessions);
    }
}
