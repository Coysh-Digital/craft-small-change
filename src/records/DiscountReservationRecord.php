<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $discountId
 * @property int $orderId
 * @property string $expiresAt
 */
class DiscountReservationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DISCOUNT_RESERVATIONS;
    }
}
