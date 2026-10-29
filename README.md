# Small Change

Small Change is a small shop and donations plugin for Craft CMS 5. We built it for charities and small organisations that need to take donations and sell a few things, but can't justify the cost of a fully featured Craft Commerce plugin. It will allow any site that has it installed to take one-off payments or recurring payments.

## Full documentation

The docs are over at [coysh.digital/plugins/craft-small-change](https://coysh.digital/plugins/craft-small-change/docs/). They cover installation, usage and troubleshooting. There are also good examples for templating in the docs.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A Stripe account

## Installation

```bash
composer require coyshdigital/craft-small-change
php craft plugin/install small-change
```

or

```bash
ddev composer require "coyshdigital/craft-small-change:^1.0" -w && ddev craft plugin/install small-change
``` 

Add your Stripe keys to `.env`:

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

The settings already point at those three variables. The settings page at **Small Change → Settings → Stripe** helps walk you through the keys and the webhook as well as checking the connection.

## Taking a donation

1. In **Small Change → Product types** create a *Donation* type, and give it a URI format and template if donations should have their own pages.
2. In **Small Change → Products** add a donation with suggested amounts.
3. Open **Small Change → Settings → Templates**, tick **Donations**, enter your site's layout (like `_layouts/site`) and press **Install templates** (or run `php craft small-change/templates/install`).
4. Create the Stripe webhook endpoint. The Webhook step on **Small Change → Settings → Stripe** shows the address to use and has a **Register webhook endpoint with Stripe** button. Put the signing secret Stripe gives you in `STRIPE_WEBHOOK_SECRET`. 

## Selling products

1. Create a *Physical* or *Non-physical* product type (**Small Change → Product types**), then add products with a price and an optional SKU.
2. Add one or more **Shipping** methods: a flat price for each method and optionally limited to some countries.
3. Optionally add **Discount codes**: a percentage or a fixed amount, with a minimum spend, dates and a maximum number of uses.
4. Optionally add **Checkout fields** (**Small Change → Checkout fields**), such as a gift message. Donation forms have their own **Donation fields**.
5. Optionally install the **Shop** templates from **Small Change → Settings → Templates** (product list, basket, checkout, payment and thank-you pages), then give your product type a URI format and template for product pages.

## Recurring donations

Switch on **Allow monthly or yearly gifts** on a donation product to offer monthly or yearly giving. Everything recurring is managed through Stripe


## AI disclosure

We use AI tools to help write parts of this plugin's code, tests and documentation. The design decisions are ours, and so is the responsibility for the result. If you find something wrong, please tell us.

## Support

If something doesn't work, or the docs don't cover it, open an issue at [github.com/Coysh-Digital/craft-small-change](https://github.com/Coysh-Digital/craft-small-change/issues) or email support@coysh.digital.

## Licence

Craft License. See `LICENSE.md`.
