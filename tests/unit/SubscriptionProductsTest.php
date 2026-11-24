<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\helpers\SubscriptionTerms;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\models\Subscription;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;

/**
 * Products sold as a subscription: fixed-length and open-ended plans, how they are bought, how a discount
 * code reaches them, and how a plan ends itself.
 */
final class SubscriptionProductsTest extends Unit
{
    use Fixtures;

    private int $eventCounter = 0;

    protected function _before(): void
    {
        $this->useFakes();
        Craft::$app->getSession()->remove('smallChange.cartNumber');
    }

    // Helpers ----------------------------------------------------------------

    private function plan(?int $payments = 10, string $interval = 'month', int $price = 5000): Product
    {
        $product = $this->makeShopProduct($price, ProductType::KIND_NON_PHYSICAL, 'Course instalments');
        $product->subscriptionInterval = $interval;
        $product->subscriptionPayments = $payments;
        $this->assertTrue(Craft::$app->getElements()->saveElement($product), json_encode($product->getErrors()));

        return Product::find()->id($product->id)->status(null)->one();
    }

    private function planCart(Product $plan, ?string $code = null): Order
    {
        $plugin = Plugin::getInstance();
        $cart = $this->makeCart([[$plan, 1]]);

        if ($code !== null) {
            $this->assertNull($plugin->carts->applyDiscount($cart, $code));
        }

        $plugin->carts->applyDetails($cart, ['email' => 'plan@example.test']);
        $plugin->carts->save($cart);

        return $cart;
    }

    private function reload(Order $order): Order
    {
        return Order::find()->id($order->id)->one();
    }

    /**
     * @param array<string, mixed> $object
     */
    private function deliver(string $type, array $object): int
    {
        $payload = (string) json_encode([
            'id' => 'evt_plan_' . ++$this->eventCounter,
            'object' => 'event',
            'api_version' => '2026-09-30.endive',
            'created' => time(),
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $t = time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_test_secret');

        return Plugin::getInstance()->webhooks->handleRequest($payload, $header)['status'];
    }

    /**
     * Buys the plan and takes its first payment.
     *
     * @return array{Order, string} the order and the Stripe subscription id
     */
    private function started(Product $plan, ?string $code = null): array
    {
        $cart = $this->planCart($plan, $code);
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $cart = $this->reload($cart);
        $ids = $this->stripe->paySubscription((string) $cart->stripeCheckoutSessionId);
        $this->assertSame(200, $this->deliver('checkout.session.completed', $this->stripe->sessions[$cart->stripeCheckoutSessionId]));

        return [$this->reload($cart), $ids['subscription']];
    }

    /**
     * Takes the next payment of a plan, as Stripe would raise and announce it.
     */
    private function renew(string $subId): void
    {
        $invoice = $this->stripe->renew($subId);
        $this->assertSame(200, $this->deliver('invoice.paid', $invoice));
    }

    private function local(string $stripeId): Subscription
    {
        return Plugin::getInstance()->subscriptions->getSubscriptionByStripeId($stripeId);
    }

    /**
     * @return array<int, array{string, array<string, mixed>}> the updateSubscription calls made to Stripe
     */
    private function stopCalls(): array
    {
        return array_map(static fn(array $call): array => [$call[0], $call[1]], $this->stripe->callsTo('updateSubscription'));
    }

    // The product ------------------------------------------------------------

    public function testAProductWithNoDeliveryCanBeASubscription(): void
    {
        $plan = $this->plan(10);

        $this->assertTrue($plan->getIsSubscription());
        $this->assertSame('month', $plan->subscriptionInterval);
        $this->assertSame(10, $plan->subscriptionPayments);
        $this->assertSame('a month, for 10 payments', $plan->getSubscriptionTerms());
        $this->assertSame(5000, $plan->price);
    }

    public function testAProductIsOneOffUnlessItIsGivenAnInterval(): void
    {
        $product = $this->makeShopProduct(5000, ProductType::KIND_NON_PHYSICAL);

        $this->assertFalse($product->getIsSubscription());
        $this->assertNull($product->getSubscriptionTerms());
    }

    public function testAProductThatIsDeliveredCannotBeASubscription(): void
    {
        $product = $this->makeShopProduct(5000, ProductType::KIND_PHYSICAL);
        $product->subscriptionInterval = 'month';

        $this->assertFalse(Craft::$app->getElements()->saveElement($product));
        $this->assertArrayHasKey('subscriptionInterval', $product->getErrors());
    }

    public function testANumberOfPaymentsNeedsAnInterval(): void
    {
        $product = $this->makeShopProduct(5000, ProductType::KIND_NON_PHYSICAL);
        $product->subscriptionPayments = 10;

        $this->assertFalse(Craft::$app->getElements()->saveElement($product));
        $this->assertArrayHasKey('subscriptionPayments', $product->getErrors());
    }

    public function testTheNumberOfPaymentsHasLimits(): void
    {
        foreach ([0, Product::MAX_SUBSCRIPTION_PAYMENTS + 1] as $bad) {
            $product = $this->makeShopProduct(5000, ProductType::KIND_NON_PHYSICAL);
            $product->subscriptionInterval = 'month';
            $product->subscriptionPayments = $bad;

            $this->assertFalse(Craft::$app->getElements()->saveElement($product), "$bad payments");
            $this->assertArrayHasKey('subscriptionPayments', $product->getErrors());
        }

        $this->assertSame(Product::MAX_SUBSCRIPTION_PAYMENTS, $this->plan(Product::MAX_SUBSCRIPTION_PAYMENTS)->subscriptionPayments);
    }

    public function testAPlanWithNoNumberRunsUntilCancelled(): void
    {
        $plan = $this->plan(null, 'year');

        $this->assertTrue($plan->getIsSubscription());
        $this->assertNull($plan->subscriptionPayments);
        $this->assertSame('a year, until cancelled', $plan->getSubscriptionTerms());
    }

    public function testTheControlPanelFormIsReadAndCleaned(): void
    {
        $product = $this->makeShopProduct(5000, ProductType::KIND_NON_PHYSICAL);
        $product->setAttributesFromRequest(['subscriptionInterval' => 'year', 'subscriptionPayments' => ' 3 ']);

        $this->assertSame('year', $product->subscriptionInterval);
        $this->assertSame(3, $product->subscriptionPayments);

        $product->setAttributesFromRequest(['subscriptionInterval' => '', 'subscriptionPayments' => '']);
        $this->assertNull($product->subscriptionInterval);
        $this->assertNull($product->subscriptionPayments);

        $product->setAttributesFromRequest(['subscriptionInterval' => 'fortnight']);
        $this->assertNull($product->subscriptionInterval, 'only months and years are billed');

        $product->setAttributesFromRequest(['subscriptionPayments' => 'ten']);
        $this->assertTrue($product->hasErrors('subscriptionPayments'));
    }

    public function testTurningBillingOffForgetsTheNumberOfPayments(): void
    {
        $plan = $this->plan(10);
        $plan->subscriptionInterval = null;
        $plan->subscriptionPayments = null;
        Craft::$app->getElements()->saveElement($plan);

        $reloaded = Product::find()->id($plan->id)->status(null)->one();
        $this->assertFalse($reloaded->getIsSubscription());
        $this->assertNull($reloaded->subscriptionPayments);
    }

    public function testTheTermsAreDescribedInWords(): void
    {
        $this->assertNull(SubscriptionTerms::describe(null, 10));
        $this->assertSame('a month, until cancelled', SubscriptionTerms::describe('month', null));
        $this->assertSame('a year, for 1 payment', SubscriptionTerms::describe('year', 1));
        $this->assertSame('a month, for 12 payments', SubscriptionTerms::describe('month', 12));
    }

    public function testTheColumnsExist(): void
    {
        $db = Craft::$app->getDb();

        $this->assertTrue($db->columnExists(Table::PRODUCTS, 'subscriptionInterval'));
        $this->assertTrue($db->columnExists(Table::PRODUCTS, 'subscriptionPayments'));
        $this->assertTrue($db->columnExists(Table::SUBSCRIPTIONS, 'maxPayments'));
    }

    // The basket -------------------------------------------------------------

    public function testASubscriptionIsOneLineOfOneAndMakesTheBasketRecurring(): void
    {
        $plan = $this->plan(10);
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);

        $this->assertNull($carts->addProduct($cart, $plan, 5), 'a quantity is ignored');
        $carts->save($cart);
        $cart = $this->reload($cart);
        $line = $cart->getLineItems()[0];

        $this->assertSame(1, $line->qty);
        $this->assertSame('month', $line->recurringInterval);
        $this->assertSame(10, $line->snapshot['payments']);
        $this->assertTrue($line->getIsSubscription());
        $this->assertFalse($line->getIsDonation());
        $this->assertSame('a month, for 10 payments', $line->getSubscriptionTerms());
        $this->assertTrue($cart->getIsRecurring());
        $this->assertFalse($cart->getHasDonation());
        $this->assertSame(5000, $cart->total);
    }

    public function testAddingItTwiceStillBuysOnePlan(): void
    {
        $plan = $this->plan(10);
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);

        $this->assertNull($carts->addProduct($cart, $plan));
        $this->assertNull($carts->addProduct($cart, $plan));
        $carts->save($cart);

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(1, $cart->getLineItems()[0]->qty);
        $this->assertSame(5000, $cart->total);
    }

    public function testAQuantityCannotBePostedForASubscription(): void
    {
        $plan = $this->plan(10);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$plan, 1]]);

        $carts->setQuantities($cart, [$plan->id => '4']);
        $carts->save($cart);

        $this->assertSame(1, $cart->getLineItems()[0]->qty);
        $this->assertSame(5000, $cart->total);

        $carts->setQuantities($cart, [$plan->id => '0']);
        $this->assertCount(0, $cart->getLineItems(), 'but it can be taken out');
    }

    public function testASubscriptionCannotBeAddedToABasketWithOtherThingsInIt(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1500, ProductType::KIND_NON_PHYSICAL, 'Ticket'), 1]]);

        $this->assertNotNull($carts->addProduct($cart, $this->plan(10)));
        $this->assertCount(1, $cart->getLineItems());
    }

    public function testAnythingElseCannotBeAddedToABasketWithASubscriptionInIt(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->plan(10), 1]]);

        $this->assertNotNull($carts->addProduct($cart, $this->makeShopProduct(1500, ProductType::KIND_NON_PHYSICAL, 'Ticket')));
        $this->assertCount(1, $cart->getLineItems());
    }

    public function testTakingTheSubscriptionOutMakesTheBasketOneOffAgain(): void
    {
        $plan = $this->plan(10);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$plan, 1]]);
        $this->assertTrue($cart->getIsRecurring());

        $carts->removeProduct($cart, (int) $plan->id);
        $carts->save($cart);

        $this->assertFalse($this->reload($cart)->getIsRecurring());
    }

    public function testChangingThePlanTellsTheCustomerBeforeTheyPay(): void
    {
        $plan = $this->plan(10);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$plan, 1]]);

        $plan->subscriptionPayments = 8;
        Craft::$app->getElements()->saveElement($plan);
        $notices = $carts->save($cart);

        $this->assertArrayHasKey('items', $notices);
        $this->assertSame(8, $cart->getLineItems()[0]->snapshot['payments']);
        $this->assertSame([], $carts->save($cart), 'and only once');
    }

    public function testAPlanThatStoppedBeingASubscriptionIsOneOffInTheBasket(): void
    {
        $plan = $this->plan(10);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$plan, 1]]);

        $plan->subscriptionInterval = null;
        $plan->subscriptionPayments = null;
        Craft::$app->getElements()->saveElement($plan);
        $notices = $carts->save($cart);

        $this->assertArrayHasKey('items', $notices);
        $this->assertFalse($cart->getIsRecurring());
        $this->assertNull($cart->getLineItems()[0]->recurringInterval);
    }

    // Paying -----------------------------------------------------------------

    public function testItStartsASubscriptionModeSessionForTheProduct(): void
    {
        $plan = $this->plan(10);
        $cart = $this->planCart($plan);
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame('subscription', $params['mode']);
        $this->assertSame(5000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(['interval' => 'month'], $params['line_items'][0]['price_data']['recurring']);
        $this->assertSame($cart->number, $params['subscription_data']['metadata']['smallChangeOrderNumber']);
        $this->assertSame((string) $plan->id, $params['subscription_data']['metadata']['smallChangeProductId']);
        $this->assertSame('10', $params['subscription_data']['metadata']['smallChangePayments']);
        $this->assertArrayNotHasKey('smallChangeGiftAid', $params['subscription_data']['metadata'], 'a fee is not a gift');
        $this->assertArrayNotHasKey('payment_intent_data', $params);
        $this->assertCount(1, $this->stripe->callsTo('createProduct'));
    }

    public function testAPlanWithNoNumberSendsNoNumber(): void
    {
        $cart = $this->planCart($this->plan(null));
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertArrayNotHasKey('smallChangePayments', $params['subscription_data']['metadata']);
    }

    public function testASubscriptionCannotBePaidForWithAnythingElse(): void
    {
        $cart = $this->planCart($this->plan(10));
        // Only a hand-edited basket can get here: the basket itself refuses it.
        $cart->setLineItems([...$cart->getLineItems(), new \coyshdigital\smallchange\models\LineItem([
            'kind' => 'nonPhysical', 'description' => 'Ticket', 'unitPrice' => 1500, 'qty' => 1,
        ])]);
        Plugin::getInstance()->orders->validateForCheckout($cart);

        $this->assertTrue($cart->hasErrors('items'));
    }

    public function testADiscountCodeTakesItsShareOffEveryPayment(): void
    {
        $this->makeDiscount('HALF', Discount::TYPE_PERCENT, 5000);
        $plan = $this->plan(10);
        $cart = $this->planCart($plan, 'HALF');

        $this->assertSame(2500, $cart->discountAmount);
        $this->assertSame(2500, $cart->total);

        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $calls = $this->stripe->callsTo('createCoupon');
        $this->assertCount(1, $calls);
        [$coupon] = $calls[0];

        $this->assertSame('forever', $coupon['duration'], 'a plan lasts as long as its coupon');
        $this->assertSame(2500, $coupon['amount_off']);
        $this->assertSame(5000, $this->stripe->callsTo('createCheckoutSession')[0][0]['line_items'][0]['price_data']['unit_amount'], 'the price stays whole; the coupon takes the share off');
    }

    public function testADiscountOnAOneOffSaleStillLastsOnce(): void
    {
        $this->makeDiscount('TENOFF', Discount::TYPE_FIXED, 1000);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(5000, ProductType::KIND_NON_PHYSICAL, 'Ticket'), 1]]);
        $carts->applyDiscount($cart, 'TENOFF');
        $carts->applyDetails($cart, ['email' => 'one@example.test']);
        $carts->save($cart);

        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');

        $this->assertSame('once', $this->stripe->callsTo('createCoupon')[0][0]['duration']);
    }

    public function testTheFirstPaymentCompletesTheOrderAndRecordsTheLimit(): void
    {
        [$order, $subId] = $this->started($this->plan(10));

        $this->assertSame(Order::STATE_COMPLETED, $order->state);
        $this->assertSame(5000, $order->totalPaid);
        $this->assertFalse($order->getHasDonation());
        $this->assertNull($order->giftAidDeclared, 'no Gift Aid is offered on a fee');

        $sub = $this->local($subId);
        $this->assertSame(10, $sub->maxPayments);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertSame([], $this->stopCalls());
    }

    // What a discounted plan costs ---------------------------------------------

    public function testADiscountedPlanIsRecordedAtWhatItActuallyCostsEachTime(): void
    {
        $this->makeDiscount('HALF', Discount::TYPE_PERCENT, 5000);
        [$order, $subId] = $this->started($this->plan(3), 'HALF');

        $this->assertSame(2500, $this->local($subId)->amount, 'not the £50 list price');
        $this->assertSame(2500, $order->totalPaid);

        $this->renew($subId);
        $this->assertSame(2500, $this->local($subId)->amount);

        Plugin::getInstance()->subscriptions->reconcile($this->local($subId));
        $this->assertSame(2500, $this->local($subId)->amount, 'asking Stripe again does not bring the list price back');
        $this->assertSame([2500, 2500], array_map(static fn($p): int => $p->amount, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)));
    }

    public function testAPlanWithoutADiscountKeepsItsListPrice(): void
    {
        [, $subId] = $this->started($this->plan(3));

        $this->assertSame(5000, $this->local($subId)->amount);
    }

    public function testThePlanCompleteEmailQuotesWhatWasPaidNotTheListPrice(): void
    {
        $this->makeDiscount('HALF', Discount::TYPE_PERCENT, 5000);
        [$order, $subId] = $this->started($this->plan(2), 'HALF');
        $this->renew($subId);

        $sub = $this->local($subId);
        $message = Plugin::getInstance()->emails->compose(Emails::KEY_SUBSCRIPTION_COMPLETE, $order, ['amount' => $sub->amount, 'interval' => 'month', 'payments' => 2]);

        $this->assertStringContainsString('£25.00', $message['html']);
        $this->assertStringNotContainsString('£50.00', $message['html']);
    }

    // The end of a plan ------------------------------------------------------

    public function testThePlanEndsAfterItsLastPaymentAndSaysSo(): void
    {
        [$order, $subId] = $this->started($this->plan(3));
        $keys = fn(): array => $this->emails->keysFor((string) $order->number);

        $this->renew($subId);
        $this->assertSame([], $this->stopCalls(), 'two of three payments taken');
        $this->assertNotContains(Emails::KEY_SUBSCRIPTION_COMPLETE, $keys());

        $this->renew($subId);

        $calls = $this->stopCalls();
        $this->assertCount(1, $calls);
        $this->assertSame([$subId, ['cancel_at_period_end' => true]], $calls[0]);
        $this->assertTrue($this->local($subId)->cancelAtPeriodEnd);
        $this->assertTrue($this->local($subId)->getIsLive(), 'it runs to the end of the period just paid for');
        $this->assertSame(1, count(array_keys($keys(), Emails::KEY_SUBSCRIPTION_COMPLETE, true)));
        $this->assertTrue(Plugin::getInstance()->subscriptions->getIsPlanComplete($this->local($subId)));
    }

    public function testAPaymentArrivingTwiceEndsThePlanOnce(): void
    {
        [$order, $subId] = $this->started($this->plan(2));
        $invoice = $this->stripe->renew($subId);

        $this->assertSame(200, $this->deliver('invoice.paid', $invoice));
        $this->assertSame(200, $this->deliver('invoice.paid', $invoice));

        $this->assertCount(1, $this->stopCalls());
        $this->assertSame(1, count(array_keys($this->emails->keysFor((string) $order->number), Emails::KEY_SUBSCRIPTION_COMPLETE, true)));
        $this->assertCount(2, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    public function testAFailedPaymentDoesNotCountTowardsTheEnd(): void
    {
        [, $subId] = $this->started($this->plan(2));

        $this->stripe->renew($subId, false);
        $this->assertSame([], $this->stopCalls());
        $this->assertFalse(Plugin::getInstance()->subscriptions->getIsPlanComplete($this->local($subId)));
    }

    public function testAPlanOfOnePaymentEndsAfterTheFirst(): void
    {
        [$order, $subId] = $this->started($this->plan(1));

        $this->assertCount(1, $this->stopCalls());
        $this->assertSame(1, count(array_keys($this->emails->keysFor((string) $order->number), Emails::KEY_SUBSCRIPTION_COMPLETE, true)));

        // The invoice webhook that follows the first payment finds it already done.
        $this->deliver('invoice.paid', $this->stripe->invoices[array_key_first($this->stripe->invoices)]);
        $this->assertCount(1, $this->stopCalls());
    }

    public function testAPlanWithNoNumberKeepsGoing(): void
    {
        [$order, $subId] = $this->started($this->plan(null));

        foreach (range(1, 5) as $_) {
            $this->renew($subId);
        }

        $this->assertSame([], $this->stopCalls());
        $this->assertNull($this->local($subId)->maxPayments);
        $this->assertNotContains(Emails::KEY_SUBSCRIPTION_COMPLETE, $this->emails->keysFor((string) $order->number));
    }

    public function testStripeBeingUnreachableLetsTheWebhookBeRetried(): void
    {
        [, $subId] = $this->started($this->plan(2));
        $this->stripe->failSubscriptionUpdate = true;

        $invoice = $this->stripe->renew($subId);
        $status = $this->deliver('invoice.paid', $invoice);

        $this->assertGreaterThanOrEqual(500, $status, 'a failed event is answered with an error so Stripe sends it again');
        $this->assertFalse($this->local($subId)->cancelAtPeriodEnd);

        $this->stripe->failSubscriptionUpdate = false;
        $this->assertSame(200, $this->deliver('invoice.paid', $invoice));
        $this->assertTrue($this->local($subId)->cancelAtPeriodEnd);
    }

    public function testTheEndOfACompletedPlanIsNotACancellation(): void
    {
        [$order, $subId] = $this->started($this->plan(2));
        $this->renew($subId);

        $this->stripe->subscriptions[$subId]['status'] = 'canceled';
        $this->stripe->subscriptions[$subId]['ended_at'] = time();
        $this->deliver('customer.subscription.deleted', $this->stripe->subscriptions[$subId]);

        $keys = $this->emails->keysFor((string) $order->number);
        $this->assertSame(Subscription::STATUS_CANCELED, $this->local($subId)->status);
        $this->assertNotContains(Emails::KEY_SUBSCRIPTION_CANCELLED, $keys);
        $this->assertNotContains(Emails::KEY_ADMIN_SUBSCRIPTION_CANCELLED, $keys);
    }

    public function testCancellingEarlyIsStillACancellation(): void
    {
        [$order, $subId] = $this->started($this->plan(10));
        $this->renew($subId);

        $this->stripe->subscriptions[$subId]['status'] = 'canceled';
        $this->stripe->subscriptions[$subId]['ended_at'] = time();
        $this->deliver('customer.subscription.deleted', $this->stripe->subscriptions[$subId]);

        $keys = $this->emails->keysFor((string) $order->number);
        $this->assertContains(Emails::KEY_SUBSCRIPTION_CANCELLED, $keys);
        $this->assertContains(Emails::KEY_ADMIN_SUBSCRIPTION_CANCELLED, $keys);
    }

    // The emails -------------------------------------------------------------

    public function testThePlanCompleteEmailSaysItIsTheLastPayment(): void
    {
        [$order] = $this->started($this->plan(3));
        $message = Plugin::getInstance()->emails->compose(Emails::KEY_SUBSCRIPTION_COMPLETE, $order, ['amount' => 5000, 'interval' => 'month', 'payments' => 3]);

        $this->assertSame('plan@example.test', $message['to']);
        $this->assertStringContainsString('payment plan', strtolower($message['subject']));
        $this->assertStringContainsString('last payment', $message['html']);
        $this->assertStringContainsString('payment 3 of 3', $message['html']);
        $this->assertStringContainsString('£50.00', $message['html']);
    }

    public function testTheRecurringEmailsSpeakOfASubscriptionNotADonation(): void
    {
        [$order] = $this->started($this->plan(3));
        $emails = Plugin::getInstance()->emails;
        $context = ['amount' => 5000, 'interval' => 'month', 'invoiceUrl' => 'https://invoice.stripe.test/in_1'];

        foreach ([Emails::KEY_SUBSCRIPTION_CANCELLED, Emails::KEY_SUBSCRIPTION_PAYMENT_FAILED, Emails::KEY_SUBSCRIPTION_ACTION_REQUIRED, Emails::KEY_ADMIN_SUBSCRIPTION_CANCELLED] as $key) {
            $message = $emails->compose($key, $order, $context);

            $this->assertStringNotContainsStringIgnoringCase('donation', $message['subject'] . strip_tags($message['html']), $key);
        }

        $this->assertStringContainsString('subscription', strtolower($emails->compose(Emails::KEY_SUBSCRIPTION_CANCELLED, $order, $context)['html']));
    }

    public function testEveryEmailHasADefinitionForTheSettingsTable(): void
    {
        $definitions = Emails::definitions();

        $this->assertArrayHasKey(Emails::KEY_SUBSCRIPTION_COMPLETE, $definitions);
        $this->assertSame(Emails::TO_DONOR, $definitions[Emails::KEY_SUBSCRIPTION_COMPLETE]['to']);
        $this->assertSame('subscription-complete', $definitions[Emails::KEY_SUBSCRIPTION_COMPLETE]['template']);
    }
}
