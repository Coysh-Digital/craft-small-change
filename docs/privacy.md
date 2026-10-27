# Privacy and data retention

Small Change holds personal data (names, emails, addresses, notes), and so do the Stripe events it stores. This
page says where, for how long, and how to remove it. We can describe what the software does, but whether that
satisfies your legal duties as the data controller is for you and your data protection lead to decide.

## Where personal data lives

| Where | What | Kept |
| --- | --- | --- |
| Orders | Email, delivery address, Gift Aid home address, notes, checkout field answers, the IP address at checkout | Until you erase it |
| Baskets | The same, before payment | 30 days of inactivity (`purgeInactiveCartsDuration`), then deleted |
| Customers | Email, name, Stripe customer ID | Until erased |
| Stored Stripe events | The full event payload, which names the donor | 90 days (`webhookLogRetention`) |
| Stripe itself | The customer record, payments, card details | Governed by Stripe; see their terms |

Card details never touch your server.

## Automatic housekeeping

Craft's garbage collection (which runs on its own on live sites, and on demand with `php craft gc`) removes
abandoned baskets and their addresses, webhook events older than the retention period, and expired discount
reservations. Once a basket has started payment it's a pending order, so it's never purged as a basket.

You can also set **Anonymise completed orders after (days)** on the Privacy tab of the settings
(`anonymiseOrdersAfter`; blank means never). The same garbage collection run then erases the personal data on
completed and cancelled orders that are older than that.

## Erasing a donor

Open one of their completed or cancelled orders and use **Erase personal data…** (needs the
`smallChange-anonymiseCustomers` permission), or from the command line, which asks you to confirm unless you add
`--yes`:

```bash
php craft small-change/orders/anonymise --email=person@example.org
```

This covers everything held under that email address:

- Every order they placed: the email becomes `anonymised-…@invalid`; the name, delivery and home addresses
  (the Address records are deleted), notes, checkout field answers, IP address and Craft user link are cleared.
  Unpaid baskets are deleted, and a checkout that was started but never paid is cancelled first.
- Their customer record is blanked, and the Stripe customer is deleted, so Stripe holds no email for them. If
  Stripe can't be reached at that moment, the local record is blanked anyway, the Stripe customer stays, and a
  warning goes in the log.
- Every stored webhook event that mentions them (found by Stripe customer, by the Stripe IDs of their orders and
  payments, and by their email address anywhere in the payload) has its payload replaced by
  `{"redacted": true, "type": …, "id": …}`.
- Staff notes on their refunds are cleared.

Erasure leaves order numbers, dates, amounts, the Gift Aid yes/no and eligible amount, payment and refund
records and Stripe's own IDs alone. Those are your financial records.

An order with a recurring donation that is still running is left alone and reported, because its emails still
have to reach someone. Cancel the donation first, then erase. Erasure can't be undone.

The *After anonymise order* event lets your own code remove the same person from your CRM or mailing list. See
[Events](events.md).

## Stripe's own emails

If you leave Stripe's customer emails on, Stripe sends its own receipts and holds those addresses. Small Change
sends receipts itself, so turn Stripe's off (Settings → Business → **Customer emails** in the Dashboard).

## Gift Aid and your CRM

We left Gift Aid record keeping out of Small Change on purpose: beyond the yes/no and the amount on each order and
payment, it keeps nothing about declarations. HMRC expects you to keep the declaration details, so they need to
live somewhere else. If you keep them in a CRM, tell that system when you erase someone.

## Craft users

Deleting a Craft user doesn't delete their orders. The link between them is removed, and the order, with its
money, stays.
