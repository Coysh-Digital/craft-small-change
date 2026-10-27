<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $email
 * @property int|null $userId
 * @property string $stripeMode
 * @property string|null $stripeCustomerId
 * @property string|null $name
 * @property string|null $dateAnonymised
 */
class CustomerRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CUSTOMERS;
    }
}
