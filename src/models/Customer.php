<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use craft\base\Model;

class Customer extends Model
{
    public ?int $id = null;
    public string $email = '';
    public ?int $userId = null;
    public string $stripeMode = 'test';
    public ?string $stripeCustomerId = null;
    public ?string $name = null;
    public ?string $dateAnonymised = null;
}
