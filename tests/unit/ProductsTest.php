<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\db\ProductQuery;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\fields\Products;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\models\GqlSchema;

final class ProductsTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
    }

    public function testAPhysicalProductKeepsItsPriceInMinorUnits(): void
    {
        $product = $this->makeShopProduct(1250);

        $saved = Product::find()->id($product->id)->one();
        $this->assertSame(1250, $saved->price);
        $this->assertSame(LineItem::KIND_PHYSICAL, $saved->getLineItemKind());
        $this->assertFalse($saved->getIsDonation());
        $this->assertSame(1250, $saved->getPrice());
    }

    public function testTheProductsFieldIsOfferedAndPicksProducts(): void
    {
        $this->assertContains(Products::class, Craft::$app->getFields()->getAllFieldTypes());

        $product = $this->makeShopProduct(900);
        $field = new Products(['handle' => 'featuredProduct']);

        $this->assertSame(Product::class, Products::elementType());
        $this->assertFalse($field->includeInGqlSchema(new GqlSchema()), 'products have no GraphQL type');

        $value = $field->normalizeValue([$product->id], null);
        $this->assertInstanceOf(ProductQuery::class, $value);
        $this->assertSame($product->id, $value->one()?->id);
    }

    public function testANonPhysicalProductIsNotDelivered(): void
    {
        $product = $this->makeShopProduct(500, ProductType::KIND_NON_PHYSICAL, 'Concert ticket');

        $this->assertSame(LineItem::KIND_NON_PHYSICAL, $product->getLineItemKind());
    }

    public function testASoldProductNeedsAPriceWhenItGoesLive(): void
    {
        $product = $this->makeShopProduct(1000);
        $product->price = null;
        $product->setScenario(Product::SCENARIO_LIVE);

        $this->assertFalse($product->validate(['price']));
        $this->assertTrue($product->hasErrors('price'));
    }

    public function testPricesAreBounded(): void
    {
        $product = $this->makeShopProduct(1000);

        foreach ([0, -5, 100000000] as $bad) {
            $product->price = $bad;
            $product->clearErrors();
            $this->assertFalse($product->validate(['price']), "$bad should be refused");
        }
    }

    public function testADonationNeverNeedsAPrice(): void
    {
        $donation = $this->makeProduct();
        $donation->setScenario(Product::SCENARIO_LIVE);

        $this->assertTrue($donation->validate(['price']));
        $this->assertNull($donation->getPrice());
    }

    public function testThePriceFieldAcceptsDecimalText(): void
    {
        $product = $this->makeShopProduct(1000);
        $product->setAttributesFromRequest(['priceInput' => '12.50']);
        $this->assertSame(1250, $product->price);

        $product->setAttributesFromRequest(['priceInput' => '1,050']);
        $this->assertSame(105000, $product->price);

        $product->clearErrors();
        $product->setAttributesFromRequest(['priceInput' => '12,50']);
        $this->assertTrue($product->hasErrors('price'), '12,50 is refused rather than read as 1250');
    }

    public function testSkusAreUnique(): void
    {
        $this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'First', 'TOWEL-1');

        $second = $this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'Second', 'TOWEL-2');
        $second->sku = 'TOWEL-1';

        $this->assertFalse($second->validate(['sku']));
        $this->assertStringContainsString('already been taken', $second->getFirstError('sku'));

        $second->sku = 'TOWEL-3';
        $second->clearErrors();
        $this->assertTrue($second->validate(['sku']));
    }

    public function testAProductMayKeepItsOwnSku(): void
    {
        $product = $this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'Mug', 'MUG-1');

        $this->assertTrue(Product::find()->id($product->id)->one()->validate(['sku']));
    }

    public function testBlankSkusAreStoredAsNull(): void
    {
        $product = $this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'Plain', '   ');

        $this->assertNull(Product::find()->id($product->id)->one()->sku);
        $this->assertNull($this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'Plain 2', null)->sku, 'many products can have no SKU');
    }

    public function testATypeCanMakeSkusMandatory(): void
    {
        $product = $this->makeShopProduct(1000);
        $type = $product->getType();
        $type->skuRequired = true;

        $product->sku = null;
        $product->setScenario(Product::SCENARIO_LIVE);

        $this->assertFalse($product->validate(['sku']));
    }

    public function testQueryFiltersByKindAndSku(): void
    {
        $donation = $this->makeProduct();
        $physical = $this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'Mug', 'MUG-9');

        $this->assertContains($physical->id, Product::find()->kind('physical')->ids());
        $this->assertNotContains($donation->id, Product::find()->kind('physical')->ids());
        $this->assertSame([$physical->id], Product::find()->sku('MUG-9')->ids());
    }

    public function testEveryKindIsAvailableToCreate(): void
    {
        $this->assertSame(['donation', 'physical', 'nonPhysical'], ProductType::AVAILABLE_KINDS);
        $this->assertCount(3, ProductType::kindLabels());
        $this->assertSame(Craft::t('small-change', 'Donation'), ProductType::kindLabels()['donation']);
        $this->assertNotNull(Plugin::getInstance());
    }
}
