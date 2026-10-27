# Caching and Blitz

A lot of Craft sites sit behind Blitz or another full-page cache, and a cache has one habit that matters here: it keeps the first copy of a page and hands it to everyone. That's fine for a product list. It's a problem for a basket, a thank-you page or a form with a CSRF token, because those belong to one visitor. Below is what Small Change does about that and what you need to set up.

We wrote it from Blitz's and Sprig's documentation and Blitz's source, not from a live site running both, so if something here doesn't match what you see, please tell us.

## What Small Change does for you

Any page that reads something personal is kept out of caches automatically. That means a template that calls `craft.smallChange.cart`, `cartSummary()`, `recentOrder()`, `checkoutClientSecret()`, `checkoutUrl()` or `orders()`. When one of those runs on a site request, Small Change:

- sends `Cache-Control: no-cache, no-store, must-revalidate`, which browsers and CDNs respect, and
- if Blitz is installed, tells it not to cache that page. It's the same as writing `{% do craft.blitz.options({ cachingEnabled: false }) %}` in the template.

Blitz is never required. Without it, only the headers are sent. For your own pages that show something personal without calling one of those functions, add the Blitz line above yourself.

## Which pages can be cached

| Page | Cached? | Why |
| --- | --- | --- |
| `/donate` | Yes | The form is the same for everyone. Its CSRF field comes from `_small-change/csrf`, and it doesn't pre-fill a signed-in user's email when Blitz is installed. |
| `/donate/payment`, `/donate/complete` | Never | They read the order and the payment secret. |
| `/donate/manage` | Yes | Same for everyone, with the CSRF partial. |
| `/account/donations` | Never | It lists one person's gifts. |
| `/shop` and product pages | Yes | On `/shop` the messages and the basket link are included dynamically (see below). The add-to-basket form uses the CSRF partial. |
| `/cart`, `/checkout`, `/checkout/payment`, `/checkout/complete` | Never | They read the basket or the order. |

Webhooks are POST requests, which Blitz never caches, so they need no setup. Stripe's return trip goes through an action URL and then to a page that reads the order, so that isn't cached either.

These are the paths the installer uses by default. If you installed into a folder (the **Folder** field on the Templates page, or `--folder` on the command line), put it in front of the page paths and the partial names in the rest of this page.

## Setting up Blitz

1. Install the templates from **Small Change → Settings → Templates**. The small partials the steps below use come with them: `_small-change/csrf` with every set, and `dynamic`, `flash` and `basket-link` with the shop.
2. Optional, but we'd recommend it: add excluded URI patterns for the pages that should never be cached. Blitz reads them as regular expressions, and excluded patterns win over included ones. Something like this, one per line:

   ```
   ^cart
   ^checkout
   ^donate/(payment|complete)
   ^account
   ```

   Small Change already keeps those pages out of the cache. The patterns are a second lock, in case someone later edits a template so it no longer calls one of the functions above.
3. Use `_small-change/csrf` for any form of your own on a cacheable page. With Blitz it outputs `craft.blitz.csrfInput()`, which fetches a token for the visitor as the page loads. A token baked into a cached page belongs to someone else's session, so a plain `csrfInput()` would make the form fail.
4. Put anything personal on a cacheable page behind a dynamic include. The shop page does this for its messages and basket link:

   ```twig
   {% include '_small-change/dynamic' with { template: '_small-change/basket-link' } only %}
   ```

   With Blitz, `_small-change/dynamic` calls `craft.blitz.includeDynamic()`, which renders the template fresh on every page load and injects it by AJAX. Without Blitz it's a plain include, so the same template works on both kinds of site. Blitz only lets you pass strings, numbers, booleans and arrays as params (no elements: pass an ID and look it up inside the template), and its own docs warn that dynamic includes aren't fast, so keep them few.
5. Don't pre-fill a form from `currentUser` on a cacheable page. Blitz can cache a page that was generated for a signed-in user, and then everyone gets their email address. The donation form already leaves it out when Blitz is installed.

## If you'd rather use Sprig

If you already use Sprig, that's another option. A form inside a Sprig component can post straight to a controller action, and Sprig adds the CSRF token itself:

```twig
{# templates/_components/add-to-basket.twig #}
<form sprig s-method="post" s-action="small-change/cart/add">
    <input type="hidden" name="productId" value="{{ productId }}">
    <label>Quantity <input type="number" name="qty" value="1" min="1"></label>
    <button type="submit">Add to basket</button>
</form>

{% if sprig.isSuccess %}
    <p role="status">Added to your basket.</p>
{% elseif sprig.isError %}
    <p role="alert">{{ sprig.message }}</p>
{% endif %}
```

```twig
{{ sprig('_components/add-to-basket', { productId: product.id }) }}
```

Sprig also has `sprig.triggerRefreshOnLoad()` for statically cached pages, which refreshes the components once the page has loaded so a stale token can't cause a failed first request.

This one is a best guess. We haven't run Small Change's actions through Sprig, and we don't know how your Sprig version treats an action that answers with a redirect or with JSON, so test adding to the basket, a failed add and a donation before you rely on it. If it misbehaves, the plain form with `_small-change/csrf` needs no extra plugin.

## Other caches

Cloudflare, a reverse proxy or a host's page cache will respect the `no-store` header on the personal pages, but you'll want a rule that never caches `/cart`, `/checkout` and the payment and thank-you pages anyway. For a cache that isn't Blitz, Craft 5.1 and later can swap `csrfInput()` for a placeholder that is filled in after the page loads: switch on the `asyncCsrfInputs` general config setting. `_small-change/csrf` falls back to a plain `csrfInput()` when Blitz isn't installed, so that setting covers it.

## A quick check

Open each cacheable page in a private window, then in a signed-in window, and look for a basket count, a message or an email address that isn't yours. Then add something to the basket, complete a test donation, and make sure the basket, the payment page and the thank-you page are right the first time.
