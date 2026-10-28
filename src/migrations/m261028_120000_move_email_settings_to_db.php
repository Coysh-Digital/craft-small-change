<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\migrations;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\models\Settings;
use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

/**
 * Moves the email sender and wording out of project config into the database, so production can change them.
 *
 * It reads the stored project config rather than the YAML, so values are kept even when the deployed YAML has
 * already dropped them. Project config itself is left alone; the next settings save cleans it up.
 */
class m261028_120000_move_email_settings_to_db extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::SETTINGS)) {
            $this->createTable(Table::SETTINGS, [
                'id' => $this->primaryKey(),
                'name' => $this->string(100)->notNull(),
                'value' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, Table::SETTINGS, ['name'], true);
        }

        $stored = Craft::$app->getProjectConfig()->get('plugins.small-change.settings');
        $existing = (new Query())->select('name')->from(Table::SETTINGS)->column($this->db);

        // Project config keeps the wording packed to remember its order.
        $stored = is_array($stored) ? ProjectConfigHelper::unpackAssociativeArrays($stored) : [];

        foreach (self::valuesToCopy($stored, $existing) as $name => $value) {
            $this->insert(Table::SETTINGS, ['name' => $name, 'value' => Json::encode($value)]);
        }

        return true;
    }

    /**
     * The settings worth copying: set to something, and not already in the database, which would be newer.
     *
     * @param array<string, mixed> $stored
     * @param string[] $existing
     * @return array<string, mixed>
     */
    public static function valuesToCopy(array $stored, array $existing): array
    {
        $values = [];

        foreach (Settings::DATABASE_SETTINGS as $name) {
            $value = $stored[$name] ?? null;

            if ($value !== null && $value !== '' && $value !== [] && !in_array($name, $existing, true)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    public function safeDown(): bool
    {
        echo "m261028_120000_move_email_settings_to_db cannot be reverted.\n";

        return false;
    }
}
