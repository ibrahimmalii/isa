<?php
/**
 * Product categories (English names; Arabic lives in bin/translations-ar.php).
 * Creates missing ones, fixes names/slugs of existing ones, deletes the retired ones
 * (their products stay, just without that category). Safe to re-run.
 *
 *   docker compose run --rm cli wp eval-file /bin-isa/categories.php
 *
 * Products → categories:
 *   Body Care    Splash, Mist
 *   Hair Care    Hair Mist
 *   Lip & Cheek  Tint, Lip Gloss
 *   Foot Care    (none yet: hidden in the shop until it has a product)
 */

defined( 'ABSPATH' ) || exit;

$categories = [
	// slug => [ name, old slugs it may exist under ]
	'body-care' => [ 'Body Care', [] ],
	'hair-care' => [ 'Hair Care', [ 'tint' ] ], // was created by hand with the slug "tint"
	'lip-cheek' => [ 'Lip & Cheek', [] ],
	'foot-care' => [ 'Foot Care', [] ],
];

// Retired: the demo categories and Skin Care.
$retired = [ 'skin-care', 'moisturizers', 'serums', 'sunscreen', 'cleansers' ];

$ids = [];
foreach ( $categories as $slug => [ $name, $old_slugs ] ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	foreach ( $old_slugs as $old ) {
		$term = $term ?: get_term_by( 'slug', $old, 'product_cat' );
	}
	$term = $term ?: get_term_by( 'name', $name, 'product_cat' );

	if ( $term ) {
		wp_update_term( $term->term_id, 'product_cat', [ 'name' => $name, 'slug' => $slug ] );
		$ids[ $slug ] = $term->term_id;
		WP_CLI::log( "  kept    $name (#{$term->term_id}, /$slug/)" );
	} else {
		$new          = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
		$ids[ $slug ] = is_wp_error( $new ) ? WP_CLI::error( $new->get_error_message() ) : $new['term_id'];
		WP_CLI::log( "  created $name (#{$ids[ $slug ]}, /$slug/)" );
	}
}

foreach ( $retired as $slug ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term ) {
		wp_delete_term( $term->term_id, 'product_cat' );
		WP_CLI::log( "  deleted {$term->name}" );
	}
}

// "Body Splash & Hair Mist" is a set: it belongs to both.
$set = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'title' => 'Body Splash & Hair Mist', 'numberposts' => 1 ] )[0] ?? null;
if ( $set && ! has_term( $ids['body-care'], 'product_cat', $set->ID ) ) {
	wp_set_object_terms( $set->ID, [ $ids['body-care'], $ids['hair-care'] ], 'product_cat' );
	WP_CLI::log( '  "Body Splash & Hair Mist" → Body Care + Hair Care' );
}

WP_CLI::success( 'Categories ready' );
