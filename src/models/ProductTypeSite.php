<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use craft\base\Model;
use craft\validators\UriFormatValidator;

class ProductTypeSite extends Model
{
    public ?int $id = null;
    public ?int $productTypeId = null;
    public ?int $siteId = null;
    public bool $hasUrls = true;
    public ?string $uriFormat = null;
    public ?string $template = null;

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['uriFormat'], UriFormatValidator::class],
            [['uriFormat'], 'required', 'when' => fn(): bool => $this->hasUrls],
            [['template'], 'string', 'max' => 500],
        ];
    }
}
