<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use coyshdigital\smallchange\elements\Order;
use craft\elements\Address;
use craft\events\CancelableEvent;

/**
 * Carries everything a CRM integration needs to hold a Gift Aid declaration, since the plugin keeps none.
 */
class OrderEvent extends CancelableEvent
{
    public Order $order;
    public ?bool $giftAidDeclared = null;
    public int $giftAidEligibleAmount = 0;
    public ?string $giftAidDeclarationText = null;
    public ?Address $billingAddress = null;
}
