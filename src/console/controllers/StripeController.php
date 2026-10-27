<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\console\controllers;

use coyshdigital\smallchange\Plugin;
use craft\console\Controller;
use craft\helpers\UrlHelper;
use Stripe\Exception\ApiErrorException;
use yii\console\ExitCode;

/**
 * Stripe housekeeping from the command line: `php craft small-change/stripe/<command>`.
 */
final class StripeController extends Controller
{
    /**
     * @var string|null for replay-events: only events of this type, such as `invoice.paid`
     */
    public ?string $type = null;

    /**
     * @var string for replay-events: how far back to look (anything strtotime understands; Stripe keeps 30 days)
     */
    public string $since = '-3 days';

    /**
     * @var string|null for register-webhook: the public address of the site, when the console can't work it out
     */
    public ?string $siteUrl = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'replay-events' => [...$options, 'type', 'since'],
            'register-webhook' => [...$options, 'siteUrl'],
            default => $options,
        };
    }

    /**
     * Creates the webhook endpoint at Stripe and prints its signing secret, once.
     */
    public function actionRegisterWebhook(): int
    {
        $base = $this->siteUrl ?? UrlHelper::siteUrl();

        if (!str_starts_with($base, 'https://')) {
            $this->stderr("Stripe only delivers to https addresses. Pass --site-url=https://example.com (got '{$base}').\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $endpoint = Plugin::getInstance()->webhooks->registerEndpoint(Plugin::getInstance()->webhooks->endpointUrl($base));
        } catch (ApiErrorException|\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Created {$endpoint['id']} for {$endpoint['url']}\n");
        $this->stdout("Signing secret (shown once, put it in STRIPE_WEBHOOK_SECRET): {$endpoint['secret']}\n");

        return ExitCode::OK;
    }

    /**
     * Creates or renames the Stripe products behind recurring donations.
     */
    public function actionSyncProducts(): int
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        if ($mode === null) {
            $this->stderr("No Stripe secret key is configured.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $plugin->stripeProducts->syncAll($mode);
        $this->stdout("Created {$result['created']}, updated {$result['updated']}, failed {$result['failed']}.\n");

        return $result['failed'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Asks Stripe about every live recurring donation and corrects the local copy.
     */
    public function actionReconcileSubscriptions(): int
    {
        $result = Plugin::getInstance()->subscriptions->reconcileAll();
        $this->stdout("Checked {$result['checked']}, changed {$result['changed']}.\n");

        return ExitCode::OK;
    }

    /**
     * Processes events this site missed (a deploy, an outage, a wrong secret). Events already handled are skipped.
     */
    public function actionReplayEvents(): int
    {
        $since = strtotime($this->since);

        if ($since === false) {
            $this->stderr("Could not read --since='{$this->since}'.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $result = Plugin::getInstance()->webhooks->replay($since, $this->type);
        } catch (ApiErrorException $e) {
            $this->stderr($e->getMessage() . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Found {$result['found']}, processed {$result['replayed']}, failed {$result['failed']}.\n");

        return $result['failed'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
