# Console commands

Run these as `php craft small-change/<controller>/<command>`. Anything in square brackets is optional.

## Stripe

| Command | What it does |
| --- | --- |
| `stripe/register-webhook [--site-url=https://example.org]` | Creates the webhook endpoint at Stripe for the events Small Change handles, pinned to its API version, and prints the signing secret once. Needs an `https` address. Refuses if this site already has a working endpoint at that address; delete it in the Dashboard first if you need a new signing secret. |
| `stripe/sync-products` | Creates the Stripe products behind recurring donations and renames any whose title changed. |
| `stripe/reconcile-subscriptions` | Asks Stripe about every live recurring donation and corrects the local copy. |
| `stripe/replay-events [--since="-3 days"] [--type=invoice.paid]` | Fetches events Stripe still holds (30 days) and processes any this site never stored or didn't finish processing. Events already handled are skipped, so it's safe to run repeatedly. |

All four have buttons on **Utilities → Stripe** for admins.

## Templates

| Command | What it does |
| --- | --- |
| `templates/install [--sets=donations,recurring,shop\|all] [--layout=_layouts/site] [--block=content] [--folder=small-change] [--overwrite] [--yes]` | Copies the example pages into your `templates/` folder, wrapped in your layout. Asks for anything you leave out. An empty `--layout=` installs a plain layout. `--folder` puts everything in a subfolder and rewrites the pages' links to match, for a site that already has pages at `/cart` or `/checkout`. Existing files are left alone unless `--overwrite`, which asks first unless `--yes`. See [Your front-end templates](templates.md). |

## Orders and privacy

| Command | What it does |
| --- | --- |
| `orders/purge-carts [--days=30]` | Deletes baskets idle for that long, with their addresses. Without `--days` it uses the Privacy setting. Craft's garbage collection does this on its own too. |
| `orders/delete-test-data [--yes]` | The go-live clean-up: deletes every order, customer, recurring donation and stored event made with a test key, and recounts discount code uses. Refuses to run unless the site is using a live key, and asks for confirmation unless `--yes`. Also a button on **Utilities → Stripe** once live keys are in. |
| `orders/anonymise --email=person@example.org [--yes]` | Erases a donor's personal data from every order, customer record and stored webhook payload. Asks for confirmation unless `--yes`. See [Privacy](privacy.md). |

`orders/anonymise` exits with a non-zero status when it left something alone (for example an order with a running
recurring donation), so a script can tell.
