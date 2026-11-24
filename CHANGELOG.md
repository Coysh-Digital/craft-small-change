# Release notes for Small Change

## 1.2.0

- Subscription products. A non-physical product can be billed every month or every year, and can end by itself after
  a set number of payments. Add the new **Subscription** field to a product type's layout to use it. See
  [Subscription products](docs/subscriptions.md).
- A discount code on a subscription takes its share off every payment, not only the first.
- A subscription that takes its last payment ends at the end of that period and emails the customer a new *Payment
  plan complete* message instead of a cancellation.
- Recurring payment emails say "payment" and "subscription" rather than "donation" when the order has no donation.

## 1.1.0

- Discount codes can be limited to chosen products. Existing codes still apply to every product.

## 1.0.0

Initial public release.

- The email sender, admin address and email wording are stored in the database rather than project config, so admins can change them in production.
