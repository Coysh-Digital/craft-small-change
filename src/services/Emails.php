<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\EmailEvent;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\jobs\SendEmail;
use coyshdigital\smallchange\jobs\SendPortalLink;
use coyshdigital\smallchange\models\Customer;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\Component;
use craft\elements\Address;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\View;
use DateTime;
use Twig\Markup;

/**
 * The plugin sends its own receipts, so they work on Craft Solo (where system messages can't be
 * edited) and can be overridden with a plain Twig template in the site's templates folder.
 */
class Emails extends Component
{
    public const EVENT_BEFORE_SEND = 'beforeSend';

    public const KEY_RECEIPT = 'receipt';
    public const KEY_ADMIN_NEW_ORDER = 'adminNewOrder';
    public const KEY_PAYMENT_FAILED = 'paymentFailed';
    public const KEY_ADMIN_REFUND_FAILED = 'adminRefundFailed';
    public const KEY_ADMIN_DISPUTE = 'adminDispute';
    public const KEY_RECEIPT_RESEND = 'receiptResend';
    public const KEY_SUBSCRIPTION_PAYMENT_FAILED = 'subscriptionPaymentFailed';
    public const KEY_SUBSCRIPTION_ACTION_REQUIRED = 'subscriptionActionRequired';
    public const KEY_SUBSCRIPTION_CANCELLED = 'subscriptionCancelled';
    public const KEY_ADMIN_SUBSCRIPTION_CANCELLED = 'adminSubscriptionCancelled';
    public const KEY_PORTAL_LINK = 'portalLink';

    /**
     * Template name for each email, under the override path and under the plugin's own templates.
     */
    private const TEMPLATES = [
        self::KEY_RECEIPT => 'receipt',
        self::KEY_ADMIN_NEW_ORDER => 'admin-new-order',
        self::KEY_PAYMENT_FAILED => 'payment-failed',
        self::KEY_ADMIN_REFUND_FAILED => 'admin-refund-failed',
        self::KEY_ADMIN_DISPUTE => 'admin-dispute',
        self::KEY_RECEIPT_RESEND => 'receipt',
        self::KEY_SUBSCRIPTION_PAYMENT_FAILED => 'subscription-payment-failed',
        self::KEY_SUBSCRIPTION_ACTION_REQUIRED => 'subscription-action-required',
        self::KEY_SUBSCRIPTION_CANCELLED => 'subscription-cancelled',
        self::KEY_ADMIN_SUBSCRIPTION_CANCELLED => 'admin-subscription-cancelled',
    ];

    public const TO_DONOR = 'donor';
    public const TO_ADMIN = 'admin';

    /**
     * The placeholders the wording edited in the control panel can use.
     */
    public const TOKENS = ['{charityName}', '{siteName}', '{orderNumber}', '{firstName}', '{amount}'];

    private const RECURRING_KEYS = [
        self::KEY_SUBSCRIPTION_PAYMENT_FAILED,
        self::KEY_SUBSCRIPTION_ACTION_REQUIRED,
        self::KEY_SUBSCRIPTION_CANCELLED,
        self::KEY_ADMIN_SUBSCRIPTION_CANCELLED,
    ];

    /**
     * Every email the plugin sends, for the settings table and the docs. A resent receipt is the receipt again, so it has no row.
     *
     * @return array<string, array{label: string, to: string, when: string, template: string}>
     */
    public static function definitions(): array
    {
        return [
            self::KEY_RECEIPT => [
                'label' => Craft::t('small-change', 'Receipt'),
                'to' => self::TO_DONOR,
                'when' => Craft::t('small-change', 'When a payment succeeds, including each payment of a recurring donation.'),
                'template' => 'receipt',
            ],
            self::KEY_PAYMENT_FAILED => [
                'label' => Craft::t('small-change', 'Payment failed'),
                'to' => self::TO_DONOR,
                'when' => Craft::t('small-change', 'When a payment method that clears slowly, such as a bank debit, fails after checkout.'),
                'template' => 'payment-failed',
            ],
            self::KEY_SUBSCRIPTION_PAYMENT_FAILED => [
                'label' => Craft::t('small-change', 'Recurring payment failed'),
                'to' => self::TO_DONOR,
                'when' => Craft::t('small-change', "When Stripe can't take a payment of a recurring donation."),
                'template' => 'subscription-payment-failed',
            ],
            self::KEY_SUBSCRIPTION_ACTION_REQUIRED => [
                'label' => Craft::t('small-change', 'Recurring payment needs confirming'),
                'to' => self::TO_DONOR,
                'when' => Craft::t('small-change', "When the donor's bank asks them to confirm a recurring payment."),
                'template' => 'subscription-action-required',
            ],
            self::KEY_SUBSCRIPTION_CANCELLED => [
                'label' => Craft::t('small-change', 'Recurring donation ended'),
                'to' => self::TO_DONOR,
                'when' => Craft::t('small-change', 'When a recurring donation is cancelled, by the donor, by you or by Stripe.'),
                'template' => 'subscription-cancelled',
            ],
            self::KEY_PORTAL_LINK => [
                'label' => Craft::t('small-change', 'Manage link'),
                'to' => self::TO_DONOR,
                'when' => Craft::t('small-change', 'When a donor asks for a link to update their card or cancel a recurring donation.'),
                'template' => 'portal-link',
            ],
            self::KEY_ADMIN_NEW_ORDER => [
                'label' => Craft::t('small-change', 'New order or donation'),
                'to' => self::TO_ADMIN,
                'when' => Craft::t('small-change', 'When an order is paid or a donation is received.'),
                'template' => 'admin-new-order',
            ],
            self::KEY_ADMIN_REFUND_FAILED => [
                'label' => Craft::t('small-change', 'Refund failed'),
                'to' => self::TO_ADMIN,
                'when' => Craft::t('small-change', "When Stripe can't complete a refund."),
                'template' => 'admin-refund-failed',
            ],
            self::KEY_ADMIN_DISPUTE => [
                'label' => Craft::t('small-change', 'Dispute opened'),
                'to' => self::TO_ADMIN,
                'when' => Craft::t('small-change', 'When a donor disputes a payment with their bank.'),
                'template' => 'admin-dispute',
            ],
            self::KEY_ADMIN_SUBSCRIPTION_CANCELLED => [
                'label' => Craft::t('small-change', 'Recurring donation ended'),
                'to' => self::TO_ADMIN,
                'when' => Craft::t('small-change', 'When a recurring donation is cancelled.'),
                'template' => 'admin-subscription-cancelled',
            ],
        ];
    }

    /**
     * What Craft's own email settings say, with environment references resolved. Used wherever the plugin's
     * sender fields are left blank.
     *
     * @return array{name: string, email: string, replyTo: string}
     */
    public static function systemSender(): array
    {
        $mail = App::mailSettings();

        return [
            'name' => (string) App::parseEnv($mail->fromName),
            'email' => (string) App::parseEnv($mail->fromEmail),
            'replyTo' => (string) App::parseEnv($mail->replyToEmail),
        ];
    }

    /**
     * @param array<string, scalar|null> $context extra facts for the template, such as a refund amount
     */
    public function queue(string $key, Order $order, array $context = []): void
    {
        if (!$this->settings()->emailEnabled($key)) {
            return;
        }

        Queue::push(new SendEmail(['key' => $key, 'orderId' => (int) $order->id, 'context' => $context]));
    }

    /**
     * Queues the link a donor asked for to manage their recurring donation.
     */
    public function queuePortalLink(Customer $customer, ?string $returnUrl): void
    {
        if (!$this->settings()->emailEnabled(self::KEY_PORTAL_LINK)) {
            return;
        }

        Queue::push(new SendPortalLink([
            'customerId' => (int) $customer->id,
            'returnUrl' => $returnUrl,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]));
    }

    /**
     * Sends one email. Returns true when it was sent or deliberately skipped, false when sending failed.
     *
     * @param array<string, scalar|null> $context
     */
    public function send(string $key, Order $order, array $context = []): bool
    {
        // Checked again here because a job queued before the switch was turned off still runs.
        if (!$this->settings()->emailEnabled($key) || !isset(self::TEMPLATES[$key])) {
            return true;
        }

        // A resent receipt is a deliberate second copy, so it skips the once-only guard and isn't recorded as the first.
        $isResend = $key === self::KEY_RECEIPT_RESEND;
        $key = $isResend ? self::KEY_RECEIPT : $key;
        $payment = $this->paymentFor($order, $context);

        // A job can be retried or run twice; the donor still gets one receipt.
        if (!$isResend && $key === self::KEY_RECEIPT && ($payment === null || $payment->receiptSentAt !== null || $payment->status !== Payment::STATUS_SUCCEEDED)) {
            return true;
        }

        $email = $this->compose($key, $order, $context, $payment);

        if ($email['to'] === '') {
            return true;
        }

        if (!$this->deliver($key, $email['to'], $email['subject'], $email['html'])) {
            Craft::warning("Could not send the {$key} email for order {$order->number}.", __METHOD__);

            return false;
        }

        if (!$isResend && $key === self::KEY_RECEIPT && $payment !== null) {
            Plugin::getInstance()->payments->markReceiptSent($payment);
        }

        return true;
    }

    /**
     * Builds an order email without sending it, so sending and the control panel preview render the same thing.
     *
     * @param array<string, scalar|null> $context
     * @param array<string, string>|null $content wording to use instead of what is saved, to preview unsaved changes
     * @return array{to: string, subject: string, html: string} an empty `to` when there is no one to send it to
     */
    public function compose(string $key, Order $order, array $context = [], ?Payment $payment = null, ?array $content = null): array
    {
        $key = $key === self::KEY_RECEIPT_RESEND ? self::KEY_RECEIPT : $key;
        $settings = $this->settings();
        $plugin = Plugin::getInstance();
        $payment ??= $this->paymentFor($order, $context);

        $to = match ($key) {
            self::KEY_ADMIN_NEW_ORDER, self::KEY_ADMIN_REFUND_FAILED, self::KEY_ADMIN_DISPUTE, self::KEY_ADMIN_SUBSCRIPTION_CANCELLED => $settings->adminEmail,
            default => (string) $order->email,
        };

        $sites = Craft::$app->getSites();
        $previousSite = $sites->getCurrentSite();

        // The site may have been deleted since; the email then goes out as the current site.
        if ($order->orderSiteId && $sites->getSiteById($order->orderSiteId)) {
            $sites->setCurrentSite($order->orderSiteId);
        }

        try {
            $siteName = $sites->getCurrentSite()->getName();
            $tokens = $this->tokens($siteName, $order, $payment, $context);
            $wording = $this->content($key, $tokens, $content);

            $html = $this->render(self::TEMPLATES[$key], [
                'order' => $order,
                'payment' => $payment,
                'lineItems' => $order->getLineItems(),
                'billingAddress' => $order->getBillingAddress(),
                'siteName' => $siteName,
                'settings' => $settings->withoutSecrets(),
                'context' => $context,
                'content' => $wording,
                'giftAid' => [
                    'declared' => $order->giftAidDeclared,
                    'amount' => $order->giftAidEligibleAmount,
                    'text' => $order->giftAidDeclared ? $plugin->giftAid->declarationText() : null,
                ],
            ]);

            return [
                'to' => $to,
                'subject' => $wording['subject'] !== '' ? $wording['subject'] : $this->subject($key, $order, $siteName, $tokens),
                'html' => $html,
            ];
        } finally {
            $sites->setCurrentSite($previousSite);
        }
    }

    /**
     * Sends the link that lets a donor manage their recurring donation. Not tied to an order, because the
     * donor asked for it by email address alone.
     */
    public function sendPortalLink(string $email, string $url): bool
    {
        if (!$this->settings()->emailEnabled(self::KEY_PORTAL_LINK)) {
            return true;
        }

        $message = $this->composePortalLink($url);

        return $this->deliver(self::KEY_PORTAL_LINK, $email, $message['subject'], $message['html']);
    }

    /**
     * @param array<string, string>|null $content
     * @return array{subject: string, html: string}
     */
    public function composePortalLink(string $url, ?array $content = null): array
    {
        $siteName = Craft::$app->getSites()->getCurrentSite()->getName();
        $wording = $this->content(self::KEY_PORTAL_LINK, $this->tokens($siteName), $content);
        $html = $this->render('portal-link', [
            'url' => $url,
            'siteName' => $siteName,
            'settings' => $this->settings()->withoutSecrets(),
            'content' => $wording,
        ]);

        return [
            'subject' => $wording['subject'] !== '' ? $wording['subject'] : Craft::t('small-change', 'Manage your recurring donation to {siteName}', ['siteName' => $siteName]),
            'html' => $html,
        ];
    }

    /**
     * The wording changed in the control panel for one email, with its placeholders filled in. It is plain
     * replacement rather than Twig, so text typed into the control panel can't run code, and the message is
     * escaped and laid out as paragraphs.
     *
     * @param array<string, string> $tokens
     * @param array<string, string>|null $content wording to use instead of what is saved
     * @return array{subject: string, heading: string, message: Markup|null}
     */
    public function content(string $key, array $tokens, ?array $content = null): array
    {
        $settings = $this->settings();
        $text = static function(string $field) use ($settings, $key, $tokens, $content): string {
            $value = $content !== null ? trim((string) ($content[$field] ?? '')) : $settings->emailText($key, $field);

            return strtr($value, $tokens);
        };

        $message = $text('message');
        $paragraphs = array_map(
            static fn(string $paragraph): string => '<p>' . nl2br(Html::encode(trim($paragraph)), false) . '</p>',
            preg_split('/\R\s*\R/', $message) ?: [],
        );

        return [
            // A subject is a single line whatever was typed.
            'subject' => trim(preg_replace('/\s+/', ' ', $text('subject')) ?? ''),
            'heading' => $text('heading'),
            'message' => $message === '' ? null : new Markup(implode("\n", $paragraphs), 'UTF-8'),
        ];
    }

    /**
     * An email as it would go out now, for the control panel: with the latest order it suits, or made-up details
     * when there isn't one. Nothing is sent or recorded.
     *
     * @param array<string, string>|null $content wording to use instead of what is saved
     * @return array{subject: string, html: string, order: Order|null} order is null when the details are made up
     */
    public function preview(string $key, ?array $content = null): array
    {
        if ($key === self::KEY_PORTAL_LINK) {
            return [...$this->composePortalLink(UrlHelper::siteUrl(), $content), 'order' => null];
        }

        $order = $this->latestOrderFor($key);
        $sample = $order === null;
        $order ??= $this->sampleOrder();
        $context = [
            'interval' => $order->getLineItems()[0]->recurringInterval ?? 'month',
            'amount' => $order->total,
            'invoiceUrl' => 'https://invoice.stripe.com/',
            'reason' => Craft::t('small-change', 'An example reason from Stripe'),
        ];
        $email = $this->compose($key, $order, $context, null, $content);

        return ['subject' => $email['subject'], 'html' => $email['html'], 'order' => $sample ? null : $order];
    }

    /**
     * The site template that replaces this email's built-in one, if there is one.
     */
    public function overrideTemplate(string $key): ?string
    {
        $template = self::definitions()[$key]['template'] ?? null;

        if ($template === null) {
            return null;
        }

        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();
        $override = trim($this->settings()->emailTemplatePath, '/') . '/' . $template;

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            return $view->doesTemplateExist($override) ? $override : null;
        } finally {
            $view->setTemplateMode($previousMode);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array<string, string>
     */
    private function tokens(string $siteName, ?Order $order = null, ?Payment $payment = null, array $context = []): array
    {
        $amount = is_numeric($context['amount'] ?? null) ? (int) $context['amount'] : ($payment->amount ?? $order?->total);

        return [
            '{charityName}' => $this->settings()->getOrganisationName(),
            '{siteName}' => $siteName,
            '{orderNumber}' => (string) $order?->number,
            '{firstName}' => (string) $order?->getBillingAddress()?->firstName,
            '{amount}' => $order !== null && $amount !== null ? Money::format((int) $amount, (string) $order->currency) : '',
        ];
    }

    private function latestOrderFor(string $key): ?Order
    {
        $query = Order::find()
            ->isCompleted()
            ->orderBy(['smallchange_orders.dateCompleted' => SORT_DESC]);

        if (in_array($key, self::RECURRING_KEYS, true)) {
            $query->kind(Order::KIND_RECURRING);
        }

        /** @var Order|null */
        return $query->one();
    }

    /**
     * A made-up donation for previews on a site with no orders yet. Never saved.
     */
    private function sampleOrder(): Order
    {
        $settings = $this->settings();
        $amount = 25 * 10 ** Money::exponent($settings->currency);

        $order = new Order();
        $order->number = 'SAMPLE1234';
        $order->email = 'donor@example.com';
        $order->currency = $settings->currency;
        $order->kind = Order::KIND_ONE_OFF;
        $order->state = Order::STATE_COMPLETED;
        $order->paymentStatus = Order::PAYMENT_PAID;
        $order->dateCompleted = new DateTime();
        $order->setLineItems([new LineItem([
            'kind' => LineItem::KIND_DONATION,
            'description' => Craft::t('small-change', 'Donation'),
            'unitPrice' => $amount,
            'qty' => 1,
            'isGiftAidEligible' => true,
        ])]);
        $order->itemSubtotal = $amount;
        $order->total = $amount;
        $order->totalPaid = $amount;
        $order->giftAidDeclared = $settings->giftAidEnabled ? true : null;
        $order->giftAidEligibleAmount = $settings->giftAidEnabled ? $amount : 0;
        $order->setBillingAddress(new Address([
            'firstName' => 'Sam',
            'lastName' => 'Example',
            'addressLine1' => '1 High Street',
            'postalCode' => 'AB1 2CD',
            'countryCode' => 'GB',
        ]));

        return $order;
    }

    /**
     * A receipt names its payment: with a recurring donation a later payment can land before an earlier receipt is sent.
     *
     * @param array<string, scalar|null> $context
     */
    private function paymentFor(Order $order, array $context): ?Payment
    {
        $paymentId = (int) ($context['paymentId'] ?? 0);

        return ($paymentId ? Plugin::getInstance()->payments->getPaymentById($paymentId) : null) ?? $this->latestPayment($order);
    }

    private function deliver(string $key, string $to, string $subject, string $html): bool
    {
        $settings = $this->settings();
        $message = Craft::$app->getMailer()->compose()
            ->setTo($to)
            ->setSubject($subject)
            ->setHtmlBody($html)
            ->setTextBody($this->toText($html));

        // A name on its own is paired with Craft's address, so it isn't ignored.
        $fromAddress = $settings->emailFromAddress !== '' ? $settings->emailFromAddress : ($settings->emailFromName !== '' ? $this->systemFromAddress() : '');

        if ($fromAddress !== '') {
            $message->setFrom($settings->emailFromName !== '' ? [$fromAddress => $settings->emailFromName] : $fromAddress);
        }

        if ($settings->emailReplyTo !== '') {
            $message->setReplyTo($settings->emailReplyTo);
        }

        $event = new EmailEvent(['key' => $key, 'message' => $message]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        // A listener cancelling a send counts as handled.
        return !$event->isValid || Craft::$app->getMailer()->send($event->message);
    }

    protected function systemFromAddress(): string
    {
        return self::systemSender()['email'];
    }

    /**
     * Renders the site's override if there is one, else the template that ships with the plugin.
     *
     * @param array<string, mixed> $variables
     */
    public function render(string $name, array $variables): string
    {
        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();
        $override = trim($this->settings()->emailTemplatePath, '/') . '/' . $name;
        // The built-in templates print the control panel wording, so a caller that has none gets the built-in wording.
        $variables += ['content' => ['subject' => '', 'heading' => '', 'message' => null]];

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if ($view->doesTemplateExist($override)) {
                return $view->renderTemplate($override, $variables);
            }

            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate('small-change/emails/' . $name, $variables);
        } finally {
            $view->setTemplateMode($previousMode);
        }
    }

    public function toText(string $html): string
    {
        // A plain-text reader has no links to click, so each link's address is written out after its words.
        $text = preg_replace_callback('~<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', static function(array $m): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(strip_tags($m[2]));

            return $label === '' || $label === $url ? $url : $label . ': ' . $url;
        }, $html) ?? $html;
        $text = preg_replace(['~<(br|/p|/h[1-6]|/tr|/li)\s*/?>~i', '~</t[dh]>~i'], ["\n", ' '], $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("~[ \t]+~", ' ', $text) ?? $text;
        $text = preg_replace("~ ?\n ?~", "\n", $text) ?? $text;

        return trim(preg_replace("~\n{3,}~", "\n\n", $text) ?? $text);
    }

    /**
     * @param array<string, string> $tokens
     */
    private function subject(string $key, Order $order, string $siteName, array $tokens): string
    {
        return match ($key) {
            self::KEY_RECEIPT => strtr($this->settings()->receiptSubject, $tokens),
            self::KEY_ADMIN_NEW_ORDER => Craft::t('small-change', '{kind} {number} on {siteName}', [
                'kind' => $order->getHasDonation() ? Craft::t('small-change', 'New donation') : Craft::t('small-change', 'New order'),
                'number' => $order->number,
                'siteName' => $siteName,
            ]),
            self::KEY_ADMIN_REFUND_FAILED => Craft::t('small-change', 'A refund failed on order {number}', ['number' => $order->number]),
            self::KEY_ADMIN_DISPUTE => Craft::t('small-change', 'A payment was disputed on order {number}', ['number' => $order->number]),
            self::KEY_ADMIN_SUBSCRIPTION_CANCELLED => Craft::t('small-change', 'A recurring donation was cancelled on order {number}', ['number' => $order->number]),
            self::KEY_SUBSCRIPTION_PAYMENT_FAILED => Craft::t('small-change', "We couldn't take your recurring donation to {siteName}", ['siteName' => $siteName]),
            self::KEY_SUBSCRIPTION_ACTION_REQUIRED => Craft::t('small-change', 'Please confirm your recurring donation to {siteName}', ['siteName' => $siteName]),
            self::KEY_SUBSCRIPTION_CANCELLED => Craft::t('small-change', 'Your recurring donation to {siteName} has ended', ['siteName' => $siteName]),
            default => Craft::t('small-change', "We couldn't take your payment to {siteName}", ['siteName' => $siteName]),
        };
    }

    private function latestPayment(Order $order): ?Payment
    {
        if (!$order->id) {
            return null;
        }

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);

        return $payments ? end($payments) : null;
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return Plugin::getInstance()->getSettings();
    }
}
