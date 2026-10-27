<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\PaymentEvent;
use coyshdigital\smallchange\events\RefundEvent;
use coyshdigital\smallchange\exceptions\RefundException;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Refund;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\PaymentRecord;
use coyshdigital\smallchange\records\RefundRecord;
use Craft;
use craft\base\Component;
use craft\helpers\Db;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use yii\db\Exception as DbException;

/**
 * Payment rows mirror Stripe PaymentIntents. They are written from state, never from the event
 * that happened to arrive, so replays and duplicate deliveries land on the same row.
 */
class Payments extends Component
{
    public const EVENT_AFTER_PAYMENT_SUCCEEDED = 'afterPaymentSucceeded';
    public const EVENT_AFTER_PAYMENT_FAILED = 'afterPaymentFailed';
    public const EVENT_AFTER_REFUND = 'afterRefund';

    /**
     * Creates or updates the payment for a PaymentIntent. The intent should have `latest_charge` expanded.
     * A succeeded payment is never downgraded by a late or replayed failure event.
     */
    public function recordPaymentIntent(Order $order, StripeObject $intent, string $mode, ?string $status = null, ?string $invoiceId = null, ?int $subscriptionId = null): Payment
    {
        $record = PaymentRecord::findOne(['stripeMode' => $mode, 'stripePaymentIntentId' => $intent->id]);
        $wasSucceeded = $record !== null && $record->status === Payment::STATUS_SUCCEEDED;
        $wasFailed = $record !== null && $record->status === Payment::STATUS_FAILED;

        if (!$record) {
            $record = new PaymentRecord();
            $record->orderId = (int) $order->id;
            $record->stripeMode = $mode;
            $record->stripePaymentIntentId = $intent->id;
            $record->customerId = $order->customerId;
            $record->amountRefunded = 0;
            $record->receiptSentAt = null;
        }

        // The intent alone can't say a payment failed, so the failure event passes it in.
        $status ??= $this->statusFor((string) $intent->status);
        $charge = $intent->latest_charge ?? null;

        $record->amount = (int) ($intent->amount_received ?: $intent->amount);
        $record->currency = strtoupper((string) $intent->currency);
        $status = $wasSucceeded ? Payment::STATUS_SUCCEEDED : $status;
        $record->status = $status;
        $record->stripeChargeId = $charge instanceof StripeObject ? $charge->id : (is_string($charge) ? $charge : $record->stripeChargeId);
        $record->stripeCheckoutSessionId = $order->stripeCheckoutSessionId;
        $record->stripeInvoiceId = $invoiceId ?? $record->stripeInvoiceId;
        $record->subscriptionId = $subscriptionId ?? $record->subscriptionId;
        $record->giftAidDeclared = (bool) $order->giftAidDeclared;
        // Each recurring payment is wholly a donation, so Gift Aid covers all of it; a one-off order covers its donation lines.
        $record->giftAidAmount = $order->giftAidDeclared ? ($order->getIsRecurring() ? $record->amount : $order->giftAidEligibleAmount) : 0;

        $error = $intent->last_payment_error ?? null;
        $record->failureCode = $error ? ($error->code ?? $error->decline_code ?? null) : null;
        $record->failureMessage = $error ? ($error->message ?? null) : null;

        if ($status === Payment::STATUS_SUCCEEDED && !$record->paidAt) {
            $record->paidAt = Db::prepareDateForDb(new \DateTime());
        }

        // Its own (nested) transaction: on PostgreSQL a failed insert poisons whatever transaction it is in.
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->save(false);
            $transaction->commit();
        } catch (DbException $e) {
            $transaction->rollBack();
            // A concurrent delivery inserted the same intent first; reload and carry on.
            $existing = PaymentRecord::findOne(['stripeMode' => $mode, 'stripePaymentIntentId' => $intent->id]);

            if (!$existing) {
                throw $e;
            }

            $record = $existing;
        }

        $payment = $this->toModel($record);

        if ($status === Payment::STATUS_SUCCEEDED && !$wasSucceeded) {
            $this->trigger(self::EVENT_AFTER_PAYMENT_SUCCEEDED, new PaymentEvent(['payment' => $payment, 'order' => $order]));
        } elseif ($status === Payment::STATUS_FAILED && !$wasFailed) {
            $this->trigger(self::EVENT_AFTER_PAYMENT_FAILED, new PaymentEvent(['payment' => $payment, 'order' => $order]));
        }

        return $payment;
    }

    public function getByPaymentIntentId(string $intentId, string $mode): ?Payment
    {
        $record = PaymentRecord::findOne(['stripeMode' => $mode, 'stripePaymentIntentId' => $intentId]);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * @return Payment[]
     */
    public function getPaymentsByOrderId(int $orderId): array
    {
        /** @var PaymentRecord[] $records */
        $records = PaymentRecord::find()->where(['orderId' => $orderId])->orderBy(['id' => SORT_ASC])->all();

        return array_map(fn(PaymentRecord $record): Payment => $this->toModel($record), $records);
    }

    public function markReceiptSent(Payment $payment): void
    {
        Db::update(PaymentRecord::tableName(), ['receiptSentAt' => Db::prepareDateForDb(new \DateTime())], ['id' => $payment->id]);
    }

    private function statusFor(string $intentStatus): string
    {
        return match ($intentStatus) {
            'succeeded' => Payment::STATUS_SUCCEEDED,
            'canceled' => Payment::STATUS_CANCELED,
            // requires_payment_method after an attempt means the attempt failed; before any attempt it is just waiting.
            default => Payment::STATUS_PENDING,
        };
    }

    private function toModel(PaymentRecord $record): Payment
    {
        return new Payment($record->getAttributes(['id', 'orderId', 'subscriptionId', 'customerId', 'stripeMode', 'stripePaymentIntentId', 'stripeChargeId', 'stripeInvoiceId', 'stripeCheckoutSessionId', 'amount', 'amountRefunded', 'currency', 'status', 'failureCode', 'failureMessage', 'giftAidDeclared', 'giftAidAmount', 'stripeDisputeId', 'disputeStatus', 'paidAt', 'receiptSentAt']));
    }

    // Lookups
    // =========================================================================

    public function getPaymentById(int $id): ?Payment
    {
        $record = PaymentRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    public function getPaymentByCharge(string $chargeId, string $mode): ?Payment
    {
        $record = PaymentRecord::findOne(['stripeMode' => $mode, 'stripeChargeId' => $chargeId]);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * @return Refund[] oldest first
     */
    public function getRefundsByPaymentId(int $paymentId): array
    {
        /** @var RefundRecord[] $records */
        $records = RefundRecord::find()->where(['paymentId' => $paymentId])->orderBy(['id' => SORT_ASC])->all();

        return array_map(fn(RefundRecord $record): Refund => $this->refundToModel($record), $records);
    }

    /**
     * Every refund on an order's payments, oldest first, in one query for the order screen.
     *
     * @return Refund[]
     */
    public function getRefundsByOrderId(int $orderId): array
    {
        $paymentIds = PaymentRecord::find()->select('id')->where(['orderId' => $orderId]);

        /** @var RefundRecord[] $records */
        $records = RefundRecord::find()->where(['paymentId' => $paymentIds])->orderBy(['id' => SORT_ASC])->all();

        return array_map(fn(RefundRecord $record): Refund => $this->refundToModel($record), $records);
    }

    /**
     * What can still be given back: the payment, less everything already refunded or on its way.
     */
    public function refundableAmount(Payment $payment): int
    {
        if ($payment->status !== Payment::STATUS_SUCCEEDED) {
            return 0;
        }

        return max(0, $payment->amount - max($payment->amountRefunded, $this->countedRefunds($payment->id ?? 0)));
    }

    // Refunds
    // =========================================================================

    /**
     * Refunds some or all of a payment through Stripe.
     *
     * @throws RefundException when it can't be done
     */
    public function refund(Payment $payment, int $amount, ?string $reason, ?string $note, ?int $userId): Refund
    {
        if ($payment->id === null || !$payment->stripePaymentIntentId) {
            throw new RefundException(Craft::t('small-change', "This payment can't be refunded because it isn't linked to a Stripe payment."));
        }

        if ($reason !== null && $reason !== '' && !in_array($reason, Refund::REASONS, true)) {
            throw new RefundException(Craft::t('small-change', "Stripe doesn't accept that reason."));
        }

        // One refund at a time per payment, so two clicks cannot each pass the "how much is left" check.
        $mutex = Craft::$app->getMutex();
        $lock = 'smallchange:refund:' . $payment->id;

        if (!$mutex->acquire($lock, 10)) {
            throw new RefundException(Craft::t('small-change', 'Another refund for this payment is in progress. Please try again.'));
        }

        try {
            $payment = $this->getPaymentById($payment->id) ?? $payment;
            $refundable = $this->refundableAmount($payment);

            if ($amount < 1 || $amount > $refundable) {
                throw new RefundException(Craft::t('small-change', 'You can refund between {min} and {max} of this payment.', [
                    'min' => Money::format(1, $payment->currency),
                    'max' => Money::format($refundable, $payment->currency),
                ]));
            }

            $plugin = Plugin::getInstance();
            $count = RefundRecord::find()->where(['paymentId' => $payment->id])->count();

            try {
                $stripeRefund = $plugin->stripe->createRefund(array_filter([
                    'payment_intent' => $payment->stripePaymentIntentId,
                    'amount' => $amount,
                    'reason' => $reason ?: null,
                    'metadata' => ['smallChangePaymentId' => (string) $payment->id],
                ], static fn($value): bool => $value !== null), 'refund-' . $payment->id . '-' . $count . '-' . $amount);
            } catch (ApiErrorException $e) {
                Craft::warning('Stripe refused a refund of ' . $amount . ' on payment ' . $payment->id . ': ' . $e->getMessage(), __METHOD__);

                throw new RefundException(Craft::t('small-change', 'Stripe could not make the refund: {reason}', ['reason' => $e->getMessage()]), 0, $e);
            }

            $refund = $this->recordRefund($stripeRefund, $payment->stripeMode, $userId, $note);

            if ($refund === null) {
                throw new RefundException(Craft::t('small-change', "The refund went through, but we couldn't record it. Check the Stripe Dashboard."));
            }

            return $refund;
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Creates or updates a refund row from Stripe's refund, then brings the payment and order into line.
     * Safe to call repeatedly with the same refund, from the CP and from webhooks.
     */
    public function recordRefund(StripeObject $stripeRefund, string $mode, ?int $userId = null, ?string $note = null): ?Refund
    {
        $intentId = $stripeRefund->payment_intent ?? null;
        $intentId = $intentId instanceof StripeObject ? $intentId->id : $intentId;
        $payment = is_string($intentId) ? $this->getByPaymentIntentId($intentId, $mode) : null;

        if (!$payment || $payment->id === null) {
            return null;
        }

        $record = RefundRecord::findOne(['stripeRefundId' => $stripeRefund->id]);
        $previousStatus = $record?->status;

        if (!$record) {
            $record = new RefundRecord();
            $record->paymentId = $payment->id;
            $record->stripeMode = $mode;
            $record->stripeRefundId = $stripeRefund->id;
            $record->userId = $userId;
            $record->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null;
        }

        $record->amount = (int) $stripeRefund->amount;
        $record->status = (string) $stripeRefund->status;
        $record->reason = $stripeRefund->reason ?? $record->reason;
        $record->failureReason = $stripeRefund->failure_reason ?? null;

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->save(false);
            $transaction->commit();
        } catch (DbException $e) {
            $transaction->rollBack();
            // A concurrent delivery created it first.
            $record = RefundRecord::findOne(['stripeRefundId' => $stripeRefund->id]) ?? throw $e;
        }

        $this->syncPayment($payment);
        $refund = $this->refundToModel($record);

        if ($previousStatus !== $record->status) {
            $fresh = $this->getPaymentById($payment->id) ?? $payment;
            $this->trigger(self::EVENT_AFTER_REFUND, new RefundEvent([
                'refund' => $refund,
                'payment' => $fresh,
                'order' => Order::find()->id($fresh->orderId)->one(),
            ]));
        }

        return $refund;
    }

    /**
     * Stripe's charge says how much has been refunded in total, including refunds made outside this site.
     */
    public function recordChargeRefunded(StripeObject $charge, string $mode): ?Payment
    {
        $payment = $this->getPaymentByCharge((string) $charge->id, $mode);

        if (!$payment) {
            $intent = $charge->payment_intent ?? null;
            $payment = is_string($intent) ? $this->getByPaymentIntentId($intent, $mode) : null;
        }

        if (!$payment) {
            return null;
        }

        $this->syncPayment($payment, (int) $charge->amount_refunded);

        return $this->getPaymentById((int) $payment->id);
    }

    /**
     * The PaymentIntent that paid an invoice. The invoice must have its `payments` expanded.
     */
    public function intentIdFromInvoice(StripeObject $invoice): ?string
    {
        $intent = $invoice->payments->data[0]->payment->payment_intent ?? null;
        $intent = $intent instanceof StripeObject ? $intent->id : $intent;

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    public function getPaymentByInvoice(string $invoiceId, string $mode): ?Payment
    {
        $record = PaymentRecord::findOne(['stripeMode' => $mode, 'stripeInvoiceId' => $invoiceId]);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * A voided or uncollectible invoice means that payment will never arrive.
     */
    public function markInvoiceFailed(string $invoiceId, string $mode): ?Payment
    {
        $record = PaymentRecord::findOne(['stripeMode' => $mode, 'stripeInvoiceId' => $invoiceId]);

        if (!$record || $record->status === Payment::STATUS_SUCCEEDED) {
            return $record ? $this->toModel($record) : null;
        }

        $record->status = Payment::STATUS_FAILED;
        $record->save(false);

        return $this->toModel($record);
    }

    /**
     * Recomputes how much of a payment is refunded and passes the result on to its order.
     *
     * @param int|null $stripeAmountRefunded the charge's own total when we have it, which wins if larger
     */
    public function syncPayment(Payment $payment, ?int $stripeAmountRefunded = null): void
    {
        if ($payment->id === null) {
            return;
        }

        $refunded = max($this->countedRefunds($payment->id), $stripeAmountRefunded ?? 0);
        Db::update(PaymentRecord::tableName(), ['amountRefunded' => min($refunded, $payment->amount)], ['id' => $payment->id]);

        $order = Order::find()->id($payment->orderId)->one();

        if ($order) {
            Plugin::getInstance()->orders->syncPaymentStatus($order);
        }
    }

    // Disputes
    // =========================================================================

    /**
     * Dispute statuses in which the donor or their bank is actively contesting the payment.
     */
    public const OPEN_DISPUTE_STATUSES = ['warning_needs_response', 'warning_under_review', 'needs_response', 'under_review', 'lost'];

    public function recordDispute(StripeObject $dispute, string $mode): ?Payment
    {
        $intent = $dispute->payment_intent ?? null;
        $intent = $intent instanceof StripeObject ? $intent->id : $intent;
        $charge = $dispute->charge ?? null;
        $charge = $charge instanceof StripeObject ? $charge->id : $charge;

        $payment = is_string($intent) ? $this->getByPaymentIntentId($intent, $mode) : null;
        $payment ??= is_string($charge) ? $this->getPaymentByCharge($charge, $mode) : null;

        if (!$payment || $payment->id === null) {
            return null;
        }

        Db::update(PaymentRecord::tableName(), [
            'stripeDisputeId' => (string) $dispute->id,
            'disputeStatus' => (string) $dispute->status,
        ], ['id' => $payment->id]);

        $order = Order::find()->id($payment->orderId)->one();

        if ($order) {
            Plugin::getInstance()->orders->syncPaymentStatus($order);
        }

        return $this->getPaymentById($payment->id);
    }

    private function countedRefunds(int $paymentId): int
    {
        return (int) RefundRecord::find()
            ->where(['paymentId' => $paymentId, 'status' => Refund::COUNTING_STATUSES])
            ->sum('[[amount]]');
    }

    private function refundToModel(RefundRecord $record): Refund
    {
        return new Refund($record->getAttributes(['id', 'paymentId', 'stripeMode', 'stripeRefundId', 'amount', 'status', 'reason', 'note', 'userId', 'failureReason', 'dateCreated']));
    }
}
