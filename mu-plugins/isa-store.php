<?php
/**
 * Plugin Name: isa store tweaks
 * Description: Egypt checkout rules + performance trims for a 1 GB server. Must-use plugin, always on.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Checkout: short Egyptian form. Name, phone, governorate, city, address.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_checkout_fields', function ( array $fields ): array {
	unset( $fields['billing']['billing_company'], $fields['shipping']['shipping_company'] );

	// Most Egyptians don't know their postcode; couriers don't need it.
	foreach ( [ 'billing', 'shipping' ] as $group ) {
		if ( isset( $fields[ $group ][ "{$group}_postcode" ] ) ) {
			$fields[ $group ][ "{$group}_postcode" ]['required'] = false;
		}
	}

	$fields['billing']['billing_phone']['required']    = true;
	$fields['billing']['billing_phone']['priority']    = 25;
	$fields['billing']['billing_phone']['placeholder'] = '01xxxxxxxxx';

	// Email optional: many COD buyers don't use one. Order updates go by phone/WhatsApp.
	$fields['billing']['billing_email']['required'] = false;

	return $fields;
} );

// Postcode is also enforced per-country by the address locale; relax it for Egypt.
add_filter( 'woocommerce_get_country_locale', function ( array $locale ): array {
	$locale['EG']['postcode'] = [ 'required' => false, 'hidden' => true ];
	return $locale;
} );

/**
 * Egyptian mobile: 01[0|1|2|5] + 8 digits. Accepts +20 / 0020 / spaces / dashes
 * and stores the national form so staff can call or WhatsApp it directly.
 */
function isa_normalize_eg_mobile( string $raw ): ?string {
	$digits = preg_replace( '/\D/', '', $raw );
	$digits = preg_replace( '/^(0020|20)(?=1)/', '0', $digits );
	return preg_match( '/^01[0125]\d{8}$/', $digits ) ? $digits : null;
}

add_action( 'woocommerce_after_checkout_validation', function ( array $data, WP_Error $errors ) {
	if ( empty( $data['billing_phone'] ) || ( $data['billing_country'] ?? 'EG' ) !== 'EG' ) {
		return;
	}
	if ( null === isa_normalize_eg_mobile( (string) $data['billing_phone'] ) ) {
		$errors->add( 'billing_phone', __( 'Please enter a valid Egyptian mobile number, e.g. 01012345678.', 'isa' ) );
	}
}, 10, 2 );

add_filter( 'woocommerce_process_checkout_field_billing_phone', function ( $value ) {
	return isa_normalize_eg_mobile( (string) $value ) ?? $value;
} );

/* -------------------------------------------------------------------------
 * Performance: this runs on a 1 GB VM. Trim what a small shop doesn't use.
 * ---------------------------------------------------------------------- */

// No marketplace upsells / remote inbox polling in wp-admin.
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
add_filter( 'woocommerce_admin_features', function ( array $features ): array {
	return array_values( array_diff( $features, [ 'marketing', 'remote-inbox-notifications', 'remote-free-extensions', 'payment-gateway-suggestions', 'shipping-label-banner' ] ) );
} );

// Heartbeat only in the editor; it's pure load elsewhere.
add_action( 'init', function () {
	if ( ! is_admin() ) {
		wp_deregister_script( 'heartbeat' );
	}
}, 1 );
add_filter( 'heartbeat_settings', function ( array $settings ): array {
	$settings['interval'] = 60;
	return $settings;
} );

// Emoji script + oEmbed discovery are dead weight on every page.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'wp_generator' );

// Cart-fragments AJAX fires on every page view; only needed where the cart is shown changing.
add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_woocommerce' ) && ! is_woocommerce() && ! is_cart() && ! is_checkout() ) {
		wp_dequeue_script( 'wc-cart-fragments' );
	}
}, 99 );
