<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\elements\db;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @extends ElementQuery<int, Product>
 */
class ProductQuery extends ElementQuery
{
    public mixed $typeId = null;
    public mixed $kind = null;
    public mixed $sku = null;

    /**
     * Narrows by product type: a handle, an array of handles, or a ProductType.
     */
    public function type(mixed $value): static
    {
        if ($value instanceof ProductType) {
            $this->typeId = $value->id;
        } elseif ($value !== null) {
            $handles = (array) $value;
            $ids = [];
            foreach (Plugin::getInstance()->productTypes->getAllProductTypes() as $type) {
                if (in_array($type->handle, $handles, true)) {
                    $ids[] = $type->id;
                }
            }
            // An unknown handle must match nothing rather than everything.
            $this->typeId = $ids ?: [0];
        } else {
            $this->typeId = null;
        }

        return $this;
    }

    public function typeId(mixed $value): static
    {
        $this->typeId = $value;

        return $this;
    }

    public function kind(mixed $value): static
    {
        $this->kind = $value;

        return $this;
    }

    public function sku(mixed $value): static
    {
        $this->sku = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('smallchange_products');

        $this->query->select([
            'smallchange_products.typeId',
            'smallchange_products.price',
            'smallchange_products.sku',
            'smallchange_products.minimumAmount',
            'smallchange_products.suggestedAmounts',
            'smallchange_products.allowCustomAmount',
            'smallchange_products.allowRecurring',
            'smallchange_products.recurringIntervals',
        ]);

        if ($this->typeId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('smallchange_products.typeId', $this->typeId));
        }

        if ($this->sku !== null) {
            $this->subQuery->andWhere(Db::parseParam('smallchange_products.sku', $this->sku));
        }

        if ($this->kind !== null) {
            $this->subQuery->innerJoin(['smallchange_producttypes' => Table::PRODUCT_TYPES], '[[smallchange_producttypes.id]] = [[smallchange_products.typeId]]');
            $this->subQuery->andWhere(Db::parseParam('smallchange_producttypes.kind', $this->kind));
        }

        return parent::beforePrepare();
    }
}
