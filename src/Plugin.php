<?php

declare(strict_types=1);

namespace coyshdigital\smallchange;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\fieldlayoutelements\DonationSettingsField;
use coyshdigital\smallchange\fieldlayoutelements\ProductPriceField;
use coyshdigital\smallchange\fieldlayoutelements\ProductSkuField;
use coyshdigital\smallchange\fields\Products;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\services\Carts;
use coyshdigital\smallchange\services\Checkout;
use coyshdigital\smallchange\services\Customers;
use coyshdigital\smallchange\services\Discounts;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\GiftAid;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\services\Payments;
use coyshdigital\smallchange\services\Privacy;
use coyshdigital\smallchange\services\ProductTypes;
use coyshdigital\smallchange\services\ShippingMethods;
use coyshdigital\smallchange\services\State;
use coyshdigital\smallchange\services\StoredSettings;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\services\StripeProducts;
use coyshdigital\smallchange\services\Subscriptions;
use coyshdigital\smallchange\services\Templates;
use coyshdigital\smallchange\services\Totals;
use coyshdigital\smallchange\services\Webhooks;
use coyshdigital\smallchange\utilities\StripeUtility;
use coyshdigital\smallchange\variables\SmallChangeVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\TitleField;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * Small Change: a deliberately small, Stripe-only shop and donations tool.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Stripe $stripe
 * @property-read ProductTypes $productTypes
 * @property-read Totals $totals
 * @property-read Orders $orders
 * @property-read Checkout $checkout
 * @property-read Customers $customers
 * @property-read Payments $payments
 * @property-read Privacy $privacy
 * @property-read Webhooks $webhooks
 * @property-read Emails $emails
 * @property-read GiftAid $giftAid
 * @property-read Discounts $discounts
 * @property-read ShippingMethods $shippingMethods
 * @property-read Carts $carts
 * @property-read Subscriptions $subscriptions
 * @property-read StripeProducts $stripeProducts
 * @property-read State $state
 * @property-read StoredSettings $storedSettings
 * @property-read Templates $templates
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MANAGE_PRODUCTS = 'smallChange-manageProducts';
    public const PERMISSION_VIEW_ORDERS = 'smallChange-viewOrders';
    public const PERMISSION_MANAGE_ORDERS = 'smallChange-manageOrders';
    public const PERMISSION_REFUND_PAYMENTS = 'smallChange-refundPayments';
    public const PERMISSION_MANAGE_SUBSCRIPTIONS = 'smallChange-manageSubscriptions';
    public const PERMISSION_MANAGE_DISCOUNTS = 'smallChange-manageDiscounts';
    public const PERMISSION_MANAGE_SHIPPING = 'smallChange-manageShipping';
    public const PERMISSION_ANONYMISE_CUSTOMERS = 'smallChange-anonymiseCustomers';

    public string $schemaVersion = '1.0.2';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public bool $hasReadOnlyCpSettings = true;

    private bool $storedSettingsApplied = false;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'stripe' => Stripe::class,
                'productTypes' => ProductTypes::class,
                'totals' => Totals::class,
                'orders' => Orders::class,
                'checkout' => Checkout::class,
                'customers' => Customers::class,
                'payments' => Payments::class,
                'webhooks' => Webhooks::class,
                'emails' => Emails::class,
                'giftAid' => GiftAid::class,
                'discounts' => Discounts::class,
                'shippingMethods' => ShippingMethods::class,
                'carts' => Carts::class,
                'subscriptions' => Subscriptions::class,
                'stripeProducts' => StripeProducts::class,
                'state' => State::class,
                'storedSettings' => StoredSettings::class,
                'privacy' => Privacy::class,
                'templates' => Templates::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'coyshdigital\\smallchange\\console\\controllers';
        }

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerUtilities();
        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerProjectConfig();
        $this->registerNativeFields();
        $this->registerVariable();
        $this->registerGc();
        $this->registerEmailTemplateRoot();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('small-change', 'Small Change');
        $item['subnav'] = [
            'overview' => [
                'label' => Craft::t('small-change', 'Overview'),
                'url' => 'small-change',
            ],
        ];

        if ($user->checkPermission(self::PERMISSION_VIEW_ORDERS)) {
            $item['subnav']['orders'] = [
                'label' => Craft::t('small-change', 'Orders'),
                'url' => 'small-change/orders',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_SUBSCRIPTIONS)) {
            $item['subnav']['subscriptions'] = [
                'label' => Craft::t('small-change', 'Recurring'),
                'url' => 'small-change/subscriptions',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            $item['subnav']['products'] = [
                'label' => Craft::t('small-change', 'Products'),
                'url' => 'small-change/products',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_DISCOUNTS)) {
            $item['subnav']['discounts'] = [
                'label' => Craft::t('small-change', 'Discount codes'),
                'url' => 'small-change/discounts',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_SHIPPING)) {
            $item['subnav']['shipping'] = [
                'label' => Craft::t('small-change', 'Shipping'),
                'url' => 'small-change/shipping',
            ];
        }

        // Admins can look at these even where changes are disallowed; each page then shows itself read-only.
        if ($user->getIsAdmin()) {
            $item['subnav']['productTypes'] = [
                'label' => Craft::t('small-change', 'Product types'),
                'url' => 'small-change/settings/product-types',
            ];
            $item['subnav']['checkoutFields'] = [
                'label' => Craft::t('small-change', 'Checkout fields'),
                'url' => 'small-change/settings/order-fields',
            ];
            $item['subnav']['donationFields'] = [
                'label' => Craft::t('small-change', 'Donation fields'),
                'url' => 'small-change/settings/donation-fields',
            ];
            $item['subnav']['settings'] = [
                'label' => Craft::t('small-change', 'Settings'),
                'url' => 'small-change/settings',
            ];
        }

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect('small-change/settings');
    }

    /**
     * Product types and the order field layouts live in project config. Removing them here, while the tables still
     * exist, lets the usual handlers delete the products and layouts, and leaves nothing for a reinstall to trip on.
     */
    protected function beforeUninstall(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ([ProductTypes::CONFIG_PATH, Orders::FIELD_LAYOUT_PATH, Orders::DONATION_FIELD_LAYOUT_PATH] as $path) {
            if ($projectConfig->get($path) !== null) {
                $projectConfig->remove($path, 'Uninstall Small Change');
            }
        }

        parent::beforeUninstall();
    }

    public function getSettings(): ?Model
    {
        $settings = parent::getSettings();

        if ($settings instanceof Settings && !$this->storedSettingsApplied && $this->isInstalled) {
            $this->storedSettingsApplied = true;
            $settings->setAttributes(self::storedSettingsToApply(
                $this->storedSettings->all(),
                array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle)),
            ), false);
        }

        return $settings;
    }

    /**
     * Craft sets the project config values while it builds the plugin, which would land on top of the database
     * ones, so they are laid over again the next time the settings are read.
     *
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): void
    {
        // Skips the query while Craft is still building the plugin.
        $this->storedSettingsApplied = true;
        parent::setSettings($settings);
        $this->storedSettingsApplied = false;
    }

    /**
     * The database values to lay over project config: only the settings that live there, and none the config
     * file sets, because the file wins.
     *
     * @param array<string, mixed> $stored
     * @param string[] $overridden
     * @return array<string, mixed>
     */
    public static function storedSettingsToApply(array $stored, array $overridden): array
    {
        return array_diff_key(array_intersect_key($stored, array_flip(Settings::DATABASE_SETTINGS)), array_flip($overridden));
    }

    protected function createSettingsModel(): ?Model
    {
        // Through the container so a test can preset values for every app instance it creates.
        return Craft::createObject(Settings::class);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['small-change'] = 'small-change/overview/index';
                $event->rules['small-change/settings/templates'] = 'small-change/templates/index';
                $event->rules['small-change/settings'] = 'small-change/settings/index';
                $event->rules['small-change/settings/<tab:(?:general|stripe|checkout|emails|giftaid|privacy)>'] = 'small-change/settings/index';
                $event->rules['small-change/settings/emails/<key:\w+>'] = 'small-change/settings/edit-email';
                $event->rules['small-change/settings/emails/<key:\w+>/preview'] = 'small-change/settings/preview-email';
                $event->rules['small-change/settings/product-types'] = 'small-change/product-types/index';
                $event->rules['small-change/settings/product-types/new'] = 'small-change/product-types/edit';
                $event->rules['small-change/settings/product-types/<productTypeId:\d+>'] = 'small-change/product-types/edit';
                $event->rules['small-change/orders'] = 'small-change/orders/index';
                $event->rules['small-change/orders/<orderId:\d+>'] = 'small-change/orders/view';
                $event->rules['small-change/subscriptions'] = 'small-change/subscriptions/index';
                $event->rules['small-change/subscriptions/<subscriptionId:\d+>'] = 'small-change/subscriptions/view';
                $event->rules['small-change/settings/order-fields'] = 'small-change/settings/order-fields';
                $event->rules['small-change/settings/donation-fields'] = 'small-change/settings/donation-fields';
                $event->rules['small-change/discounts'] = 'small-change/discounts/index';
                $event->rules['small-change/discounts/new'] = 'small-change/discounts/edit';
                $event->rules['small-change/discounts/<discountId:\d+>'] = 'small-change/discounts/edit';
                $event->rules['small-change/shipping'] = 'small-change/shipping-methods/index';
                $event->rules['small-change/shipping/new'] = 'small-change/shipping-methods/edit';
                $event->rules['small-change/shipping/<methodId:\d+>'] = 'small-change/shipping-methods/edit';
                $event->rules['small-change/products'] = 'small-change/products/index';
                $event->rules['small-change/products/<typeHandle:{handle}>/<elementId:\d+><slug:(?:-{slug})?>'] = 'elements/edit';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('small-change', 'Small Change'),
                    'permissions' => [
                        self::PERMISSION_MANAGE_PRODUCTS => [
                            'label' => Craft::t('small-change', 'Manage products'),
                        ],
                        self::PERMISSION_VIEW_ORDERS => [
                            'label' => Craft::t('small-change', 'View orders and donations'),
                            'nested' => [
                                self::PERMISSION_MANAGE_ORDERS => [
                                    'label' => Craft::t('small-change', 'Manage orders'),
                                ],
                                self::PERMISSION_REFUND_PAYMENTS => [
                                    'label' => Craft::t('small-change', 'Refund payments'),
                                ],
                                self::PERMISSION_ANONYMISE_CUSTOMERS => [
                                    'label' => Craft::t('small-change', 'Anonymise customers'),
                                ],
                            ],
                        ],
                        self::PERMISSION_MANAGE_SUBSCRIPTIONS => [
                            'label' => Craft::t('small-change', 'Manage recurring donations'),
                        ],
                        self::PERMISSION_MANAGE_DISCOUNTS => [
                            'label' => Craft::t('small-change', 'Manage discount codes'),
                        ],
                        self::PERMISSION_MANAGE_SHIPPING => [
                            'label' => Craft::t('small-change', 'Manage shipping methods'),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Emails are rendered in site template mode so a site can override them, and an override should be able to
     * extend the shipped layout rather than copying it. Only the emails folder is exposed.
     */
    private function registerEmailTemplateRoot(): void
    {
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, static function(RegisterTemplateRootsEvent $event): void {
            $event->roots['small-change/emails'] = __DIR__ . '/templates/emails';
        });
    }

    private function registerGc(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, static function(): void {
            self::getInstance()->privacy->runHousekeeping();
        });
    }

    private function registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = StripeUtility::class;
            },
        );
    }

    private function registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = Product::class;
                $event->types[] = Order::class;
            },
        );
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = Products::class;
            },
        );
    }

    private function registerProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $path = ProductTypes::CONFIG_PATH . '.{uid}';

        foreach ([Orders::FIELD_LAYOUT_PATH, Orders::DONATION_FIELD_LAYOUT_PATH] as $layoutPath) {
            $projectConfig
                ->onAdd($layoutPath . '.{uid}', fn($event) => $this->orders->handleChangedFieldLayout($event))
                ->onUpdate($layoutPath . '.{uid}', fn($event) => $this->orders->handleChangedFieldLayout($event))
                ->onRemove($layoutPath . '.{uid}', fn($event) => $this->orders->handleDeletedFieldLayout($event));
        }

        $projectConfig
            ->onAdd($path, fn($event) => $this->productTypes->handleChangedProductType($event))
            ->onUpdate($path, fn($event) => $this->productTypes->handleChangedProductType($event))
            ->onRemove($path, fn($event) => $this->productTypes->handleDeletedProductType($event));

        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            function(RebuildConfigEvent $event): void {
                $this->productTypes->rebuildProjectConfig($event);
                $this->orders->rebuildProjectConfig($event);
            },
        );
    }

    /**
     * Offers the title and donation settings in the product type's layout designer.
     */
    private function registerNativeFields(): void
    {
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
            static function(DefineFieldLayoutFieldsEvent $event): void {
                if ($event->sender->type !== Product::class) {
                    return;
                }

                $event->fields[] = TitleField::class;
                $event->fields[] = DonationSettingsField::class;
                $event->fields[] = ProductPriceField::class;
                $event->fields[] = ProductSkuField::class;
            },
        );
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('smallChange', SmallChangeVariable::class);
            },
        );
    }
}
