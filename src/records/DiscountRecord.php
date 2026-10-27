<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property int|null $percent
 * @property int|null $amount
 * @property int|null $minSubtotal
 * @property int|null $maxUses
 * @property int $uses
 * @property string|null $dateFrom
 * @property string|null $dateTo
 * @property bool $enabled
 */
class DiscountRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DISCOUNTS;
    }
}
