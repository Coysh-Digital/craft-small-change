<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $productTypeId
 * @property int $siteId
 * @property bool $hasUrls
 * @property string|null $uriFormat
 * @property string|null $template
 */
class ProductTypeSiteRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PRODUCT_TYPES_SITES;
    }
}
