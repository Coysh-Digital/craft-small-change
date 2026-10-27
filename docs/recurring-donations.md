# Recurring donations

A donation product can offer monthly and yearly giving. Switch on **Allow monthly or yearly gifts** on the product and
choose which intervals to offer. The example donation form then shows a "How often" choice.

## How it works

A recurring donation is a Stripe Subscription started by Checkout. Stripe owns the schedule and the card. Small
Change keeps a local copy, so the control panel can list recurring donations and every payment can be traced to
the order that started it.

- The first payment completes the order, exactly like a one-off donation.
- Each later payment is added to the same order as a new payment, with its own receipt.
- If the donor declared Gift Aid, it applies to the whole of every payment. Donations are never mixed with goods
  in a recurring order.

Small Change creates a Stripe Product for each donation product the first time someone gives to it monthly or
yearly, so your Dashboard groups the income by campaign. **Utilities → Stripe → Sync products to Stripe** (or
`php craft small-change/stripe/sync-products`) creates any that are missing and renames those whose title changed.
The buttons on that utility are for admins.

## Seeing them

**Small Change → Recurring** lists every recurring donation with its status and next payment date. Open one to
see its payments, ask Stripe for its latest state with **Check with Stripe**, or stop it with **Cancel at period
end** or **Cancel now**. Refunds are made from the order, as for any payment.

Statuses follow Stripe's: `active`, `past_due` (a payment failed and Stripe is retrying), `unpaid`, `paused`,
`canceled` and so on.

## When a payment fails

Stripe retries a failed payment on its own schedule. Meanwhile the donor is emailed a link to Stripe's hosted
invoice page, where they can pay with a different card, and the subscription shows as past due. If Stripe needs
the donor to confirm the payment (3-D Secure, for example), they get an email about that too. When a recurring
donation ends, whether Stripe gave up on it, the donor cancelled or you did, the donor and your admin address both
get an email.

## Letting donors manage their own

There are no donor accounts. A donor who wants to change their card or stop giving asks for a link, using the
**Recurring donor pages** templates (`donate/manage.twig`):

1. They enter their email address. The reply is always the same ("If that email address has a recurring
   donation, we've emailed you a link to manage it.") whether or not it matches, so the form can't be used to find
   out who donates. If you've switched that email off, the form says links can't be sent at the moment instead.
2. If the address has a recurring donation that hasn't ended, they're emailed a signed link that works for 15 minutes. The email goes through the queue, so
   the form answers just as quickly for an address that doesn't match.
3. The link opens Stripe's customer portal, where they update their card or cancel. Stripe tells Small Change,
   and the control panel catches up.

Requests are rate-limited. Signed-in users can go straight to the portal with
`actionUrl('small-change/portal/open')` (see the installed `account/donations.twig`, and the
[form reference](templates.md#manage-a-recurring-donation-small-changeportal)). That only works for a recurring
donation they made while signed in. Small Change doesn't match on the account's email address, because on a site where anyone
can register without confirming it, a stranger could use a donor's address to reach their card and invoices.
Someone who gave as a guest uses the emailed link, which proves they own the address.

The portal only works once it's set up in the Stripe Dashboard (**Settings → Billing → Customer portal**). That
includes deciding whether donors may cancel.

## Checking for missed changes

Webhooks keep the list up to date. If Stripe couldn't reach your site for a while, run
`php craft small-change/stripe/reconcile-subscriptions` (or **Check recurring donations** on the Stripe utility,
which runs in the background) to ask Stripe about every recurring donation that hasn't ended, and
`php craft small-change/stripe/replay-events --since="-3 days"` to process any events that were missed. See
[Console commands](commands.md).
