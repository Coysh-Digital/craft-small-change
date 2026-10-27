<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property int $price
 * @property string[]|null $countryCodes
 * @property bool $enabled
 * @property int $sortOrder
 */
class ShippingMethodRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SHIPPING_METHODS;
    }
}
