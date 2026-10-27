<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\console\controllers;

use coyshdigital\smallchange\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Housekeeping for orders: `php craft small-change/orders/<command>`.
 */
final class OrdersController extends Controller
{
    /**
     * @var string|null for anonymise: the donor's email address
     */
    public ?string $email = null;

    /**
     * @var int|null for purge-carts: remove baskets idle for this many days (default: the Privacy setting)
     */
    public ?int $days = null;

    /**
     * @var bool for anonymise: don't ask for confirmation
     */
    public bool $yes = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'anonymise', 'delete-test-data' => [...$options, 'email', 'yes'],
            'purge-carts' => [...$options, 'days'],
            default => $options,
        };
    }

    /**
     * Deletes baskets nobody has touched for a while, and tidies expired discount reservations.
     */
    public function actionPurgeCarts(): int
    {
        $privacy = Plugin::getInstance()->privacy;
        $total = 0;

        // The service handles a bounded batch at a time, so keep going until nothing is left.
        do {
            $count = $privacy->purgeInactiveCarts($this->days);
            $total += $count;
        } while ($count > 0);

        $this->stdout("Removed {$total} basket(s).\n");

        return ExitCode::OK;
    }

    /**
     * The go-live clean-up: deletes every order, customer, subscription and stored event made with a test key.
     * Only runs once the site is using a live key.
     */
    public function actionDeleteTestData(): int
    {
        $privacy = Plugin::getInstance()->privacy;

        if (!$this->yes && !$this->confirm('Delete everything made in Stripe test mode? This cannot be undone.')) {
            return ExitCode::OK;
        }

        try {
            $result = $privacy->deleteTestData();
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Deleted {$result['orders']} order(s), {$result['subscriptions']} subscription(s), {$result['customers']} customer(s) and {$result['events']} stored event(s).\n");

        return ExitCode::OK;
    }

    /**
     * Erases a donor's personal data from every order, customer record and stored webhook payload. Amounts,
     * dates and Stripe ids are kept.
     */
    public function actionAnonymise(): int
    {
        if ($this->email === null || trim($this->email) === '') {
            $this->stderr("Pass the donor's address with --email=person@example.com\n");

            return ExitCode::USAGE;
        }

        if (!$this->yes && !$this->confirm("Erase all personal data held for {$this->email}? This cannot be undone.")) {
            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->privacy->anonymiseDonor($this->email);
        $this->stdout("Anonymised {$result['orders']} order(s).\n");

        foreach ($result['skipped'] as $line) {
            $this->stderr("Left alone: {$line}\n");
        }

        return $result['skipped'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
