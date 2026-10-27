<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\models\Totals;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\Component;
use craft\elements\Address;

/**
 * Gift Aid is an option, not a records system. This offers the declaration and passes the answer
 * on; it stores only the yes/no and the eligible amount on the order and keeps nothing else.
 */
class GiftAid extends Component
{
    public const METADATA_DECLARED = 'smallChangeGiftAid';
    public const METADATA_AMOUNT = 'smallChangeGiftAidAmount';

    /**
     * What HMRC needs to identify the donor: full name and home address.
     */
    private const REQUIRED_ADDRESS_FIELDS = [
        'firstName' => 'First name',
        'lastName' => 'Last name',
        'addressLine1' => 'House name or number and street',
        'postalCode' => 'Postcode',
    ];

    public function isEnabled(): bool
    {
        return $this->settings()->giftAidEnabled;
    }

    /**
     * Whether to offer the tick box. Payments for goods never qualify, so it needs a donation line.
     */
    public function appliesTo(Order $order): bool
    {
        return $this->isEnabled() && $order->getHasDonation();
    }

    public function declarationText(): string
    {
        return $this->settings()->renderGiftAidText();
    }

    /**
     * Records the donor's answer on the order. The eligible amount is only ever the donation
     * lines, and only when they said yes.
     */
    public function apply(Order $order, bool $ticked, Totals $totals): void
    {
        if (!$this->appliesTo($order)) {
            $order->giftAidDeclared = null;
            $order->giftAidEligibleAmount = 0;

            return;
        }

        $order->giftAidDeclared = $ticked;
        $order->giftAidEligibleAmount = $ticked ? $totals->giftAidEligibleAmount : 0;
    }

    /**
     * The fields still missing from the home address a declaration needs.
     *
     * @return array<string, string> attribute => label
     */
    public function missingAddressFields(?Address $address): array
    {
        $missing = [];

        foreach (self::REQUIRED_ADDRESS_FIELDS as $attribute => $label) {
            if ($address === null || trim((string) $address->$attribute) === '') {
                $missing[$attribute] = Craft::t('small-change', $label);
            }
        }

        return $missing;
    }

    /**
     * Added to the PaymentIntent (or subscription) metadata. Empty when the option wasn't offered.
     *
     * @return array<string, string>
     */
    public function metadata(Order $order): array
    {
        if ($order->giftAidDeclared === null) {
            return [];
        }

        return [
            self::METADATA_DECLARED => $order->giftAidDeclared ? 'yes' : 'no',
            self::METADATA_AMOUNT => (string) ($order->giftAidDeclared ? $order->giftAidEligibleAmount : 0),
        ];
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return Plugin::getInstance()->getSettings();
    }
}
