<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use craft\base\Model;

class LineItem extends Model
{
    public const KIND_PHYSICAL = 'physical';
    public const KIND_NON_PHYSICAL = 'nonPhysical';
    public const KIND_DONATION = 'donation';

    /**
     * Per line. Generous for a small shop; it exists so a typo cannot ask Stripe for a million of something.
     */
    public const MAX_QUANTITY = 999;

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $purchasableId = null;
    public string $kind = self::KIND_DONATION;
    public string $description = '';
    public ?string $sku = null;
    public int $unitPrice = 0;
    public int $qty = 1;
    public int $subtotal = 0;
    public ?string $recurringInterval = null;
    public bool $isGiftAidEligible = false;

    /**
     * What the line was when it was bought, so later edits to the product don't rewrite history.
     *
     * @var array<string, mixed>|null
     */
    public ?array $snapshot = null;
    public int $sortOrder = 0;

    public function getIsDonation(): bool
    {
        return $this->kind === self::KIND_DONATION;
    }

    public function getIsPhysical(): bool
    {
        return $this->kind === self::KIND_PHYSICAL;
    }

    /**
     * Keeps subtotal in step with price and quantity.
     */
    public function recalculate(): void
    {
        $this->subtotal = $this->unitPrice * $this->qty;
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['kind'], 'in', 'range' => [self::KIND_PHYSICAL, self::KIND_NON_PHYSICAL, self::KIND_DONATION]],
            [['description'], 'required'],
            [['unitPrice'], 'integer', 'min' => 1],
            [['qty'], 'integer', 'min' => 1, 'max' => self::MAX_QUANTITY],
            [['recurringInterval'], 'in', 'range' => ['month', 'year'], 'skipOnEmpty' => true],
        ];
    }
}
