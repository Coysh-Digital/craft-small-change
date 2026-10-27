<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\elements\db;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\LineItem;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use yii\db\Expression;
use yii\db\Query;

/**
 * Unlike most element queries this one does not hide anything by default: carts are real rows
 * and callers choose what they want with `state` or `isCompleted`.
 *
 *
 * @extends ElementQuery<int, Order>
 */
class OrderQuery extends ElementQuery
{
    public mixed $number = null;
    public mixed $email = null;
    public mixed $customerId = null;
    public mixed $userId = null;
    public mixed $state = null;
    public mixed $paymentStatus = null;
    public mixed $kind = null;
    public mixed $dateCompleted = null;
    public mixed $giftAidDeclared = null;
    public mixed $stripeMode = null;
    public mixed $stripeCheckoutSessionId = null;
    public mixed $stripePaymentIntentId = null;
    public ?bool $isCompleted = null;
    public ?bool $hasDonation = null;
    public ?bool $needsDispatch = null;

    public function init(): void
    {
        parent::init();

        // Order statuses are derived, not an enabled flag.
        $this->status = null;
    }

    public function number(mixed $value): static
    {
        $this->number = $value;

        return $this;
    }

    public function email(mixed $value): static
    {
        $this->email = $value;

        return $this;
    }

    public function customerId(mixed $value): static
    {
        $this->customerId = $value;

        return $this;
    }

    public function userId(mixed $value): static
    {
        $this->userId = $value;

        return $this;
    }

    public function state(mixed $value): static
    {
        $this->state = $value;

        return $this;
    }

    public function paymentStatus(mixed $value): static
    {
        $this->paymentStatus = $value;

        return $this;
    }

    public function kind(mixed $value): static
    {
        $this->kind = $value;

        return $this;
    }

    public function dateCompleted(mixed $value): static
    {
        $this->dateCompleted = $value;

        return $this;
    }

    public function giftAidDeclared(mixed $value): static
    {
        $this->giftAidDeclared = $value;

        return $this;
    }

    public function stripeMode(mixed $value): static
    {
        $this->stripeMode = $value;

        return $this;
    }

    public function stripeCheckoutSessionId(mixed $value): static
    {
        $this->stripeCheckoutSessionId = $value;

        return $this;
    }

    public function stripePaymentIntentId(mixed $value): static
    {
        $this->stripePaymentIntentId = $value;

        return $this;
    }

    public function isCompleted(?bool $value = true): static
    {
        $this->isCompleted = $value;

        return $this;
    }

    /**
     * Paid orders with something to post that has not been marked as sent.
     */
    public function needsDispatch(?bool $value = true): static
    {
        $this->needsDispatch = $value;

        return $this;
    }

    public function hasDonation(?bool $value = true): static
    {
        $this->hasDonation = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('smallchange_orders');

        $this->query->select([
            'smallchange_orders.number',
            'smallchange_orders.orderSiteId',
            'smallchange_orders.kind',
            'smallchange_orders.state',
            'smallchange_orders.paymentStatus',
            'smallchange_orders.email',
            'smallchange_orders.customerId',
            'smallchange_orders.userId',
            'smallchange_orders.currency',
            'smallchange_orders.itemSubtotal',
            'smallchange_orders.discountAmount',
            'smallchange_orders.shippingAmount',
            'smallchange_orders.total',
            'smallchange_orders.totalPaid',
            'smallchange_orders.totalRefunded',
            'smallchange_orders.giftAidDeclared',
            'smallchange_orders.giftAidEligibleAmount',
            'smallchange_orders.discountId',
            'smallchange_orders.discountCode',
            'smallchange_orders.shippingMethodId',
            'smallchange_orders.shippingMethodName',
            'smallchange_orders.shippingAddressId',
            'smallchange_orders.billingAddressId',
            'smallchange_orders.subscriptionId',
            'smallchange_orders.stripeMode',
            'smallchange_orders.stripeCheckoutSessionId',
            'smallchange_orders.stripePaymentIntentId',
            'smallchange_orders.checkoutAttempt',
            'smallchange_orders.returnUrl',
            'smallchange_orders.customerNote',
            'smallchange_orders.adminNote',
            'smallchange_orders.lastIp',
            'smallchange_orders.dateCompleted',
            'smallchange_orders.dateFulfilled',
            'smallchange_orders.dateAnonymised',
            'smallchange_orders.dateLastActivity',
        ]);

        foreach (['number', 'email', 'kind', 'state', 'paymentStatus', 'stripeMode', 'stripeCheckoutSessionId', 'stripePaymentIntentId'] as $column) {
            if ($this->$column !== null) {
                $this->subQuery->andWhere(Db::parseParam("smallchange_orders.$column", $this->$column));
            }
        }

        foreach (['customerId', 'userId'] as $column) {
            if ($this->$column !== null) {
                $this->subQuery->andWhere(Db::parseNumericParam("smallchange_orders.$column", $this->$column));
            }
        }

        if ($this->giftAidDeclared !== null) {
            $this->subQuery->andWhere(['smallchange_orders.giftAidDeclared' => $this->giftAidDeclared]);
        }

        if ($this->dateCompleted !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('smallchange_orders.dateCompleted', $this->dateCompleted));
        }

        if ($this->isCompleted !== null) {
            $this->subQuery->andWhere($this->isCompleted
                ? ['smallchange_orders.state' => Order::STATE_COMPLETED]
                : ['not', ['smallchange_orders.state' => Order::STATE_COMPLETED]]);
        }

        if ($this->needsDispatch) {
            $physicalLines = (new Query())
                ->select(new Expression('1'))
                ->from(['smallchange_physical_lines' => Table::LINE_ITEMS])
                ->where('[[smallchange_physical_lines.orderId]] = [[smallchange_orders.id]]')
                ->andWhere(['smallchange_physical_lines.kind' => LineItem::KIND_PHYSICAL]);

            $this->subQuery->andWhere([
                'smallchange_orders.state' => Order::STATE_COMPLETED,
                'smallchange_orders.paymentStatus' => [Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED],
                'smallchange_orders.dateFulfilled' => null,
            ]);
            $this->subQuery->andWhere(['exists', $physicalLines]);
        }

        if ($this->hasDonation !== null) {
            $donationLines = (new Query())
                ->select(new Expression('1'))
                ->from(['smallchange_donation_lines' => Table::LINE_ITEMS])
                ->where('[[smallchange_donation_lines.orderId]] = [[smallchange_orders.id]]')
                ->andWhere(['smallchange_donation_lines.kind' => LineItem::KIND_DONATION]);

            $this->subQuery->andWhere([$this->hasDonation ? 'exists' : 'not exists', $donationLines]);
        }

        return parent::beforePrepare();
    }

    /**
     * @return array<string, mixed>|string|null
     */
    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Order::STATUS_PENDING => ['smallchange_orders.state' => Order::STATE_PENDING],
            Order::STATUS_COMPLETED => ['smallchange_orders.state' => Order::STATE_COMPLETED, 'smallchange_orders.paymentStatus' => Order::PAYMENT_PAID],
            Order::STATUS_PARTIALLY_REFUNDED => ['smallchange_orders.paymentStatus' => Order::PAYMENT_PARTIALLY_REFUNDED],
            Order::STATUS_REFUNDED => ['smallchange_orders.paymentStatus' => Order::PAYMENT_REFUNDED],
            Order::STATUS_DISPUTED => ['smallchange_orders.paymentStatus' => Order::PAYMENT_DISPUTED],
            Order::STATUS_CANCELLED => ['smallchange_orders.state' => Order::STATE_CANCELLED],
            default => parent::statusCondition($status),
        };
    }
}
