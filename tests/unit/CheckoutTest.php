<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\BuildCheckoutSessionEvent;
use coyshdigital\smallchange\events\OrderEvent;
use coyshdigital\smallchange\exceptions\CheckoutException;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Checkout;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\services\Payments;
use coyshdigital\smallchange\tests\Fixtures;
use yii\base\Event;

final class CheckoutTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
    }

    // Building the session ---------------------------------------------------

    public function testTheEmbeddedSessionHasTheRightShape(): void
    {
        $order = $this->makePendingOrder('25');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame('payment', $params['mode']);
        $this->assertSame('elements', $params['ui_mode']);
        $this->assertSame($order->number, $params['client_reference_id']);
        $this->assertSame([['price_data' => ['currency' => 'gbp', 'unit_amount' => 2500, 'product_data' => ['name' => 'General fund']], 'quantity' => 1]], $params['line_items']);
        $this->assertSame('donor@example.test', $params['customer_email']);
        $this->assertSame('always', $params['customer_creation']);
        $this->assertArrayNotHasKey('customer', $params);
    }

    public function testElementsSessionsNeverGetParametersStripeRefuses(): void
    {
        $this->makePendingOrder();
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        foreach (['submit_type', 'success_url', 'cancel_url', 'after_expiration', 'custom_text', 'payment_method_types'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $params, "$forbidden is not allowed with ui_mode=elements");
        }
    }

    public function testHostedCheckoutBringsADonorWhoBacksOutBackToThePaymentPage(): void
    {
        Plugin::getInstance()->getSettings()->checkoutMode = 'hosted_page';
        $order = $this->makeOrder();

        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertStringContainsString('donate/payment', $params['cancel_url']);
        $this->assertStringContainsString('number=' . $order->number, $params['cancel_url']);
    }

    public function testTheHostedPageAddressIsOnlyHandedToTheSessionThatPlacedTheOrder(): void
    {
        Plugin::getInstance()->getSettings()->checkoutMode = 'hosted_page';
        $order = $this->makeOrder();
        $url = Plugin::getInstance()->checkout->start($order, 'donate/payment');

        $this->assertSame($url, Plugin::getInstance()->checkout->hostedUrl($order));

        \Craft::$app->getSession()->remove('smallChange.recentOrders');
        $this->assertNull(Plugin::getInstance()->checkout->hostedUrl($order));
    }

    public function testNoHostedAddressOnceThePaymentIsDone(): void
    {
        Plugin::getInstance()->getSettings()->checkoutMode = 'hosted_page';
        $order = $this->makeOrder();
        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $this->assertNull(Plugin::getInstance()->checkout->hostedUrl(Order::find()->id($order->id)->one()));
    }

    public function testEverySessionOptsOutOfManagedPayments(): void
    {
        $this->makePendingOrder();
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame(['enabled' => false], $params['managed_payments']);
    }

    public function testTheReturnUrlKeepsTheSessionPlaceholderLiteral(): void
    {
        $this->makePendingOrder();
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertStringContainsString('small-change/checkout/complete', $params['return_url']);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $params['return_url']);
        $this->assertStringNotContainsString('%7B', $params['return_url']);
        $this->assertMatchesRegularExpression('/[?&]t=[^&]+&session_id=/', $params['return_url']);
    }

    public function testGiftAidAnswerTravelsInThePaymentIntentMetadata(): void
    {
        $order = $this->makePendingOrder('25', true);
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame('yes', $params['payment_intent_data']['metadata']['smallChangeGiftAid']);
        $this->assertSame('2500', $params['payment_intent_data']['metadata']['smallChangeGiftAidAmount']);
        $this->assertSame($order->number, $params['payment_intent_data']['metadata']['smallChangeOrderNumber']);
        $this->assertArrayNotHasKey('smallChangeGiftAid', $params['metadata'], 'only the intent carries it');
    }

    public function testNoGiftAidMetadataWhenTheOptionIsOff(): void
    {
        Plugin::getInstance()->getSettings()->giftAidEnabled = false;
        $this->makePendingOrder('25');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertArrayNotHasKey('smallChangeGiftAid', $params['payment_intent_data']['metadata']);
        $this->assertArrayNotHasKey('smallChangeGiftAidAmount', $params['payment_intent_data']['metadata']);
    }

    public function testAKnownStripeCustomerIsReusedInsteadOfAnEmail(): void
    {
        $plugin = Plugin::getInstance();
        $customer = $plugin->customers->findOrCreate('donor@example.test', 'test');
        $plugin->customers->setStripeCustomerId($customer, 'cus_known');

        $this->makePendingOrder();
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame('cus_known', $params['customer']);
        $this->assertArrayNotHasKey('customer_email', $params);
        $this->assertArrayNotHasKey('customer_creation', $params);
    }

    public function testHostedModeUsesSuccessAndCancelUrlsAndTheDonateButton(): void
    {
        Plugin::getInstance()->getSettings()->checkoutMode = 'hosted_page';

        $order = $this->makeOrder();
        $url = Plugin::getInstance()->checkout->start($order, 'donate/payment');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame('hosted_page', $params['ui_mode']);
        $this->assertSame('donate', $params['submit_type']);
        $this->assertArrayHasKey('success_url', $params);
        $this->assertArrayHasKey('cancel_url', $params);
        $this->assertArrayNotHasKey('return_url', $params);
        $this->assertStringStartsWith('https://checkout.stripe.test/', (string) $url);
    }

    public function testEmbeddedStartReturnsNoRedirect(): void
    {
        $this->assertNull(Plugin::getInstance()->checkout->start($this->makeOrder(), 'donate/payment'));
    }

    public function testListenersCannotChangeTheMoney(): void
    {
        Event::on(Checkout::class, Checkout::EVENT_BUILD_CHECKOUT_SESSION, static function(BuildCheckoutSessionEvent $e): void {
            $e->params['line_items'][0]['price_data']['unit_amount'] = 1;
            $e->params['mode'] = 'subscription';
            $e->params['metadata']['custom'] = 'kept';
        });

        $this->makePendingOrder('25');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];
        Event::off(Checkout::class, Checkout::EVENT_BUILD_CHECKOUT_SESSION);

        $this->assertSame(2500, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('payment', $params['mode']);
        $this->assertSame('kept', $params['metadata']['custom']);
    }

    // Starting ---------------------------------------------------------------

    public function testStartingMovesTheOrderToPendingAndRecordsTheSession(): void
    {
        $order = $this->makePendingOrder();
        $saved = Order::find()->id($order->id)->one();

        $this->assertSame(Order::STATE_PENDING, $saved->state);
        $this->assertSame('cs_test_1', $saved->stripeCheckoutSessionId);
        $this->assertNotNull($saved->customerId);
        $this->assertTrue(Plugin::getInstance()->orders->isInSession((string) $order->number), 'the donor can now see their own order');
    }

    public function testStartingIsRecomputedFromTheLinesNotTheSavedTotal(): void
    {
        $order = $this->makeOrder(null, '25');
        // Simulate a tampered or stale saved total.
        $order->total = 1;
        $order->itemSubtotal = 1;
        \Craft::$app->getElements()->saveElement($order, false);

        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame(2500, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(2500, Order::find()->id($order->id)->one()->total);
    }

    public function testStartingTwiceWithTheSameAmountsReusesTheSession(): void
    {
        $order = $this->makeOrder();
        $checkout = Plugin::getInstance()->checkout;

        $checkout->start($order, 'donate/payment');
        $first = $order->stripeCheckoutSessionId;
        $checkout->start($order, 'donate/payment');

        $this->assertSame($first, $order->stripeCheckoutSessionId, 'a double click must not make two sessions');
        $this->assertCount(2, $this->stripe->callsTo('createCheckoutSession'));
        $this->assertSame([], $this->stripe->callsTo('expireCheckoutSession'));
        $this->assertSame(1, count($this->stripe->sessions));
    }

    public function testComingBackToTheFormAndStartingAgainGivesAPayableSession(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makeOrder();
        $plugin->checkout->start($order, 'donate/payment');
        $first = $order->stripeCheckoutSessionId;

        // The details form was submitted again, as the controllers do it: the started payment is pulled back first.
        $plugin->carts->applyDetails($order, ['customerNote' => 'Ring the bell']);
        $plugin->carts->save($order);
        $plugin->checkout->start($order, 'donate/payment');

        $this->assertSame('expired', $this->stripe->sessions[$first]['status']);
        $this->assertNotSame($first, $order->stripeCheckoutSessionId, 'the expired session must not come back as a replay');
        $this->assertSame('open', $this->stripe->sessions[$order->stripeCheckoutSessionId]['status']);
        $this->assertNotNull($plugin->checkout->clientSecret($order));
    }

    public function testChangingTheAmountExpiresTheOldSession(): void
    {
        $order = $this->makeOrder(null, '25');
        $checkout = Plugin::getInstance()->checkout;
        $checkout->start($order, 'donate/payment');
        $old = $order->stripeCheckoutSessionId;

        $line = $order->getLineItems()[0];
        $line->unitPrice = 5000;
        $order->setLineItems([$line]);
        $checkout->start($order, 'donate/payment');

        $this->assertNotSame($old, $order->stripeCheckoutSessionId);
        $this->assertSame([[$old]], $this->stripe->callsTo('expireCheckoutSession'));
        $this->assertSame('expired', $this->stripe->sessions[$old]['status']);
    }

    public function testAStripeOutageLeavesTheOrderAsACart(): void
    {
        $this->stripe->failCreate = true;
        $order = $this->makeOrder();

        try {
            Plugin::getInstance()->checkout->start($order, 'donate/payment');
            $this->fail('expected a CheckoutException');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('try again', $e->getMessage());
            $this->assertStringNotContainsString('network', $e->getMessage(), 'Stripe detail is not shown to donors');
        }

        $this->assertSame(Order::STATE_CART, Order::find()->id($order->id)->one()->state);
    }

    public function testCannotStartWithoutKeysOrForACompletedOrder(): void
    {
        $order = $this->makeOrder();
        $this->stripe->fakeMode = '';
        $plugin = Plugin::getInstance();

        $broken = new class() extends \coyshdigital\smallchange\tests\FakeStripe {
            public function mode(): ?string
            {
                return null;
            }
        };
        $plugin->set('stripe', $broken);

        $this->expectException(CheckoutException::class);
        $plugin->checkout->start($order, 'donate/payment');
    }

    public function testACompletedOrderCannotBeStartedAgain(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $this->expectException(CheckoutException::class);
        Plugin::getInstance()->checkout->start(Order::find()->id($order->id)->one(), 'donate/payment');
    }

    // The client secret ------------------------------------------------------

    public function testTheClientSecretIsOnlyForTheSessionThatPlacedTheOrder(): void
    {
        $order = $this->makePendingOrder();
        $checkout = Plugin::getInstance()->checkout;

        $this->assertSame('cs_test_1_secret_abc', $checkout->clientSecret($order));

        // A different browser: same order, no session membership.
        \Craft::$app->getSession()->remove('smallChange.recentOrders');
        $this->assertNull($checkout->clientSecret($order));
    }

    public function testTheSecretFromCreateIsUsedIfRetrieveWithholdsIt(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->sessions[$order->stripeCheckoutSessionId]['client_secret'] = null;

        $this->assertSame('cs_test_1_secret_abc', Plugin::getInstance()->checkout->clientSecret($order));

        \Craft::$app->getSession()->remove('smallChange.clientSecrets');
        $this->assertNull(Plugin::getInstance()->checkout->clientSecret($order));
    }

    public function testNoClientSecretOnceThePaymentIsDone(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $this->assertNull(Plugin::getInstance()->checkout->clientSecret(Order::find()->id($order->id)->one()));
    }

    // Completing -------------------------------------------------------------

    public function testCompletingRecordsOnePaymentAndMarksTheOrderPaid(): void
    {
        $order = $this->makePendingOrder('25', true);
        $intentId = $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->assertTrue(Plugin::getInstance()->orders->completeOrder($order));

        $saved = Order::find()->id($order->id)->one();
        $this->assertSame(Order::STATE_COMPLETED, $saved->state);
        $this->assertSame(Order::PAYMENT_PAID, $saved->paymentStatus);
        $this->assertSame(2500, $saved->totalPaid);
        $this->assertSame($intentId, $saved->stripePaymentIntentId);
        $this->assertNotNull($saved->dateCompleted);
        $this->assertSame('completed', $saved->getStatus());

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertCount(1, $payments);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payments[0]->status);
        $this->assertSame(2500, $payments[0]->amount);
        $this->assertTrue($payments[0]->giftAidDeclared);
        $this->assertSame(2500, $payments[0]->giftAidAmount);
        $this->assertSame($intentId, $payments[0]->stripePaymentIntentId);
    }

    public function testCompletingTwiceDoesEverythingOnce(): void
    {
        $afterEvents = 0;
        $paymentEvents = 0;
        Event::on(Orders::class, Orders::EVENT_AFTER_COMPLETE_ORDER, static function() use (&$afterEvents): void {
            $afterEvents++;
        });
        Event::on(Payments::class, Payments::EVENT_AFTER_PAYMENT_SUCCEEDED, static function() use (&$paymentEvents): void {
            $paymentEvents++;
        });

        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $orders = Plugin::getInstance()->orders;

        $this->assertTrue($orders->completeOrder($order));
        $this->assertFalse($orders->completeOrder($order), 'the second call sees a completed order');
        $this->assertFalse($orders->completeOrder(Order::find()->id($order->id)->one()));

        Event::off(Orders::class, Orders::EVENT_AFTER_COMPLETE_ORDER);
        Event::off(Payments::class, Payments::EVENT_AFTER_PAYMENT_SUCCEEDED);

        $this->assertSame(1, $afterEvents);
        $this->assertSame(1, $paymentEvents);
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
        $this->assertSame(['receipt', 'adminNewOrder'], $this->emails->keysFor((string) $order->number));
    }

    public function testCompletingCarriesTheGiftAidFieldsInTheEvents(): void
    {
        $captured = null;
        Event::on(Orders::class, Orders::EVENT_AFTER_COMPLETE_ORDER, static function(OrderEvent $e) use (&$captured): void {
            $captured = $e;
        });

        $order = $this->makePendingOrder('40', true);
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);
        Event::off(Orders::class, Orders::EVENT_AFTER_COMPLETE_ORDER);

        $this->assertInstanceOf(OrderEvent::class, $captured);
        $this->assertTrue($captured->giftAidDeclared);
        $this->assertSame(4000, $captured->giftAidEligibleAmount);
        $this->assertStringContainsString('Small Church', (string) $captured->giftAidDeclarationText);
        $this->assertSame('Ada', $captured->billingAddress?->firstName);
        $this->assertSame('SW1Y 4JH', $captured->billingAddress?->postalCode);
    }

    public function testABeforeListenerCanVetoCompletion(): void
    {
        Event::on(Orders::class, Orders::EVENT_BEFORE_COMPLETE_ORDER, static function(OrderEvent $e): void {
            $e->isValid = false;
        });

        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $done = Plugin::getInstance()->orders->completeOrder($order);
        Event::off(Orders::class, Orders::EVENT_BEFORE_COMPLETE_ORDER);

        $this->assertFalse($done);
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state);
    }

    public function testAnUnpaidSessionDoesNotCompleteTheOrder(): void
    {
        $order = $this->makePendingOrder();

        $this->assertFalse(Plugin::getInstance()->orders->completeOrder($order));
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state);
        $this->assertSame([], $this->emails->queued);
    }

    public function testASessionFromTheOtherModeNeverCompletesAnOrder(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $this->stripe->sessions[$order->stripeCheckoutSessionId]['livemode'] = true;

        $this->assertFalse(Plugin::getInstance()->orders->completeOrder($order));
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state);
    }

    public function testTheStripeCustomerIsRememberedAfterPayment(): void
    {
        $order = $this->makePendingOrder('25', true);
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $customer = Plugin::getInstance()->customers->findOrCreate('donor@example.test', 'test');
        $this->assertSame('cus_test_paid', $customer->stripeCustomerId);
        $this->assertSame([['cus_test_paid', ['name' => 'Ada Lovelace', 'address' => ['line1' => '12 St James Square', 'city' => 'London', 'postal_code' => 'SW1Y 4JH', 'country' => 'GB']]]], $this->stripe->callsTo('updateCustomer'), 'name and address are pushed to Stripe');
    }

    public function testAnAmountMismatchStillCompletesWithWhatStripeTook(): void
    {
        $order = $this->makePendingOrder('25');
        $this->stripe->pay((string) $order->stripeCheckoutSessionId, 2400);
        Plugin::getInstance()->orders->completeOrder($order);

        $saved = Order::find()->id($order->id)->one();
        $this->assertSame(2400, $saved->totalPaid, 'the record shows what was actually paid');
        $this->assertSame(2500, $saved->total);
    }

    // Expiry and failure -----------------------------------------------------

    public function testAnExpiredSessionReturnsThePendingOrderToACart(): void
    {
        $order = $this->makePendingOrder();
        $orders = Plugin::getInstance()->orders;

        $orders->releaseExpired($order, 'cs_some_other_session');
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state, 'an older session expiring changes nothing');

        $orders->releaseExpired($order, (string) $order->stripeCheckoutSessionId);
        $saved = Order::find()->id($order->id)->one();
        $this->assertSame(Order::STATE_CART, $saved->state);
        $this->assertNull($saved->stripeCheckoutSessionId);
    }

    public function testExpiryNeverUndoesACompletedOrder(): void
    {
        $order = $this->makePendingOrder();
        $sessionId = (string) $order->stripeCheckoutSessionId;
        $this->stripe->pay($sessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        Plugin::getInstance()->orders->releaseExpired(Order::find()->id($order->id)->one(), $sessionId);

        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);
    }

    // The return trip --------------------------------------------------------

    public function testReturningAfterPaymentCompletesTheOrderAndGrantsAccess(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        \Craft::$app->getSession()->remove('smallChange.recentOrders');

        $token = $plugin->orders->createAccessToken($order, 'donate/payment');
        $result = $plugin->checkout->handleReturn($token, (string) $order->stripeCheckoutSessionId);

        $this->assertSame(Checkout::OUTCOME_PAID, $result['outcome']);
        $this->assertSame(Order::STATE_COMPLETED, $result['order']->state);
        $this->assertSame('donate/payment', $result['paymentUrl']);
        $this->assertNotNull($plugin->orders->recentOrder($order->number), 'the signed link puts the order in this browser session');
    }

    public function testReturningBeforeAnyPaymentGoesBackToThePaymentForm(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();

        $result = $plugin->checkout->handleReturn($plugin->orders->createAccessToken($order, 'donate/payment'), (string) $order->stripeCheckoutSessionId);

        $this->assertSame(Checkout::OUTCOME_OPEN, $result['outcome']);
        $this->assertSame(Order::STATE_PENDING, $result['order']->state);
    }

    public function testADelayedPaymentShowsAsProcessing(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $this->stripe->sessions[$order->stripeCheckoutSessionId]['status'] = 'complete';

        $result = $plugin->checkout->handleReturn($plugin->orders->createAccessToken($order, 'x'), (string) $order->stripeCheckoutSessionId);

        $this->assertSame(Checkout::OUTCOME_PROCESSING, $result['outcome']);
        $this->assertSame(Order::PAYMENT_PROCESSING, $result['order']->paymentStatus);
    }

    /**
     * @dataProvider badReturns
     */
    public function testBadReturnsAreRefused(string $case): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $other = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $token = $plugin->orders->createAccessToken($order, 'x');
        $sessionId = (string) $order->stripeCheckoutSessionId;

        switch ($case) {
            case 'forged token':
                $token = 'forged';
                break;
            case 'someone else\'s session':
                $sessionId = (string) $other->stripeCheckoutSessionId;
                break;
            case 'unknown session':
                $sessionId = 'cs_nope';
                break;
            case 'wrong mode':
                $this->stripe->sessions[$sessionId]['livemode'] = true;
                break;
            case 'mismatched reference':
                $this->stripe->sessions[$sessionId]['client_reference_id'] = 'SOMEONEELSE';
                break;
        }

        $this->expectException(\Throwable::class);
        $plugin->checkout->handleReturn($token, $sessionId);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badReturns(): array
    {
        return [
            'forged token' => ['forged token'],
            'someone else\'s session' => ['someone else\'s session'],
            'unknown session' => ['unknown session'],
            'wrong mode' => ['wrong mode'],
            'mismatched reference' => ['mismatched reference'],
        ];
    }

    public function testAForgedReturnDoesNotGrantAccess(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        \Craft::$app->getSession()->remove('smallChange.recentOrders');

        try {
            $plugin->checkout->handleReturn('forged', (string) $order->stripeCheckoutSessionId);
        } catch (CheckoutException) {
            // expected
        }

        $this->assertNull($plugin->orders->recentOrder($order->number));
    }
}
