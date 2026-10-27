<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\controllers\SettingsController;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\variables\SmallChangeVariable;
use Craft;

final class SettingsTest extends Unit
{
    public function testDefaultsValidate(): void
    {
        $settings = new Settings();

        $this->assertTrue($settings->validate(), json_encode($settings->getErrors()) ?: '');
        $this->assertSame('GBP', $settings->currency);
        $this->assertFalse($settings->giftAidEnabled);
        $this->assertSame(Settings::CHECKOUT_MODE_ELEMENTS, $settings->checkoutMode);
    }

    /**
     * @dataProvider rawSecrets
     */
    public function testRawSecretsAreRejected(string $attribute, string $value): void
    {
        $settings = new Settings();
        $settings->$attribute = $value;

        $this->assertFalse($settings->validate([$attribute]));
        $this->assertArrayHasKey($attribute, $settings->getErrors());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rawSecrets(): array
    {
        return [
            'secret key' => ['stripeSecretKey', 'sk_test_abc123'],
            'live secret key' => ['stripeSecretKey', 'sk_live_abc123'],
            'restricted key' => ['stripeSecretKey', 'rk_live_abc123'],
            'webhook secret' => ['stripeWebhookSecret', 'whsec_abc123'],
        ];
    }

    public function testEnvironmentReferencesAreAccepted(): void
    {
        $settings = new Settings();
        $settings->stripeSecretKey = '$MY_STRIPE_KEY';
        $settings->stripeWebhookSecret = '$MY_WEBHOOK_SECRET';

        $this->assertTrue($settings->validate(['stripeSecretKey', 'stripeWebhookSecret']));
    }

    public function testCurrencyIsNormalisedAndChecked(): void
    {
        $settings = new Settings();
        $settings->currency = ' eur ';
        $this->assertTrue($settings->validate(['currency']));
        $this->assertSame('EUR', $settings->currency);

        $settings->currency = 'POUNDS';
        $this->assertFalse($settings->validate(['currency']));
    }

    public function testTheCharityNameFallsBackToTheSiteName(): void
    {
        $settings = new Settings();
        $settings->giftAidEnabled = true;
        $siteName = Craft::$app->getSites()->getCurrentSite()->getName();

        $this->assertTrue($settings->validate(['charityName']), 'a blank name is fine now');
        $this->assertSame($siteName, $settings->getOrganisationName());
        $this->assertStringContainsString('to ' . $siteName . '.', $settings->renderGiftAidText());

        $settings->charityName = '  Small Church ';
        $this->assertSame('Small Church', $settings->getOrganisationName());
    }

    public function testEmailsFollowTheMasterSwitchAndTheirOwn(): void
    {
        $settings = new Settings();
        $this->assertTrue($settings->emailEnabled(Emails::KEY_RECEIPT));

        $settings->disabledEmails = [Emails::KEY_RECEIPT];
        $this->assertFalse($settings->emailEnabled(Emails::KEY_RECEIPT));
        $this->assertFalse($settings->emailEnabled(Emails::KEY_RECEIPT_RESEND), 'a resent receipt is the receipt');
        $this->assertTrue($settings->emailEnabled(Emails::KEY_ADMIN_NEW_ORDER));

        $settings->sendEmails = false;
        $this->assertFalse($settings->emailEnabled(Emails::KEY_ADMIN_NEW_ORDER));
    }

    public function testOnlyRealEmailsCanBeSwitchedOff(): void
    {
        $settings = new Settings();

        $settings->disabledEmails = [Emails::KEY_RECEIPT, Emails::KEY_PORTAL_LINK];
        $this->assertTrue($settings->validate(['disabledEmails']));

        $settings->disabledEmails = ['receipt', 'nonsense'];
        $this->assertFalse($settings->validate(['disabledEmails']));

        $settings->disabledEmails = [];
        $this->assertTrue($settings->validate(['disabledEmails']));
    }

    public function testEveryEmailIsDescribedAndHasATemplate(): void
    {
        foreach (Emails::definitions() as $key => $definition) {
            $this->assertContains($definition['to'], [Emails::TO_DONOR, Emails::TO_ADMIN], $key);
            $this->assertNotSame('', $definition['when'], $key);
            $this->assertFileExists(dirname(__DIR__, 2) . '/src/templates/emails/' . $definition['template'] . '.twig', $key);
        }

        $this->assertArrayNotHasKey(Emails::KEY_RECEIPT_RESEND, Emails::definitions());
    }

    public function testSavingKeepsWhatWasNotPostedAndWhatTheConfigFileOwns(): void
    {
        $stored = ['currency' => 'EUR', 'adminEmail' => 'stored@example.test', 'charityName' => 'Old name'];
        $posted = ['charityName' => 'New name', 'adminEmail' => 'posted@example.test', 'checkoutMode' => 'hosted_page'];

        $merged = SettingsController::mergeSettings($stored, $posted, ['adminEmail']);

        $this->assertSame('EUR', $merged['currency'], 'a locked field is never posted, so it keeps its stored value');
        $this->assertSame('stored@example.test', $merged['adminEmail'], 'the config file owns this one');
        $this->assertSame('New name', $merged['charityName']);
        $this->assertSame('hosted_page', $merged['checkoutMode']);
    }

    public function testTheTabBarSharedByTheSettingsAndTemplatesPages(): void
    {
        $onSettings = SettingsController::tabs(true, false);
        $this->assertSame('#emails', $onSettings['emails']['url']);
        $this->assertStringEndsWith('small-change/settings/templates', $onSettings['templates']['url']);

        $fromTemplates = SettingsController::tabs(false, false);
        $this->assertStringEndsWith('small-change/settings/emails', $fromTemplates['emails']['url']);

        $this->assertArrayNotHasKey('templates', SettingsController::tabs(true, true), 'the installer writes files, so it needs admin changes');
    }

    public function testGiftAidWordingFillsInTheCharityName(): void
    {
        $settings = new Settings();
        $settings->charityName = 'Small Church';

        $text = $settings->renderGiftAidText();

        $this->assertStringContainsString('to Small Church.', $text);
        $this->assertStringNotContainsString('{charityName}', $text);
        $this->assertStringContainsString('responsibility to pay any difference', $text);
    }

    public function testEachGiftAidScopeHasItsOwnWording(): void
    {
        $settings = new Settings(['charityName' => 'Small Church']);

        $settings->giftAidScope = Settings::GIFT_AID_SINGLE;
        $this->assertStringStartsWith('I want to Gift Aid my donation to Small Church.', $settings->renderGiftAidText());

        $settings->giftAidScope = Settings::GIFT_AID_FUTURE;
        $this->assertStringContainsString('any donations I make in the future to Small Church.', $settings->renderGiftAidText());
        $this->assertStringNotContainsString('past 4 years', $settings->renderGiftAidText());

        $settings->giftAidScope = Settings::GIFT_AID_PAST_AND_FUTURE;
        $this->assertStringContainsString('in the past 4 years to Small Church.', $settings->renderGiftAidText());

        $settings->giftAidScope = Settings::GIFT_AID_CUSTOM;
        $settings->giftAidDeclarationText = 'Our own words for {charityName}.';
        $this->assertSame('Our own words for Small Church.', $settings->renderGiftAidText());

        $settings->giftAidScope = 'everything';
        $this->assertFalse($settings->validate(['giftAidScope']));
    }

    public function testWordingChangedBeforeTheScopeExistedIsKept(): void
    {
        $settings = new Settings();
        $this->assertSame(Settings::GIFT_AID_PAST_AND_FUTURE, $settings->getEffectiveGiftAidScope(), 'the old default wording');

        $settings->giftAidDeclarationText = 'Edited wording for {charityName}.';
        $this->assertSame(Settings::GIFT_AID_CUSTOM, $settings->getEffectiveGiftAidScope());

        $settings->giftAidScope = Settings::GIFT_AID_SINGLE;
        $this->assertSame(Settings::GIFT_AID_SINGLE, $settings->getEffectiveGiftAidScope(), 'a choice made since wins');
    }

    public function testCustomGiftAidWordingIsOnlyRequiredWhenItIsUsed(): void
    {
        $settings = new Settings(['giftAidEnabled' => true, 'giftAidDeclarationText' => '', 'giftAidScope' => Settings::GIFT_AID_FUTURE]);
        $this->assertTrue($settings->validate(['giftAidDeclarationText']));

        $settings->giftAidScope = Settings::GIFT_AID_CUSTOM;
        $this->assertFalse($settings->validate(['giftAidDeclarationText']));
    }

    public function testTheCharityNameCanComeFromTheEnvironment(): void
    {
        $siteName = Craft::$app->getSites()->getCurrentSite()->getName();
        $settings = new Settings(['charityName' => '$SMALL_CHANGE_TEST_CHARITY']);

        putenv('SMALL_CHANGE_TEST_CHARITY=Small Church');
        $_SERVER['SMALL_CHANGE_TEST_CHARITY'] = 'Small Church';

        try {
            $this->assertSame('Small Church', $settings->getOrganisationName());
            $this->assertSame('Small Church', $settings->withoutSecrets()->charityName, 'templates get the name, not the reference');
            $this->assertSame('$SMALL_CHANGE_TEST_CHARITY', $settings->charityName);
        } finally {
            putenv('SMALL_CHANGE_TEST_CHARITY');
            unset($_SERVER['SMALL_CHANGE_TEST_CHARITY']);
        }

        $this->assertSame($siteName, $settings->getOrganisationName(), 'an unset variable falls back to the site name');
    }

    public function testEmailWordingIsCheckedAndTrimmed(): void
    {
        $settings = new Settings();
        $settings->emailContent = [Emails::KEY_RECEIPT => ['subject' => ' Thanks ', 'heading' => '', 'message' => 'Hello']];
        $this->assertTrue($settings->validate(['emailContent']));
        $this->assertSame('Thanks', $settings->emailText(Emails::KEY_RECEIPT, 'subject'));
        $this->assertSame('', $settings->emailText(Emails::KEY_PAYMENT_FAILED, 'subject'));

        $settings->emailContent = ['nonsense' => ['subject' => 'x']];
        $this->assertFalse($settings->validate(['emailContent']));

        $settings->emailContent = [Emails::KEY_RECEIPT => ['footer' => 'x']];
        $this->assertFalse($settings->validate(['emailContent']));

        $settings->emailContent = [Emails::KEY_RECEIPT => ['subject' => str_repeat('a', 256)]];
        $this->assertFalse($settings->validate(['emailContent']));
    }

    public function testTheStripeApiBaseIsOptionalAndMustBeAUrl(): void
    {
        $settings = new Settings();
        $this->assertSame('', $settings->stripeApiBase, 'blank by default: the real Stripe API');
        $this->assertTrue($settings->validate(['stripeApiBase']));

        $settings->stripeApiBase = 'http://localhost:12111';
        $this->assertTrue($settings->validate(['stripeApiBase']));

        $settings->stripeApiBase = 'not a url';
        $this->assertFalse($settings->validate(['stripeApiBase']));
    }

    public function testTemplatesGetACopyWithoutTheStripeValues(): void
    {
        $settings = new Settings(['stripeSecretKey' => '$STRIPE_SECRET_KEY', 'stripeWebhookSecret' => '$STRIPE_WEBHOOK_SECRET', 'stripeApiBase' => 'http://localhost:12111', 'charityName' => 'Small Church']);
        $copy = $settings->withoutSecrets();

        $this->assertSame('', $copy->stripeSecretKey);
        $this->assertSame('', $copy->stripeWebhookSecret);
        $this->assertSame('', $copy->stripeApiBase);
        $this->assertSame('Small Church', $copy->charityName);
        $this->assertSame('$STRIPE_SECRET_KEY', $settings->stripeSecretKey, 'the plugin itself keeps the reference');
    }

    public function testTheSettingsFormOnlyEverShowsAReference(): void
    {
        $this->assertSame('$STRIPE_SECRET_KEY', SettingsController::referenceOnly('$STRIPE_SECRET_KEY'));
        $this->assertSame('@stripeKey', SettingsController::referenceOnly('@stripeKey'));
        $this->assertSame('', SettingsController::referenceOnly(''));
        // A key that got in through the config file, or was typed into a rejected save, is not put back on the page.
        $this->assertSame('', SettingsController::referenceOnly('sk_test_abc'));
        $this->assertSame('', SettingsController::referenceOnly('whsec_abc'));
    }

    public function testOnlyAPublishableKeyIsEverPrintedIntoAPage(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $before = $settings->stripePublishableKey;
        $variable = new SmallChangeVariable();

        try {
            $settings->stripePublishableKey = 'pk_test_abc';
            $this->assertSame('pk_test_abc', $variable->getPublishableKey());

            // The secret key pasted into the wrong box.
            $settings->stripePublishableKey = 'sk_test_abc';
            $this->assertNull($variable->getPublishableKey());

            $settings->stripePublishableKey = '';
            $this->assertNull($variable->getPublishableKey());
        } finally {
            $settings->stripePublishableKey = $before;
        }
    }

    public function testRetentionSettingsMustBePositive(): void
    {
        $settings = new Settings();
        $settings->purgeInactiveCartsDuration = 0;
        $settings->anonymiseOrdersAfter = 0;

        $this->assertFalse($settings->validate(['purgeInactiveCartsDuration']));
        $this->assertFalse($settings->validate(['anonymiseOrdersAfter']));
    }
}
