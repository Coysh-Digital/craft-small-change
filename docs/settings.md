# Settings

**Small Change → Settings** is for admins. Most settings are stored in project config, and you can change them
only where `allowAdminChanges` is on; where it's off, they're read-only. For the Stripe keys that means the
environment variable references only, never the keys themselves. The email sender, the admin address and the
wording of each email are the exception: they're stored in the database, so each environment keeps its own and
admins can change them in production too. You can also set any setting in `config/small-change.php`, and the
control panel then shows it as overridden. Saving the page leaves a setting alone when it's set in the config file
or its field is locked.

The tabs are General, Stripe, Checkout, Emails, Gift Aid and Privacy. Where admin changes are allowed there's also
**Templates**, the page installer described in [Your front-end templates](templates.md).

```php
<?php
// config/small-change.php
return [
    'checkoutMode' => 'hosted_page',
    'adminEmail' => 'office@example.org',
];
```

## Stripe

| Setting | Default | Meaning |
| --- | --- | --- |
| `stripeSecretKey` | `$STRIPE_SECRET_KEY` | An environment variable reference. A raw key (`sk_` or `rk_`) is rejected. |
| `stripePublishableKey` | `$STRIPE_PUBLISHABLE_KEY` | An environment variable reference, from the same mode as the secret key. |
| `stripeWebhookSecret` | `$STRIPE_WEBHOOK_SECRET` | An environment variable reference. A raw signing secret (`whsec_`) is rejected. |
| `stripeApiBase` | empty | Config file only. Points the plugin at a Stripe mock for development; leave it empty. |

The Stripe tab walks through setup in three numbered steps. First the API keys. Then the webhook: its URL with a
copy button, the events and API version to choose if you create the endpoint by hand, and a
**Register webhook endpoint with Stripe** button that does it for you once a secret key is saved. Last, a check of
the connection. If the URL is one Stripe can't reach, such as a `.ddev.site` address, the tab says so and gives
the `stripe listen` command to use instead.

## General

| Setting | Default | Meaning |
| --- | --- | --- |
| `currency` | `GBP` | The one currency for the whole install, as a three-letter code. It can change until the first live order. See below. |
| `charityName` | the site name | Used in Gift Aid wording and emails. The name itself or an environment variable such as `$CHARITY_NAME`. Leave it blank, or point it at a variable that isn't set, and the site name is used. |

### Changing the currency

Amounts are stored as whole pence/cents with no currency of their own, so changing the currency doesn't convert
anything: prices keep their numbers, and £6.50 becomes €6.50. The General tab lists the products, delivery
methods, fixed-amount discount codes and test orders that this affects, so you know what to check afterwards.

- Open baskets move to the new currency. Placed test orders keep the one they were placed in.
- A currency with a different number of decimal places (JPY has none, GBP has two) would change prices by a
  factor of 100, so it's refused while any products, delivery methods or fixed-amount codes exist.
- Once an order has been placed with a live key, real money is involved, and the currency is fixed for good.

The lock follows the data, so deleting `config/small-change.php` makes no difference to it. These checks run when
you save the settings page, though. A currency set in the config file skips them and doesn't move open baskets, so
don't change it there once you've gone live.

## Checkout

| Setting | Default | Meaning |
| --- | --- | --- |
| `checkoutMode` | `elements` | `elements` (**Embedded on your site**) shows Stripe's payment form inside your page. `hosted_page` (**Stripe-hosted page**) sends the donor to a page on Stripe. Both produce identical orders. |

## Gift Aid

| Setting | Default | Meaning |
| --- | --- | --- |
| `giftAidEnabled` | off | Shows the tick box on donations. See [Gift Aid](gift-aid.md). |
| `giftAidScope` | `pastAndFuture` | Which donations the declaration covers: `single` (this donation only), `future` (this one and future ones), `pastAndFuture` (HMRC's model wording, which also covers the past 4 years) or `custom`. Each preset has its own wording, shown on the tab. |
| `giftAidDeclarationText` | HMRC's model wording | Only used when `giftAidScope` is `custom`, and required then if Gift Aid is on. `{charityName}` is replaced with the charity name, or the site name if that's blank. If you write your own wording, it's up to you to make sure it still meets HMRC's rules. If `giftAidScope` has never been set and this wording differs from HMRC's, it's treated as `custom`, so edited wording keeps being used. |

## Emails

| Setting | Default | Meaning |
| --- | --- | --- |
| `sendEmails` | on | The master switch. Off stops every email Small Change sends, including the link a donor can ask for to manage a recurring donation. Stripe's own emails are separate. |
| `disabledEmails` | none | A list of email keys to switch off one by one, such as `receipt`. The Emails tab shows each email with its own switch, who gets it and when. The keys are in [Emails](emails.md). |
| `emailFromName`, `emailFromAddress`, `emailReplyTo` | blank | Sender. Anything left blank uses Craft's own email settings (**Settings → Email**), and the tab shows what those are. A From name with no address is used with Craft's address. Stored in the database. |
| `adminEmail` | blank | Where the new-order, refund-failed, dispute and cancellation notices go. Leave it empty and none of those are sent. Stored in the database. |
| `emailContent` | none | Wording changed on each email's edit screen: a subject, heading and message per email key. Anything blank keeps the built-in wording. Stored in the database. See [Emails](emails.md). |

The settings stored in the database aren't deployed with project config. Pulling a production database down to
develop against brings production's sender and wording with it. An upgrade from a version that kept them in project
config copies them across when its migration runs, and the next save of the settings page removes them from
project config.
| `receiptSubject` | `Your receipt from {siteName}` | The receipt's subject when its edit screen has none saved. The edit screen starts from this value, and a subject saved there wins. The placeholders in [Emails](emails.md) work here too. |
| `emailTemplatePath` | `_small-change/emails` | Where to put your own versions of the email templates. See [Emails](emails.md). |

## Privacy

| Setting | Default | Meaning |
| --- | --- | --- |
| `purgeInactiveCartsDuration` | 30 | Days before an abandoned basket is deleted. |
| `anonymiseOrdersAfter` | never | Optional. Days after which completed and cancelled orders lose their personal data. |
| `webhookLogRetention` | 90 | Days that stored Stripe events are kept. |

See [Privacy and data retention](privacy.md).

## Permissions

We'd give each person only what they need (**Settings → Users → User Groups**):

- `smallChange-manageProducts`, `smallChange-manageDiscounts`, `smallChange-manageShipping`
- `smallChange-viewOrders`, with `smallChange-manageOrders` (dispatch, cancel, notes, resend receipts),
  `smallChange-refundPayments` and `smallChange-anonymiseCustomers` (erase a donor's personal data)
- `smallChange-manageSubscriptions` (see and cancel recurring donations)

Product types, the checkout and donation fields, and these settings need an admin.

## Rate limits

The public actions are limited per visitor: 30 checkout starts and 30 donation starts in ten minutes, 200 basket
changes in ten minutes, and 5 portal-link requests an hour. Each attempt also counts against the address the
connection came from, with a ceiling twenty times higher, since a proxy or an office gateway serves many people.
If your site sits behind a proxy or CDN, check that Craft is seeing visitors' addresses rather than the proxy's
(Craft reads the usual forwarded headers; see `ipHeaders` in Craft's request component config), or every visitor
shares one limit.

A determined attacker will get past these limits. For a donation form that takes cards from the public, we'd also
turn on Stripe Radar's rules in the Dashboard, which is the best place to stop card testing.
