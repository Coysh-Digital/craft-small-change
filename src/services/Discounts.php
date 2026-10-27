<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\records\DiscountRecord;
use coyshdigital\smallchange\records\DiscountReservationRecord;
use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use yii\db\Expression;

/**
 * Discount codes owned by the plugin. Usage limits are enforced with short-lived reservations, so two
 * donors racing for the last use cannot both get it, and an abandoned checkout gives its use back.
 */
class Discounts extends Component
{
    /**
     * A reservation lasts as long as the Checkout Session it belongs to.
     */
    public const RESERVATION_TTL = 86400;

    /**
     * @return Discount[] sorted by name
     */
    public function getAllDiscounts(): array
    {
        /** @var DiscountRecord[] $records */
        $records = DiscountRecord::find()->orderBy(['name' => SORT_ASC])->all();

        return array_map(fn(DiscountRecord $record): Discount => $this->toModel($record), $records);
    }

    public function getDiscountById(int $id): ?Discount
    {
        $record = DiscountRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    public function getDiscountByCode(string $code): ?Discount
    {
        $record = DiscountRecord::findOne(['code' => Discount::normaliseCode($code)]);

        return $record ? $this->toModel($record) : null;
    }

    public function saveDiscount(Discount $discount, bool $runValidation = true): bool
    {
        if ($runValidation && !$discount->validate()) {
            return false;
        }

        $record = $discount->id ? DiscountRecord::findOne($discount->id) : new DiscountRecord();

        if (!$record) {
            return false;
        }

        $record->name = $discount->name;
        $record->code = Discount::normaliseCode($discount->code);
        $record->type = $discount->type;
        $record->percent = $discount->type === Discount::TYPE_PERCENT ? $discount->percent : null;
        $record->amount = $discount->type === Discount::TYPE_FIXED ? $discount->amount : null;
        $record->minSubtotal = $discount->minSubtotal;
        $record->maxUses = $discount->maxUses;
        $record->dateFrom = $discount->dateFrom ? Db::prepareDateForDb($discount->dateFrom) : null;
        $record->dateTo = $discount->dateTo ? Db::prepareDateForDb($discount->dateTo) : null;
        $record->enabled = $discount->enabled;
        $record->save(false);

        $discount->id = $record->id;

        return true;
    }

    /**
     * Orders keep the code they used as text, so a code can be deleted without rewriting history.
     */
    public function deleteDiscount(Discount $discount): bool
    {
        return (bool) DiscountRecord::deleteAll(['id' => $discount->id]);
    }

    /**
     * Why a code can't be used on this order, in words for the donor, or null when it can.
     * Deliberately vague about disabled, expired and used-up codes so codes can't be probed.
     */
    public function problemWith(Discount $discount, Order $order): ?string
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $invalid = Craft::t('small-change', 'That code is not valid.');

        if (!$discount->enabled || ($discount->dateFrom && $discount->dateFrom > $now) || ($discount->dateTo && $discount->dateTo < $now)) {
            return $invalid;
        }

        $discountable = $this->discountableSubtotal($order);

        if ($discountable <= 0) {
            return Craft::t('small-change', 'That code does not apply to anything in your basket.');
        }

        if ($discount->minSubtotal !== null && $discountable < $discount->minSubtotal) {
            return Craft::t('small-change', 'That code needs at least {amount} of products in your basket.', [
                'amount' => Money::format($discount->minSubtotal, $order->currency, true),
            ]);
        }

        if ($discount->maxUses !== null && $this->usesTaken($discount, $order->id ? (int) $order->id : null) >= $discount->maxUses) {
            return $invalid;
        }

        return null;
    }

    /**
     * Holds one use of the code for this order. Returns false if none is left.
     */
    public function reserve(Discount $discount, Order $order): bool
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'smallchange:discount:' . $discount->id;

        if (!$mutex->acquire($lock, 10)) {
            return false;
        }

        try {
            if ($discount->maxUses !== null && $this->usesTaken($discount, (int) $order->id) >= $discount->maxUses) {
                return false;
            }

            $record = DiscountReservationRecord::findOne(['orderId' => $order->id]) ?? new DiscountReservationRecord();
            $record->discountId = (int) $discount->id;
            $record->orderId = (int) $order->id;
            $record->expiresAt = Db::prepareDateForDb(new DateTime('+' . self::RESERVATION_TTL . ' seconds'));
            $record->save(false);

            return true;
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Gives the order's held use back: the cart changed, the session expired, or the order was cancelled.
     */
    public function release(Order $order): void
    {
        if ($order->id) {
            DiscountReservationRecord::deleteAll(['orderId' => $order->id]);
        }
    }

    /**
     * Turns the order's reservation into a real use. Called once, as the order completes.
     */
    public function consume(Order $order): void
    {
        if (!$order->id || !$order->discountId) {
            return;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            DiscountReservationRecord::deleteAll(['orderId' => $order->id]);
            $db->createCommand()->update(Table::DISCOUNTS, ['uses' => new Expression('[[uses]] + 1')], ['id' => $order->discountId])->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Uses already spent plus uses currently held by other orders' live reservations.
     */
    public function usesTaken(Discount $discount, ?int $exceptOrderId = null): int
    {
        $uses = (int) DiscountRecord::find()->select('uses')->where(['id' => $discount->id])->scalar();

        $reserved = DiscountReservationRecord::find()
            ->where(['discountId' => $discount->id])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())]);

        if ($exceptOrderId !== null) {
            $reserved->andWhere(['not', ['orderId' => $exceptOrderId]]);
        }

        return $uses + (int) $reserved->count();
    }

    /**
     * Removes reservations that have run out. They are already ignored when counting, so this only tidies up.
     */
    public function purgeExpiredReservations(): int
    {
        return DiscountReservationRecord::deleteAll(['<=', 'expiresAt', Db::prepareDateForDb(new DateTime())]);
    }

    /**
     * Only products are discounted; donations never are.
     */
    public function discountableSubtotal(Order $order): int
    {
        $subtotal = 0;

        foreach ($order->getLineItems() as $item) {
            if (!$item->getIsDonation()) {
                $subtotal += $item->unitPrice * $item->qty;
            }
        }

        return $subtotal;
    }

    private function toModel(DiscountRecord $record): Discount
    {
        return new Discount($record->getAttributes(['id', 'name', 'code', 'type', 'percent', 'amount', 'minSubtotal', 'maxUses', 'uses', 'dateFrom', 'dateTo', 'enabled']));
    }
}
