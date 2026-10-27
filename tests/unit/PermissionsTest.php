<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\elements\User;
use ReflectionMethod;

/**
 * What the control panel hands out through Craft's own element routes, which don't ask the plugin's controllers.
 */
final class PermissionsTest extends Unit
{
    use Fixtures;

    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
    }

    private function admin(): User
    {
        $name = 'admin' . random_int(1, 999999);
        $user = new User(['username' => $name, 'email' => $name . '@example.test', 'admin' => true]);
        Craft::$app->getElements()->saveElement($user, false);

        return $user;
    }

    /**
     * Straight from defineSources(): Craft's sources() remembers its first answer for the rest of the request.
     *
     * @param class-string<Order|Product> $class
     * @return array<int, mixed>
     */
    private function sourcesFor(string $class, string $context = 'index'): array
    {
        return (new ReflectionMethod($class, 'defineSources'))->invoke(null, $context);
    }

    public function testTheElementIndexListsNothingWithoutTheRightPermission(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // The index serves any source it is given to anyone who can reach the control panel, so there must be none.
        $this->assertSame([], $this->sourcesFor(Order::class));
        $this->assertSame([], $this->sourcesFor(Product::class));

        Craft::$app->getUser()->setIdentity($this->admin());

        $this->assertNotEmpty($this->sourcesFor(Order::class));
        $this->assertNotEmpty($this->sourcesFor(Product::class));
    }

    public function testTheProductPickerIsOpenToEditorsButTheIndexIsNot(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // A Products field opens the modal, and an editor filling in a page may not manage products.
        $this->assertNotEmpty($this->sourcesFor(Product::class, 'modal'));
        $this->assertSame([], $this->sourcesFor(Product::class, 'index'));
        $this->assertSame([], $this->sourcesFor(Order::class, 'modal'), 'orders stay closed in every context');
    }

    public function testOrdersAreNeverExportedInBulk(): void
    {
        Craft::$app->getUser()->setIdentity($this->admin());

        $this->assertSame([], Order::exporters('*'));
    }

    public function testOrdersAreNeverSavedThroughCraftsGenericElementSave(): void
    {
        // elements/save would accept any attribute with a validation rule, the money and payment state included.
        $this->assertFalse((new Order())->canSave($this->admin()));
    }

    public function testProductDraftsNeedTheProductPermission(): void
    {
        $product = $this->makeProduct();

        $this->assertFalse($product->canCreateDrafts(new User()));
        $this->assertTrue($product->canCreateDrafts($this->admin()));
    }
}
