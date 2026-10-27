<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\RefundEvent;
use coyshdigital\smallchange\exceptions\RefundException;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Refund;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\RefundRecord;
use coyshdigital\smallchange\services\Payments;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use yii\base\Event;

final class RefundsTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
    }

    /**
     * A paid £25 donation and its payment.
     *
     * @return array{Order, Payment}
     */
    private function paid(string $amount = '25'): array
    {
        $order = $this->makePendingOrder($amount, true);
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);
        $order = Order::find()->id($order->id)->one();
        $payment = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0];

        return [$order, $payment];
    }

    private function reloadOrder(Order $order): Order
    {
        return Order::find()->id($order->id)->one();
    }

    // Making refunds ---------------------------------------------------------

    public function testAPartialRefundIsRecordedAndShownOnTheOrder(): void
    {
        [$order, $payment] = $this->paid();

        $refund = Plugin::getInstance()->payments->refund($payment, 1000, 'requested_by_customer', 'Donor asked', null);

        $this->assertSame(1000, $refund->amount);
        $this->assertSame(Refund::STATUS_SUCCEEDED, $refund->status);
        $this->assertSame('Donor asked', $refund->note);
        $this->assertNull($refund->userId, 'a refund made by code has no staff member');
        $this->assertNotNull($refund->dateCreated);

        $saved = $this->reloadOrder($order);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $saved->paymentStatus);
        $this->assertSame(1000, $saved->totalRefunded);
        $this->assertSame(2500, $saved->totalPaid);
        $this->assertSame(1000, Plugin::getInstance()->payments->getPaymentById((int) $payment->id)->amountRefunded);
    }

    public function testTheRefundIsSentToStripeAgainstThePaymentIntent(): void
    {
        [, $payment] = $this->paid();
        Plugin::getInstance()->payments->refund($payment, 500, 'duplicate', null, null);

        [$params, $key] = $this->stripe->callsTo('createRefund')[0];

        $this->assertSame($payment->stripePaymentIntentId, $params['payment_intent']);
        $this->assertSame(500, $params['amount']);
        $this->assertSame('duplicate', $params['reason']);
        $this->assertSame(['smallChangePaymentId' => (string) $payment->id], $params['metadata']);
        $this->assertSame('refund-' . $payment->id . '-0-500', $key);
    }

    public function testRefundingTheLotMarksTheOrderRefunded(): void
    {
        [$order, $payment] = $this->paid();
        Plugin::getInstance()->payments->refund($payment, 2500, null, null, null);

        $saved = $this->reloadOrder($order);
        $this->assertSame(Order::PAYMENT_REFUNDED, $saved->paymentStatus);
        $this->assertSame('refunded', $saved->getStatus());
        $this->assertSame(0, Plugin::getInstance()->payments->refundableAmount(Plugin::getInstance()->payments->getPaymentById((int) $payment->id)));
    }

    public function testSeveralRefundsAddUpAndCannotExceedThePayment(): void
    {
        [$order, $payment] = $this->paid();
        $payments = Plugin::getInstance()->payments;

        $payments->refund($payment, 1000, null, null, null);
        $payments->refund($payments->getPaymentById((int) $payment->id), 1000, null, null, null);
        $this->assertSame(2000, $this->reloadOrder($order)->totalRefunded);

        // The order screen lists them from the order alone, oldest first, without a query per payment.
        $this->assertSame(
            array_map(static fn(Refund $refund): int => (int) $refund->id, $payments->getRefundsByPaymentId((int) $payment->id)),
            array_map(static fn(Refund $refund): int => (int) $refund->id, $payments->getRefundsByOrderId((int) $order->id)),
        );
        $this->assertSame([], $payments->getRefundsByOrderId((int) $order->id + 1000), 'another order has none of them');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessageMatches('/between £0.01 and £5/');
        $payments->refund($payments->getPaymentById((int) $payment->id), 600, null, null, null);
    }

    /**
     * @dataProvider impossibleAmounts
     */
    public function testImpossibleAmountsAreRefusedBeforeStripeIsAsked(int $amount): void
    {
        [, $payment] = $this->paid();

        try {
            Plugin::getInstance()->payments->refund($payment, $amount, null, null, null);
            $this->fail('expected a RefundException');
        } catch (RefundException) {
            $this->assertSame([], $this->stripe->callsTo('createRefund'));
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function impossibleAmounts(): array
    {
        return ['zero' => [0], 'negative' => [-100], 'more than was paid' => [2501]];
    }

    public function testAReasonStripeDoesNotKnowIsRefused(): void
    {
        [, $payment] = $this->paid();

        $this->expectException(RefundException::class);
        Plugin::getInstance()->payments->refund($payment, 100, 'because', null, null);
    }

    public function testAPaymentThatNeverSucceededCannotBeRefunded(): void
    {
        $order = $this->makePendingOrder();
        $payment = new Payment(['id' => 99, 'orderId' => (int) $order->id, 'status' => Payment::STATUS_PENDING, 'amount' => 2500, 'currency' => 'GBP', 'stripePaymentIntentId' => 'pi_x']);

        $this->assertSame(0, Plugin::getInstance()->payments->refundableAmount($payment));
    }

    public function testAPendingRefundCountsAgainstWhatCanStillBeRefunded(): void
    {
        [, $payment] = $this->paid();
        $this->stripe->nextRefundStatus = 'pending';
        $payments = Plugin::getInstance()->payments;

        $payments->refund($payment, 2000, null, null, null);

        $this->assertSame(500, $payments->refundableAmount($payments->getPaymentById((int) $payment->id)));
    }

    public function testAStripeRefusalLeavesNothingBehind(): void
    {
        [$order, $payment] = $this->paid();
        $this->stripe->failRefund = true;

        try {
            Plugin::getInstance()->payments->refund($payment, 500, null, null, null);
            $this->fail('expected a RefundException');
        } catch (RefundException $e) {
            $this->assertStringContainsString('Stripe could not make the refund', $e->getMessage());
        }

        $this->assertSame(0, (int) RefundRecord::find()->count());
        $this->assertSame(Order::PAYMENT_PAID, $this->reloadOrder($order)->paymentStatus);
    }

    public function testTheAfterRefundEventFiresOncePerChange(): void
    {
        $events = [];
        Event::on(Payments::class, Payments::EVENT_AFTER_REFUND, static function(RefundEvent $e) use (&$events): void {
            $events[] = [$e->refund->status, $e->refund->amount, $e->order?->number];
        });

        [$order, $payment] = $this->paid();
        $this->stripe->nextRefundStatus = 'pending';
        $refund = Plugin::getInstance()->payments->refund($payment, 1000, null, null, null);

        // The same refund arriving again, then succeeding.
        $first = \Stripe\StripeObject::constructFrom($this->stripe->refunds[$refund->stripeRefundId]);
        Plugin::getInstance()->payments->recordRefund($first, 'test');
        $this->stripe->refunds[$refund->stripeRefundId]['status'] = 'succeeded';
        Plugin::getInstance()->payments->recordRefund(\Stripe\StripeObject::constructFrom($this->stripe->refunds[$refund->stripeRefundId]), 'test');
        Plugin::getInstance()->payments->recordRefund(\Stripe\StripeObject::constructFrom($this->stripe->refunds[$refund->stripeRefundId]), 'test');
        Event::off(Payments::class, Payments::EVENT_AFTER_REFUND);

        $this->assertSame([['pending', 1000, $order->number], ['succeeded', 1000, $order->number]], $events);
    }

    // Refunds that arrive by webhook ----------------------------------------

    public function testARefundMadeInTheStripeDashboardAppearsHere(): void
    {
        [$order, $payment] = $this->paid();
        $dashboardRefund = \Stripe\StripeObject::constructFrom([
            'id' => 're_dashboard_1', 'object' => 'refund', 'amount' => 700, 'status' => 'succeeded', 'reason' => 'requested_by_customer',
            'payment_intent' => $payment->stripePaymentIntentId, 'failure_reason' => null,
        ]);

        $refund = Plugin::getInstance()->payments->recordRefund($dashboardRefund, 'test');

        $this->assertSame(700, $refund?->amount);
        $this->assertNull($refund?->userId, 'nobody here made it');
        $this->assertSame(700, $this->reloadOrder($order)->totalRefunded);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $this->reloadOrder($order)->paymentStatus);
    }

    public function testRecordingTheSameRefundTwiceStoresOneRow(): void
    {
        [, $payment] = $this->paid();
        $stripeRefund = \Stripe\StripeObject::constructFrom(['id' => 're_dup', 'object' => 'refund', 'amount' => 300, 'status' => 'succeeded', 'payment_intent' => $payment->stripePaymentIntentId]);

        Plugin::getInstance()->payments->recordRefund($stripeRefund, 'test');
        Plugin::getInstance()->payments->recordRefund($stripeRefund, 'test');

        $this->assertSame(1, (int) RefundRecord::find()->where(['stripeRefundId' => 're_dup'])->count());
        $this->assertSame(300, Plugin::getInstance()->payments->getPaymentById((int) $payment->id)->amountRefunded);
    }

    public function testARefundForAnUnknownPaymentIsIgnored(): void
    {
        $stripeRefund = \Stripe\StripeObject::constructFrom(['id' => 're_stranger', 'object' => 'refund', 'amount' => 300, 'status' => 'succeeded', 'payment_intent' => 'pi_not_ours']);

        $this->assertNull(Plugin::getInstance()->payments->recordRefund($stripeRefund, 'test'));
    }

    public function testAFailedRefundGivesTheMoneyBackToTheRefundableTotal(): void
    {
        [$order, $payment] = $this->paid();
        $this->stripe->nextRefundStatus = 'pending';
        $refund = Plugin::getInstance()->payments->refund($payment, 1000, null, null, null);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $this->reloadOrder($order)->paymentStatus);

        $failed = $this->stripe->refunds[$refund->stripeRefundId];
        $failed['status'] = 'failed';
        $failed['failure_reason'] = 'expired_or_canceled_card';
        Plugin::getInstance()->payments->recordRefund(\Stripe\StripeObject::constructFrom($failed), 'test');

        $saved = $this->reloadOrder($order);
        $this->assertSame(Order::PAYMENT_PAID, $saved->paymentStatus, 'back to paid: nothing was refunded');
        $this->assertSame(0, $saved->totalRefunded);
        $this->assertSame(2500, Plugin::getInstance()->payments->refundableAmount(Plugin::getInstance()->payments->getPaymentById((int) $payment->id)));
        $this->assertSame('expired_or_canceled_card', $this->getRefundRow($refund->stripeRefundId)->failureReason);
    }

    public function testTheChargesOwnTotalCatchesRefundsWeNeverHeardAbout(): void
    {
        [$order, $payment] = $this->paid();
        $charge = \Stripe\StripeObject::constructFrom(['id' => $payment->stripeChargeId, 'object' => 'charge', 'payment_intent' => $payment->stripePaymentIntentId, 'amount_refunded' => 2500]);

        Plugin::getInstance()->payments->recordChargeRefunded($charge, 'test');

        $this->assertSame(Order::PAYMENT_REFUNDED, $this->reloadOrder($order)->paymentStatus);
        $this->assertSame(2500, $this->reloadOrder($order)->totalRefunded);
    }

    private function getRefundRow(string $stripeRefundId): RefundRecord
    {
        return RefundRecord::findOne(['stripeRefundId' => $stripeRefundId]);
    }

    // Disputes ---------------------------------------------------------------

    private function dispute(Payment $payment, string $status, string $id = 'dp_1'): \Stripe\StripeObject
    {
        return \Stripe\StripeObject::constructFrom(['id' => $id, 'object' => 'dispute', 'status' => $status, 'amount' => 2500, 'reason' => 'fraudulent', 'payment_intent' => $payment->stripePaymentIntentId, 'charge' => $payment->stripeChargeId]);
    }

    public function testADisputeMarksTheOrderDisputedAndAWonOneRestoresIt(): void
    {
        [$order, $payment] = $this->paid();
        $payments = Plugin::getInstance()->payments;

        $payments->recordDispute($this->dispute($payment, 'needs_response'), 'test');
        $this->assertSame(Order::PAYMENT_DISPUTED, $this->reloadOrder($order)->paymentStatus);
        $this->assertSame('dp_1', $payments->getPaymentById((int) $payment->id)->stripeDisputeId);

        $payments->recordDispute($this->dispute($payment, 'under_review'), 'test');
        $this->assertSame(Order::PAYMENT_DISPUTED, $this->reloadOrder($order)->paymentStatus);

        $payments->recordDispute($this->dispute($payment, 'won'), 'test');
        $this->assertSame(Order::PAYMENT_PAID, $this->reloadOrder($order)->paymentStatus);
        $this->assertSame('won', $payments->getPaymentById((int) $payment->id)->disputeStatus);
    }

    public function testALostDisputeStaysDisputed(): void
    {
        [$order, $payment] = $this->paid();
        Plugin::getInstance()->payments->recordDispute($this->dispute($payment, 'lost'), 'test');

        $this->assertSame(Order::PAYMENT_DISPUTED, $this->reloadOrder($order)->paymentStatus);
    }

    public function testAnInquiryThatClosesIsNotADispute(): void
    {
        [$order, $payment] = $this->paid();
        $payments = Plugin::getInstance()->payments;

        $payments->recordDispute($this->dispute($payment, 'warning_needs_response'), 'test');
        $this->assertSame(Order::PAYMENT_DISPUTED, $this->reloadOrder($order)->paymentStatus);

        $payments->recordDispute($this->dispute($payment, 'warning_closed'), 'test');
        $this->assertSame(Order::PAYMENT_PAID, $this->reloadOrder($order)->paymentStatus);
    }

    public function testAWonDisputeOnARefundedOrderGoesBackToRefunded(): void
    {
        [$order, $payment] = $this->paid();
        $payments = Plugin::getInstance()->payments;
        $payments->refund($payment, 2500, null, null, null);
        $payments->recordDispute($this->dispute($payment, 'needs_response'), 'test');
        $this->assertSame(Order::PAYMENT_DISPUTED, $this->reloadOrder($order)->paymentStatus);

        $payments->recordDispute($this->dispute($payment, 'won'), 'test');

        $this->assertSame(Order::PAYMENT_REFUNDED, $this->reloadOrder($order)->paymentStatus);
    }

    public function testADisputeForAnUnknownPaymentIsIgnored(): void
    {
        $stranger = \Stripe\StripeObject::constructFrom(['id' => 'dp_x', 'status' => 'needs_response', 'payment_intent' => 'pi_nope', 'charge' => 'ch_nope']);

        $this->assertNull(Plugin::getInstance()->payments->recordDispute($stranger, 'test'));
    }

    // Order housekeeping -----------------------------------------------------

    public function testAPendingOrderCanBeCancelledAndTheDiscountReturned(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $discount = $this->makeDiscount('CANCEL', 'fixed', 500, 1);
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        Plugin::getInstance()->carts->applyDiscount($cart, 'CANCEL');
        $this->addDeliveryDetails($cart);
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $session = $cart->stripeCheckoutSessionId;

        Plugin::getInstance()->orders->cancelOrder($this->reloadOrder($cart));

        $saved = $this->reloadOrder($cart);
        $this->assertSame(Order::STATE_CANCELLED, $saved->state);
        $this->assertSame('cancelled', $saved->getStatus());
        $this->assertSame('expired', $this->stripe->sessions[$session]['status']);
        $this->assertSame(0, Plugin::getInstance()->discounts->usesTaken($discount));
    }

    public function testACompletedOrderCannotBeCancelled(): void
    {
        [$order] = $this->paid();
        Plugin::getInstance()->orders->cancelOrder($order);

        $this->assertSame(Order::STATE_COMPLETED, $this->reloadOrder($order)->state);
    }

    public function testResendingAReceiptSendsAnotherCopyWithoutDisturbingTheFirst(): void
    {
        $mailer = new \coyshdigital\smallchange\tests\RecordingMailer(['from' => 'site@example.test']);
        Craft::$app->set('mailer', $mailer);
        Plugin::getInstance()->set('emails', new \coyshdigital\smallchange\services\Emails());
        [$order] = $this->paid();
        $emails = Plugin::getInstance()->emails;

        $emails->send('receipt', $order);
        $firstSentAt = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0]->receiptSentAt;
        $emails->send('receiptResend', $order);
        $emails->send('receipt', $order);

        $this->assertCount(2, $mailer->sent, 'the original, plus the deliberate resend; the repeat of the original is still suppressed');
        $this->assertSame($firstSentAt, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0]->receiptSentAt);
    }
}
