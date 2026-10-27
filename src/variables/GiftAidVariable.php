<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\variables;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\services\GiftAid;

/**
 * craft.smallChange.giftAid
 */
class GiftAidVariable
{
    public function __construct(private readonly GiftAid $giftAid)
    {
    }

    public function getEnabled(): bool
    {
        return $this->giftAid->isEnabled();
    }

    public function appliesTo(?Order $order): bool
    {
        return $order !== null && $this->giftAid->appliesTo($order);
    }

    public function getDeclarationText(): string
    {
        return $this->giftAid->declarationText();
    }
}
