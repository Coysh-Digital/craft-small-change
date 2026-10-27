<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use craft\base\Model;
use DateTime;

/**
 * A recurring donation. Stripe owns the schedule and the card; this is the local copy that lets the
 * control panel list it and lets each payment be traced back to the order that started it.
 */
class Subscription extends Model
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_INCOMPLETE_EXPIRED = 'incomplete_expired';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_PAUSED = 'paused';

    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR = 'year';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $customerId = null;
    public ?int $productId = null;
    public string $stripeMode = 'test';
    public string $stripeSubscriptionId = '';
    public string $status = self::STATUS_INCOMPLETE;
    public string $interval = self::INTERVAL_MONTH;
    public int $amount = 0;
    public string $currency = 'GBP';
    public ?DateTime $currentPeriodEnd = null;
    public bool $cancelAtPeriodEnd = false;
    public ?DateTime $canceledAt = null;
    public ?DateTime $endedAt = null;
    public bool $giftAidDeclared = false;
    public ?DateTime $lastStripeEventAt = null;
    public ?DateTime $lastFailureAt = null;
    public ?DateTime $dateCreated = null;

    /**
     * Whether Stripe may still charge the donor. A cancelled or never-completed subscription may not.
     */
    public function getIsLive(): bool
    {
        return !in_array($this->status, [self::STATUS_CANCELED, self::STATUS_INCOMPLETE_EXPIRED], true);
    }
}
