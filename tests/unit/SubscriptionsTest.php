<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\exceptions\RefundException;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Subscription;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\CustomerRecord;
use coyshdigital\smallchange\records\PaymentRecord;
use coyshdigital\smallchange\records\WebhookEventRecord;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\Webhooks;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use Stripe\Event;

final class SubscriptionsTest extends Unit
{
    use Fixtures;

    private int $eventCounter = 0;

    protected function _before(): void
    {
        $this->useFakes();
    }

    // Helpers ----------------------------------------------------------------

    private function recurringProduct(): Product
    {
        $product = $this->makeProduct();
        $product->allowRecurring = true;
        $product->recurringIntervals = ['month', 'year'];
        Craft::$app->getElements()->saveElement($product);

        return $product;
    }

    private function recurringOrder(string $interval = 'month', string $amount = '10'): Order
    {
        $order = Plugin::getInstance()->orders->buildDonation($this->recurringProduct(), $amount, 'monthly@example.test', true, $this->homeAddress(), 'donate/thanks', $interval);
        $this->assertFalse($order->hasErrors(), json_encode($order->getErrors()));
        Craft::$app->getElements()->saveElement($order);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');

        return $order;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function deliver(string $type, array $object, ?int $created = null, ?string $id = null): int
    {
        $payload = (string) json_encode([
            'id' => $id ?? 'evt_sub_' . ++$this->eventCounter,
            'object' => 'event',
            'api_version' => '2026-09-30.endive',
            'created' => $created ?? time(),
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $t = time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_test_secret');

        return Plugin::getInstance()->webhooks->handleRequest($payload, $header)['status'];
    }

    /**
     * A recurring donation that has been through its first payment.
     *
     * @return array{Order, string, array<string, string>}
     */
    private function started(): array
    {
        $order = $this->recurringOrder();
        $ids = $this->stripe->paySubscription((string) $order->stripeCheckoutSessionId);
        $this->assertSame(200, $this->deliver('checkout.session.completed', $this->stripe->sessions[$order->stripeCheckoutSessionId]));

        return [Order::find()->id($order->id)->one(), $ids['subscription'], $ids];
    }

    private function local(string $stripeId): Subscription
    {
        return Plugin::getInstance()->subscriptions->getSubscriptionByStripeId($stripeId);
    }

    // Starting ---------------------------------------------------------------

    public function testARecurringDonationStartsASubscriptionModeSession(): void
    {
        $order = $this->recurringOrder('year', '120');
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];

        $this->assertSame('subscription', $params['mode']);
        $this->assertSame(['enabled' => false], $params['managed_payments']);
        $this->assertSame(12000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(['interval' => 'year'], $params['line_items'][0]['price_data']['recurring']);
        $this->assertStringStartsWith('prod_test_', $params['line_items'][0]['price_data']['product']);
        $this->assertSame($order->number, $params['subscription_data']['metadata']['smallChangeOrderNumber']);
        $this->assertSame('yes', $params['subscription_data']['metadata']['smallChangeGiftAid']);
        $this->assertArrayNotHasKey('customer_creation', $params);
        $this->assertArrayNotHasKey('payment_intent_data', $params);
        $this->assertArrayNotHasKey('submit_type', $params);
    }

    public function testTheStripeProductIsCreatedOnceAndReused(): void
    {
        $product = $this->recurringProduct();
        $orders = Plugin::getInstance()->orders;

        foreach ([1, 2] as $_) {
            $order = $orders->buildDonation($product, '10', 'a@example.test', false, null, null, 'month');
            Craft::$app->getElements()->saveElement($order);
            Plugin::getInstance()->checkout->start($order, 'donate/payment');
        }

        $this->assertCount(1, $this->stripe->callsTo('createProduct'));
    }

    public function testAnIntervalTheProductDoesNotOfferIsRefused(): void
    {
        $product = $this->makeProduct();
        $order = Plugin::getInstance()->orders->buildDonation($product, '10', 'a@example.test', false, null, null, 'month');

        $this->assertTrue($order->hasErrors(), 'a one-off-only product cannot be given monthly');

        $product = $this->recurringProduct();
        $product->recurringIntervals = ['month'];
        Craft::$app->getElements()->saveElement($product);
        $order = Plugin::getInstance()->orders->buildDonation($product, '10', 'a@example.test', false, null, null, 'year');

        $this->assertTrue($order->hasErrors());
    }

    public function testAnUnknownIntervalIsRefused(): void
    {
        $order = Plugin::getInstance()->orders->buildDonation($this->recurringProduct(), '10', 'a@example.test', false, null, null, 'week');

        $this->assertTrue($order->hasErrors());
    }

    // The first payment ------------------------------------------------------

    public function testTheFirstPaymentCompletesTheOrderAndRecordsTheSubscription(): void
    {
        [$order, $subId, $ids] = $this->started();

        $this->assertSame(Order::STATE_COMPLETED, $order->state);
        $this->assertSame(Order::PAYMENT_PAID, $order->paymentStatus);
        $this->assertSame(1000, $order->totalPaid);
        $this->assertSame(1000, $order->giftAidEligibleAmount);
        $this->assertTrue($order->getIsRecurring());

        $sub = $this->local($subId);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertSame('month', $sub->interval);
        $this->assertSame(1000, $sub->amount);
        $this->assertSame((int) $order->id, $sub->orderId);
        $this->assertNotNull($sub->currentPeriodEnd);
        $this->assertTrue($sub->giftAidDeclared);
        $this->assertSame($sub->id, $order->subscriptionId);

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertCount(1, $payments);
        $this->assertSame($ids['invoice'], $payments[0]->stripeInvoiceId);
        $this->assertSame($sub->id, $payments[0]->subscriptionId);
        $this->assertContains(Emails::KEY_RECEIPT, $this->emails->keysFor((string) $order->number));
    }

    public function testTheFirstInvoiceArrivingAsWellDoesNotDoubleCount(): void
    {
        [$order, , $ids] = $this->started();
        $this->deliver('invoice.paid', $this->stripe->invoices[$ids['invoice']]);

        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
        $this->assertSame(1000, Order::find()->id($order->id)->one()->totalPaid);
    }

    public function testTheInvoiceCanCompleteTheOrderBeforeTheSessionEventArrives(): void
    {
        $order = $this->recurringOrder();
        $ids = $this->stripe->paySubscription((string) $order->stripeCheckoutSessionId);

        $this->assertSame(200, $this->deliver('invoice.paid', $this->stripe->invoices[$ids['invoice']]));
        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);

        $this->deliver('checkout.session.completed', $this->stripe->sessions[$order->stripeCheckoutSessionId]);
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    public function testInvoicesForOtherThingsOnTheAccountAreIgnored(): void
    {
        $result = $this->deliver('invoice.paid', ['id' => 'in_other', 'object' => 'invoice', 'billing_reason' => 'manual']);

        $this->assertSame(200, $result);
        $this->assertSame('ignored', WebhookEventRecord::find()->orderBy(['id' => SORT_DESC])->one()->status);
    }

    public function testInvoicesForSubscriptionsThisPluginDidNotStartAreLeftAlone(): void
    {
        $this->stripe->subscriptions['sub_elsewhere'] = [
            'id' => 'sub_elsewhere', 'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_x', 'currency' => 'gbp',
            'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null, 'metadata' => [], 'items' => ['data' => []], 'latest_invoice' => null,
        ];

        $result = $this->deliver('invoice.paid', [
            'id' => 'in_elsewhere', 'object' => 'invoice', 'billing_reason' => 'subscription_cycle',
            'parent' => ['subscription_details' => ['subscription' => 'sub_elsewhere', 'metadata' => []]],
        ]);

        $this->assertSame(200, $result);
        $this->assertNull(Plugin::getInstance()->subscriptions->getSubscriptionByStripeId('sub_elsewhere'), 'nothing of another product on the account is recorded');
        $this->assertSame([], $this->stripe->callsTo('retrieveSubscription'), 'and Stripe is not even asked about it');
    }

    // Later payments ---------------------------------------------------------

    public function testEachRenewalIsAnotherPaymentWithItsOwnReceipt(): void
    {
        [$order, $subId] = $this->started();
        $invoice = $this->stripe->renew($subId);
        $this->deliver('invoice.paid', $invoice);

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertCount(2, $payments);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payments[1]->status);
        $this->assertSame($invoice['id'], $payments[1]->stripeInvoiceId);
        $this->assertSame(2000, Order::find()->id($order->id)->one()->totalPaid);
        $this->assertSame(2, count(array_keys($this->emails->keysFor((string) $order->number), Emails::KEY_RECEIPT, true)));
    }

    public function testARepeatedRenewalEventChangesNothing(): void
    {
        [$order, $subId] = $this->started();
        $invoice = $this->stripe->renew($subId);
        $this->deliver('invoice.paid', $invoice, null, 'evt_same');
        $this->deliver('invoice.paid', $invoice, null, 'evt_same');

        $this->assertCount(2, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
        $this->assertSame(2, count(array_keys($this->emails->keysFor((string) $order->number), Emails::KEY_RECEIPT, true)));
    }

    public function testRenewalsAreGiftAidedLikeTheFirstPayment(): void
    {
        [$order, $subId] = $this->started();
        $this->deliver('invoice.paid', $this->stripe->renew($subId));

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertSame(1000, $payments[1]->giftAidAmount);
    }

    // Trouble ----------------------------------------------------------------

    public function testAFailedRenewalMarksItPastDueAndTellsTheDonor(): void
    {
        [$order, $subId] = $this->started();
        $invoice = $this->stripe->renew($subId, false);
        $this->deliver('invoice.payment_failed', $invoice);

        $sub = Plugin::getInstance()->subscriptions->getSubscriptionByStripeId($subId);
        $this->assertNotNull($sub->lastFailureAt);

        $this->assertContains(Emails::KEY_SUBSCRIPTION_PAYMENT_FAILED, $this->emails->keysFor((string) $order->number));
        $context = $this->emails->contexts[array_key_last($this->emails->contexts)];
        $this->assertSame(1000, $context['amount']);
        $this->assertSame('month', $context['interval']);
        $this->assertSame($invoice['hosted_invoice_url'], $context['invoiceUrl']);
    }

    public function testPastDueComesFromTheSubscriptionEvent(): void
    {
        [, $subId] = $this->started();
        $this->stripe->renew($subId, false);
        $this->deliver('customer.subscription.updated', $this->stripe->subscriptions[$subId]);

        $this->assertSame(Subscription::STATUS_PAST_DUE, $this->local($subId)->status);
    }

    public function testActionRequiredAsksTheDonorToConfirm(): void
    {
        [$order, $subId] = $this->started();
        $this->deliver('invoice.payment_action_required', $this->stripe->renew($subId, false));

        $this->assertContains(Emails::KEY_SUBSCRIPTION_ACTION_REQUIRED, $this->emails->keysFor((string) $order->number));
    }

    public function testAVoidedInvoiceFailsItsPayment(): void
    {
        [$order, $subId] = $this->started();
        $invoice = $this->stripe->renew($subId, false);
        $this->deliver('invoice.paid', $invoice, null, 'evt_attempt');
        PaymentRecord::updateAll(['status' => Payment::STATUS_PENDING], ['stripeInvoiceId' => $invoice['id']]);
        $this->deliver('invoice.voided', $invoice);

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertSame(Payment::STATUS_FAILED, end($payments)->status);
    }

    // Ending -----------------------------------------------------------------

    public function testCancellationIsAnnouncedOnceToTheDonorAndOnceToTheTeam(): void
    {
        [$order, $subId] = $this->started();
        $this->stripe->cancelSubscription($subId);
        $this->deliver('customer.subscription.deleted', $this->stripe->subscriptions[$subId]);
        $this->deliver('customer.subscription.deleted', $this->stripe->subscriptions[$subId]);

        $this->assertSame(Subscription::STATUS_CANCELED, $this->local($subId)->status);
        $keys = $this->emails->keysFor((string) $order->number);
        $this->assertSame(1, count(array_keys($keys, Emails::KEY_SUBSCRIPTION_CANCELLED, true)));
        $this->assertSame(1, count(array_keys($keys, Emails::KEY_ADMIN_SUBSCRIPTION_CANCELLED, true)));
    }

    public function testACancellationMadeByStaffIsAnnouncedToo(): void
    {
        [$order, $subId] = $this->started();
        Plugin::getInstance()->subscriptions->cancel($this->local($subId), false);
        // Stripe's own event for the same change arrives afterwards.
        $this->deliver('customer.subscription.deleted', $this->stripe->subscriptions[$subId]);

        $keys = $this->emails->keysFor((string) $order->number);
        $this->assertSame(1, count(array_keys($keys, Emails::KEY_SUBSCRIPTION_CANCELLED, true)));
        $this->assertSame(1, count(array_keys($keys, Emails::KEY_ADMIN_SUBSCRIPTION_CANCELLED, true)));
    }

    public function testCancellingAtPeriodEndSaysNothingUntilItActuallyEnds(): void
    {
        [$order, $subId] = $this->started();
        Plugin::getInstance()->subscriptions->cancel($this->local($subId), true);

        $this->assertNotContains(Emails::KEY_SUBSCRIPTION_CANCELLED, $this->emails->keysFor((string) $order->number));
    }

    public function testAnOlderEventNeverOverwritesANewerState(): void
    {
        [, $subId] = $this->started();
        $now = time();

        $cancelled = $this->stripe->subscriptions[$subId];
        $cancelled['status'] = 'canceled';
        $this->deliver('customer.subscription.deleted', $cancelled, $now);

        $stale = $this->stripe->subscriptions[$subId];
        $stale['status'] = 'active';
        $this->deliver('customer.subscription.updated', $stale, $now - 60);

        $this->assertSame(Subscription::STATUS_CANCELED, $this->local($subId)->status);
    }

    public function testSubscriptionsThisPluginDidNotStartAreIgnored(): void
    {
        $this->deliver('customer.subscription.created', ['id' => 'sub_foreign', 'object' => 'subscription', 'status' => 'active', 'metadata' => [], 'customer' => 'cus_x', 'items' => ['data' => []]]);

        $this->assertNull(Plugin::getInstance()->subscriptions->getSubscriptionByStripeId('sub_foreign'));
    }

    public function testCancellingAtPeriodEndKeepsItLiveUntilThen(): void
    {
        [, $subId] = $this->started();
        $subs = Plugin::getInstance()->subscriptions;

        $updated = $subs->cancel($this->local($subId), true);

        $this->assertTrue($updated->cancelAtPeriodEnd);
        $this->assertTrue($updated->getIsLive());
        $this->assertSame([[$subId, ['cancel_at_period_end' => true]]], $this->stripe->callsTo('updateSubscription'));
    }

    public function testACancellationScheduledByThePortalCountsAsEndingAtPeriodEnd(): void
    {
        [, $subId] = $this->started();
        $scheduled = $this->stripe->subscriptions[$subId];
        // What Stripe's portal actually sends: a future cancel_at, with cancel_at_period_end still false.
        $scheduled['cancel_at'] = strtotime('+20 days');
        $scheduled['cancel_at_period_end'] = false;
        $scheduled['canceled_at'] = time();
        $this->deliver('customer.subscription.updated', $scheduled);

        $sub = $this->local($subId);
        $this->assertTrue($sub->cancelAtPeriodEnd);
        $this->assertTrue($sub->getIsLive());
    }

    public function testCancellingNowEndsItAtOnce(): void
    {
        [, $subId] = $this->started();
        $updated = Plugin::getInstance()->subscriptions->cancel($this->local($subId), false);

        $this->assertSame(Subscription::STATUS_CANCELED, $updated->status);
        $this->assertFalse($updated->getIsLive());
    }

    public function testAStripeRefusalToCancelSurfacesAsAnError(): void
    {
        [, $subId] = $this->started();
        $stripe = new class() extends \coyshdigital\smallchange\tests\FakeStripe {
            public function cancelSubscription(string $id): \Stripe\StripeObject
            {
                throw new \Stripe\Exception\InvalidRequestException('No such subscription');
            }
        };
        Plugin::getInstance()->set('stripe', $stripe);

        $this->expectException(RefundException::class);
        Plugin::getInstance()->subscriptions->cancel($this->local($subId), false);
    }

    public function testReconcilingCatchesAMissedWebhook(): void
    {
        [, $subId] = $this->started();
        $this->stripe->cancelSubscription($subId);

        $result = Plugin::getInstance()->subscriptions->reconcileAll();

        $this->assertSame(['checked' => 1, 'changed' => 1], $result);
        $this->assertSame(Subscription::STATUS_CANCELED, $this->local($subId)->status);
        $this->assertSame(['checked' => 0, 'changed' => 0], Plugin::getInstance()->subscriptions->reconcileAll(), 'cancelled ones are not asked about again');
    }

    public function testReconcilingLeavesOtherModeSubscriptionsAlone(): void
    {
        $this->started();
        $asked = count($this->stripe->callsTo('retrieveSubscription'));

        // A live key would only be told "no such subscription" about a test one.
        $this->stripe->fakeMode = \coyshdigital\smallchange\tests\FakeStripe::MODE_LIVE;

        $this->assertSame(['checked' => 0, 'changed' => 0], Plugin::getInstance()->subscriptions->reconcileAll());
        $this->assertCount($asked, $this->stripe->callsTo('retrieveSubscription'), 'Stripe was not asked');
    }

    // Customers --------------------------------------------------------------

    public function testACustomerChangeAtStripeIsCopiedAcross(): void
    {
        [$order, $subId] = $this->started();
        $stripeCustomerId = $this->stripe->subscriptions[$subId]['customer'];

        $this->deliver('customer.updated', ['id' => $stripeCustomerId, 'object' => 'customer', 'email' => 'New@Example.test', 'name' => 'Ada L']);

        $record = CustomerRecord::findOne(['stripeCustomerId' => $stripeCustomerId]);
        $this->assertSame('new@example.test', $record->email);
        $this->assertSame('Ada L', $record->name);
        $this->assertSame('monthly@example.test', Order::find()->id($order->id)->one()->email, 'the order keeps what the donor gave at the time');
    }

    public function testADeletedStripeCustomerIsForgotten(): void
    {
        [, $subId] = $this->started();
        $stripeCustomerId = $this->stripe->subscriptions[$subId]['customer'];

        $this->deliver('customer.deleted', ['id' => $stripeCustomerId, 'object' => 'customer']);

        $this->assertNull(CustomerRecord::findOne(['email' => 'monthly@example.test'])->stripeCustomerId);
    }

    // Webhook endpoint and replay --------------------------------------------

    public function testRegisteringAnEndpointListsEveryHandledEventAndRemembersIt(): void
    {
        $result = Plugin::getInstance()->webhooks->registerEndpoint('https://example.test/actions/small-change/webhooks/stripe');

        $this->assertSame('whsec_created', $result['secret']);
        [$params] = $this->stripe->callsTo('createWebhookEndpoint')[0];
        $this->assertSame(Webhooks::ENABLED_EVENTS, $params['enabled_events']);
        $this->assertSame('2026-09-30.endive', $params['api_version'], 'event payloads must match the version the handlers expect');
        $this->assertSame($result['id'], Plugin::getInstance()->state->get(Webhooks::STATE_ENDPOINT_ID, 'test'));
    }

    public function testRegisteringAgainKeepsTheEndpointThatIsAlreadyThere(): void
    {
        $webhooks = Plugin::getInstance()->webhooks;
        $url = 'https://example.test/actions/small-change/webhooks/stripe';
        $first = $webhooks->registerEndpoint($url);

        try {
            $webhooks->registerEndpoint($url);
            $this->fail('a second endpoint would sign deliveries with a secret this site does not hold');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($first['id'], $e->getMessage());
        }

        $this->assertCount(1, $this->stripe->callsTo('createWebhookEndpoint'));

        // Deleted in the Dashboard since: a new one is wanted.
        $this->stripe->deleteWebhookEndpoint($first['id']);
        $this->assertNotSame($first['id'], $webhooks->registerEndpoint($url)['id']);
    }

    public function testTheEndpointAddressIsTheSitesNotTheControlPanels(): void
    {
        $this->assertSame('https://example.test/actions/small-change/webhooks/stripe', Plugin::getInstance()->webhooks->endpointUrl('https://example.test/'));
        $this->assertStringNotContainsString('admin', Plugin::getInstance()->webhooks->endpointUrl());
    }

    public function testEveryEventTheEndpointListensForHasAHandler(): void
    {
        // An event that is enabled but unhandled would be stored as "ignored" forever.
        $handled = file_get_contents(dirname(__DIR__, 2) . '/src/services/Webhooks.php');

        foreach (Webhooks::ENABLED_EVENTS as $type) {
            $this->assertGreaterThanOrEqual(2, substr_count($handled, "'" . $type . "'"), $type . ' must be both subscribed to and dispatched');
        }
    }

    public function testReplayProcessesOnlyWhatWasMissed(): void
    {
        [, $subId] = $this->started();
        $seen = Event::constructFrom(['id' => 'evt_seen', 'object' => 'event', 'created' => time(), 'livemode' => false, 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripe->subscriptions[$subId]]]);
        $this->deliver('customer.subscription.updated', $this->stripe->subscriptions[$subId], null, 'evt_seen');

        $this->stripe->cancelSubscription($subId);
        $missed = Event::constructFrom(['id' => 'evt_missed', 'object' => 'event', 'created' => time(), 'livemode' => false, 'type' => 'customer.subscription.deleted', 'data' => ['object' => $this->stripe->subscriptions[$subId]]]);
        $ignored = Event::constructFrom(['id' => 'evt_other', 'object' => 'event', 'created' => time(), 'livemode' => false, 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]]);
        $this->stripe->events = [$seen, $missed, $ignored];

        $result = Plugin::getInstance()->webhooks->replay(time() - 3600);

        $this->assertSame(['found' => 2, 'replayed' => 1, 'failed' => 0], $result);
        $this->assertSame(Subscription::STATUS_CANCELED, $this->local($subId)->status);
        $this->assertSame('processed', WebhookEventRecord::findOne(['stripeEventId' => 'evt_missed'])->status);
    }

    public function testReplayCanBeLimitedToOneType(): void
    {
        $this->stripe->events = [
            Event::constructFrom(['id' => 'evt_a', 'object' => 'event', 'created' => time(), 'livemode' => false, 'type' => 'customer.deleted', 'data' => ['object' => ['id' => 'cus_a']]]),
            Event::constructFrom(['id' => 'evt_b', 'object' => 'event', 'created' => time(), 'livemode' => false, 'type' => 'customer.updated', 'data' => ['object' => ['id' => 'cus_b']]]),
        ];

        $result = Plugin::getInstance()->webhooks->replay(time() - 60, 'customer.deleted');

        $this->assertSame(1, $result['found']);
        $this->assertNull(WebhookEventRecord::findOne(['stripeEventId' => 'evt_b']));
    }

    // Stripe products --------------------------------------------------------

    public function testSyncCreatesMissingProductsAndRenamesExistingOnes(): void
    {
        $product = $this->recurringProduct();
        $service = Plugin::getInstance()->stripeProducts;

        $this->assertSame(['created' => 1, 'updated' => 0, 'failed' => 0], $service->syncAll('test'));

        $product->title = 'Building fund';
        Craft::$app->getElements()->saveElement($product);

        $this->assertSame(['created' => 0, 'updated' => 1, 'failed' => 0], $service->syncAll('test'));
        $this->assertSame('Building fund', $this->stripe->callsTo('updateProduct')[0][1]['name']);
    }
}
