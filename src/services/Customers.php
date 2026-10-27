<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\models\Customer;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\CustomerRecord;
use Craft;
use craft\base\Component;
use craft\elements\Address;
use craft\elements\User;
use craft\helpers\Db;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;

/**
 * Local customer records, keyed by email and Stripe mode. A Stripe Customer is only created
 * by Checkout itself, and then recorded here when the order completes.
 */
class Customers extends Component
{
    public function findOrCreate(string $email, string $mode, ?string $name = null): Customer
    {
        $email = self::normaliseEmail($email);

        $record = CustomerRecord::findOne(['email' => $email, 'stripeMode' => $mode]);

        if (!$record) {
            $record = new CustomerRecord();
            $record->email = $email;
            $record->stripeMode = $mode;
            $record->userId = User::find()->email(Db::escapeParam($email))->status(null)->select(['elements.id'])->scalar() ?: null;
        }

        if ($name !== null && trim($name) !== '' && $record->name !== $name) {
            $record->name = $name;
        }

        if ($record->getDirtyAttributes()) {
            $record->save(false);
        }

        return $this->toModel($record);
    }

    public function getById(int $id): ?Customer
    {
        $record = CustomerRecord::findOne($id);

        return $record && $record->dateAnonymised === null ? $this->toModel($record) : null;
    }

    public function getByEmail(string $email, string $mode): ?Customer
    {
        $record = CustomerRecord::findOne(['email' => self::normaliseEmail($email), 'stripeMode' => $mode]);

        return $record && $record->dateAnonymised === null ? $this->toModel($record) : null;
    }

    public function getByStripeCustomerId(string $stripeCustomerId, string $mode): ?Customer
    {
        $record = CustomerRecord::findOne(['stripeCustomerId' => $stripeCustomerId, 'stripeMode' => $mode]);

        return $record ? $this->toModel($record) : null;
    }

    public function setStripeCustomerId(Customer $customer, string $stripeCustomerId): void
    {
        if ($customer->stripeCustomerId === $stripeCustomerId) {
            return;
        }

        $record = CustomerRecord::findOne($customer->id);

        if ($record) {
            $record->stripeCustomerId = $stripeCustomerId;
            $record->save(false);
            $customer->stripeCustomerId = $stripeCustomerId;
        }
    }

    /**
     * Keeps Stripe's name and address in step with the donor's home address. Best effort: a
     * failure here must never stop a payment, so it is logged and swallowed.
     */
    public function syncToStripe(Customer $customer, ?Address $address): void
    {
        if (!$customer->stripeCustomerId || $address === null) {
            return;
        }

        $params = array_filter([
            'name' => $this->fullName($address),
            'address' => $this->stripeAddress($address),
        ]);

        if (!$params) {
            return;
        }

        try {
            Plugin::getInstance()->stripe->updateCustomer($customer->stripeCustomerId, $params);
        } catch (ApiErrorException $e) {
            Craft::warning('Could not update the Stripe customer ' . $customer->stripeCustomerId . ': ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Keeps our copy of a customer's email and name in step with Stripe's. Returns whether we know them.
     */
    public function syncFromStripe(StripeObject $stripeCustomer, string $mode): bool
    {
        $record = CustomerRecord::findOne(['stripeCustomerId' => $stripeCustomer->id, 'stripeMode' => $mode]);

        if (!$record || $record->dateAnonymised !== null) {
            return false;
        }

        $email = is_string($stripeCustomer->email ?? null) ? self::normaliseEmail($stripeCustomer->email) : '';

        // Emails are unique per mode, so a change onto an address another record holds is left for a human.
        if ($email !== '' && $email !== $record->email && !CustomerRecord::find()->where(['email' => $email, 'stripeMode' => $mode])->exists()) {
            $record->email = $email;
        }

        if (is_string($stripeCustomer->name ?? null) && trim($stripeCustomer->name) !== '') {
            $record->name = mb_substr(trim($stripeCustomer->name), 0, 255);
        }

        if ($record->getDirtyAttributes()) {
            $record->save(false);
        }

        return true;
    }

    /**
     * The Stripe customer was deleted, so our record no longer points at anything.
     */
    public function forgetStripeCustomer(string $stripeCustomerId, string $mode): bool
    {
        return (bool) CustomerRecord::updateAll(['stripeCustomerId' => null], ['stripeCustomerId' => $stripeCustomerId, 'stripeMode' => $mode]);
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array<string, string>
     */
    public function stripeAddress(Address $address): array
    {
        return array_filter([
            'line1' => (string) $address->addressLine1,
            'line2' => (string) $address->addressLine2,
            'city' => (string) $address->locality,
            'state' => (string) $address->administrativeArea,
            'postal_code' => (string) $address->postalCode,
            'country' => (string) $address->countryCode,
        ], static fn(string $value): bool => $value !== '');
    }

    public function fullName(Address $address): string
    {
        return trim(implode(' ', array_filter([(string) $address->firstName, (string) $address->lastName])));
    }

    private function toModel(CustomerRecord $record): Customer
    {
        return new Customer($record->getAttributes(['id', 'email', 'userId', 'stripeMode', 'stripeCustomerId', 'name', 'dateAnonymised']));
    }
}
