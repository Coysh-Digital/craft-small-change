<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\base;

/**
 * Anything that can become an order line.
 */
interface PurchasableInterface
{
    public function getPurchasableId(): ?int;

    public function getDescription(): string;

    public function getSku(): ?string;

    /**
     * One of the LineItem::KIND_* values.
     */
    public function getLineItemKind(): string;

    /**
     * The fixed price in minor units, or null when the buyer chooses the amount (donations).
     */
    public function getPrice(): ?int;
}
