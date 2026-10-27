# Small Change documentation

Small Change is a small, Stripe-only shop and donations plugin for Craft CMS 5. We built it for charities and small organisations that need to take donations and sell a few things, but can't justify the cost of a fully featured Craft Commerce plugin. We kept it small on purpose: when a choice is between more capable and simpler, we pick simpler.

If you're new, read these in order:

1. [Installation and first donation](installation.md): from `composer require` to a test donation.
2. [Your front-end templates](templates.md): installing the donation and shop pages into your own layout,
   what each one does, and every form they post.
3. [Showing one product or a donation form on a page](blocks.md): a field and a block that let an editor choose
   what to show, with no shop page needed.
4. [Settings](settings.md): every setting and what it does.
5. [Donations](donations.md) and [Recurring donations](recurring-donations.md).
6. [Gift Aid](gift-aid.md): what the plugin does, and what it leaves alone on purpose.
7. [Selling products](shop.md): products, delivery, discount codes and checkout fields.
8. [Webhooks](webhooks.md): how Stripe tells your site about payments, and how to connect it.
9. [Emails](emails.md): receipts and notices, changing their wording, and restyling them.
10. [Caching and Blitz](caching.md): keeping baskets and forms working behind a static cache.
11. [Twig reference](twig.md) and [Events](events.md), for developers.
12. [Privacy and data retention](privacy.md), [Console commands](commands.md) and
    [Troubleshooting](troubleshooting.md).
13. [Local development with Stripe](local-development.md).

## What it does and does not do

Small Change takes one-off and monthly or yearly donations, with Gift Aid as an option. It also has a basket for physical and non-physical products, flat-rate delivery, discount codes, refunds and disputes, receipts, a self-service link for recurring donors, and erasure of personal data.

It doesn't do tax or VAT, stock, product variants, more than one currency, any gateway but Stripe,
carrier-calculated shipping, customer accounts of its own (a signed-in Craft user can list their own orders and
nothing more), gift cards, Gift Aid record keeping or HMRC claims, or PDF invoices. If you need any of those, you
want Craft Commerce.

## How it works in one paragraph

Every payment goes through a Stripe Checkout Session that your server creates, so card details never touch
your site. The server works out every amount itself and ignores anything the browser says about money.
Stripe then tells your site what happened by webhook, and the webhook is the source of truth: an order is
only marked paid when Stripe says so, however many times and in whatever order the news arrives.
