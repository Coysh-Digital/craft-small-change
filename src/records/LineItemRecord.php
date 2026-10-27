<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $orderId
 * @property int|null $purchasableId
 * @property string $kind
 * @property string $description
 * @property string|null $sku
 * @property int $unitPrice
 * @property int $qty
 * @property int $subtotal
 * @property string|null $recurringInterval
 * @property bool $isGiftAidEligible
 * @property array<string, mixed>|null $snapshot
 * @property int $sortOrder
 */
class LineItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LINE_ITEMS;
    }
}
