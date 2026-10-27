<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\migrations;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;

/**
 * Creates every table the plugin uses.
 *
 * Money columns are bigint minor units. Columns holding a fixed set of values
 * are plain strings, validated by the models, because Craft supports MySQL and
 * PostgreSQL and enums differ between them.
 */
class Install extends Migration
{
    /**
     * Creation order matters only for the foreign keys, and those are added at
     * the end, so the cycle between orders, customers and subscriptions is safe.
     */
    private const TABLES = [
        Table::PRODUCT_TYPES,
        Table::PRODUCT_TYPES_SITES,
        Table::PRODUCTS,
        Table::STRIPE_PRODUCTS,
        Table::CUSTOMERS,
        Table::DISCOUNTS,
        Table::SHIPPING_METHODS,
        Table::ORDERS,
        Table::SUBSCRIPTIONS,
        Table::LINE_ITEMS,
        Table::PAYMENTS,
        Table::REFUNDS,
        Table::DISCOUNT_RESERVATIONS,
        Table::WEBHOOK_EVENTS,
        Table::STATE,
    ];

    public function safeUp(): bool
    {
        $db = Craft::$app->getDb();
        $present = array_values(array_filter(self::TABLES, static fn(string $table): bool => $db->tableExists($table)));

        // A project-config sync can replay the install on a database that already has the tables.
        if (count($present) === count(self::TABLES)) {
            return true;
        }

        // An install that stopped part-way. Carrying on would leave a schema that looks installed but isn't.
        if ($present) {
            throw new \RuntimeException('Small Change is only partly installed: ' . implode(', ', $present) . ' already exist. Drop its tables and install it again.');
        }

        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * Takes the plugin's elements, their addresses and field layouts with the tables. Otherwise Craft would keep
     * rows for element types it can no longer load. The project config goes in Plugin::beforeUninstall().
     */
    public function safeDown(): bool
    {
        if ($this->db->tableExists(Table::ORDERS)) {
            $rows = (new Query())->select(['shippingAddressId', 'billingAddressId'])->from(Table::ORDERS)->all($this->db);
            $addressIds = array_values(array_unique(array_filter(array_merge(
                array_column($rows, 'shippingAddressId'),
                array_column($rows, 'billingAddressId'),
            ))));

            if ($addressIds) {
                $this->delete(CraftTable::ELEMENTS, ['id' => $addressIds]);
            }
        }

        // Orders first and on their own: removing a product sets its line items' purchasable to null, and in one
        // statement PostgreSQL then rechecks line items whose order the same statement has just removed.
        $this->delete(CraftTable::ELEMENTS, ['type' => Order::class]);
        $this->delete(CraftTable::ELEMENTS, ['type' => Product::class]);

        foreach (array_reverse(self::TABLES) as $table) {
            $this->dropAllForeignKeysToTable($table);
        }

        foreach (array_reverse(self::TABLES) as $table) {
            $this->dropTableIfExists($table);
        }

        $fields = Craft::$app->getFields();
        $fields->deleteLayoutsByType(Order::class);
        $fields->deleteLayoutsByType(Product::class);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::PRODUCT_TYPES, $this->withMeta([
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'kind' => $this->string(20)->notNull(),
            'fieldLayoutId' => $this->integer(),
            'skuRequired' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
        ]));

        $this->createTable(Table::PRODUCT_TYPES_SITES, $this->withMeta([
            'id' => $this->primaryKey(),
            'productTypeId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'hasUrls' => $this->boolean()->notNull()->defaultValue(true),
            'uriFormat' => $this->text(),
            'template' => $this->string(500),
        ]));

        $this->createTable(Table::PRODUCTS, [
            'id' => $this->integer()->notNull(),
            'typeId' => $this->integer()->notNull(),
            // Null for donation-kind products, which take an amount from the donor.
            'price' => $this->bigInteger(),
            'sku' => $this->string(100),
            'minimumAmount' => $this->bigInteger(),
            'suggestedAmounts' => $this->json(),
            'allowCustomAmount' => $this->boolean()->notNull()->defaultValue(true),
            'allowRecurring' => $this->boolean()->notNull()->defaultValue(false),
            'recurringIntervals' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(Table::STRIPE_PRODUCTS, $this->withMeta([
            'id' => $this->primaryKey(),
            'productId' => $this->integer()->notNull(),
            'stripeMode' => $this->string(4)->notNull(),
            'stripeProductId' => $this->string()->notNull(),
        ]));

        $this->createTable(Table::CUSTOMERS, $this->withMeta([
            'id' => $this->primaryKey(),
            'email' => $this->string()->notNull(),
            'userId' => $this->integer(),
            'stripeMode' => $this->string(4)->notNull(),
            'stripeCustomerId' => $this->string(),
            'name' => $this->string(),
            'dateAnonymised' => $this->dateTime(),
        ]));

        $this->createTable(Table::DISCOUNTS, $this->withMeta([
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            // Stored upper-case so the unique index is case-insensitive on both databases.
            'code' => $this->string(64)->notNull(),
            'type' => $this->string(10)->notNull(),
            // Basis points: 1250 is 12.5%.
            'percent' => $this->integer()->unsigned(),
            'amount' => $this->bigInteger(),
            'minSubtotal' => $this->bigInteger(),
            'maxUses' => $this->integer()->unsigned(),
            'uses' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateFrom' => $this->dateTime(),
            'dateTo' => $this->dateTime(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
        ]));

        $this->createTable(Table::SHIPPING_METHODS, $this->withMeta([
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'price' => $this->bigInteger()->notNull()->defaultValue(0),
            // Null means every country.
            'countryCodes' => $this->json(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
        ]));

        $this->createTable(Table::ORDERS, [
            'id' => $this->integer()->notNull(),
            'number' => $this->string(10)->notNull(),
            // The site the customer used. Null once that site is deleted; the order outlives it.
            'orderSiteId' => $this->integer(),
            'kind' => $this->string(10)->notNull()->defaultValue('oneOff'),
            'state' => $this->string(10)->notNull()->defaultValue('cart'),
            'paymentStatus' => $this->string(20)->notNull()->defaultValue('unpaid'),
            'email' => $this->string(),
            'customerId' => $this->integer(),
            'userId' => $this->integer(),
            'currency' => $this->char(3)->notNull(),
            'itemSubtotal' => $this->bigInteger()->notNull()->defaultValue(0),
            'discountAmount' => $this->bigInteger()->notNull()->defaultValue(0),
            'shippingAmount' => $this->bigInteger()->notNull()->defaultValue(0),
            'total' => $this->bigInteger()->notNull()->defaultValue(0),
            'totalPaid' => $this->bigInteger()->notNull()->defaultValue(0),
            'totalRefunded' => $this->bigInteger()->notNull()->defaultValue(0),
            // Null means the option was not offered; true or false is the donor's answer.
            'giftAidDeclared' => $this->boolean(),
            'giftAidEligibleAmount' => $this->bigInteger()->notNull()->defaultValue(0),
            'discountId' => $this->integer(),
            'discountCode' => $this->string(64),
            'shippingMethodId' => $this->integer(),
            'shippingMethodName' => $this->string(),
            'shippingAddressId' => $this->integer(),
            'billingAddressId' => $this->integer(),
            'subscriptionId' => $this->integer(),
            'stripeMode' => $this->string(4),
            'stripeCheckoutSessionId' => $this->string(),
            'stripePaymentIntentId' => $this->string(),
            // Counts the times a started payment was pulled back, so each attempt asks Stripe for a new session.
            'checkoutAttempt' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'returnUrl' => $this->text(),
            'customerNote' => $this->text(),
            'adminNote' => $this->text(),
            'lastIp' => $this->string(45),
            'dateCompleted' => $this->dateTime(),
            'dateFulfilled' => $this->dateTime(),
            'dateAnonymised' => $this->dateTime(),
            'dateLastActivity' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(Table::SUBSCRIPTIONS, $this->withMeta([
            'id' => $this->primaryKey(),
            'orderId' => $this->integer(),
            'customerId' => $this->integer(),
            'productId' => $this->integer(),
            'stripeMode' => $this->string(4)->notNull(),
            'stripeSubscriptionId' => $this->string()->notNull(),
            'status' => $this->string(20)->notNull(),
            'interval' => $this->string(5)->notNull(),
            'amount' => $this->bigInteger()->notNull(),
            'currency' => $this->char(3)->notNull(),
            'currentPeriodEnd' => $this->dateTime(),
            'cancelAtPeriodEnd' => $this->boolean()->notNull()->defaultValue(false),
            'canceledAt' => $this->dateTime(),
            'endedAt' => $this->dateTime(),
            'giftAidDeclared' => $this->boolean()->notNull()->defaultValue(false),
            // Webhooks arrive out of order; handlers skip events older than this.
            'lastStripeEventAt' => $this->dateTime(),
            'lastFailureAt' => $this->dateTime(),
        ]));

        $this->createTable(Table::LINE_ITEMS, $this->withMeta([
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'purchasableId' => $this->integer(),
            'kind' => $this->string(20)->notNull(),
            'description' => $this->string()->notNull(),
            'sku' => $this->string(100),
            'unitPrice' => $this->bigInteger()->notNull(),
            'qty' => $this->integer()->unsigned()->notNull()->defaultValue(1),
            'subtotal' => $this->bigInteger()->notNull(),
            'recurringInterval' => $this->string(5),
            'isGiftAidEligible' => $this->boolean()->notNull()->defaultValue(false),
            'snapshot' => $this->json(),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
        ]));

        $this->createTable(Table::PAYMENTS, $this->withMeta([
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'subscriptionId' => $this->integer(),
            'customerId' => $this->integer(),
            'stripeMode' => $this->string(4)->notNull(),
            'stripePaymentIntentId' => $this->string(),
            'stripeChargeId' => $this->string(),
            'stripeInvoiceId' => $this->string(),
            'stripeCheckoutSessionId' => $this->string(),
            'amount' => $this->bigInteger()->notNull(),
            'amountRefunded' => $this->bigInteger()->notNull()->defaultValue(0),
            'currency' => $this->char(3)->notNull(),
            'status' => $this->string(20)->notNull(),
            'failureCode' => $this->string(),
            'failureMessage' => $this->text(),
            'giftAidDeclared' => $this->boolean()->notNull()->defaultValue(false),
            'giftAidAmount' => $this->bigInteger()->notNull()->defaultValue(0),
            'stripeDisputeId' => $this->string(),
            'disputeStatus' => $this->string(40),
            'paidAt' => $this->dateTime(),
            'receiptSentAt' => $this->dateTime(),
        ]));

        $this->createTable(Table::REFUNDS, $this->withMeta([
            'id' => $this->primaryKey(),
            'paymentId' => $this->integer()->notNull(),
            'stripeMode' => $this->string(4)->notNull(),
            'stripeRefundId' => $this->string()->notNull(),
            'amount' => $this->bigInteger()->notNull(),
            'status' => $this->string(20)->notNull(),
            'reason' => $this->string(40),
            'note' => $this->text(),
            'userId' => $this->integer(),
            'failureReason' => $this->string(),
        ]));

        $this->createTable(Table::DISCOUNT_RESERVATIONS, $this->withMeta([
            'id' => $this->primaryKey(),
            'discountId' => $this->integer()->notNull(),
            'orderId' => $this->integer()->notNull(),
            'expiresAt' => $this->dateTime()->notNull(),
        ]));

        // Small facts that belong to one environment and Stripe mode, such as the webhook endpoint we registered.
        $this->createTable(Table::STATE, $this->withMeta([
            'id' => $this->primaryKey(),
            'name' => $this->string(100)->notNull(),
            'stripeMode' => $this->string(4)->notNull(),
            'value' => $this->text(),
        ]));

        $this->createTable(Table::WEBHOOK_EVENTS, [
            'id' => $this->primaryKey(),
            'stripeEventId' => $this->string()->notNull(),
            'type' => $this->string()->notNull(),
            'stripeMode' => $this->string(4)->notNull(),
            'livemode' => $this->boolean()->notNull(),
            'apiVersion' => $this->string(),
            // data.object.id and data.object.customer, kept as columns so anonymisation can find them.
            'stripeObjectId' => $this->string(),
            'stripeCustomerId' => $this->string(),
            'status' => $this->string(10)->notNull()->defaultValue('received'),
            'attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'error' => $this->text(),
            'payload' => $this->mediumText()->notNull(),
            'redactedAt' => $this->dateTime(),
            'dateReceived' => $this->dateTime()->notNull(),
            'dateProcessed' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::PRODUCT_TYPES, ['handle'], true);
        $this->createIndex(null, Table::PRODUCT_TYPES, ['fieldLayoutId']);
        $this->createIndex(null, Table::PRODUCT_TYPES_SITES, ['productTypeId', 'siteId'], true);
        $this->createIndex(null, Table::PRODUCT_TYPES_SITES, ['siteId']);
        $this->createIndex(null, Table::PRODUCTS, ['typeId']);
        // Not unique: drafts and revisions are rows too and share their product's SKU. The element validates uniqueness.
        $this->createIndex(null, Table::PRODUCTS, ['sku']);
        $this->createIndex(null, Table::STRIPE_PRODUCTS, ['productId', 'stripeMode'], true);

        $this->createIndex(null, Table::CUSTOMERS, ['email', 'stripeMode'], true);
        $this->createIndex(null, Table::CUSTOMERS, ['userId']);
        $this->createIndex(null, Table::CUSTOMERS, ['stripeCustomerId']);

        $this->createIndex(null, Table::DISCOUNTS, ['code'], true);
        $this->createIndex(null, Table::SHIPPING_METHODS, ['handle'], true);

        $this->createIndex(null, Table::ORDERS, ['number'], true);
        $this->createIndex(null, Table::ORDERS, ['state', 'paymentStatus']);
        $this->createIndex(null, Table::ORDERS, ['email']);
        $this->createIndex(null, Table::ORDERS, ['customerId']);
        $this->createIndex(null, Table::ORDERS, ['userId']);
        $this->createIndex(null, Table::ORDERS, ['stripeCheckoutSessionId']);
        $this->createIndex(null, Table::ORDERS, ['stripePaymentIntentId']);
        $this->createIndex(null, Table::ORDERS, ['dateLastActivity']);

        $this->createIndex(null, Table::SUBSCRIPTIONS, ['stripeSubscriptionId'], true);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['orderId']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['customerId']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['status']);

        $this->createIndex(null, Table::LINE_ITEMS, ['orderId']);
        $this->createIndex(null, Table::LINE_ITEMS, ['purchasableId']);

        $this->createIndex(null, Table::PAYMENTS, ['orderId']);
        $this->createIndex(null, Table::PAYMENTS, ['subscriptionId']);
        $this->createIndex(null, Table::PAYMENTS, ['stripeMode', 'stripePaymentIntentId'], true);
        $this->createIndex(null, Table::PAYMENTS, ['stripeMode', 'stripeInvoiceId'], true);
        $this->createIndex(null, Table::PAYMENTS, ['stripeChargeId']);

        $this->createIndex(null, Table::REFUNDS, ['stripeRefundId'], true);
        $this->createIndex(null, Table::REFUNDS, ['paymentId']);

        $this->createIndex(null, Table::DISCOUNT_RESERVATIONS, ['orderId'], true);
        $this->createIndex(null, Table::DISCOUNT_RESERVATIONS, ['discountId', 'expiresAt']);

        $this->createIndex(null, Table::STATE, ['name', 'stripeMode'], true);
        $this->createIndex(null, Table::WEBHOOK_EVENTS, ['stripeEventId'], true);
        $this->createIndex(null, Table::WEBHOOK_EVENTS, ['stripeObjectId']);
        $this->createIndex(null, Table::WEBHOOK_EVENTS, ['stripeCustomerId']);
        $this->createIndex(null, Table::WEBHOOK_EVENTS, ['status']);
        $this->createIndex(null, Table::WEBHOOK_EVENTS, ['dateReceived']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::PRODUCT_TYPES, ['fieldLayoutId'], CraftTable::FIELDLAYOUTS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::PRODUCT_TYPES_SITES, ['productTypeId'], Table::PRODUCT_TYPES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PRODUCT_TYPES_SITES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PRODUCTS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PRODUCTS, ['typeId'], Table::PRODUCT_TYPES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::STRIPE_PRODUCTS, ['productId'], Table::PRODUCTS, ['id'], 'CASCADE');

        $this->addForeignKey(null, Table::CUSTOMERS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::ORDERS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::ORDERS, ['orderSiteId'], CraftTable::SITES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['customerId'], Table::CUSTOMERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['discountId'], Table::DISCOUNTS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['shippingMethodId'], Table::SHIPPING_METHODS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['shippingAddressId'], CraftTable::ADDRESSES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['billingAddressId'], CraftTable::ADDRESSES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['orderId'], Table::ORDERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['customerId'], Table::CUSTOMERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['productId'], Table::PRODUCTS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::LINE_ITEMS, ['orderId'], Table::ORDERS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::LINE_ITEMS, ['purchasableId'], CraftTable::ELEMENTS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::PAYMENTS, ['orderId'], Table::ORDERS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PAYMENTS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::PAYMENTS, ['customerId'], Table::CUSTOMERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::REFUNDS, ['paymentId'], Table::PAYMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::REFUNDS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::DISCOUNT_RESERVATIONS, ['discountId'], Table::DISCOUNTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::DISCOUNT_RESERVATIONS, ['orderId'], Table::ORDERS, ['id'], 'CASCADE');
    }

    /**
     * Appends the audit columns every plain table carries.
     *
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    private function withMeta(array $columns): array
    {
        return $columns + [
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ];
    }
}
