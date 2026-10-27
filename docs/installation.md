# Installation and first donation

## Requirements

- Craft CMS 5.0 or later and PHP 8.2 or later
- MySQL 8 or PostgreSQL 13 or later
- A Stripe account (a free sandbox is enough to start)
- A site that Stripe can reach for webhooks (see [Webhooks](webhooks.md); you don't need this to try things locally)

## Install

```bash
composer require coyshdigital/craft-small-change
php craft plugin/install small-change
```

## Add your Stripe keys

Copy your keys from the Stripe Dashboard (Developers → API keys) into `.env`:

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Then open **Small Change → Settings → Stripe**. Step 1 there covers the two API keys, and the signing secret sits
in step 2. All three fields hold references to those variables (`$STRIPE_SECRET_KEY` and so on), and they come
filled in with exactly those names. Small Change refuses a raw secret key or signing secret, so a secret can't end
up in project config or version control.

Small Change reads the mode from the secret key: `sk_test_` is test mode and `sk_live_` is live (a restricted
`rk_test_` or `rk_live_` key works the same way). A banner at the top of every Small Change screen tells you when
you're in test mode.

While you're in the settings, the **General** tab has the currency (GBP unless you change it) and the charity
name used in Gift Aid wording and emails. The currency can change until the first live order and is fixed after
that. See [Settings](settings.md).

## Create a donation product

1. **Small Change → Product types → New product type**: choose the *Donation* kind. Give it a URI format and
   template if donations should have their own pages.
2. **Small Change → Products → New product**: add suggested amounts (for example `5, 10, 25`), a minimum, and
   whether the donor can type their own amount.

## Install the templates

Open **Small Change → Settings → Templates**, tick **Donations** (and **Shop** or **Recurring donor pages** if you want
them), give the path of your site's layout (like `_layouts/site`) and the block your layout puts content in, or
let it add a plain layout for you, and press **Install templates**. Or from the terminal:

```bash
php craft small-change/templates/install --sets=donations --layout=_layouts/site
```

Visit `/donate`. There are no routes to add. The pages are plain HTML, and once they're installed they're yours to
change however you like. [Your front-end templates](templates.md) explains how they fit together and how to edit them.

## Connect webhooks

Stripe's webhooks are what complete an order, so Stripe has to be able to reach your site.

- Step 2 on **Small Change → Settings → Stripe** shows the webhook URL, with a button to copy it, and the ways to
  create the endpoint. Step 3 checks the connection.
- On a live or staging site, use **Register webhook endpoint with Stripe** on that tab or on **Utilities → Stripe** (or
  `php craft small-change/stripe/register-webhook`). It creates the endpoint for the events Small Change
  handles and shows you the signing secret once. Put that in `STRIPE_WEBHOOK_SECRET`.
- On your own computer, Stripe can't reach the URL, and the tab says so and gives you the
  `stripe listen --forward-to` command to forward events with the Stripe CLI. See
  [local development](local-development.md).

## Turn off Stripe's own receipts

Small Change sends its own receipt, which can carry Gift Aid wording and uses your templates. In the Stripe
Dashboard open Settings → Business → **Customer emails** and turn off **Successful payments**, or donors will get two.
If you'd rather keep Stripe's, switch off Small Change's receipt under **Small Change → Settings → Emails**
instead (see [Emails](emails.md)).

## Try it

Use Stripe's test card `4242 4242 4242 4242`, any future expiry, any CVC. The order should appear under
**Small Change → Orders**, the donor should get a receipt, and **Utilities → Stripe** should list the webhook events.

## Going live

1. Put your live keys and live webhook secret in the live environment's `.env`, and check the currency, because
   it's fixed once the first live order exists.
2. Register the webhook endpoint in live mode (the button or command above, run on the live site).
3. Delete what test mode left behind: **Utilities → Stripe → Delete test data**, or
   `php craft small-change/orders/delete-test-data`. It only runs once live keys are in, and it removes test
   orders, donors, recurring donations and stored events.
4. Make a small real donation and refund it from the order screen. We'd always do this before telling anyone the
   form is live.

Test and live data are kept apart. Every order, payment and subscription is marked with the Stripe mode it
belongs to, and a webhook for the other mode is ignored. It's logged without its contents, so a staging copy
that was pointed at a live endpoint by mistake doesn't end up holding live donors' details. Campaign totals
(`donationTotal`) count only the mode the site is using, and the Orders index has a **Test orders** source and a
Mode column, so a leftover test order is easy to spot until you delete it.

## Uninstalling

Uninstalling (**Settings → Plugins**, or `php craft plugin/uninstall small-change`) removes everything the plugin
put in your database and project config: its tables, every product and order, the addresses attached to orders,
the product types with their field layouts, and the checkout and donation field layouts. Take a database backup
first if you might want any of it back.

Three things stay, because they aren't the plugin's to remove:

- Your Stripe account is untouched: customers, payments, subscriptions and the products behind recurring
  donations are all still there. Recurring donations keep charging until you cancel them in the Dashboard.
- The webhook endpoint the plugin registered. Delete it under Developers → Webhooks in the Dashboard, or Stripe
  will keep sending events to an address that no longer answers.
- The templates the installer wrote into your `templates/` folder, and the three Stripe variables in `.env`.
