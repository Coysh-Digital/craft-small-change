# Showing one product or a donation form on a page

You don't need a shop page or a `/donate` page to sell one thing or take one kind of gift. A common setup is a
page builder (a Matrix field) where an editor adds a "Donation" block or a "Product" block to any page, picks
which donation or product it is, and the page shows a form. Setting that up takes about ten minutes, and
everything in it is ordinary Craft: a field, an entry type and a template.

The same method works anywhere you want an editor to choose a product: a field on an entry, a global set, a
landing page template.

## Before you start

- Install the templates for what you want to show, from **Small Change → Settings → Templates**. A donation
  form needs the **Donations** set, because the form hands the donor on to the payment and thank-you pages in
  it. A product needs the **Shop** set, because the buyer pays at checkout. If you put them in a folder, use the
  folder in the template names below. See [Your front-end templates](templates.md).
- Create the product. A donation is a product whose product type has the *Donation* kind, and anything for
  sale belongs to a type of the *Physical product* or *Non-physical product* kind. See
  [Donations](donations.md) and [Selling products](shop.md).
- A product doesn't need a page of its own. Leave the type's **Product URI format** blank if you only want to
  show it inside other pages.

## 1. Create the field

1. Go to **Settings → Fields** and create a new field.
2. Choose the type **Small Change Products**. It looks and works like Craft's Entries field.
3. Give it a name and a handle, for example *Donation* and `donation`.
4. Set **Max Relations** to `1`, so an editor picks exactly one.
5. Under **Sources**, tick the product types the editor may choose from. One field for donations and another for
   shop products stops anyone picking a book for a donation form.

Editors don't need the permission to manage products to choose from the field, only access to the place the
field is used.

## 2. Put the field somewhere editors can use it

For a page builder, make a Matrix block:

1. Go to **Settings → Entry Types** and create an entry type, for example *Donation form*. Turn off its title
   field if you don't want one, and add your field to its layout.
2. Open your Matrix field and add the new entry type to it.

That is all the setup in the control panel. For a single choice on a normal page, add the field to that
section's entry type instead.

## 3. Draw it in a template

How you render Matrix blocks is up to your site. If you include one template per block type, create one for
each new type. These examples read the field from `block`; on an ordinary entry it would be `entry.donation`.

A donation form:

```twig
{% set product = block.donation.one() %}

{% if product %}
    <h2>{{ product.title }}</h2>
    {% include 'donate/_form' with { product: product } %}
{% endif %}
```

A product with a buy button:

```twig
{% set product = block.product.one() %}

{% if product %}
    <h2>{{ product.title }}</h2>
    <p>{{ craft.smallChange.formatMoney(product.price) }}</p>
    {% include 'shop/_add-form' with { product: product, buyNow: true, label: 'Buy now' } only %}
{% endif %}
```

Keep the `{% if product %}`. When nothing is chosen, or the chosen product has since been disabled, the block
then shows nothing and the rest of the page still works.

What the two partials give you:

- `donate/_form` is the same form `/donate` uses: suggested amounts, a free amount if you allow one, the
  one-off or recurring choice, email, your donation fields and the Gift Aid tick box. It loads its own scripts.
  It sends the donor to `/donate/payment` and then `/donate/complete`.
- `shop/_add-form` is the add-to-basket form. `buyNow: true` sends the buyer straight to checkout, which suits
  one product on a page. Leave it out to add to the basket and show the basket. `label` is the button text.

Both are plain HTML in your `templates/` folder, so change the markup however you like. Use your own layout
classes around them, and show whatever else the product has (images, a description) from its custom fields.

## Without a field

If the page always shows the same thing, skip the field and fetch the product in the template:

```twig
{% set product = craft.smallChange.products({ sku: 'tea-towel' }).one() %}
{% set fund = craft.smallChange.products({ kind: 'donation' }).one() %}
```

You can also query by `id`, `title`, or `type` (a product type's handle). See the [Twig reference](twig.md).

## Things to know

- After a failed submission (a missing email, an amount that's too small), the page comes back with the errors
  shown. If you put two donation forms on one page, both show them, so we'd keep to one form per page.
- The forms use the `_small-change/csrf` partial, so a page with a form can sit behind Blitz or another
  full-page cache. See [Caching and Blitz](caching.md).
- The form only says which product and what amount. The plugin works out the price on the server when the order
  is made, so nobody can use a block to change a price.
- Gift Aid, discount codes and delivery work as they do on the installed pages, because the same checkout sits
  behind the form.
- If the picker is empty, the field's sources don't include the product's type, or there isn't a product of
  that type yet.
- If the block shows nothing, no product is chosen, the product is disabled, or the block's template isn't
  being found. Its name has to match the way your site picks a template for each block type.
