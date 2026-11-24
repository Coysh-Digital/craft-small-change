<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\SubscriptionEvent;
use coyshdigital\smallchange\exceptions\RefundException;
use coyshdigital\smallchange\models\Subscription;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\SubscriptionRecord;
use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use yii\base\Exception;
use yii\db\IntegrityException;

/**
 * Local copies of Stripe subscriptions. Every change here comes from Stripe, either as a webhook or by
 * asking it, and webhooks can arrive out of order, so an older event never overwrites a newer state.
 */
class Subscriptions extends Component
{
    public const EVENT_AFTER_STATUS_CHANGE = 'afterStatusChange';

    public function getSubscriptionById(int $id): ?Subscription
    {
        $record = SubscriptionRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    public function getSubscriptionByStripeId(string $stripeId): ?Subscription
    {
        $record = SubscriptionRecord::findOne(['stripeSubscriptionId' => $stripeId]);

        return $record ? $this->toModel($record) : null;
    }

    public function getSubscriptionByOrderId(int $orderId): ?Subscription
    {
        $record = SubscriptionRecord::findOne(['orderId' => $orderId]);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * @return Subscription[] newest first
     */
    public function getSubscriptions(?string $status = null, int $limit = 200): array
    {
        $query = SubscriptionRecord::find()->orderBy(['id' => SORT_DESC])->limit($limit);

        if ($status !== null && $status !== '') {
            $query->where(['status' => $status]);
        }

        /** @var SubscriptionRecord[] $records */
        $records = $query->all();

        return array_map(fn(SubscriptionRecord $record): Subscription => $this->toModel($record), $records);
    }

    /**
     * @return Subscription[]
     */
    public function getSubscriptionsByCustomerId(int $customerId): array
    {
        /** @var SubscriptionRecord[] $records */
        $records = SubscriptionRecord::find()->where(['customerId' => $customerId])->orderBy(['id' => SORT_DESC])->all();

        return array_map(fn(SubscriptionRecord $record): Subscription => $this->toModel($record), $records);
    }

    /**
     * Brings the local copy in line with a Stripe subscription, creating it if need be.
     *
     * @param int|null $eventCreated the event's timestamp when this comes from a webhook; anything older
     *                               than what we already know is ignored. Null means "asked Stripe just now".
     */
    public function syncFromStripe(StripeObject $stripeSub, ?int $eventCreated = null, ?Order $order = null): ?Subscription
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        if ($mode === null) {
            return null;
        }

        $record = SubscriptionRecord::findOne(['stripeSubscriptionId' => $stripeSub->id]);

        if ($record && $eventCreated !== null && $record->lastStripeEventAt !== null && $eventCreated < (int) strtotime($record->lastStripeEventAt . ' UTC')) {
            return $this->toModel($record);
        }

        $oldStatus = $record?->status;
        $record ??= new SubscriptionRecord();
        $item = $stripeSub->items->data[0] ?? null;
        $price = $item->price ?? null;
        $metadata = $stripeSub->metadata ?? null;

        $number = $metadata->smallChangeOrderNumber ?? null;
        $order ??= is_string($number) ? Order::find()->number($number)->one() : null;
        $stripeCustomerId = $stripeSub->customer instanceof StripeObject ? $stripeSub->customer->id : $stripeSub->customer;
        $customer = is_string($stripeCustomerId) ? $plugin->customers->getByStripeCustomerId($stripeCustomerId, $mode) : null;

        $record->stripeMode = $mode;
        $record->stripeSubscriptionId = (string) $stripeSub->id;
        if ($order !== null && $order->id) {
            $record->orderId = (int) $order->id;
        }

        $record->customerId = $customer ? $customer->id : ($order !== null && $order->customerId ? $order->customerId : $record->customerId);
        $productId = $metadata->smallChangeProductId ?? null;
        $record->productId = is_string($productId) && ctype_digit($productId) ? (int) $productId : $record->productId;
        $record->status = (string) $stripeSub->status;
        $record->interval = (string) ($price->recurring->interval ?? $record->interval ?? Subscription::INTERVAL_MONTH);
        $record->amount = (int) (($price->unit_amount ?? 0) * ($item->quantity ?? 1));
        $record->currency = strtoupper((string) ($price->currency ?? $stripeSub->currency ?? 'gbp'));
        // Stripe's Basil API version and later put the period on the item; older versions had it on the subscription.
        $periodEnd = $item->current_period_end ?? $stripeSub->current_period_end ?? null;
        $record->currentPeriodEnd = $periodEnd ? Db::prepareDateForDb((new DateTime())->setTimestamp((int) $periodEnd)) : null;
        // The customer portal schedules a cancellation with `cancel_at` and leaves `cancel_at_period_end` false.
        $cancelAt = $stripeSub->cancel_at ?? null;
        $record->cancelAtPeriodEnd = (bool) ($stripeSub->cancel_at_period_end ?? false) || ($cancelAt !== null && (int) $cancelAt > time());
        $record->canceledAt = ($stripeSub->canceled_at ?? null) ? Db::prepareDateForDb((new DateTime())->setTimestamp((int) $stripeSub->canceled_at)) : null;
        $record->endedAt = ($stripeSub->ended_at ?? null) ? Db::prepareDateForDb((new DateTime())->setTimestamp((int) $stripeSub->ended_at)) : null;
        $record->giftAidDeclared = ($metadata->smallChangeGiftAid ?? null) === 'yes';
        // Set when the plan was started and never changed, so a later sync without the metadata leaves it alone.
        $payments = $metadata->smallChangePayments ?? null;
        $record->maxPayments = is_string($payments) && ctype_digit($payments) && (int) $payments > 0 ? (int) $payments : $record->maxPayments;
        // Asking Stripe directly reflects now, so any webhook created before this moment is stale.
        $record->lastStripeEventAt = Db::prepareDateForDb((new DateTime())->setTimestamp($eventCreated ?? time()));

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->save(false);
            $transaction->commit();
        } catch (IntegrityException) {
            $transaction->rollBack();
            // A concurrent delivery recorded it first; carry on with theirs.
            $existing = SubscriptionRecord::findOne(['stripeSubscriptionId' => $stripeSub->id]);

            if (!$existing) {
                throw new \RuntimeException('Could not record subscription ' . $stripeSub->id);
            }

            $record = $existing;
        }

        $subscription = $this->toModel($record);

        if ($oldStatus !== $record->status) {
            $this->trigger(self::EVENT_AFTER_STATUS_CHANGE, new SubscriptionEvent([
                'subscription' => $subscription,
                'oldStatus' => $oldStatus,
                'newStatus' => $record->status,
            ]));
        }

        if ($oldStatus !== null && $oldStatus !== Subscription::STATUS_CANCELED && $record->status === Subscription::STATUS_CANCELED) {
            $this->announceCancellation($subscription);
        }

        return $subscription;
    }

    /**
     * Whether the plan has taken all the payments it was sold with. A subscription with no set number never completes.
     */
    public function getIsPlanComplete(Subscription $subscription): bool
    {
        return $subscription->id !== null
            && $subscription->maxPayments !== null
            && Plugin::getInstance()->payments->countSucceededBySubscription($subscription->id) >= $subscription->maxPayments;
    }

    /**
     * Once a plan has taken its last payment, tells Stripe to stop at the end of the period just paid for and
     * lets the customer know. Safe to call after every payment: only the call that schedules the end sends the email.
     *
     * @throws ApiErrorException when Stripe can't be told, so a webhook is retried rather than left to charge again
     * @throws Exception when the subscription can't be locked
     */
    public function endWhenComplete(Subscription $subscription, Order $order): ?Subscription
    {
        if ($subscription->id === null || $subscription->maxPayments === null) {
            return null;
        }

        $mutex = Craft::$app->getMutex();
        $lock = 'smallchange:subscription:' . $subscription->id;

        if (!$mutex->acquire($lock, 15)) {
            throw new Exception('Could not lock subscription ' . $subscription->id . ' to end it.');
        }

        try {
            // Reloaded under the lock: another delivery of the same payment may have ended it while this one waited.
            $current = $this->getSubscriptionById($subscription->id);

            if (!$current || !$current->getIsLive() || $current->cancelAtPeriodEnd || !$this->getIsPlanComplete($current)) {
                return null;
            }

            $stripeSub = Plugin::getInstance()->stripe->updateSubscription($current->stripeSubscriptionId, ['cancel_at_period_end' => true]);
            $ended = $this->syncFromStripe($stripeSub) ?? $current;

            Plugin::getInstance()->emails->queue(Emails::KEY_SUBSCRIPTION_COMPLETE, $order, [
                'amount' => $current->amount,
                'interval' => $current->interval,
                'payments' => (int) $current->maxPayments,
            ]);

            return $ended;
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Tells the donor and the team it has ended. Done here, on the change itself, so it happens once
     * whether the news came from a webhook, a check with Stripe or a staff member's own cancellation.
     */
    private function announceCancellation(Subscription $subscription): void
    {
        $order = $subscription->orderId ? Order::find()->id($subscription->orderId)->one() : null;

        // A plan that took its last payment has already had its own email; it isn't a cancellation.
        if (!$order || $this->getIsPlanComplete($subscription)) {
            return;
        }

        $emails = Plugin::getInstance()->emails;
        $context = ['amount' => $subscription->amount, 'interval' => $subscription->interval];
        $emails->queue(Emails::KEY_SUBSCRIPTION_CANCELLED, $order, $context);
        $emails->queue(Emails::KEY_ADMIN_SUBSCRIPTION_CANCELLED, $order, $context);
    }

    /**
     * Asks Stripe for the subscription's current state and records it.
     */
    public function reconcile(Subscription $subscription): ?Subscription
    {
        try {
            $stripeSub = Plugin::getInstance()->stripe->retrieveSubscription($subscription->stripeSubscriptionId);
        } catch (ApiErrorException $e) {
            Craft::warning('Could not reconcile subscription ' . $subscription->stripeSubscriptionId . ': ' . $e->getMessage(), __METHOD__);

            return null;
        }

        return $this->syncFromStripe($stripeSub);
    }

    /**
     * Asks Stripe about every subscription that could still charge someone, so a missed webhook
     * cannot leave the list wrong for long.
     *
     * @return array{checked: int, changed: int}
     */
    public function reconcileAll(): array
    {
        $result = ['checked' => 0, 'changed' => 0];
        $mode = Plugin::getInstance()->stripe->mode();

        foreach ($this->getSubscriptions(null, 5000) as $subscription) {
            // A cancelled subscription can't come back, so it is not worth a request. One from the other mode
            // belongs to another set of keys, which would only answer "no such subscription".
            if (!$subscription->getIsLive() || $subscription->stripeMode !== $mode) {
                continue;
            }

            $result['checked']++;
            $updated = $this->reconcile($subscription);

            if ($updated !== null && ($updated->status !== $subscription->status || $updated->cancelAtPeriodEnd !== $subscription->cancelAtPeriodEnd)) {
                $result['changed']++;
            }
        }

        return $result;
    }

    /**
     * Stops a recurring donation, now or when the period already paid for ends.
     *
     * @throws RefundException when Stripe refuses
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd): Subscription
    {
        $stripe = Plugin::getInstance()->stripe;

        try {
            $stripeSub = $atPeriodEnd
                ? $stripe->updateSubscription($subscription->stripeSubscriptionId, ['cancel_at_period_end' => true])
                : $stripe->cancelSubscription($subscription->stripeSubscriptionId);
        } catch (ApiErrorException $e) {
            throw new RefundException(Craft::t('small-change', 'Stripe could not cancel the recurring donation: {reason}', ['reason' => $e->getMessage()]), 0, $e);
        }

        return $this->syncFromStripe($stripeSub) ?? $subscription;
    }

    /**
     * Notes that a payment failed, so the control panel can show who needs a nudge.
     */
    public function markPaymentFailed(Subscription $subscription): void
    {
        if ($subscription->id) {
            Db::update(SubscriptionRecord::tableName(), ['lastFailureAt' => Db::prepareDateForDb(new DateTime())], ['id' => $subscription->id]);
        }
    }

    private function toModel(SubscriptionRecord $record): Subscription
    {
        return new Subscription($record->getAttributes(['id', 'orderId', 'customerId', 'productId', 'stripeMode', 'stripeSubscriptionId', 'status', 'interval', 'amount', 'currency', 'currentPeriodEnd', 'cancelAtPeriodEnd', 'canceledAt', 'endedAt', 'giftAidDeclared', 'maxPayments', 'lastStripeEventAt', 'lastFailureAt', 'dateCreated']));
    }
}
