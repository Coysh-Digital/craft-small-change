<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\models\ShippingMethod;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;

final class ShippingMethodsTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
    }

    public function testAMethodWithNoCountriesServesEverywhere(): void
    {
        $method = $this->makeShippingMethod('Anywhere', 500, null);

        $this->assertTrue($method->appliesTo('GB'));
        $this->assertTrue($method->appliesTo('FR'));
        $this->assertTrue($method->appliesTo(null));
    }

    public function testAMethodWithCountriesServesOnlyThose(): void
    {
        $method = $this->makeShippingMethod('UK only', 350, ['GB', 'IE']);

        $this->assertTrue($method->appliesTo('GB'));
        $this->assertTrue($method->appliesTo('gb'), 'case does not matter');
        $this->assertTrue($method->appliesTo('IE'));
        $this->assertFalse($method->appliesTo('FR'));
        $this->assertFalse($method->appliesTo(null), 'no address yet: not known to serve it');
    }

    public function testOnlyEnabledApplicableMethodsAreOffered(): void
    {
        $uk = $this->makeShippingMethod('UK', 350, ['GB']);
        $world = $this->makeShippingMethod('World', 1500, null);
        $off = $this->makeShippingMethod('Disabled', 100, null);
        $off->enabled = false;
        Plugin::getInstance()->shippingMethods->saveMethod($off);

        $forGb = array_map(static fn(ShippingMethod $m): ?int => $m->id, Plugin::getInstance()->shippingMethods->getApplicableMethods('GB'));
        $forFr = array_map(static fn(ShippingMethod $m): ?int => $m->id, Plugin::getInstance()->shippingMethods->getApplicableMethods('FR'));

        $this->assertSame([$uk->id, $world->id], $forGb);
        $this->assertSame([$world->id], $forFr);
    }

    public function testMethodsKeepTheirOrderAndCanBeReordered(): void
    {
        $service = Plugin::getInstance()->shippingMethods;
        $a = $this->makeShippingMethod('A', 100);
        $b = $this->makeShippingMethod('B', 200);
        $c = $this->makeShippingMethod('C', 300);

        $this->assertSame([$a->id, $b->id, $c->id], array_map(static fn(ShippingMethod $m): ?int => $m->id, $service->getAllMethods()));

        $service->reorder([(int) $c->id, (int) $a->id, (int) $b->id]);

        $this->assertSame([$c->id, $a->id, $b->id], array_map(static fn(ShippingMethod $m): ?int => $m->id, $service->getAllMethods()));
    }

    public function testFreeDeliveryIsAllowedButNegativeIsNot(): void
    {
        $free = new ShippingMethod(['name' => 'Collect', 'handle' => 'collect', 'price' => 0]);
        $this->assertTrue($free->validate());

        $negative = new ShippingMethod(['name' => 'Bad', 'handle' => 'bad', 'price' => -1]);
        $this->assertFalse($negative->validate(['price']));
    }

    public function testHandlesAreUniqueAndCountriesAreCodes(): void
    {
        $this->makeShippingMethod('Post');
        $method = $this->makeShippingMethod('Other');

        $method->handle = 'post';
        $existing = Plugin::getInstance()->shippingMethods->getAllMethods()[0];
        $clash = new ShippingMethod(['name' => 'Clash', 'handle' => $existing->handle, 'price' => 1]);
        $this->assertFalse($clash->validate(['handle']));

        $method->countryCodes = ['GBR'];
        $this->assertFalse($method->validate(['countryCodes']));
    }

    public function testDeletingAMethodLeavesOrdersWithItsNameAndPrice(): void
    {
        $method = $this->makeShippingMethod('Courier', 600);
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        $this->addDeliveryDetails($cart);
        $this->assertSame('Courier', $cart->shippingMethodName);

        Plugin::getInstance()->shippingMethods->deleteMethod($method);

        $reloaded = \coyshdigital\smallchange\elements\Order::find()->id($cart->id)->one();
        $this->assertNull($reloaded->shippingMethodId);
        $this->assertSame('Courier', $reloaded->shippingMethodName);
        $this->assertSame(600, $reloaded->shippingAmount);
    }
}
