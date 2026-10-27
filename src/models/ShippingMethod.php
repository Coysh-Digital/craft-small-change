<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\records\ShippingMethodRecord;
use craft\base\Model;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;

class ShippingMethod extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public int $price = 0;

    /**
     * ISO country codes this method serves; null means every country.
     *
     * @var string[]|null
     */
    public ?array $countryCodes = null;
    public bool $enabled = true;
    public int $sortOrder = 0;

    public function appliesTo(?string $countryCode): bool
    {
        if ($this->countryCodes === null || $this->countryCodes === []) {
            return true;
        }

        return $countryCode !== null && in_array(strtoupper($countryCode), $this->countryCodes, true);
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid']],
            [['handle'], UniqueValidator::class, 'targetClass' => ShippingMethodRecord::class],
            // Zero is allowed: free delivery is a real method.
            [['price'], 'integer', 'min' => 0, 'max' => Money::MAX_MINOR],
            [['countryCodes'], 'each', 'rule' => ['match', 'pattern' => '/^[A-Z]{2}$/']],
        ];
    }
}
