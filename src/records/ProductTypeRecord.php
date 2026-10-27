<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $kind
 * @property int|null $fieldLayoutId
 * @property bool $skuRequired
 * @property int $sortOrder
 * @property string $uid
 */
class ProductTypeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PRODUCT_TYPES;
    }
}
