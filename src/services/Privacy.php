<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\OrderEvent;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\models\Subscription;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\CustomerRecord;
use coyshdigital\smallchange\records\DiscountRecord;
use coyshdigital\smallchange\records\PaymentRecord;
use coyshdigital\smallchange\records\RefundRecord;
use coyshdigital\smallchange\records\StripeProductRecord;
use coyshdigital\smallchange\records\SubscriptionRecord;
use coyshdigital\smallchange\records\WebhookEventRecord;
use Craft;
use craft\base\Component;
use craft\elements\Address;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use Throwable;
use yii\db\Query;

/**
 * Keeping less: purging abandoned baskets and old webhook payloads, and erasing a donor's personal data
 * while keeping what the books need (amounts, dates and Stripe's own ids).
 */
class Privacy extends Component
{
    public const EVENT_AFTER_ANONYMISE_ORDER = 'afterAnonymiseOrder';

    /**
     * How many rows one garbage-collection pass handles, so a backlog can't make a web request slow.
     */
    private const BATCH = 200;

    /**
     * Statuses in which Stripe will never charge the donor again, so the records behind them may be erased.
     */
    private const ENDED_SUBSCRIPTION_STATUSES = [Subscription::STATUS_CANCELED, Subscription::STATUS_INCOMPLETE_EXPIRED];

    /**
     * Everything the housekeeping run does. Called from Craft's garbage collection.
     *
     * @return array{carts: int, events: int, reservations: int, anonymised: int}
     */
    public function runHousekeeping(): array
    {
        return [
            'carts' => $this->purgeInactiveCarts(),
            'events' => $this->purgeWebhookEvents(),
            'reservations' => Plugin::getInstance()->discounts->purgeExpiredReservations(),
            'anonymised' => $this->anonymiseDueOrders(),
        ];
    }

    /**
     * Deletes baskets nobody has touched for the configured number of days, with their addresses.
     * A started checkout is a pending order, not a basket, so it is never touched here.
     */
    public function purgeInactiveCarts(?int $days = null, int $limit = self::BATCH): int
    {
        $days ??= $this->settings()->purgeInactiveCartsDuration;
        $cutoff = Db::prepareDateForDb(new DateTime('-' . max(1, $days) . ' days'));

        $carts = Order::find()
            ->state(Order::STATE_CART)
            ->status(null)
            ->site('*')
            ->unique()
            ->andWhere(['<', 'COALESCE([[smallchange_orders.dateLastActivity]], [[elements.dateCreated]])', $cutoff])
            ->limit($limit)
            ->all();

        $count = 0;

        foreach ($carts as $cart) {
            if (Craft::$app->getElements()->deleteElement($cart, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Webhook payloads hold names and emails, so they are not kept for ever. Their ids stay useful for as long as
     * the retention allows replay and debugging, and that is all.
     */
    public function purgeWebhookEvents(?int $days = null): int
    {
        $days ??= $this->settings()->webhookLogRetention;

        return WebhookEventRecord::deleteAll(['<', 'dateReceived', Db::prepareDateForDb(new DateTime('-' . max(1, $days) . ' days'))]);
    }

    /**
     * Whether anything made with a test key is still stored.
     */
    public function hasTestData(): bool
    {
        return Order::find()->stripeMode(Stripe::MODE_TEST)->status(null)->exists()
            || CustomerRecord::find()->where(['stripeMode' => Stripe::MODE_TEST])->exists()
            || WebhookEventRecord::find()->where(['stripeMode' => Stripe::MODE_TEST])->exists();
    }

    /**
     * The clean-up before going live: removes every order, payment, customer, subscription and stored event made
     * with a test key. It refuses to run unless the site is using a live key, so it can never empty a site that
     * is still being tested.
     *
     * @return array{orders: int, subscriptions: int, customers: int, events: int}
     * @throws \RuntimeException when the site is not using a live key
     */
    public function deleteTestData(): array
    {
        if (Plugin::getInstance()->stripe->mode() !== Stripe::MODE_LIVE) {
            throw new \RuntimeException(Craft::t('small-change', 'Test data can only be deleted once the site is using a live Stripe key.'));
        }

        $elements = Craft::$app->getElements();
        $orders = 0;

        // Deleting an order takes its line items, payments, refunds and discount reservation with it.
        do {
            $deleted = 0;

            foreach (Order::find()->stripeMode(Stripe::MODE_TEST)->status(null)->limit(self::BATCH)->all() as $order) {
                if ($elements->deleteElement($order, true)) {
                    $deleted++;
                }
            }

            $orders += $deleted;
        } while ($deleted > 0);

        $subscriptions = SubscriptionRecord::deleteAll(['stripeMode' => Stripe::MODE_TEST]);
        $customers = CustomerRecord::deleteAll(['stripeMode' => Stripe::MODE_TEST]);
        $events = WebhookEventRecord::deleteAll(['stripeMode' => Stripe::MODE_TEST]);
        StripeProductRecord::deleteAll(['stripeMode' => Stripe::MODE_TEST]);

        $this->recountDiscountUses();

        return ['orders' => $orders, 'subscriptions' => $subscriptions, 'customers' => $customers, 'events' => $events];
    }

    /**
     * A code's used count is rebuilt from the orders that remain, so test orders don't use up a live code.
     */
    private function recountDiscountUses(): void
    {
        $counts = (new Query())
            ->select(['discountId', 'n' => 'COUNT(*)'])
            ->from(Table::ORDERS)
            ->where(['state' => Order::STATE_COMPLETED])
            ->andWhere(['not', ['discountId' => null]])
            ->groupBy('discountId')
            ->indexBy('discountId')
            ->column();

        DiscountRecord::updateAll(['uses' => 0]);

        foreach ($counts as $discountId => $uses) {
            DiscountRecord::updateAll(['uses' => (int) $uses], ['id' => $discountId]);
        }
    }

    /**
     * Applies the optional retention period: orders finished that long ago lose their personal data.
     */
    public function anonymiseDueOrders(int $limit = self::BATCH): int
    {
        $days = $this->settings()->anonymiseOrdersAfter;

        if ($days === null) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb(new DateTime('-' . max(1, $days) . ' days'));
        $count = 0;

        $orders = Order::find()
            ->state([Order::STATE_COMPLETED, Order::STATE_CANCELLED])
            ->status(null)
            ->site('*')
            ->unique()
            ->andWhere(['smallchange_orders.dateAnonymised' => null])
            ->andWhere(['<', 'COALESCE([[smallchange_orders.dateCompleted]], [[smallchange_orders.dateUpdated]])', $cutoff])
            ->limit($limit)
            ->all();

        foreach ($orders as $order) {
            if ($this->anonymiseOrder($order) === null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Erases everything this install holds about a person: every order placed with the address, their
     * customer records, and the webhook payloads that mention them.
     *
     * @return array{orders: int, skipped: string[]} orders anonymised, and the reasons any were left
     */
    public function anonymiseDonor(string $email): array
    {
        $email = Customers::normaliseEmail($email);
        $result = ['orders' => 0, 'skipped' => []];

        if ($email === '') {
            return $result;
        }

        // Escaped, or an address with a comma in it would erase everyone it names.
        $orders = Order::find()->email(Db::escapeParam($email))->status(null)->site('*')->unique()->all();

        foreach ($orders as $order) {
            // An erasure request outranks an unfinished purchase: a basket is simply deleted, and a checkout
            // that was started but never paid is cancelled (which expires its Stripe session) before it is wiped.
            if ($order->state === Order::STATE_CART) {
                Craft::$app->getElements()->deleteElement($order, true);
                $result['orders']++;

                continue;
            }

            if ($order->state === Order::STATE_PENDING) {
                Plugin::getInstance()->orders->cancelOrder($order);
                $order = Order::find()->id($order->id)->status(null)->site('*')->one() ?? $order;
            }

            $reason = $this->anonymiseOrder($order);

            if ($reason === null) {
                $result['orders']++;
            } else {
                $result['skipped'][] = $order->number . ': ' . $reason;
            }
        }

        // A customer record can outlive its orders (an order may have been purged), so it is handled in its own right.
        /** @var CustomerRecord[] $customers */
        $customers = CustomerRecord::find()->where(['email' => $email])->all();

        foreach ($customers as $customer) {
            $this->anonymiseCustomerRecord($customer, $email);
        }

        $this->redactWebhookPayloads([$email], [], []);

        return $result;
    }

    /**
     * Removes one order's personal data. Returns null when done, or the reason it was left alone.
     * An order with a recurring donation still running is left, because its emails still have to reach someone.
     */
    public function anonymiseOrder(Order $order): ?string
    {
        if ($order->dateAnonymised !== null) {
            return null;
        }

        if ($order->state === Order::STATE_CART || $order->state === Order::STATE_PENDING) {
            return Craft::t('small-change', 'It has not been completed or cancelled yet.');
        }

        $plugin = Plugin::getInstance();
        /** @var SubscriptionRecord[] $subscriptions */
        $subscriptions = SubscriptionRecord::find()->where(['orderId' => $order->id])->all();

        foreach ($subscriptions as $subscription) {
            if (!in_array($subscription->status, self::ENDED_SUBSCRIPTION_STATUSES, true)) {
                return Craft::t('small-change', 'It has a recurring donation that is still running. Cancel that first.');
            }
        }

        $email = (string) $order->email;
        /** @var PaymentRecord[] $payments */
        $payments = PaymentRecord::find()->where(['orderId' => $order->id])->all();
        $stripeIds = array_filter(array_merge(
            [$order->stripeCheckoutSessionId, $order->stripePaymentIntentId],
            array_map(static fn(PaymentRecord $p): ?string => $p->stripePaymentIntentId, $payments),
            array_map(static fn(PaymentRecord $p): ?string => $p->stripeChargeId, $payments),
            array_map(static fn(PaymentRecord $p): ?string => $p->stripeInvoiceId, $payments),
            array_map(static fn(SubscriptionRecord $s): string => $s->stripeSubscriptionId, $subscriptions),
        ));
        $refundIds = RefundRecord::find()->select('stripeRefundId')->where(['paymentId' => array_map(static fn(PaymentRecord $p): int => $p->id, $payments)])->column();

        $customer = $order->customerId ? CustomerRecord::findOne($order->customerId) : null;
        $stripeCustomerIds = $customer && $customer->stripeCustomerId ? [$customer->stripeCustomerId] : [];

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $order->email = $this->pseudonym();
            $order->customerNote = null;
            $order->adminNote = null;
            $order->lastIp = null;
            $order->returnUrl = null;
            $order->userId = null;
            $order->setShippingAddress(null);
            $order->setBillingAddress(null);
            $order->dateAnonymised = new DateTime();

            foreach ($order->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                $order->setFieldValue($field->handle, null);
            }

            if (!Craft::$app->getElements()->saveElement($order, false, false, false)) {
                throw new \RuntimeException('Could not anonymise order ' . $order->number . ': ' . implode(' ', $order->getFirstErrors()));
            }

            // Re-index now, not from the queue: the old address must not stay searchable for even a few minutes.
            Craft::$app->getSearch()->indexElementAttributes($order);

            // A staff note on a refund can name the donor as easily as the order's own note.
            RefundRecord::updateAll(['note' => null], ['paymentId' => array_map(static fn(PaymentRecord $p): int => $p->id, $payments)]);

            if ($customer) {
                $this->anonymiseCustomerRecord($customer, $email);
            }

            $this->redactWebhookPayloads($email !== '' ? [$email] : [], array_merge(array_values($stripeIds), array_values($refundIds)), $stripeCustomerIds);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->trigger(self::EVENT_AFTER_ANONYMISE_ORDER, new OrderEvent(['order' => $order]));

        return null;
    }

    /**
     * Blanks a customer row, and removes the Stripe customer too. A customer with a recurring donation still
     * running is left whole, because the donor's emails and portal link depend on it.
     */
    private function anonymiseCustomerRecord(CustomerRecord $customer, string $email): bool
    {
        if ($customer->dateAnonymised !== null) {
            return true;
        }

        $live = SubscriptionRecord::find()
            ->where(['customerId' => $customer->id])
            ->andWhere(['not in', 'status', self::ENDED_SUBSCRIPTION_STATUSES])
            ->exists();

        if ($live) {
            return false;
        }

        $stripeCustomerId = $customer->stripeCustomerId;

        if ($stripeCustomerId !== null) {
            try {
                Plugin::getInstance()->stripe->deleteCustomer($stripeCustomerId);
                $customer->stripeCustomerId = null;
            } catch (Throwable $e) {
                // Stripe being unreachable must not stop the local erasure; the id stays so it can be removed later.
                Craft::warning('Could not delete Stripe customer ' . $stripeCustomerId . ': ' . $e->getMessage(), __METHOD__);
            }
        }

        $customer->email = $this->pseudonym();
        $customer->name = null;
        $customer->userId = null;
        $customer->dateAnonymised = Db::prepareDateForDb(new DateTime());
        $customer->save(false);

        if ($stripeCustomerId !== null) {
            $this->redactWebhookPayloads([$email], [], [$stripeCustomerId]);
        }

        return true;
    }

    /**
     * Replaces the stored payload of every webhook event that mentions the person, keeping only what identifies the event.
     *
     * @param string[] $emails
     * @param string[] $objectIds
     * @param string[] $stripeCustomerIds
     * @return int events redacted
     */
    public function redactWebhookPayloads(array $emails, array $objectIds, array $stripeCustomerIds): int
    {
        $emails = array_values(array_filter($emails, static fn(string $e): bool => $e !== ''));
        $conditions = ['or'];

        foreach ($emails as $email) {
            // Lower-cased on both sides: LIKE ignores case on MySQL but not on PostgreSQL, and a payload may hold any casing.
            $conditions[] = ['like', 'LOWER([[payload]])', mb_strtolower($email)];
        }

        if ($objectIds) {
            $conditions[] = ['stripeObjectId' => array_values($objectIds)];
        }

        if ($stripeCustomerIds) {
            $conditions[] = ['stripeCustomerId' => array_values($stripeCustomerIds)];
            $conditions[] = ['stripeObjectId' => array_values($stripeCustomerIds)];
        }

        if (count($conditions) === 1) {
            return 0;
        }

        $redacted = 0;
        $rows = WebhookEventRecord::find()->where(['redactedAt' => null])->andWhere($conditions)->select(['id', 'type', 'stripeEventId'])->asArray()->all();

        foreach ($rows as $row) {
            $redacted += WebhookEventRecord::updateAll([
                'payload' => Json::encode(['redacted' => true, 'type' => $row['type'], 'id' => $row['stripeEventId']]),
                'redactedAt' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $row['id']]);
        }

        return $redacted;
    }

    /**
     * A throwaway address that can't be reversed to the original. Random rather than a hash of the email, which
     * anyone holding a candidate address could confirm.
     */
    private function pseudonym(): string
    {
        return 'anonymised-' . bin2hex(random_bytes(8)) . '@invalid';
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return Plugin::getInstance()->getSettings();
    }
}
