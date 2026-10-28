<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\Plugin;
use Craft;

final class StoredSettingsTest extends Unit
{
    protected function _after(): void
    {
        Craft::$app->getDb()->createCommand()->delete(Table::SETTINGS)->execute();
        // Back to the project config values, without the rows just deleted.
        Plugin::getInstance()->setSettings(['emailFromName' => '', 'adminEmail' => 'admin@example.test', 'emailContent' => []]);
    }

    public function testValuesRoundTripAndABlankIsKept(): void
    {
        $stored = Plugin::getInstance()->storedSettings;

        $stored->save(['emailFromName' => 'Small Church', 'emailContent' => ['receipt' => ['heading' => 'Thank you']]]);
        $stored->save(['emailFromName' => '']);

        // Rows come back in no particular order.
        $all = $stored->all();
        ksort($all);
        $this->assertSame(['emailContent' => ['receipt' => ['heading' => 'Thank you']], 'emailFromName' => ''], $all);
    }

    public function testRowsAreLaidOverProjectConfig(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->storedSettings->save(['emailFromName' => 'From the database', 'adminEmail' => '']);

        // What Craft does with project config while building the plugin.
        $plugin->setSettings(['emailFromName' => 'From project config', 'adminEmail' => 'office@example.test', 'currency' => 'GBP']);

        $this->assertSame('From the database', $plugin->getSettings()->emailFromName);
        $this->assertSame('', $plugin->getSettings()->adminEmail, 'a cleared value must not bring back the old one');
        $this->assertSame('GBP', $plugin->getSettings()->currency);
    }

    public function testOnlyDatabaseSettingsAreAppliedAndTheConfigFileWins(): void
    {
        $applied = Plugin::storedSettingsToApply(
            ['emailFromName' => 'Small Church', 'adminEmail' => 'office@example.test', 'currency' => 'EUR'],
            ['adminEmail'],
        );

        $this->assertSame(['emailFromName' => 'Small Church'], $applied);
    }

    public function testNothingIsReadBeforeTheTableExists(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->renameTable(Table::SETTINGS, '{{%smallchange_settings_moved}}')->execute();
        $db->getSchema()->refresh();

        try {
            $this->assertSame([], Plugin::getInstance()->storedSettings->all());
        } finally {
            $db->createCommand()->renameTable('{{%smallchange_settings_moved}}', Table::SETTINGS)->execute();
            $db->getSchema()->refresh();
        }
    }
}
