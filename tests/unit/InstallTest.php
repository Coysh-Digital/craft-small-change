<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\migrations\Install;
use coyshdigital\smallchange\migrations\m261016_120000_add_checkout_attempt;
use coyshdigital\smallchange\migrations\m261028_120000_move_email_settings_to_db;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use ReflectionClass;
use ReflectionMethod;

final class InstallTest extends Unit
{
    use Fixtures;

    public function testUninstallingTakesTheElementsAndLayoutsWithIt(): void
    {
        $this->useFakes();
        $order = $this->makeOrder(null, '25', true);
        $addressId = $order->billingAddressId;
        $productId = $order->getLineItems()[0]->purchasableId;
        $this->assertNotNull($addressId);

        // What Craft does on uninstall: the plugin's hook, then the migration down.
        (new ReflectionMethod(Plugin::class, 'beforeUninstall'))->invoke(Plugin::getInstance());
        $migration = new Install();
        $this->assertTrue($migration->safeDown());

        $elements = (new Query())->from(CraftTable::ELEMENTS);
        $this->assertFalse((clone $elements)->where(['id' => [$order->id, $productId, $addressId]])->exists(), 'the order, its address and the product are gone');
        $this->assertFalse((clone $elements)->where(['type' => [Order::class, Product::class]])->exists());
        $this->assertFalse((new Query())->from(CraftTable::FIELDLAYOUTS)->where(['type' => [Order::class, Product::class], 'dateDeleted' => null])->exists());
        foreach (['smallChange.productTypes', 'smallChange.orders.fieldLayouts', 'smallChange.orders.donationFieldLayouts'] as $path) {
            $this->assertNull(Craft::$app->getProjectConfig()->get($path), "$path must not linger for a reinstall to trip on");
        }
        $this->assertFalse(Craft::$app->getDb()->tableExists(Table::ORDERS));

        // And back, for the tests that follow.
        Craft::$app->getDb()->getSchema()->refresh();
        $this->assertTrue($migration->safeUp());
        Craft::$app->getDb()->getSchema()->refresh();
        $this->assertTrue(Craft::$app->getDb()->tableExists(Table::ORDERS));
    }

    public function testAHalfBuiltInstallIsReportedNotSkipped(): void
    {
        $db = Craft::$app->getDb();
        $migration = new Install();
        $this->assertTrue($migration->safeUp(), 'a full set of tables is the replay case and is left alone');

        $db->createCommand()->dropTable(Table::STATE)->execute();
        $db->getSchema()->refresh();

        try {
            $migration->safeUp();
            $this->fail('A database with some of the tables must not pass as installed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only partly installed', $e->getMessage());
        } finally {
            // Back to a whole install for the tests that follow.
            $this->assertTrue($migration->safeDown());
            $db->getSchema()->refresh();
            $this->assertTrue($migration->safeUp());
            $db->getSchema()->refresh();
        }

        $this->assertTrue($db->tableExists(Table::STATE));
    }

    public function testAnInstallFromBeforeTheSettingsTableIsAReplayNotAHalfBuild(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->dropTable(Table::SETTINGS)->execute();
        $db->getSchema()->refresh();

        try {
            $this->assertTrue((new Install())->safeUp());
            $db->getSchema()->refresh();
            $this->assertTrue($db->tableExists(Table::SETTINGS), 'the replay adds the table a later version brought in');
        } finally {
            if (!$db->tableExists(Table::SETTINGS)) {
                (new m261028_120000_move_email_settings_to_db())->safeUp();
                $db->getSchema()->refresh();
            }
        }
    }

    public function testTheEmailSettingsMigrationCreatesTheTableAndRunsTwice(): void
    {
        $db = Craft::$app->getDb();
        $migration = new m261028_120000_move_email_settings_to_db();
        $db->createCommand()->dropTable(Table::SETTINGS)->execute();
        $db->getSchema()->refresh();

        try {
            $this->assertTrue($migration->safeUp());
            $db->getSchema()->refresh();
            $this->assertTrue($db->tableExists(Table::SETTINGS));
            $this->assertTrue($migration->safeUp(), 'running it again must change nothing');
        } finally {
            if (!$db->tableExists(Table::SETTINGS)) {
                $migration->safeUp();
            }

            $db->getSchema()->refresh();
        }
    }

    public function testTheEmailSettingsMigrationCopiesWhatIsSetAndNotYetInTheDatabase(): void
    {
        $stored = [
            'currency' => 'GBP',
            'emailFromName' => 'Small Church',
            'emailFromAddress' => '',
            'adminEmail' => 'office@example.test',
            'emailContent' => ['receipt' => ['heading' => 'Thank you']],
            'emailReplyTo' => 'replies@example.test',
        ];

        $this->assertSame([
            'emailFromName' => 'Small Church',
            'adminEmail' => 'office@example.test',
            'emailContent' => ['receipt' => ['heading' => 'Thank you']],
        ], m261028_120000_move_email_settings_to_db::valuesToCopy($stored, ['emailReplyTo']), 'a blank stays out, a row already there wins, and other settings stay in project config');
        $this->assertSame([], m261028_120000_move_email_settings_to_db::valuesToCopy(['emailContent' => []], []));
    }

    public function testTheCheckoutAttemptMigrationFillsTheGapAndRunsTwice(): void
    {
        $db = Craft::$app->getDb();
        $migration = new m261016_120000_add_checkout_attempt();

        $this->assertTrue($migration->safeUp(), 'a fresh install already has the column, so this is the no-op case');

        $db->createCommand()->dropColumn(Table::ORDERS, 'checkoutAttempt')->execute();
        $db->getSchema()->refresh();
        $this->assertFalse($db->columnExists(Table::ORDERS, 'checkoutAttempt'));

        try {
            $this->assertTrue($migration->safeUp());
            $db->getSchema()->refresh();
            $this->assertTrue($db->columnExists(Table::ORDERS, 'checkoutAttempt'));
            $this->assertTrue($migration->safeUp(), 'running it again must change nothing');
        } finally {
            if (!$db->columnExists(Table::ORDERS, 'checkoutAttempt')) {
                $migration->safeUp();
            }

            $db->getSchema()->refresh();
        }

        $this->assertSame(0, (int) Craft::$app->getDb()->getSchema()->getTableSchema(Table::ORDERS)?->getColumn('checkoutAttempt')?->defaultValue);
    }

    public function testEveryTableExists(): void
    {
        $db = Craft::$app->getDb();
        $tables = (new ReflectionClass(Table::class))->getConstants();

        $this->assertCount(16, $tables);

        foreach ($tables as $name => $table) {
            $this->assertTrue($db->tableExists($table), "{$name} ({$table}) was not created by the install migration");
        }
    }

    public function testOrderNumbersAreUniqueAndMoneyColumnsAreBigInts(): void
    {
        $schema = Craft::$app->getDb()->getSchema();
        $orders = $schema->getTableSchema(Table::ORDERS);

        $this->assertNotNull($orders);

        foreach (['itemSubtotal', 'discountAmount', 'shippingAmount', 'total', 'totalPaid', 'totalRefunded', 'giftAidEligibleAmount'] as $column) {
            // PostgreSQL reports bigint as int8.
            $this->assertContains($orders->getColumn($column)?->dbType, ['bigint', 'int8'], "{$column} must be a bigint");
        }

        $uniqueOnNumber = false;
        foreach ($schema->findUniqueIndexes($orders) as $columns) {
            $uniqueOnNumber = $uniqueOnNumber || $columns === ['number'];
        }

        $this->assertTrue($uniqueOnNumber, 'orders.number needs a unique index');
    }

    public function testGiftAidStoresOnlyTheAnswerAndTheAmount(): void
    {
        $schema = Craft::$app->getDb()->getSchema();
        $columns = array_keys($schema->getTableSchema(Table::ORDERS)?->columns ?? []);

        // Gift Aid is an option, not a records system: no declaration text, name or date is stored.
        $giftAid = array_values(array_filter($columns, static fn(string $name): bool => stripos($name, 'giftaid') !== false));
        sort($giftAid);

        $this->assertSame(['giftAidDeclared', 'giftAidEligibleAmount'], $giftAid);
    }
}
