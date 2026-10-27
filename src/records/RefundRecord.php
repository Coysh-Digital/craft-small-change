<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $paymentId
 * @property string $stripeMode
 * @property string $stripeRefundId
 * @property int $amount
 * @property string $status
 * @property string|null $reason
 * @property string|null $note
 * @property int|null $userId
 * @property string|null $failureReason
 * @property string $dateCreated
 */
class RefundRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::REFUNDS;
    }
}
