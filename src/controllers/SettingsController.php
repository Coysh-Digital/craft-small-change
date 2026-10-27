<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\services\Webhooks;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class SettingsController extends Controller
{
    /**
     * The tabs that are panes of the settings form, in order. Templates is a page of its own that shares the tab bar.
     */
    public const TABS = ['general', 'stripe', 'checkout', 'emails', 'giftaid', 'privacy'];

    /**
     * The tab bar for the settings page (panes switched in the browser) and for the Templates page (real links back
     * to each pane). The installer writes files, so Templates is only offered where admin changes are allowed.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function tabs(bool $onSettingsPage, bool $readOnly): array
    {
        $labels = [
            'general' => Craft::t('small-change', 'General'),
            'stripe' => Craft::t('small-change', 'Stripe'),
            'checkout' => Craft::t('small-change', 'Checkout'),
            'emails' => Craft::t('small-change', 'Emails'),
            'giftaid' => Craft::t('small-change', 'Gift Aid'),
            'privacy' => Craft::t('small-change', 'Privacy'),
        ];
        $tabs = [];

        foreach (self::TABS as $id) {
            $tabs[$id] = [
                'label' => $labels[$id],
                'url' => $onSettingsPage ? '#' . $id : UrlHelper::cpUrl('small-change/settings/' . $id),
            ];
        }

        if (!$readOnly) {
            $tabs['templates'] = [
                'label' => Craft::t('small-change', 'Templates'),
                'url' => UrlHelper::cpUrl('small-change/settings/templates'),
            ];
        }

        return $tabs;
    }

    /**
     * What to store: the settings project config already holds, with the posted ones laid over them. Craft keeps
     * only the keys it is given, so anything left out here (a disabled field, or a value the config file owns)
     * would be deleted and fall back to its default.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $posted
     * @param string[] $overridden settings the config file owns, which the form can't change
     * @return array<string, mixed>
     */
    public static function mergeSettings(array $stored, array $posted, array $overridden): array
    {
        return array_merge($stored, array_diff_key($posted, array_flip($overridden)));
    }

    /**
     * The value as the form may show it: an environment reference or an alias, never a literal key.
     */
    public static function referenceOnly(string $value): string
    {
        $value = trim($value);

        return $value === '' || str_starts_with($value, '$') || str_starts_with($value, '@') ? $value : '';
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Viewing is open to admins even where changes are disallowed; every save below asks for more.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(?string $tab = null): Response
    {
        $plugin = Plugin::getInstance();
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $currencyHolders = $plugin->orders->currencyHolders();
        $webhookUrl = $plugin->webhooks->endpointUrl();

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        return $this->renderTemplate('small-change/settings/index', [
            'settings' => $settings,
            // Only an environment reference is ever put back into the form; a key itself stays out of the page.
            'keyFields' => [
                'stripeSecretKey' => self::referenceOnly($settings->stripeSecretKey),
                'stripePublishableKey' => self::referenceOnly($settings->stripePublishableKey),
                'stripeWebhookSecret' => self::referenceOnly($settings->stripeWebhookSecret),
            ],
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('small-change')),
            'tabs' => self::tabs(true, $readOnly),
            'selectedTab' => in_array($tab, self::TABS, true) ? $tab : self::TABS[0],
            // Saving redirects here without a tab, so the page puts the visitor back on the one they were using.
            'restoreTab' => $tab === null,
            'readOnly' => $readOnly,
            'currencyLocked' => ($currencyHolders['liveOrders'] ?? 0) > 0,
            'currencyHolders' => $currencyHolders,
            'systemEmail' => Emails::systemSender(),
            'emailDefinitions' => Emails::definitions(),
            'siteName' => Craft::$app->getSites()->getCurrentSite()->getName(),
            'webhookUrl' => $webhookUrl,
            'webhookPublic' => Webhooks::isPublicUrl($webhookUrl),
            'enabledEvents' => Webhooks::ENABLED_EVENTS,
            'apiVersion' => Stripe::API_VERSION,
        ]);
    }

    public function actionOrderFields(): Response
    {
        return $this->fieldsPage(Orders::LAYOUT_CHECKOUT);
    }

    public function actionDonationFields(): Response
    {
        return $this->fieldsPage(Orders::LAYOUT_DONATION);
    }

    public function actionSaveOrderFields(): Response
    {
        return $this->saveFields(Orders::LAYOUT_CHECKOUT, Craft::t('small-change', 'Checkout fields saved.'));
    }

    public function actionSaveDonationFields(): Response
    {
        return $this->saveFields(Orders::LAYOUT_DONATION, Craft::t('small-change', 'Donation fields saved.'));
    }

    private function fieldsPage(string $for): Response
    {
        $orders = Plugin::getInstance()->orders;

        return $this->renderTemplate('small-change/settings/order-fields', [
            'slot' => $for,
            'fieldLayout' => $orders->getFieldLayout($for),
            'unsupported' => $orders->getUnsupportedFields($for),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    private function saveFields(string $for, string $message): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        Plugin::getInstance()->orders->saveFieldLayout($layout, $for);

        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl();
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $params = (array) $this->request->getBodyParam('settings', []);

        // One switch per email is posted as emails[key]; the model stores the ones that are off.
        if (is_array($params['emails'] ?? null)) {
            $params['disabledEmails'] = array_values(array_filter(
                array_keys(Emails::definitions()),
                static fn(string $key): bool => empty($params['emails'][$key]),
            ));
        }

        // The API base is for a local mock and is set in the config file only; it never comes from the form.
        unset($params['emails'], $params['stripeApiBase']);

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $oldCurrency = $settings->currency;
        $newCurrency = isset($params['currency']) ? strtoupper(trim((string) $params['currency'])) : $oldCurrency;
        $currencyError = $plugin->orders->currencyChangeError($oldCurrency, $newCurrency);

        if ($currencyError !== null) {
            $settings->addError('currency', $currencyError);

            return $this->asModelFailure($settings, Craft::t('small-change', "Couldn't save settings."), 'settings');
        }

        foreach (['giftAidEnabled', 'sendEmails'] as $switch) {
            if (array_key_exists($switch, $params)) {
                $params[$switch] = (bool) $params[$switch];
            }
        }

        if (array_key_exists('anonymiseOrdersAfter', $params) && $params['anonymiseOrdersAfter'] === '') {
            $params['anonymiseOrdersAfter'] = null;
        }

        // Settings the config file owns are not editable here, and neither are disabled fields: both keep what is stored.
        $stored = (array) (Craft::$app->getProjectConfig()->get('plugins.' . $plugin->handle . '.settings') ?? []);
        $stored = array_intersect_key($stored, array_flip($settings->attributes()));
        $params = self::mergeSettings(
            $stored,
            $params,
            array_keys(Craft::$app->getConfig()->getConfigFromFile('small-change')),
        );

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $params)) {
            return $this->asModelFailure($settings, Craft::t('small-change', "Couldn't save settings."), 'settings');
        }

        /** @var Settings $saved */
        $saved = $plugin->getSettings();

        if (strtoupper($saved->currency) !== strtoupper($oldCurrency)) {
            $plugin->orders->moveCartsToCurrency($saved->currency);
        }

        return $this->asSuccess(Craft::t('small-change', 'Settings saved.'));
    }

    public function actionEditEmail(string $key): Response
    {
        $definition = Emails::definitions()[$key] ?? throw new NotFoundHttpException('Email not found');
        $plugin = Plugin::getInstance();

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $content = [];

        foreach (array_keys(Settings::EMAIL_CONTENT_FIELDS) as $field) {
            $content[$field] = $settings->emailText($key, $field);
        }

        // The receipt subject used to be a setting of its own, so the form starts from it until it is saved here.
        if ($key === Emails::KEY_RECEIPT && $content['subject'] === '') {
            $content['subject'] = $settings->receiptSubject;
        }

        $preview = $plugin->emails->preview($key);
        $overrides = array_keys(Craft::$app->getConfig()->getConfigFromFile('small-change'));

        return $this->renderTemplate('small-change/settings/email', [
            'key' => $key,
            'email' => $definition,
            'content' => $content,
            'preview' => $preview,
            'overrideTemplate' => $plugin->emails->overrideTemplate($key),
            'enabled' => $settings->emailEnabled($key),
            'adminEmail' => $settings->adminEmail,
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges || in_array('emailContent', $overrides, true),
            'overridden' => in_array('emailContent', $overrides, true),
            'tokens' => Emails::TOKENS,
        ]);
    }

    public function actionSaveEmail(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $key = (string) $this->request->getRequiredBodyParam('key');

        if (!isset(Emails::definitions()[$key])) {
            throw new NotFoundHttpException('Email not found');
        }

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $emailContent = $settings->emailContent;
        // Only what differs from the built-in wording is stored.
        $emailContent[$key] = array_filter($this->postedContent(), static fn(string $text): bool => $text !== '');

        if ($emailContent[$key] === []) {
            unset($emailContent[$key]);
        }

        $stored = (array) (Craft::$app->getProjectConfig()->get('plugins.' . $plugin->handle . '.settings') ?? []);
        $stored = array_intersect_key($stored, array_flip($settings->attributes()));
        $params = self::mergeSettings(
            $stored,
            ['emailContent' => $emailContent],
            array_keys(Craft::$app->getConfig()->getConfigFromFile('small-change')),
        );

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $params)) {
            return $this->asModelFailure($settings, Craft::t('small-change', "Couldn't save the email."), 'settings');
        }

        return $this->asSuccess(Craft::t('small-change', 'Email saved.'));
    }

    /**
     * The email as it would go out: as saved for a GET (opened from the settings table), or with the wording
     * being edited for a POST, which the edit screen sends to refresh its preview.
     */
    public function actionPreviewEmail(?string $key = null): Response
    {
        $key ??= (string) $this->request->getRequiredBodyParam('key');

        if (!isset(Emails::definitions()[$key])) {
            throw new NotFoundHttpException('Email not found');
        }

        $preview = Plugin::getInstance()->emails->preview($key, $this->request->getIsPost() ? $this->postedContent() : null);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['subject' => $preview['subject'], 'html' => $preview['html']]);
        }

        $this->response->format = Response::FORMAT_HTML;
        $this->response->content = $preview['html'];

        return $this->response;
    }

    /**
     * @return array<string, string>
     */
    private function postedContent(): array
    {
        $posted = (array) $this->request->getBodyParam('content', []);
        $content = [];

        foreach (array_keys(Settings::EMAIL_CONTENT_FIELDS) as $field) {
            $value = $posted[$field] ?? '';
            $content[$field] = is_string($value) ? trim($value) : '';
        }

        return $content;
    }
}
