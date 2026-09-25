<?php
/**
 * Deletes the DEMO-* products and their images. Run once real products are in.
 *   docker compose run --rm cli wp eval-file /bin-isa/remove-demo.php
 */

defined( 'ABSPATH' ) || exit;

$ids = wc_get_products( [ 'limit' => -1, 'return' => 'ids', 'status' => 'any' ] );
$n   = 0;
foreach ( $ids as $id ) {
	$product = wc_get_product( $id );
	if ( ! $product || ! str_starts_with( (string) $product->get_sku(), 'DEMO-' ) ) {
		continue;
	}
	foreach ( array_filter( array_merge( [ $product->get_image_id() ], $product->get_gallery_image_ids() ) ) as $att ) {
		wp_delete_attachment( (int) $att, true );
	}
	$product->delete( true );
	$n++;
}
WP_CLI::success( "removed $n demo products" );
