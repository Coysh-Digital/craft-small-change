# Troubleshooting

## An order stays "pending" after the donor paid

Stripe's webhook is what completes an order. Open **Utilities → Stripe** and see which of these fits:

- If there are no events at all, Stripe can't reach your site. Compare the endpoint address in the Stripe
  Dashboard (Developers → Webhooks) with the **Webhook URL** on **Small Change → Settings → Stripe**, check it's
  the live endpoint for a live key, and make sure your site isn't behind a password or firewall. When the URL
  isn't public, that tab shows the `stripe listen --forward-to` command to run instead (see
  [local development](local-development.md)).
- If Stripe's delivery log for the endpoint (Developers → Webhooks) shows a 403 with "The signature could not
  be verified.", `STRIPE_WEBHOOK_SECRET` is the wrong secret. Each endpoint has its own, and the Stripe CLI
  prints a different one. A 403 with "The webhook signing secret is not configured." means the variable is
  empty.
- A 400 with "This endpoint must use the Snapshot payload format, not Thin." means the endpoint was created as
  *Thin*. Delete it in the Dashboard and register it again with **Register webhook endpoint with Stripe** or
  `php craft small-change/stripe/register-webhook`.
- Events marked `failed` show the error beside them. Fix the cause, then press **Process missed events (last 3
  days)** or run `php craft small-change/stripe/replay-events` (Stripe will also retry on its own).
- Events marked `ignored` were for the other Stripe mode (test events at a live site, or the reverse)
  or for something Small Change doesn't handle.

After an outage, `php craft small-change/stripe/replay-events --since="-3 days"` catches up. See
[Webhooks](webhooks.md) for more.

## The donor paid but got no receipt

First check that **Send emails** is on and the **Receipt** isn't switched off under **Small Change → Settings →
Emails** (see [Emails](emails.md)). Emails go out through the queue, so make sure a queue runner is active
(`php craft queue/listen`, or cron running `php craft queue/run`), then look at **Utilities → Queue Manager** for
failed jobs. Once that's fixed, use **Resend receipt** on the order.

## "Invalid ui_mode: elements" (or a mention of Managed Payments)

Your Stripe account has Stripe's merchant-of-record product on by default. Small Change opts every payment out
of it, so you shouldn't see this. If you do, tell us, because that's a bug.

## The payment page says "We couldn't find that donation"

The page only works for the browser session that placed the order, or through the signed link Stripe returns
the donor to (valid for two hours). Opening the URL in another browser, or hours later, shows nothing, and that's
by design. Receipts don't link to the order for the same reason. On the shop's payment page the message is "We
couldn't find that order".

## A discount code "is not valid"

The message is deliberately vague. Check that the code is enabled, within its dates, over the minimum spend and
not used up. A code with a usage limit is also held while someone else is paying with it, and it comes back if
they abandon the payment.

## The payment form never appears

The page loads but there's no card form. Almost always, the layout the pages extend doesn't output
`{{ endBody() }}` before `</body>`, so Small Change's script and Stripe's are never added. The installer warns
about this ("Your layout does not output endBody()"). Check the layout also has `{{ head() }}` and
`{{ beginBody() }}`. Open the browser console: a missing `Stripe` script or a blocked request to
`js.stripe.com` shows up there. If the form appears but looks wrong on a phone, add
`<meta name="viewport" content="width=device-width, initial-scale=1">` to the layout.

## A 500 error that mentions a missing template

It usually reads "Unable to find the template" followed by a name, and it comes from an `{% include %}` in one of
your own templates that points at a Small Change page that isn't there. Check the name first. The "raised so far"
bar is `donate/_progress`, with an underscore, and `donate/progress` won't be found. If the templates simply
haven't been installed yet, use **Small Change → Settings → Templates**, or write the include so it can't break the page:

```twig
{% include 'donate/_progress' ignore missing %}
```

## A form fails, or someone else's basket shows, on a cached page

That's a static cache serving one visitor's copy of a page to another. Small Change keeps the personal pages out of
the cache for you, but forms on cacheable pages need a token that's fetched per visitor, and anything personal
needs a dynamic include. [Caching and Blitz](caching.md) walks through it.

## The installed pages are blank

The block name you gave at install has to exist in your layout. The installer warns when it can't find it: "Your
layout does not seem to have a "content" block, so the pages will show nothing until it does." If your layout has `{% block main %}`, install with
`--block=main`. To fix a site that's already installed, change `{% block content %}` at the top of each page to your
block's name, or run the installer again with `--overwrite`.

## Amounts look wrong by a factor of 100

Amounts are stored and sent in minor units (pence). If you read them in a template, use
`craft.smallChange.formatMoney()` or `craft.smallChange.decimal()` rather than printing the raw number.

## A recurring donor says they cannot get in

The manage link works for 15 minutes, and only for addresses that have a running recurring donation. To be safe,
the page says the same thing whether or not the address matched, so check **Small Change → Recurring** for their
email. Also check that Stripe's customer portal is switched on in the Dashboard (**Settings → Billing →
Customer portal**). If the page says it can't email links at the moment, the **Manage link** email (or all
email) is switched off under **Small Change → Settings → Emails**.

## Upgrading

Run `php craft up` after every update, then check that **Utilities → Stripe** shows no failed events.
