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
| category | | `Body Care`, `Hair Care`, `Lip & Cheek` or `Foot Care`; several: `Body Care, Hair Care` |
| stock | | Number; empty = don't track stock |
| featured | | `yes` = shows under "Bestsellers" on the home page |
| short_description | | 1–2 lines under the price |
| description | | Full text: ingredients, how to use, skin type |
| images | | `front.jpg|back.jpg` |
| parent_sku | | Only on shade rows: the sku of the product this shade belongs to |
| shade | | Only on shade rows: the colour name, e.g. `Red` |
| scent | | Instead of `shade` for smells (body splash, hair mist), e.g. `Tropical` |
| shade_color | | Only on shade rows: the dot colour, e.g. `#b3263a` |

### Products with shades or scents (tints, body splash, hair mist…)

One product, several colours. The product page lists every shade with its colour dot and how many
are left ("5 left", "Sold out"). Each shade has its own + / − quantity and one button adds them all,
so a customer can take 2 Red + 1 Yellow in one go. Shop cards show the shade dots under the name.

**In the CSV:** the product row as usual (its `stock` is ignored), then one row per shade with its
own `sku`, `stock`, `parent_sku`, `shade`, `shade_color` and optionally its photo. Leave `price`
empty to use the product's price. See the last three rows of `products/products-template.csv`.

**In the admin (the live site):**
1. **Products → Attributes → Shade** (colours) or **Scent** (smells) **→ Configure terms**: add each colour and pick its **Swatch colour**.
   Drag them to set the order they show in.
2. **Products → Add New**, set **Product data** to **Variable product**.
3. **Attributes** tab: add **Shade** or **Scent**, select the colours, tick **Used for variations**, **Save attributes**.
4. **Variations** tab: **Generate variations**. For each one: price, tick **Manage stock?** and enter the
   quantity you have, optionally its photo. **Save changes**, then **Publish**.
5. Arabic: translate the shade names in the **Translate Site** editor, like product names.

The list is used when **Shade** or **Scent** is the product's only attribute; anything else keeps
WooCommerce's normal dropdowns. Code: `theme/isa/inc/shades.php`.

## Before launch: fill in the placeholders

Search the admin pages for `[` and replace every bracketed placeholder:

- **Contact, FAQ, About**: WhatsApp number, email, Instagram, hours, your story
- **Shipping & Returns, Privacy, Terms**: these are *drafts*. Check the days and rules
- **Settings → General → WhatsApp number**: turns on the floating WhatsApp button

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
| `theme/isa/` | Child theme: colours, fonts, layout, WhatsApp button, shade picker (`inc/shades.php`) |
| `mu-plugins/isa-store.php` | Egypt checkout (phone required + validated, no postcode/company, email optional) and speed trims for a 1 GB server |
| `bin/setup.sh` | Store configuration as code |
| `bin/pages/` | Page content (home, about, FAQ, policies) |
| `bin/import-products.php` | CSV → products |

## Production

- Server: Oracle `VM.Standard.E2.1.Micro` (1 GB), Frankfurt, `130.162.236.161`, behind Cloudflare (DNS + SSL + CDN).
  SSH: `ssh -i ~/.ssh/isa_key ubuntu@130.162.236.161`. About $2.13/month (the 50 GB disk; the VM itself is free).
- First-time build: `server/provision.sh` (packages, tuning, nginx page cache, backups), then `server/tls.sh`.
- **Deploy code changes**: push to `main`, then `bash server/deploy.sh`.
- **Content lives on the server now.** Add products, orders, pages and translations in the live admin
  (https://isa-skin.com/wp-admin). `server/push-site.sh` copies local → server and **overwrites** the live
  database. It was only for the first launch.
- Backups: daily at 03:30 to `/var/backups/isa` (database + uploads, 7 days kept, root-only).
- **Security** (`server/harden.sh`, after the 2026-09-26 break-in): wp-login is rate-limited, and wp-admin can't
  install or update plugins/themes (`DISALLOW_FILE_MODS`). Install a plugin on the server instead:
  `sudo -u www-data wp --path=/var/www/isa plugin install <slug> --activate`. Minor/security updates run nightly
  at 04:15 (`/usr/local/bin/isa-update`). Use a long unique admin password: `password` is what got guessed.

## Store rules

- **Payments**: InstaPay / Vodafone Cash (manual transfer) only. No cash on delivery: refused parcels cost the courier fee both ways. Card gateway (Paymob) comes later.
- **Shipping**: the customer pays the courier on delivery (about 80–150 EGP), so checkout adds 0. The estimate text is in `theme/isa/functions.php` (`isa_shipping_note`).
- **Categories**: `bin/categories.php` (English names); Arabic names in `bin/translations-ar.php`.
- **Checkout**: guest checkout, Egypt only, classic (shortcode) checkout. The Egypt field rules don't apply to WooCommerce's block checkout, so don't switch the Cart/Checkout pages to blocks.
- **Hosting target**: 1 GB Oracle micro VM, isolated from Tamreena. Keep plugins minimal.
