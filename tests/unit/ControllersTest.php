<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\controllers\CartController;
use coyshdigital\smallchange\controllers\CheckoutController;
use coyshdigital\smallchange\controllers\DonationsController;
use coyshdigital\smallchange\controllers\PortalController;
use coyshdigital\smallchange\controllers\WebhooksController;
use coyshdigital\smallchange\Plugin;
use yii\base\InlineAction;

final class ControllersTest extends Unit
{
    public function testOnlyTheWebhookActionOptsOutOfCsrf(): void
    {
        $plugin = Plugin::getInstance();

        $webhooks = new WebhooksController('webhooks', $plugin);
        $webhooks->beforeAction(new InlineAction('stripe', $webhooks, 'actionStripe'));
        $this->assertFalse($webhooks->enableCsrfValidation, 'Stripe cannot send a CSRF token; the signature authenticates it');

        foreach ([DonationsController::class => 'donations', CheckoutController::class => 'checkout', CartController::class => 'cart'] as $class => $id) {
            $controller = new $class($id, $plugin);
            $this->assertTrue($controller->enableCsrfValidation, "$class must keep CSRF protection");
        }
    }

    public function testOnlyTheIntendedActionsAreAnonymous(): void
    {
        $plugin = Plugin::getInstance();
        $allow = static fn(object $controller): array => array_keys((array) (new \ReflectionProperty($controller, 'allowAnonymous'))->getValue($controller));

        $this->assertSame(['stripe'], $allow(new WebhooksController('webhooks', $plugin)));

        $webhookFlags = (new \ReflectionProperty(WebhooksController::class, 'allowAnonymous'))->getValue(new WebhooksController('webhooks', $plugin))['stripe'];
        $this->assertSame(\craft\web\Controller::ALLOW_ANONYMOUS_LIVE | \craft\web\Controller::ALLOW_ANONYMOUS_OFFLINE, $webhookFlags, 'Stripe keeps delivering while the site is offline');
        $this->assertSame(['start'], $allow(new DonationsController('donations', $plugin)));
        $this->assertSame(['start', 'complete', 'client-secret'], $allow(new CheckoutController('checkout', $plugin)));
        $this->assertSame(['add', 'update', 'remove', 'apply-discount', 'remove-discount'], $allow(new CartController('cart', $plugin)));
        $this->assertSame(['request-link', 'open'], $allow(new PortalController('portal', $plugin)));
    }

    public function testEveryOtherControllerRequiresALogin(): void
    {
        $plugin = Plugin::getInstance();
        $anonymous = ['webhooks', 'donations', 'checkout', 'cart', 'portal'];

        foreach (glob(dirname(__DIR__, 2) . '/src/controllers/*Controller.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $id = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', substr($name, 0, -10)));

            if (in_array($id, $anonymous, true)) {
                continue;
            }

            $class = 'coyshdigital\\smallchange\\controllers\\' . $name;
            $controller = new $class($id, $plugin);
            $allow = (new \ReflectionProperty($controller, 'allowAnonymous'))->getValue($controller);

            // Craft's default is "never" (0); an empty list means the same.
            $this->assertTrue($allow === 0 || $allow === [], "$name must not allow anonymous access");
        }
    }
}
