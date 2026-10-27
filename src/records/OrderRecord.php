<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $number
 * @property int|null $orderSiteId
 * @property string $kind
 * @property string $state
 * @property string $paymentStatus
 * @property string|null $email
 * @property int|null $customerId
 * @property int|null $userId
 * @property string $currency
 * @property int $itemSubtotal
 * @property int $discountAmount
 * @property int $shippingAmount
 * @property int $total
 * @property int $totalPaid
 * @property int $totalRefunded
 * @property bool|null $giftAidDeclared
 * @property int $giftAidEligibleAmount
 * @property int|null $discountId
 * @property string|null $discountCode
 * @property int|null $shippingMethodId
 * @property string|null $shippingMethodName
 * @property int|null $shippingAddressId
 * @property int|null $billingAddressId
 * @property int|null $subscriptionId
 * @property string|null $stripeMode
 * @property string|null $stripeCheckoutSessionId
 * @property string|null $stripePaymentIntentId
 * @property int $checkoutAttempt
 * @property string|null $returnUrl
 * @property string|null $customerNote
 * @property string|null $adminNote
 * @property string|null $lastIp
 * @property string|null $dateCompleted
 * @property string|null $dateFulfilled
 * @property string|null $dateAnonymised
 * @property string|null $dateLastActivity
 */
class OrderRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ORDERS;
    }
}
