<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\fields;

use coyshdigital\smallchange\elements\db\ProductQuery;
use coyshdigital\smallchange\elements\Product;
use Craft;
use craft\elements\ElementCollection;
use craft\fields\BaseRelationField;
use craft\models\GqlSchema;

/**
 * Lets an editor choose products or donations, so a block or entry can show one wherever the site wants it.
 * Limit the field to a product type in its settings to offer only donations, or only shop products.
 */
final class Products extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('small-change', 'Small Change Products');
    }

    public static function icon(): string
    {
        return 'cart-shopping';
    }

    public static function elementType(): string
    {
        return Product::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('small-change', 'Choose a product');
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|\\%s<\\%s>', ProductQuery::class, ElementCollection::class, Product::class);
    }

    /**
     * Products have no GraphQL type, so the field stays out of every schema.
     */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return false;
    }
}
