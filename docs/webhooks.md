# Webhooks

Webhooks are the source of truth for payment state. The visitor's browser might close, a network might drop, and
Stripe can deliver an event twice or out of order. None of that can leave an order wrong, because an order only
ever moves on what Stripe says happened.

## What the plugin does with each delivery

1. Rejects *thin* events with a 400: the endpoint must use the **Snapshot** payload style.
2. Verifies the signature against `STRIPE_WEBHOOK_SECRET`, and rejects anything older than five minutes. With no
   secret set, or a signature that doesn't match, it answers 403.
3. Stores the event, keyed by Stripe's event ID, before doing anything with it. A second delivery of an event
   that was already handled is answered with 200 without doing the work again. A delivery of one that failed
   runs it again.
4. Ignores events for the other Stripe mode, and keeps only their ID and type, so a test site never holds live
   donors' details.
5. Runs the handler inline, inside a database transaction, and answers Stripe. Two copies of one event arriving at
   once are handled one after the other, and the second finds nothing left to do. A failure answers 500, so
   Stripe retries, and the stored row shows the error. Emails go through the queue.

**Utilities → Stripe** lists the latest 50 events with their result (`processed`, `ignored`, `failed`) and the
number of tries.

## Events handled

`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`,
`checkout.session.expired`, `payment_intent.succeeded`, `payment_intent.payment_failed`,
`payment_intent.processing`, `charge.refunded`, `refund.created`, `refund.updated`, `refund.failed`,
`charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`, `invoice.paid`,
`invoice.payment_failed`, `invoice.payment_action_required`, `invoice.voided`, `invoice.marked_uncollectible`,
`customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`,
`customer.subscription.paused`, `customer.subscription.resumed`, `customer.updated` and `customer.deleted`.
Anything else is stored and ignored.

## Registering the endpoint

Step 2 of **Small Change → Settings → Stripe** shows the webhook URL with a copy button, and **Utilities →
Stripe** shows the same block. If the address isn't one Stripe can reach (plain `http`, `localhost`, or a
development domain such as `.test` or `.ddev.site`), it warns you and gives you the `stripe listen --forward-to`
command to use instead.

On a site Stripe can reach, an admin can use **Register webhook endpoint with Stripe** on either page (on the
settings page it appears once a secret key is saved), or run

```bash
php craft small-change/stripe/register-webhook --site-url=https://example.org
```

It subscribes to exactly the events above, pins the endpoint to API version `2026-09-30.endive`, and shows the
signing secret once, so copy it into `STRIPE_WEBHOOK_SECRET` straight away. The endpoint's address is
`https://example.org/actions/small-change/webhooks/stripe`. The command only accepts an `https` address. If this
site already has a working endpoint at that address, registering again is refused, because a second endpoint
would sign with a secret you don't have; delete the old one in the Stripe Dashboard first if you need a new
secret.

You can also add the endpoint by hand in the Stripe Dashboard under Developers → Webhooks: the settings page lists
the events to tick. Either way, we'd leave the event list alone. The plugin ignores events it doesn't handle, but
it can't act on one it needs and never receives.

Once the secret is in your environment, step 3 on the settings page shows whether your keys and signing secret
are set.

For local development, forward events with the Stripe CLI instead: see [local development](local-development.md).

## Catching up

If your site was down or the secret was wrong for a while, nothing is lost: Stripe keeps events for 30 days.

```bash
php craft small-change/stripe/replay-events --since="-3 days"
```

That fetches the events above from Stripe and processes any this site never stored or failed to handle. Add
`--type=invoice.paid` to fetch only one type. **Process missed events (last 3 days)** on the Stripe utility does
the same for the last three days. Events already handled are skipped, so it's safe to run twice.

## Stored events and privacy

Event payloads contain names and emails, so they are purged after `webhookLogRetention` days (default 90) and
are redacted when you erase a donor. See [Privacy](privacy.md).
