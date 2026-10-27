<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\records\DiscountRecord;
use Craft;
use craft\base\Model;
use craft\validators\UniqueValidator;
use DateTime;

class Discount extends Model
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    public ?int $id = null;
    public string $name = '';
    public string $code = '';
    public string $type = self::TYPE_PERCENT;

    /**
     * Basis points: 1250 is 12.5%.
     */
    public ?int $percent = null;
    public ?int $amount = null;
    public ?int $minSubtotal = null;
    public ?int $maxUses = null;
    public int $uses = 0;
    public ?DateTime $dateFrom = null;
    public ?DateTime $dateTo = null;
    public bool $enabled = true;

    /**
     * Codes are matched case-insensitively, so they are stored in one case.
     */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * The shape the Totals service takes.
     *
     * @return array{type: string, percent: ?int, amount: ?int, minSubtotal: ?int}
     */
    public function toSpec(): array
    {
        return ['type' => $this->type, 'percent' => $this->percent, 'amount' => $this->amount, 'minSubtotal' => $this->minSubtotal];
    }

    public function describe(string $currency): string
    {
        return $this->type === self::TYPE_PERCENT
            ? rtrim(rtrim(number_format(($this->percent ?? 0) / 100, 2, '.', ''), '0'), '.') . '%'
            : Money::format((int) $this->amount, $currency, true);
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'code'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['code'], 'filter', 'filter' => static fn($value) => is_string($value) ? self::normaliseCode($value) : $value],
            [['code'], 'string', 'max' => 64],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9][A-Z0-9_-]*$/', 'message' => Craft::t('small-change', 'Use letters, numbers, hyphens and underscores only.')],
            [['code'], UniqueValidator::class, 'targetClass' => DiscountRecord::class],
            [['type'], 'in', 'range' => [self::TYPE_PERCENT, self::TYPE_FIXED]],
            [['percent'], 'required', 'when' => fn(): bool => $this->type === self::TYPE_PERCENT],
            [['percent'], 'integer', 'min' => 1, 'max' => 10000],
            [['amount'], 'required', 'when' => fn(): bool => $this->type === self::TYPE_FIXED],
            [['amount', 'minSubtotal'], 'integer', 'min' => 1, 'max' => Money::MAX_MINOR],
            [['maxUses'], 'integer', 'min' => 1],
            [['dateTo'], 'validateDates'],
        ];
    }

    public function validateDates(): void
    {
        if ($this->dateFrom && $this->dateTo && $this->dateTo <= $this->dateFrom) {
            $this->addError('dateTo', Craft::t('small-change', 'The end date must be after the start date.'));
        }
    }
}
