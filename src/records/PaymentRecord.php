<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $orderId
 * @property int|null $subscriptionId
 * @property int|null $customerId
 * @property string $stripeMode
 * @property string|null $stripePaymentIntentId
 * @property string|null $stripeChargeId
 * @property string|null $stripeInvoiceId
 * @property string|null $stripeCheckoutSessionId
 * @property int $amount
 * @property int $amountRefunded
 * @property string $currency
 * @property string $status
 * @property string|null $failureCode
 * @property string|null $failureMessage
 * @property bool $giftAidDeclared
 * @property int $giftAidAmount
 * @property string|null $stripeDisputeId
 * @property string|null $disputeStatus
 * @property string|null $paidAt
 * @property string|null $receiptSentAt
 */
class PaymentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PAYMENTS;
    }
}
