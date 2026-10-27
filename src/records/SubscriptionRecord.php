<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $orderId
 * @property int|null $customerId
 * @property int|null $productId
 * @property string $stripeMode
 * @property string $stripeSubscriptionId
 * @property string $status
 * @property string $interval
 * @property int $amount
 * @property string $currency
 * @property string|null $currentPeriodEnd
 * @property bool $cancelAtPeriodEnd
 * @property string|null $canceledAt
 * @property string|null $endedAt
 * @property bool $giftAidDeclared
 * @property string|null $lastStripeEventAt
 * @property string|null $lastFailureAt
 * @property string $dateCreated
 */
class SubscriptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBSCRIPTIONS;
    }
}
