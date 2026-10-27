<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $productId
 * @property string $stripeMode
 * @property string $stripeProductId
 */
class StripeProductRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::STRIPE_PRODUCTS;
    }
}
