# Local development with Stripe

Small Change completes orders from Stripe webhooks, so Stripe needs a way to reach your local site. The
Stripe CLI does that by forwarding events to your machine.

## 1. Use test keys

In your `.env`:

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

These are the variable names the settings point at out of the box (**Small Change → Settings → Stripe**). If more
than one person works on the site, we'd give each developer a separate Stripe sandbox so webhook events don't mix.

The plugin reads the mode from the secret key's prefix: `sk_test_` (or `rk_test_` for a restricted key) is test
mode, `sk_live_` (or `rk_live_`) is live. In test mode, every Small Change screen in the control panel says so at
the top.

## 2. Forward webhooks

Install the [Stripe CLI](https://docs.stripe.com/stripe-cli), log in, then forward events to the
plugin's webhook action. On a local site, step 2 of **Small Change → Settings → Stripe** shows the webhook URL
and a ready-made `stripe listen --forward-to` command for it. With DDEV you'd add `--skip-verify`, like this
(the `index.php?action=` form of the address works as well as the `/actions/` one the settings page shows):

```bash
stripe listen --forward-to "https://your-site.ddev.site/index.php?action=small-change/webhooks/stripe" --skip-verify
```

You only need `--skip-verify` because DDEV's local certificate isn't publicly trusted. The command
prints a signing secret beginning `whsec_`. Put it in `STRIPE_WEBHOOK_SECRET`. The secret stays the same
each time you restart `stripe listen`.

The endpoint has to receive **snapshot** events. A Dashboard endpoint created with the default "Thin"
payload is rejected with a 400 and a message saying so. Events sent by `stripe listen` are snapshot
events, so you're fine here.

## 3. Try a donation

Use Stripe's test cards:

| Card | Result |
|---|---|
| `4242 4242 4242 4242` | Succeeds |
| `4000 0025 0000 3155` | Asks for 3D Secure |
| `4000 0000 0000 9995` | Declined |

Any future expiry date and any three-digit CVC will do.

A completed donation shows in **Small Change → Orders**, and the Stripe utility (**Utilities → Stripe**)
lists the most recent webhook events and how each was handled.

## If Stripe rejects the payment form

Small Change creates Checkout Sessions with Stripe's *Managed Payments* (merchant of record) switched off.
Your site is the merchant: it sends its own receipts and handles Gift Aid. Stripe can turn Managed Payments on
by default for an account, and in that mode the embedded payment form is refused with
"Invalid ui_mode: elements". The plugin opts out on every session, so you shouldn't see this. If you do, check
**Settings → Managed Payments** in the Stripe Dashboard and make sure the plugin is up to date.

## Notes

- `stripe trigger` creates unrelated objects, so the plugin can't match them to an order. It stores the
  event, answers 200 and marks it ignored. That's expected.
- If a webhook fails, the plugin answers 500 and Stripe retries it. The event is stored either way, so
  you can see what went wrong in the utility.
- The plugin sends the receipts. In your Stripe Dashboard, turn off "Successful payments" customer
  emails so donors don't get two.
