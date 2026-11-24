<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\migrations;

use coyshdigital\smallchange\db\Table;
use craft\db\Migration;

/**
 * Adds what a product needs to be sold as a subscription (how often it bills and how many payments it takes), and
 * what a subscription needs to end itself after that many. Existing rows get null: a one-off sale, and a
 * subscription that runs until cancelled. A fresh install already has the columns, so each is checked first.
 */
class m261124_100000_add_subscription_products extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::PRODUCTS, 'subscriptionInterval')) {
            // Yii accepts the builder; its type hint only says string.
            $this->addColumn(Table::PRODUCTS, 'subscriptionInterval', (string) $this->string(5)->after('recurringIntervals'));
        }

        if (!$this->db->columnExists(Table::PRODUCTS, 'subscriptionPayments')) {
            $this->addColumn(Table::PRODUCTS, 'subscriptionPayments', (string) $this->integer()->after('subscriptionInterval'));
        }

        if (!$this->db->columnExists(Table::SUBSCRIPTIONS, 'maxPayments')) {
            $this->addColumn(Table::SUBSCRIPTIONS, 'maxPayments', (string) $this->integer()->after('giftAidDeclared'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261124_100000_add_subscription_products cannot be reverted.\n";

        return false;
    }
}
