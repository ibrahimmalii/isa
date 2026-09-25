# isa-skin.com

WordPress + WooCommerce store for **isa** skin care (Egypt). Storefront parent theme,
`isa` child theme, one must-use plugin. All configuration is scripted, so a fresh
server is set up by running the same script.

## Run locally

```bash
docker compose up -d
docker compose run --rm cli bash /bin-isa/setup.sh
```

- Store: http://localhost:8090
- Admin: http://localhost:8090/wp-admin (`admin` / `admin`, local only)

`setup.sh` can be re-run safely. It re-applies settings, pages and menus. Shipping zones
are only created once; after that, edit their prices in the admin.

## Add your products

1. Copy `products/products-template.csv` to `products/products.csv` and fill it in
   (Excel / Google Sheets → "Download as CSV").
2. Put the photos in `products/images/` and write their file names in the `images`
   column, separated by `|`. The first one is the main photo. Square photos look best.
3. Import (re-running updates products by SKU, nothing is duplicated):

   ```bash
   docker compose run --rm cli wp eval-file /bin-isa/import-products.php /products/products.csv
   ```
4. Remove the demo products:

   ```bash
   docker compose run --rm cli wp eval-file /bin-isa/remove-demo.php
   ```

| Column | Required | Notes |
|---|---|---|
| sku | yes | Your product code, e.g. `ISA-001`. Never change it. It's how updates find the product |
| name | yes | |
| price | yes | EGP, number only |
| sale_price | | Leave empty if not on sale |
| category | | e.g. `Serums`; several: `Serums, Vitamin C` |
| stock | | Number; empty = don't track stock |
| featured | | `yes` = shows under "Bestsellers" on the home page |
| short_description | | 1–2 lines under the price |
| description | | Full text: ingredients, how to use, skin type |
| images | | `front.jpg|back.jpg` |

## Before launch: fill in the placeholders

Search the admin pages for `[` and replace every bracketed placeholder:

- **Contact, FAQ, About**: WhatsApp number, email, Instagram, hours, your story
- **Shipping & Returns, Privacy, Terms**: these are *drafts*. Check the days and rules
- **WooCommerce → Settings → Payments → InstaPay / Vodafone Cash**: your InstaPay address + wallet number
- **Settings → General → WhatsApp number**: turns on the floating WhatsApp button
- **WooCommerce → Settings → Shipping**: real delivery prices (defaults: Cairo & Giza 60, rest of Egypt 90)

## Languages (English + Arabic)

- URLs: `/en/…` and `/ar/…` (`/` redirects to `/en/`). The header link switches the same page between languages.
  Powered by TranslatePress (free), configured by `bin/trp-configure.php`.
- **Your products in Arabic**: open the product on the site while logged in → **Translate Site** (top admin bar)
  → pick **العربية** → click the name/description → type the Arabic → **Save**. Do the same for categories and any
  text you add later.
- Theme text (header, footer, home sections, checkout errors): `theme/isa/languages/ar.po`. Storefront has no
  Arabic pack, so `storefront-ar.po` covers its visible strings. WooCommerce uses its official Arabic pack.
- Page text (FAQ, policies, menus, headings): `bin/translations-ar.php`, applied by `setup.sh`. Edits you make in
  the Translate Site editor are kept. The script never overwrites a hand-edited translation (pass `--force` to
  `seed-translations.php` if you want it to).
- Arabic typography: Amiri (headings) + IBM Plex Sans Arabic (text), switched on `html[lang="ar"]`.
- Admin stays English: **Users → Profile → Language** controls your own admin language.
- Product URLs stay English (`/ar/product/…`); translating slugs is a paid TranslatePress add-on.

## Brand

- Logo source: `brand/logo-original.jpg`. `bin/make-brand.php` cuts the transparent logos + favicon from it
  (`docker run --rm -v "$PWD":/app wordpress:php8.3-apache php -d memory_limit=1G /app/bin/make-brand.php`);
  `setup.sh` installs them as the site logo and icon.
- Home hero photo: drop a portrait photo at `theme/isa/assets/hero.jpg` and it replaces the logo in the arch.
- Top bar text, WhatsApp and Instagram: **Settings → General** (bottom of the page).
- Palette and type are the tokens at the top of `theme/isa/style.css`: taupe `#967a64` from the logo, walnut `#4a3a2f`,
  Bodoni Moda (headings) + Jost (text). The arch shape is the one signature element; keep the rest quiet.

## What's in here

| Path | What |
|---|---|
| `theme/isa/` | Child theme: colours, fonts, layout, WhatsApp button |
| `mu-plugins/isa-store.php` | Egypt checkout (phone required + validated, no postcode/company, email optional) and speed trims for a 1 GB server |
| `bin/setup.sh` | Store configuration as code |
| `bin/pages/` | Page content (home, about, FAQ, policies) |
| `bin/import-products.php` | CSV → products |

## Store rules

- **Payments**: cash on delivery (default) + InstaPay / Vodafone Cash (manual transfer). Card gateway (Paymob) comes later.
- **Checkout**: guest checkout, Egypt only, classic (shortcode) checkout. The Egypt field rules don't apply to WooCommerce's block checkout, so don't switch the Cart/Checkout pages to blocks.
- **Hosting target**: 1 GB Oracle micro VM, isolated from Tamreena. Keep plugins minimal.
