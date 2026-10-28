<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\db\Table;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;

/**
 * The settings kept in the database rather than project config (Settings::DATABASE_SETTINGS), so each
 * environment has its own and production can change them.
 */
class StoredSettings extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $db = Craft::$app->getDb();

        // Missing between an upgrade and its migration. Checked first because a failed query would abort a
        // PostgreSQL transaction.
        if (!$db->tableExists(Table::SETTINGS)) {
            return [];
        }

        $values = [];

        foreach ((new Query())->select(['name', 'value'])->from(Table::SETTINGS)->all($db) as $row) {
            $values[(string) $row['name']] = $row['value'] === null ? null : Json::decode((string) $row['value']);
        }

        return $values;
    }

    /**
     * Blank values are stored too, so clearing a field can't bring back an older value from project config.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $name => $value) {
            $encoded = Json::encode($value);
            Db::upsert(Table::SETTINGS, ['name' => $name, 'value' => $encoded], ['value' => $encoded]);
        }
    }
}
