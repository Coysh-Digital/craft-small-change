<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\WebhookEvent;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\WebhookEventRecord;
use coyshdigital\smallchange\services\Webhooks;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use yii\base\Event;

final class WebhooksTest extends Unit
{
    use Fixtures;

    private int $eventCounter = 0;

    protected function _before(): void
    {
        $this->useFakes();
    }

    // Helpers ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $object
     */
    private function payload(string $type, array $object, bool $livemode = false, ?string $id = null): string
    {
        return (string) json_encode([
            'id' => $id ?? 'evt_test_' . ++$this->eventCounter,
            'object' => 'event',
            'api_version' => '2026-09-30.endive',
            'created' => time(),
            'livemode' => $livemode,
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }

    private function sign(string $payload, ?string $secret = null, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret ?? 'whsec_test_secret');
    }

    /**
     * @return array{status: int, message: string}
     */
    private function deliver(string $payload, ?string $header = null): array
    {
        return Plugin::getInstance()->webhooks->handleRequest($payload, $header ?? $this->sign($payload));
    }

    private function statusOf(string $eventId): ?string
    {
        return WebhookEventRecord::findOne(['stripeEventId' => $eventId])?->status;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionObject(Order $order): array
    {
        return $this->stripe->sessions[$order->stripeCheckoutSessionId];
    }

    // Verification -----------------------------------------------------------

    public function testAGoodSignatureIsAcceptedAndStored(): void
    {
        $payload = $this->payload('customer.created', ['id' => 'cus_1', 'object' => 'customer'], false, 'evt_good');
        $result = $this->deliver($payload);

        $this->assertSame(200, $result['status']);
        $record = WebhookEventRecord::findOne(['stripeEventId' => 'evt_good']);
        $this->assertSame('ignored', $record->status, 'unknown types are stored and ignored');
        $this->assertSame('customer.created', $record->type);
        $this->assertSame('test', $record->stripeMode);
        $this->assertSame('cus_1', $record->stripeObjectId);
        $this->assertSame($payload, $record->payload);
    }

    public function testASignedDeliveryMarksTheWebhookAsConnectedForThatMode(): void
    {
        $webhooks = Plugin::getInstance()->webhooks;
        $this->assertFalse($webhooks->hasReceivedDelivery(), 'a secret alone is not a connection');

        // An event for the other kind of key is a delivery too, but not one for the mode in use.
        $this->deliver($this->payload('customer.created', ['id' => 'cus_live'], true));
        $this->assertFalse($webhooks->hasReceivedDelivery());

        $this->deliver($this->payload('customer.created', ['id' => 'cus_test'], false));
        $this->assertTrue($webhooks->hasReceivedDelivery());
    }

    public function testRefusedAndReplayedEventsDoNotCountAsAConnection(): void
    {
        $payload = $this->payload('customer.created', ['id' => 'cus_1']);
        $this->deliver($payload, 'nonsense');
        $this->assertFalse(Plugin::getInstance()->webhooks->hasReceivedDelivery(), 'a bad signature proves nothing');

        $this->stripe->events = [\Stripe\Event::constructFrom(json_decode($this->payload('customer.updated', ['id' => 'cus_9', 'object' => 'customer']), true))];
        Plugin::getInstance()->webhooks->replay(time() - 3600);
        $this->assertFalse(Plugin::getInstance()->webhooks->hasReceivedDelivery(), 'fetching events from Stripe is not Stripe reaching us');
    }

    public function testBadSignaturesAreRefusedAndNothingIsStored(): void
    {
        $payload = $this->payload('customer.created', ['id' => 'cus_1'], false, 'evt_bad');

        foreach ([
            'wrong secret' => $this->sign($payload, 'whsec_other'),
            'tampered body' => $this->sign($payload . ' '),
            'garbage header' => 'nonsense',
            'empty header' => '',
            'stale timestamp' => $this->sign($payload, null, time() - 3600),
        ] as $label => $header) {
            $this->assertSame(403, $this->deliver($payload, $header)['status'], $label);
        }

        $this->assertNull(WebhookEventRecord::findOne(['stripeEventId' => 'evt_bad']));
    }

    public function testNoSigningSecretMeansEveryDeliveryIsRefused(): void
    {
        $this->stripe->fakeWebhookSecret = null;
        $payload = $this->payload('customer.created', ['id' => 'cus_1']);

        $this->assertSame(403, $this->deliver($payload)['status']);
    }

    public function testThinEventsAreRejectedWithAClearMessage(): void
    {
        $payload = (string) json_encode(['id' => 'evt_thin', 'object' => 'v2.core.event', 'type' => 'v1.billing.meter.error_report_triggered', 'livemode' => false]);
        $result = $this->deliver($payload);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Snapshot', $result['message']);
        $this->assertNull(WebhookEventRecord::findOne(['stripeEventId' => 'evt_thin']));
    }

    public function testNonJsonIsABadRequest(): void
    {
        $this->assertSame(400, $this->deliver('not json')['status']);
    }

    public function testAnEventForTheOtherModeIsStoredButIgnored(): void
    {
        $payload = $this->payload('checkout.session.completed', ['id' => 'cs_live_1', 'object' => 'checkout.session'], true, 'evt_live');
        $result = $this->deliver($payload);

        $this->assertSame(200, $result['status']);
        $this->assertSame('ignored', $this->statusOf('evt_live'));
        $this->assertSame('live', WebhookEventRecord::findOne(['stripeEventId' => 'evt_live'])->stripeMode);

        // Nothing about the donor is kept from an event that belongs to another install.
        $stored = json_decode((string) WebhookEventRecord::findOne(['stripeEventId' => 'evt_live'])->payload, true);
        $this->assertSame('evt_live', $stored['id']);
        $this->assertArrayNotHasKey('data', $stored);
    }

    public function testDuplicateDeliveriesAreAcknowledgedAndNotReprocessed(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $payload = $this->payload('checkout.session.completed', $this->sessionObject($order), false, 'evt_dup');

        $this->assertSame('Processed.', $this->deliver($payload)['message']);
        $this->assertSame('Duplicate event.', $this->deliver($payload)['message']);
        $this->assertSame('Duplicate event.', $this->deliver($payload)['message']);

        $this->assertSame(1, (int) WebhookEventRecord::find()->where(['stripeEventId' => 'evt_dup'])->count());
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
        $this->assertSame(['receipt', 'adminNewOrder'], $this->emails->keysFor((string) $order->number));
    }

    public function testADeliveryWaitsOutAnotherOneStillHandlingTheSameEvent(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $payload = $this->payload('checkout.session.completed', $this->sessionObject($order), false, 'evt_overlap');

        // Another request is part-way through this event: Stripe is told to try again rather than the handlers running twice.
        $mutex = Craft::$app->getMutex();
        $this->assertTrue($mutex->acquire('smallchange:event:evt_overlap'));

        try {
            $this->assertSame(503, $this->deliver($payload)['status']);
            $this->assertCount(0, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
        } finally {
            $mutex->release('smallchange:event:evt_overlap');
        }

        $this->assertSame('Processed.', $this->deliver($payload)['message']);
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    public function testAnEventFinishedWhileADeliveryWaitedIsNotRunAgain(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $payload = $this->payload('checkout.session.completed', $this->sessionObject($order), false, 'evt_finished');

        $this->deliver($payload);

        // The second delivery stored the event as received before the first finished; by the time it has the lock the row says otherwise.
        $stale = WebhookEventRecord::findOne(['stripeEventId' => 'evt_finished']);
        $stale->status = Webhooks::STATUS_RECEIVED;

        $event = \Stripe\Webhook::constructEvent($payload, $this->sign($payload), 'whsec_test_secret');
        $result = Plugin::getInstance()->webhooks->process($stale, $event);

        $this->assertSame('Duplicate event.', $result['message']);
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    // Checkout sessions ------------------------------------------------------

    public function testSessionCompletedCompletesTheOrder(): void
    {
        $order = $this->makePendingOrder('25', true);
        $intentId = $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->assertSame(200, $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)))['status']);

        $saved = Order::find()->id($order->id)->one();
        $this->assertSame(Order::STATE_COMPLETED, $saved->state);
        $this->assertSame($intentId, $saved->stripePaymentIntentId);
    }

    public function testWebhookThenReturnTripCompleteExactlyOnce(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)));
        $result = $plugin->checkout->handleReturn($plugin->orders->createAccessToken($order, 'x'), (string) $order->stripeCheckoutSessionId);

        $this->assertSame('paid', $result['outcome']);
        $this->assertCount(1, $plugin->payments->getPaymentsByOrderId((int) $order->id));
        $this->assertSame(['receipt', 'adminNewOrder'], $this->emails->keysFor((string) $order->number));
    }

    public function testReturnTripThenWebhookCompleteExactlyOnce(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        $plugin->checkout->handleReturn($plugin->orders->createAccessToken($order, 'x'), (string) $order->stripeCheckoutSessionId);
        $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)));
        $this->deliver($this->payload('payment_intent.succeeded', $this->stripe->intents[$this->sessionObject($order)['payment_intent']]));

        $this->assertCount(1, $plugin->payments->getPaymentsByOrderId((int) $order->id));
        $this->assertSame(['receipt', 'adminNewOrder'], $this->emails->keysFor((string) $order->number));
    }

    public function testPaymentIntentSucceededBeforeTheSessionEventStillWorks(): void
    {
        $order = $this->makePendingOrder();
        $intentId = $this->stripe->pay((string) $order->stripeCheckoutSessionId);

        // Out of order: the intent event arrives first.
        $this->deliver($this->payload('payment_intent.succeeded', $this->stripe->intents[$intentId]));
        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);

        $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)));
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    public function testSessionCompletedButUnpaidMarksTheOrderProcessing(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->sessions[$order->stripeCheckoutSessionId]['status'] = 'complete';

        $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)));

        $saved = Order::find()->id($order->id)->one();
        $this->assertSame(Order::PAYMENT_PROCESSING, $saved->paymentStatus);
        $this->assertSame(Order::STATE_PENDING, $saved->state);
    }

    public function testAsyncPaymentSucceededCompletesAProcessingOrder(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->sessions[$order->stripeCheckoutSessionId]['status'] = 'complete';
        $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)));

        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $this->deliver($this->payload('checkout.session.async_payment_succeeded', $this->sessionObject($order)));

        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);
    }

    public function testAsyncPaymentFailedMarksFailureAndTellsTheDonor(): void
    {
        $order = $this->makePendingOrder();
        $this->deliver($this->payload('checkout.session.async_payment_failed', $this->sessionObject($order)));

        $this->assertSame(Order::PAYMENT_FAILED, Order::find()->id($order->id)->one()->paymentStatus);
        $this->assertSame(['paymentFailed'], $this->emails->keysFor((string) $order->number));
    }

    public function testSessionExpiredReturnsTheOrderToACart(): void
    {
        $order = $this->makePendingOrder();
        $this->deliver($this->payload('checkout.session.expired', $this->sessionObject($order)));

        $this->assertSame(Order::STATE_CART, Order::find()->id($order->id)->one()->state);
    }

    public function testAnEventForAnUnknownOrderIsIgnoredNotFailed(): void
    {
        $payload = $this->payload('checkout.session.completed', ['id' => 'cs_cli_fixture', 'object' => 'checkout.session', 'client_reference_id' => null, 'metadata' => [], 'payment_status' => 'paid'], false, 'evt_cli');
        $result = $this->deliver($payload);

        $this->assertSame(200, $result['status'], 'Stripe CLI fixtures must not cause retries');
        $this->assertSame('ignored', $this->statusOf('evt_cli'));
    }

    // Payment intents --------------------------------------------------------

    public function testPaymentIntentFailureIsRecordedWithoutChangingTheOrder(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $intent = [
            'id' => 'pi_declined', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'amount' => 2500, 'amount_received' => 0,
            'currency' => 'gbp', 'latest_charge' => 'ch_1', 'metadata' => ['smallChangeOrderNumber' => $order->number],
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
        ];

        $this->deliver($this->payload('payment_intent.payment_failed', $intent));

        $payments = $plugin->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertCount(1, $payments);
        $this->assertSame('failed', $payments[0]->status);
        $this->assertSame('card_declined', $payments[0]->failureCode);
        $this->assertSame('Your card was declined.', $payments[0]->failureMessage);
        $this->assertSame(Order::STATE_PENDING, Order::find()->id($order->id)->one()->state, 'the donor can retry on the same form');
    }

    public function testALateFailureNeverDowngradesASucceededPayment(): void
    {
        $plugin = Plugin::getInstance();
        $order = $this->makePendingOrder();
        $intentId = $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $this->deliver($this->payload('payment_intent.succeeded', $this->stripe->intents[$intentId]));

        $failed = $this->stripe->intents[$intentId];
        $failed['status'] = 'requires_payment_method';
        $this->deliver($this->payload('payment_intent.payment_failed', $failed));

        $this->assertSame('succeeded', $plugin->payments->getPaymentsByOrderId((int) $order->id)[0]->status);
    }

    public function testPaymentIntentProcessingMarksTheOrderProcessing(): void
    {
        $order = $this->makePendingOrder();
        $intent = ['id' => 'pi_slow', 'object' => 'payment_intent', 'status' => 'processing', 'amount' => 2500, 'amount_received' => 0, 'currency' => 'gbp', 'metadata' => ['smallChangeOrderNumber' => $order->number], 'last_payment_error' => null];

        $this->deliver($this->payload('payment_intent.processing', $intent));

        $this->assertSame(Order::PAYMENT_PROCESSING, Order::find()->id($order->id)->one()->paymentStatus);
    }

    // Refunds and disputes ---------------------------------------------------

    /**
     * @return array{Order, \coyshdigital\smallchange\models\Payment}
     */
    private function paidOrder(): array
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        return [Order::find()->id($order->id)->one(), Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0]];
    }

    public function testRefundEventsUpdateThePaymentAndOrder(): void
    {
        [$order, $payment] = $this->paidOrder();
        $refund = ['id' => 're_hook_1', 'object' => 'refund', 'amount' => 1000, 'status' => 'succeeded', 'payment_intent' => $payment->stripePaymentIntentId, 'reason' => null, 'failure_reason' => null];

        $this->assertSame(200, $this->deliver($this->payload('refund.created', $refund))['status']);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, Order::find()->id($order->id)->one()->paymentStatus);

        $this->deliver($this->payload('refund.updated', $refund));
        $this->assertSame(1, (int) \coyshdigital\smallchange\records\RefundRecord::find()->count());
    }

    public function testAFailedRefundTellsTheAdmin(): void
    {
        [$order, $payment] = $this->paidOrder();
        $refund = ['id' => 're_hook_2', 'object' => 'refund', 'amount' => 1000, 'status' => 'failed', 'payment_intent' => $payment->stripePaymentIntentId, 'failure_reason' => 'lost_or_stolen_card'];

        $this->deliver($this->payload('refund.failed', $refund));

        $this->assertContains('adminRefundFailed', $this->emails->keysFor((string) $order->number));
        $this->assertSame(['amount' => 1000, 'reason' => 'lost_or_stolen_card'], end($this->emails->contexts));
        $this->assertSame(Order::PAYMENT_PAID, Order::find()->id($order->id)->one()->paymentStatus);
    }

    public function testChargeRefundedCatchesUpWithRefundsMadeElsewhere(): void
    {
        [$order, $payment] = $this->paidOrder();

        $this->deliver($this->payload('charge.refunded', ['id' => $payment->stripeChargeId, 'object' => 'charge', 'payment_intent' => $payment->stripePaymentIntentId, 'amount_refunded' => 2500]));

        $this->assertSame(Order::PAYMENT_REFUNDED, Order::find()->id($order->id)->one()->paymentStatus);
    }

    public function testDisputeEventsMarkTheOrderAndTellTheAdminOnce(): void
    {
        [$order, $payment] = $this->paidOrder();
        $dispute = ['id' => 'dp_hook', 'object' => 'dispute', 'status' => 'needs_response', 'amount' => 2500, 'reason' => 'fraudulent', 'payment_intent' => $payment->stripePaymentIntentId, 'charge' => $payment->stripeChargeId];

        $this->deliver($this->payload('charge.dispute.created', $dispute));
        $this->assertSame(Order::PAYMENT_DISPUTED, Order::find()->id($order->id)->one()->paymentStatus);

        $this->deliver($this->payload('charge.dispute.updated', ['status' => 'under_review'] + $dispute));
        $this->deliver($this->payload('charge.dispute.closed', ['status' => 'won'] + $dispute));

        $this->assertSame(Order::PAYMENT_PAID, Order::find()->id($order->id)->one()->paymentStatus);
        $this->assertSame(1, count(array_keys($this->emails->keysFor((string) $order->number), 'adminDispute')));
    }

    public function testUnrelatedRefundAndDisputeEventsAreIgnoredNotFailed(): void
    {
        $r = $this->deliver($this->payload('refund.created', ['id' => 're_x', 'object' => 'refund', 'amount' => 1, 'status' => 'succeeded', 'payment_intent' => 'pi_nope'], false, 'evt_r'));
        $d = $this->deliver($this->payload('charge.dispute.created', ['id' => 'dp_x', 'status' => 'needs_response', 'payment_intent' => 'pi_nope', 'charge' => 'ch_nope'], false, 'evt_d'));

        $this->assertSame(200, $r['status']);
        $this->assertSame(200, $d['status']);
        $this->assertSame('ignored', $this->statusOf('evt_r'));
        $this->assertSame('ignored', $this->statusOf('evt_d'));
    }

    // Failure and retry ------------------------------------------------------

    public function testAHandlerErrorAnswers500AndALaterRetrySucceeds(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $payload = $this->payload('checkout.session.completed', $this->sessionObject($order), false, 'evt_retry');

        $explode = true;
        Event::on(Webhooks::class, Webhooks::EVENT_BEFORE_PROCESS_EVENT, static function() use (&$explode): void {
            if ($explode) {
                throw new \RuntimeException('database went away');
            }
        });

        $first = $this->deliver($payload);
        $this->assertSame(500, $first['status'], 'a 5xx makes Stripe retry');
        $record = WebhookEventRecord::findOne(['stripeEventId' => 'evt_retry']);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('database went away', (string) $record->error);
        $this->assertSame(1, $record->attempts);

        $explode = false;
        $second = $this->deliver($payload);
        Event::off(Webhooks::class, Webhooks::EVENT_BEFORE_PROCESS_EVENT);

        $this->assertSame(200, $second['status']);
        $record = WebhookEventRecord::findOne(['stripeEventId' => 'evt_retry']);
        $this->assertSame('processed', $record->status);
        $this->assertSame(2, $record->attempts);
        $this->assertSame(Order::STATE_COMPLETED, Order::find()->id($order->id)->one()->state);
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    public function testListenersSeeEveryProcessedEvent(): void
    {
        $seen = [];
        Event::on(Webhooks::class, Webhooks::EVENT_AFTER_PROCESS_EVENT, static function(WebhookEvent $e) use (&$seen): void {
            $seen[] = [$e->stripeEvent->type, $e->actedOn];
        });

        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        $this->deliver($this->payload('checkout.session.completed', $this->sessionObject($order)));
        $this->deliver($this->payload('customer.created', ['id' => 'cus_1']));
        Event::off(Webhooks::class, Webhooks::EVENT_AFTER_PROCESS_EVENT);

        $this->assertSame([['checkout.session.completed', true], ['customer.created', false]], $seen);
    }
}
