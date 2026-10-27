<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $typeId
 * @property int|null $price
 * @property string|null $sku
 * @property int|null $minimumAmount
 * @property int[]|null $suggestedAmounts
 * @property bool $allowCustomAmount
 * @property bool $allowRecurring
 * @property string[]|null $recurringIntervals
 */
class ProductRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PRODUCTS;
    }
}
