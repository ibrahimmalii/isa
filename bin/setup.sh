#!/usr/bin/env bash
# Configures a fresh WordPress into the isa store. Safe to re-run (idempotent).
# Runs INSIDE the WP-CLI container:
#   docker compose run --rm cli bash /bin-isa/setup.sh
#
# Env overrides: SITE_URL, ADMIN_USER, ADMIN_PASS, ADMIN_EMAIL, SITE_PUBLIC (0|1)
set -euo pipefail

SITE_URL="${SITE_URL:-http://localhost:8090}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@isa-skin.com}"
SITE_PUBLIC="${SITE_PUBLIC:-0}"

# Option writes print nothing on success; a rejected write warns instead of aborting.
opt() { wp option update "$@" --quiet 2>/dev/null || echo "  (warn) option $1 not updated"; }
say() { printf '\n\033[1;35m▸ %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------- core
say "WordPress core"
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title="isa" --admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASS" --admin_email="$ADMIN_EMAIL" --skip-email
fi
opt blogname "isa"
opt blogdescription "skincare"
opt timezone_string "Africa/Cairo"
opt date_format "j F Y"
opt blog_public "$SITE_PUBLIC"
opt default_comment_status closed
wp rewrite structure '/%postname%/' --quiet
wp rewrite flush --hard --quiet

# Default clutter
wp post delete 1 --force --quiet 2>/dev/null || true   # Hello world
wp post delete 2 --force --quiet 2>/dev/null || true   # Sample page
wp plugin delete hello akismet --quiet 2>/dev/null || true

# ---------------------------------------------------------------- plugins + theme
say "WooCommerce + theme"
wp plugin is-installed woocommerce || wp plugin install woocommerce --quiet
wp plugin activate woocommerce --quiet
# Arabic storefront: language packs + TranslatePress (/en/… and /ar/…).
wp plugin is-installed translatepress-multilingual || wp plugin install translatepress-multilingual --quiet
wp plugin activate translatepress-multilingual --quiet 2>/dev/null || true   # its activation redirect confuses WP-CLI
wp language core install ar --quiet
wp language plugin install woocommerce translatepress-multilingual ar --quiet
wp theme is-installed storefront || wp theme install storefront --quiet
wp theme activate isa --quiet
wp language theme install storefront ar --quiet
wp theme delete twentytwentythree twentytwentyfour twentytwentyfive --quiet 2>/dev/null || true

# ---------------------------------------------------------------- brand
say "Brand: logo, site icon, Storefront colours"
# import_brand <file> → attachment ID (imported once, matched by file name)
import_brand() {
	local id
	id="$(wp post list --post_type=attachment --meta_key=_isa_brand --meta_value="$1" --field=ID | head -1)"
	if [ -z "$id" ]; then
		id="$(wp media import "/brand/$1" --porcelain)"
		wp post meta update "$id" _isa_brand "$1" --quiet
	fi
	echo "$id"
}
if [ -d /brand ]; then
	wp theme mod set custom_logo "$(import_brand logo-mark.png)" --quiet
	opt site_icon "$(import_brand icon.png)"
fi
# Storefront's customizer colours, set to the brand palette so its inline CSS agrees with ours.
for kv in \
	storefront_heading_color=#4a3a2f storefront_text_color=#5e4a3c storefront_accent_color=#967a64 \
	storefront_header_background_color=#f6efe7 storefront_header_text_color=#5e4a3c storefront_header_link_color=#4a3a2f \
	storefront_footer_background_color=#4a3a2f storefront_footer_heading_color=#ede0d3 \
	storefront_footer_text_color=#cdbba9 storefront_footer_link_color=#f6efe7 \
	storefront_button_background_color=#4a3a2f storefront_button_text_color=#f6efe7 \
	storefront_button_alt_background_color=#967a64 storefront_button_alt_text_color=#ffffff \
	background_color=f6efe7; do
	wp theme mod set "${kv%%=*}" "${kv#*=}" --quiet
done

# ---------------------------------------------------------------- store settings
say "Store settings (Egypt, EGP, no tax)"
opt woocommerce_default_country "EG:EGC"
opt woocommerce_allowed_countries "specific"
opt woocommerce_specific_allowed_countries '["EG"]' --format=json
opt woocommerce_ship_to_countries ""
opt woocommerce_currency "EGP"
opt woocommerce_currency_pos "right_space"
opt woocommerce_price_num_decimals 0
opt woocommerce_price_thousand_sep ","
opt woocommerce_calc_taxes "no"
opt woocommerce_enable_guest_checkout "yes"
opt woocommerce_enable_checkout_login_reminder "no"
opt woocommerce_enable_signup_and_login_from_checkout "no"
opt woocommerce_checkout_phone_field "required"
opt woocommerce_checkout_company_field "hidden"
opt woocommerce_checkout_address_2_field "optional"
# Product photos: 4:5 portrait, sharp on retina phones.
opt woocommerce_thumbnail_image_width 600
opt woocommerce_single_image_width 1000
opt woocommerce_thumbnail_cropping custom
opt woocommerce_thumbnail_cropping_custom_width 4
opt woocommerce_thumbnail_cropping_custom_height 5
opt woocommerce_manage_stock "yes"
opt woocommerce_notify_low_stock_amount 3
opt woocommerce_enable_reviews "yes"
opt woocommerce_review_rating_verification_label "yes"
opt woocommerce_coming_soon "no"
opt woocommerce_task_list_hidden "yes"
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_show_marketplace_suggestions "no"
opt woocommerce_allow_tracking "no"

# ---------------------------------------------------------------- payments
# No cash on delivery: refused parcels cost us the courier fee both ways.
say "Payments: InstaPay/Vodafone Cash (no cash on delivery)"
opt woocommerce_cod_settings '{
	"enabled":"no",
	"title":"Cash on delivery",
	"description":"Pay in cash when your order arrives.",
	"instructions":"Please have the exact amount ready. We will call you to confirm before shipping.",
	"enable_for_methods":[],
	"enable_for_virtual":"no"
}' --format=json
# Stock "Direct bank transfer" gateway, repurposed for InstaPay / wallets.
opt woocommerce_bacs_settings '{
	"enabled":"yes",
	"title":"InstaPay / Vodafone Cash",
	"description":"Transfer the total, then send the screenshot on WhatsApp with your order number. We ship after we confirm the transfer.",
	"instructions":"InstaPay: 01014917877\nVodafone Cash: 01096121030\nSend the transfer screenshot + your order number on WhatsApp.",
	"account_details":""
}' --format=json
opt woocommerce_gateway_order '{"bacs":0,"cod":1}' --format=json
opt woocommerce_cheque_settings '{"enabled":"no"}' --format=json

# ---------------------------------------------------------------- shipping
# The customer pays the courier on delivery (about 80–150 EGP), so shipping adds
# nothing to the order total; the theme shows the estimate next to it.
say "Shipping zones (paid to the courier)"
zone_id() { wp wc shipping_zone list --user="$ADMIN_USER" --format=json | php -r '$z=json_decode(stream_get_contents(STDIN),true); foreach($z as $r){ if($r["name"]===$argv[1]){ echo $r["id"]; exit; } }' "$1"; }

ensure_zone() { # name, cost, "code:type code:type ..."
	local name="$1" cost="$2" locations="$3" id
	id="$(zone_id "$name")"
	if [ -z "$id" ]; then
		id="$(wp wc shipping_zone create --user="$ADMIN_USER" --name="$name" --porcelain)"
		local json="[" first=1 loc
		for loc in $locations; do
			[ $first -eq 1 ] || json+=","
			json+="{\"code\":\"${loc%%|*}\",\"type\":\"${loc##*|}\"}"
			first=0
		done
		json+="]"
		wp eval "
			\$zone = new WC_Shipping_Zone($id);
			foreach (json_decode('$json', true) as \$l) { \$zone->add_location(\$l['code'], \$l['type']); }
			\$zone->save();
		"
		wp wc shipping_zone_method create "$id" --user="$ADMIN_USER" --method_id=flat_rate \
			--settings="{\"title\":\"Home delivery\",\"cost\":\"$cost\",\"tax_status\":\"none\"}" --quiet
		echo "  created zone '$name'"
	fi
}
ensure_zone "Cairo & Giza" 0 "EG:EGC|state EG:EGGZ|state"
ensure_zone "Rest of Egypt" 0 "EG|country"
wp eval-file /bin-isa/shipping-courier.php

# ---------------------------------------------------------------- categories
say "Product categories"
wp eval-file /bin-isa/categories.php

# ---------------------------------------------------------------- pages
say "Pages"
# Any status (WP ships a draft privacy-policy page; reuse it, don't duplicate it).
page_id() { wp eval "\$p = get_page_by_path('$1'); echo \$p ? \$p->ID : '';"; }
# upsert_page <slug> <title> <content-file> [template]
upsert_page() {
	local slug="$1" title="$2" file="$3" tpl="${4:-}" id
	id="$(page_id "$slug")"
	if [ -z "$id" ]; then
		id="$(wp post create "$file" --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --porcelain)"
	else
		wp post update "$id" "$file" --post_title="$title" --post_status=publish --quiet
	fi
	if [ -n "$tpl" ]; then wp post meta update "$id" _wp_page_template "$tpl" --quiet; fi
	echo "$id"
}

P=/bin-isa/pages
home_id="$(upsert_page home "Home" "$P/home.html" template-fullwidth.php)"
about_id="$(upsert_page about "About isa" "$P/about.html")"
faq_id="$(upsert_page faq "FAQ" "$P/faq.html")"
contact_id="$(upsert_page contact "Contact" "$P/contact.html")"
shipping_id="$(upsert_page shipping-returns "Shipping & Returns" "$P/shipping-returns.html")"
privacy_id="$(upsert_page privacy-policy "Privacy Policy" "$P/privacy.html")"
terms_id="$(upsert_page terms "Terms & Conditions" "$P/terms.html")"

opt show_on_front page
opt page_on_front "$home_id"
opt wp_page_for_privacy_policy "$privacy_id"
opt woocommerce_terms_page_id "$terms_id"

# Classic (shortcode) cart/checkout: lighter than the block checkout, and the
# Egypt field rules in mu-plugins/isa-store.php only apply to the classic form.
cart_id="$(wp option get woocommerce_cart_page_id)"
checkout_id="$(wp option get woocommerce_checkout_page_id)"
wp post update "$cart_id" --post_content='<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' --quiet
wp post update "$checkout_id" --post_content='<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' --quiet
# Drop WooCommerce's auto "Refund and Returns Policy" page; shipping-returns replaces it.
old_refund="$(page_id refund_returns)"
if [ -n "$old_refund" ]; then wp post delete "$old_refund" --force --quiet; fi

# ---------------------------------------------------------------- menus
say "Menus"
ensure_menu() { # name location
	wp menu list --fields=name --format=csv | grep -qx "$1" || wp menu create "$1" --quiet
	wp menu location assign "$1" "$2" --quiet 2>/dev/null || true
}
reset_menu_items() { # name
	local ids
	ids="$(wp menu item list "$1" --format=ids 2>/dev/null || true)"
	[ -n "$ids" ] && wp menu item delete $ids --quiet
	return 0
}
shop_id="$(wp option get woocommerce_shop_page_id)"

ensure_menu "Main" primary
reset_menu_items "Main"
wp menu item add-post Main "$shop_id" --title="Shop" --quiet
wp menu item add-post Main "$about_id" --quiet
wp menu item add-post Main "$faq_id" --quiet
wp menu item add-post Main "$contact_id" --quiet
wp menu location assign Main handheld --quiet 2>/dev/null || true

# Policies live in the footer (a nav-menu widget), not Storefront's header "secondary" slot.
ensure_menu "Footer" footer-unused
reset_menu_items "Footer"
for id in "$shipping_id" "$privacy_id" "$terms_id" "$contact_id"; do
	wp menu item add-post Footer "$id" --quiet
done

footer_menu_id="$(wp menu list --fields=term_id,name --format=csv | awk -F, '$2=="Footer"{print $1}')"
# Empty blog sidebar → Storefront renders every page full width.
wp widget reset sidebar-1 --quiet 2>/dev/null || true
wp widget reset footer-1 --quiet 2>/dev/null || true
wp widget add nav_menu footer-1 --title="Help" --nav_menu="$footer_menu_id" --quiet
wp menu location remove Footer secondary --quiet 2>/dev/null || true

# ---------------------------------------------------------------- languages
say "Languages: English /en/ + Arabic /ar/"
wp eval-file /bin-isa/trp-configure.php
wp i18n make-mo /var/www/html/wp-content/themes/isa/languages --quiet 2>/dev/null || true
if [ -f /bin-isa/translations-ar.php ]; then wp eval-file /bin-isa/seed-translations.php /bin-isa/translations-ar.php; fi

# ---------------------------------------------------------------- done
wp cache flush --quiet
say "Done → $SITE_URL  (admin: $SITE_URL/wp-admin  user: $ADMIN_USER)"
