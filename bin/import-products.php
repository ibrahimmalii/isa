<?php
/**
 * Import / update products from a CSV. Re-runnable: rows are matched by SKU,
 * images are matched by file content, so running it twice changes nothing.
 *
 *   docker compose run --rm cli wp eval-file /bin-isa/import-products.php /products/products.csv
 *
 * Columns (header row required, order doesn't matter):
 *   sku, name, category, price, sale_price, stock, featured,
 *   short_description, description, images
 *
 * - category:  one or more names separated by "," (created if missing)
 * - stock:     number, or empty = don't track stock
 * - featured:  yes/no — featured products show under "Bestsellers" on the home page
 * - images:    file names in products/images/, separated by "|". First = main image.
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$csv_path   = $args[0] ?? '/products/products.csv';
$images_dir = $args[1] ?? dirname( $csv_path ) . '/images';

if ( ! is_readable( $csv_path ) ) {
	WP_CLI::error( "CSV not found: $csv_path" );
}

$fh     = fopen( $csv_path, 'r' );
$header = fgetcsv( $fh );
if ( ! $header ) {
	WP_CLI::error( 'CSV is empty.' );
}
// Strip a UTF-8 BOM (Excel adds one) and normalise header names.
$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
$header    = array_map( fn( $h ) => strtolower( trim( $h ) ), $header );

foreach ( [ 'sku', 'name', 'price' ] as $required ) {
	if ( ! in_array( $required, $header, true ) ) {
		WP_CLI::error( "Missing required column: $required" );
	}
}

/** Attachment ID for a local image, reusing an existing upload of the same file. */
$attach_image = function ( string $file, int $product_id ) use ( $images_dir ): ?int {
	$path = $images_dir . '/' . $file;
	if ( ! is_readable( $path ) ) {
		WP_CLI::warning( "  image not found: products/images/$file" );
		return null;
	}
	$hash     = md5_file( $path );
	$existing = get_posts( [
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'meta_key'    => '_isa_source_md5',
		'meta_value'  => $hash,
		'fields'      => 'ids',
		'numberposts' => 1,
	] );
	if ( $existing ) {
		return (int) $existing[0];
	}
	$tmp = wp_tempnam( $file );
	copy( $path, $tmp );
	$id = media_handle_sideload( [ 'name' => basename( $file ), 'tmp_name' => $tmp ], $product_id );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		WP_CLI::warning( "  image failed: $file — " . $id->get_error_message() );
		return null;
	}
	update_post_meta( $id, '_isa_source_md5', $hash );
	return (int) $id;
};

$term_ids = function ( string $names ): array {
	$ids = [];
	foreach ( array_filter( array_map( 'trim', explode( ',', $names ) ) ) as $name ) {
		$term = term_exists( $name, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat' );
		if ( ! is_wp_error( $term ) ) {
			$ids[] = (int) $term['term_id'];
		}
	}
	return $ids;
};

$created = $updated = $skipped = 0;
$line    = 1;

while ( ( $row = fgetcsv( $fh ) ) !== false ) {
	$line++;
	if ( count( array_filter( $row, fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
		continue; // blank line
	}
	$r   = array_combine( $header, array_pad( array_map( 'trim', $row ), count( $header ), '' ) );
	$sku = $r['sku'];

	if ( '' === $sku || '' === $r['name'] || ! is_numeric( $r['price'] ) ) {
		WP_CLI::warning( "line $line: needs sku, name and a numeric price — skipped" );
		$skipped++;
		continue;
	}

	$id      = wc_get_product_id_by_sku( $sku );
	$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
	$is_new  = ! $id;

	$product->set_sku( $sku );
	$product->set_name( $r['name'] );
	$product->set_status( 'publish' );
	$product->set_regular_price( (string) $r['price'] );
	$product->set_sale_price( is_numeric( $r['sale_price'] ?? '' ) ? (string) $r['sale_price'] : '' );
	$product->set_short_description( $r['short_description'] ?? '' );
	$product->set_description( $r['description'] ?? '' );
	$product->set_featured( in_array( strtolower( $r['featured'] ?? '' ), [ 'yes', 'y', '1', 'true' ], true ) );

	$stock = $r['stock'] ?? '';
	if ( is_numeric( $stock ) ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( (int) $stock );
	} else {
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
	}

	if ( ! empty( $r['category'] ) ) {
		$product->set_category_ids( $term_ids( $r['category'] ) );
	}

	$product_id = $product->save();

	$files = array_filter( array_map( 'trim', explode( '|', $r['images'] ?? '' ) ) );
	if ( $files ) {
		$ids = array_values( array_filter( array_map( fn( $f ) => $attach_image( $f, $product_id ), $files ) ) );
		if ( $ids ) {
			$product->set_image_id( array_shift( $ids ) );
			$product->set_gallery_image_ids( $ids );
			$product->save();
		}
	}

	$is_new ? $created++ : $updated++;
	WP_CLI::log( sprintf( '  %s %s — %s', $is_new ? '+' : '~', $sku, $r['name'] ) );
}
fclose( $fh );

WP_CLI::success( "created $created, updated $updated, skipped $skipped" );
