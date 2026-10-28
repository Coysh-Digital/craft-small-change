<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\functional;

use Codeception\Test\Unit;
use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\StoredSettings;
use coyshdigital\smallchange\tests\FunctionalTester;
use Craft;
use craft\elements\User;

/**
 * The email sender and wording are saved where admin changes are disallowed; the rest of the settings are not.
 */
final class SettingsControllerTest extends Unit
{
    protected FunctionalTester $tester;

    protected function _before(): void
    {
        $this->setAllowAdminChanges(false);

        $admin = new User(['username' => 'settings-admin', 'email' => 'settings-admin@example.test', 'admin' => true]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($admin, false));
        $this->tester->amLoggedInAs($admin);
        $this->tester->haveHttpHeader('Accept', 'application/json');
    }

    protected function _after(): void
    {
        $this->setAllowAdminChanges(null);
        Craft::$app->getDb()->createCommand()->delete(Table::SETTINGS)->execute();
    }

    public function testTheReadOnlyPageStillOffersTheSenderFields(): void
    {
        $this->tester->haveHttpHeader('Accept', 'text/html');
        $this->tester->amOnPage('/index.php?p=admin/small-change/settings/emails');

        $this->tester->seeResponseCodeIs(200);
        $this->tester->seeElement('input[name="action"][value="small-change/settings/save"]');
        $this->tester->seeElement('input[name="settings[emailFromName]"]:not([disabled])');
        $this->tester->seeElement('input[name="settings[currency]"][disabled]');

        $this->tester->amOnPage('/index.php?p=admin/small-change/settings/emails/' . Emails::KEY_RECEIPT);

        $this->tester->seeResponseCodeIs(200);
        $this->tester->seeElement('input[name="action"][value="small-change/settings/save-email"]');
        $this->tester->seeElement('input[name="content[heading]"]:not([disabled])');
    }

    public function testTheSenderIsSavedAndProjectConfigIsLeftAlone(): void
    {
        $currency = Craft::$app->getProjectConfig()->get('plugins.small-change.settings.currency');

        $this->tester->sendAjaxPostRequest('/actions/small-change/settings/save', [
            'action' => 'small-change/settings/save',
            'settings' => ['emailFromName' => ' Small Church ', 'adminEmail' => 'office@example.test', 'currency' => 'EUR'],
        ]);

        $this->tester->seeResponseCodeIs(200);
        $stored = (new StoredSettings())->all();
        $this->assertSame('Small Church', $stored['emailFromName'] ?? null, $this->tester->grabPageSource());
        $this->assertSame('office@example.test', $stored['adminEmail'] ?? null);
        $this->assertSame($currency, Craft::$app->getProjectConfig()->get('plugins.small-change.settings.currency'), 'only the email settings can change here');
    }

    public function testABadAddressIsRejectedAndNothingIsSaved(): void
    {
        $this->tester->sendAjaxPostRequest('/actions/small-change/settings/save', [
            'action' => 'small-change/settings/save',
            'settings' => ['emailFromName' => 'Small Church', 'emailFromAddress' => 'not an address'],
        ]);

        $this->tester->seeResponseCodeIs(400);
        $this->assertSame([], (new StoredSettings())->all());
    }

    public function testAnEmailsWordingIsSaved(): void
    {
        $this->tester->sendAjaxPostRequest('/actions/small-change/settings/save-email', [
            'action' => 'small-change/settings/save-email',
            'key' => Emails::KEY_RECEIPT,
            'content' => ['subject' => '', 'heading' => 'Thank you', 'message' => 'It means a lot.'],
        ]);

        $this->tester->seeResponseCodeIs(200);
        $this->assertSame(
            [Emails::KEY_RECEIPT => ['heading' => 'Thank you', 'message' => 'It means a lot.']],
            (new StoredSettings())->all()['emailContent'] ?? null,
            $this->tester->grabPageSource(),
        );
    }

    /**
     * The connector builds a new app for each request, so the setting goes in through the environment.
     */
    private function setAllowAdminChanges(?bool $allow): void
    {
        if ($allow === null) {
            putenv('CRAFT_ALLOW_ADMIN_CHANGES');
            unset($_SERVER['CRAFT_ALLOW_ADMIN_CHANGES']);
        } else {
            putenv('CRAFT_ALLOW_ADMIN_CHANGES=' . ($allow ? 'true' : 'false'));
            $_SERVER['CRAFT_ALLOW_ADMIN_CHANGES'] = $allow ? 'true' : 'false';
        }

        Craft::$app->getConfig()->getGeneral()->allowAdminChanges = $allow ?? true;
    }
}
