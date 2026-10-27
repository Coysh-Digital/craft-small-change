<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use coyshdigital\smallchange\services\Emails;
use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\models\Site;

/**
 * Plugin settings, stored in project config.
 *
 * The three Stripe values are environment variable references, never keys, so
 * a secret cannot end up in project config or version control.
 */
class Settings extends Model
{
    public const CHECKOUT_MODE_ELEMENTS = 'elements';
    public const CHECKOUT_MODE_HOSTED = 'hosted_page';

    public const DEFAULT_GIFT_AID_TEXT = 'I want to Gift Aid my donation and any donations I make in the future or have made in the past 4 years to {charityName}. I am a UK taxpayer and understand that if I pay less Income Tax and/or Capital Gains Tax than the amount of Gift Aid claimed on all my donations in that tax year it is my responsibility to pay any difference.';

    public const GIFT_AID_SINGLE = 'single';
    public const GIFT_AID_FUTURE = 'future';
    public const GIFT_AID_PAST_AND_FUTURE = 'pastAndFuture';
    public const GIFT_AID_CUSTOM = 'custom';

    /**
     * The wording for each declaration scope a charity can choose. The single and multiple-donation ones follow HMRC's model declarations.
     */
    public const GIFT_AID_TEXTS = [
        self::GIFT_AID_SINGLE => 'I want to Gift Aid my donation to {charityName}. I am a UK taxpayer and understand that if I pay less Income Tax and/or Capital Gains Tax in the current tax year than the amount of Gift Aid claimed on all my donations it is my responsibility to pay any difference.',
        self::GIFT_AID_FUTURE => 'I want to Gift Aid my donation and any donations I make in the future to {charityName}. I am a UK taxpayer and understand that if I pay less Income Tax and/or Capital Gains Tax than the amount of Gift Aid claimed on all my donations in that tax year it is my responsibility to pay any difference.',
        self::GIFT_AID_PAST_AND_FUTURE => self::DEFAULT_GIFT_AID_TEXT,
    ];

    public const EMAIL_CONTENT_FIELDS = ['subject' => 255, 'heading' => 255, 'message' => 5000];

    public string $stripeSecretKey = '$STRIPE_SECRET_KEY';
    public string $stripePublishableKey = '$STRIPE_PUBLISHABLE_KEY';
    public string $stripeWebhookSecret = '$STRIPE_WEBHOOK_SECRET';

    /**
     * For testing against a local stripe-mock. Leave blank. Set it in config/small-change.php, never in the control panel.
     */
    public string $stripeApiBase = '';

    public string $checkoutMode = self::CHECKOUT_MODE_ELEMENTS;
    public string $currency = 'GBP';

    /**
     * The name itself or an environment variable reference such as $CHARITY_NAME.
     */
    public string $charityName = '';
    public bool $giftAidEnabled = false;

    /**
     * Which donations the declaration covers. Null (never chosen) means the custom wording when it has been
     * changed from the default, so installs that edited it before this option existed keep their wording.
     */
    public ?string $giftAidScope = null;

    /**
     * Only used when the scope is custom.
     */
    public string $giftAidDeclarationText = self::DEFAULT_GIFT_AID_TEXT;

    public bool $sendEmails = true;

    /**
     * Keys of the emails switched off one by one (see Emails::definitions()). Anything not listed is sent.
     *
     * @var string[]
     */
    public array $disabledEmails = [];

    public string $emailFromName = '';
    public string $emailFromAddress = '';
    public string $emailReplyTo = '';
    public string $adminEmail = '';

    /**
     * Still honoured, but the receipt's subject is now edited with the rest of its wording in $emailContent, which wins when set.
     */
    public string $receiptSubject = 'Your receipt from {siteName}';

    /**
     * Wording changed in the control panel, by email key: subject, heading and message. A blank or missing value keeps the built-in wording.
     *
     * @var array<string, array<string, string>>
     */
    public array $emailContent = [];

    public string $emailTemplatePath = '_small-change/emails';

    public int $purgeInactiveCartsDuration = 30;
    public ?int $anonymiseOrdersAfter = null;
    public int $webhookLogRetention = 90;

    /**
     * @return array<int, mixed>
     */
    public function defineRules(): array
    {
        return [
            [['stripeSecretKey', 'stripePublishableKey', 'stripeWebhookSecret'], 'trim'],
            [['stripeSecretKey', 'stripeWebhookSecret'], 'validateNotRawSecret'],
            [['stripeApiBase'], 'validateApiBase', 'skipOnEmpty' => true],
            [['checkoutMode'], 'in', 'range' => [self::CHECKOUT_MODE_ELEMENTS, self::CHECKOUT_MODE_HOSTED]],
            [['currency'], 'filter', 'filter' => static fn($value) => is_string($value) ? strtoupper(trim($value)) : $value],
            [['currency'], 'required'],
            [['currency'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'message' => '{attribute} must be a three-letter currency code such as GBP.'],
            [['charityName', 'emailFromName', 'receiptSubject', 'emailTemplatePath'], 'string', 'max' => 255],
            [['giftAidScope'], 'in', 'range' => [self::GIFT_AID_SINGLE, self::GIFT_AID_FUTURE, self::GIFT_AID_PAST_AND_FUTURE, self::GIFT_AID_CUSTOM]],
            [['giftAidDeclarationText'], 'required', 'when' => fn() => $this->giftAidEnabled && $this->getEffectiveGiftAidScope() === self::GIFT_AID_CUSTOM],
            [['giftAidEnabled', 'sendEmails'], 'boolean'],
            [['disabledEmails'], 'validateDisabledEmails'],
            [['emailContent'], 'validateEmailContent'],
            [['emailFromAddress', 'emailReplyTo', 'adminEmail'], 'email'],
            [['purgeInactiveCartsDuration', 'webhookLogRetention'], 'integer', 'min' => 1],
            [['anonymiseOrdersAfter'], 'integer', 'min' => 1],
        ];
    }

    /**
     * Keeps the stored list to real email keys, so a renamed or removed email can't linger in project config.
     */
    public function validateDisabledEmails(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_array($value)) {
            $this->addError($attribute, Craft::t('small-change', 'Disabled emails must be a list.'));

            return;
        }

        $known = array_keys(Emails::definitions());

        foreach ($value as $key) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                $this->addError($attribute, Craft::t('small-change', 'Unknown email: {key}.', ['key' => is_scalar($key) ? (string) $key : '?']));

                return;
            }
        }
    }

    /**
     * Only real email keys and the three text fields, each a string within its length.
     */
    public function validateEmailContent(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_array($value)) {
            $this->addError($attribute, Craft::t('small-change', 'Email wording must be a list.'));

            return;
        }

        $known = array_keys(Emails::definitions());

        foreach ($value as $key => $fields) {
            if (!is_string($key) || !in_array($key, $known, true) || !is_array($fields)) {
                $this->addError($attribute, Craft::t('small-change', 'Unknown email: {key}.', ['key' => (string) $key]));

                return;
            }

            foreach ($fields as $field => $text) {
                $max = self::EMAIL_CONTENT_FIELDS[$field] ?? null;

                if ($max === null || !is_string($text)) {
                    $this->addError($attribute, Craft::t('small-change', 'Unknown email field: {field}.', ['field' => (string) $field]));

                    return;
                }

                if (mb_strlen($text) > $max) {
                    $this->addError($attribute, Craft::t('small-change', 'The {field} of an email can be at most {max} characters.', ['field' => $field, 'max' => $max]));

                    return;
                }
            }
        }
    }

    /**
     * One field of an email's custom wording, trimmed, or '' when the built-in wording applies.
     */
    public function emailText(string $key, string $field): string
    {
        return trim($this->emailContent[$key][$field] ?? '');
    }

    /**
     * Whether the plugin should send this email: the master switch is on and this one hasn't been turned off.
     * A resent receipt follows the receipt.
     */
    public function emailEnabled(string $key): bool
    {
        if ($key === Emails::KEY_RECEIPT_RESEND) {
            $key = Emails::KEY_RECEIPT;
        }

        return $this->sendEmails && !in_array($key, $this->disabledEmails, true);
    }

    /**
     * The name shown to donors: the one entered here, or the name of the site when it's left blank.
     */
    public function getOrganisationName(?Site $site = null): string
    {
        $name = App::parseEnv(trim($this->charityName));

        // An unset variable parses to null, and the site name is a better fallback than "$CHARITY_NAME".
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return ($site ?? Craft::$app->getSites()->getCurrentSite())->getName();
    }

    /**
     * Yii's url validator wants a dot in the host, which rules out the localhost a mock server runs on.
     */
    public function validateApiBase(string $attribute): void
    {
        $scheme = parse_url((string) $this->$attribute, PHP_URL_SCHEME);

        if (filter_var($this->$attribute, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            $this->addError($attribute, Craft::t('small-change', 'Enter a full URL such as http://localhost:12111.'));
        }
    }

    /**
     * Rejects a literal key, so the setting stays an environment reference.
     */
    public function validateNotRawSecret(string $attribute): void
    {
        $value = (string) $this->$attribute;

        if (preg_match('/^(sk|rk|whsec)_/', $value) !== 1) {
            return;
        }

        $this->addError($attribute, Craft::t('small-change', 'Enter an environment variable reference such as {example} and keep the value itself in your .env file.', [
            'example' => $attribute === 'stripeWebhookSecret' ? '$STRIPE_WEBHOOK_SECRET' : '$STRIPE_SECRET_KEY',
        ]));
    }

    /**
     * A copy for site and email templates, without the Stripe values. They are environment references by design,
     * but a template has no business with them either way.
     */
    public function withoutSecrets(): static
    {
        $copy = clone $this;
        $copy->stripeSecretKey = '';
        $copy->stripeWebhookSecret = '';
        $copy->stripeApiBase = '';
        // Templates print this directly, so they get the name rather than an environment reference.
        $copy->charityName = $this->getOrganisationName();

        return $copy;
    }

    public function getEffectiveGiftAidScope(): string
    {
        if ($this->giftAidScope !== null) {
            return $this->giftAidScope;
        }

        return trim($this->giftAidDeclarationText) === self::DEFAULT_GIFT_AID_TEXT ? self::GIFT_AID_PAST_AND_FUTURE : self::GIFT_AID_CUSTOM;
    }

    /**
     * The Gift Aid wording for display, with the charity name filled in.
     */
    public function renderGiftAidText(?string $scope = null): string
    {
        $scope ??= $this->getEffectiveGiftAidScope();
        $text = self::GIFT_AID_TEXTS[$scope] ?? $this->giftAidDeclarationText;

        return str_replace('{charityName}', $this->getOrganisationName(), $text);
    }
}
