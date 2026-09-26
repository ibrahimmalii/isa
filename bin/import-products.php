<?php
/**
 * Import / update products from a CSV. Re-runnable: rows are matched by SKU,
 * images are matched by file content, so running it twice changes nothing.
 *
 *   docker compose run --rm cli wp eval-file /bin-isa/import-products.php /products/products.csv
 *
 * Columns (header row required, order doesn't matter):
 *   sku, name, category, price, sale_price, stock, featured,
 *   short_description, description, images, parent_sku, shade, scent, shade_color
 *
 * - category:  one or more names separated by "," (created if missing)
 * - stock:     number, or empty = don't track stock
 * - featured:  yes/no — featured products show under "Bestsellers" on the home page
 * - images:    file names in products/images/, separated by "|". First = main image.
 *
 * Shades (one product, several colours): give the product its normal row, then one row per shade
 * with parent_sku = the product's sku, shade = the colour name (or scent = the smell, for splashes and
 * mists), shade_color = its dot colour (#c0392b).
 * A shade row needs its own sku and stock; name/price may be left empty (price falls back to the
 * product's). The product then becomes a variable product. Shades missing from the CSV are left alone.
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

// Read everything first: a product row must know whether shade rows point at it.
$rows = [];
$line = 1;
while ( ( $row = fgetcsv( $fh ) ) !== false ) {
	$line++;
	if ( count( array_filter( $row, fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
		continue; // blank line
	}
	$rows[ $line ] = array_combine( $header, array_pad( array_map( 'trim', $row ), count( $header ), '' ) );
}
fclose( $fh );

$shade_rows = array_filter( $rows, fn( $r ) => '' !== ( $r['parent_sku'] ?? '' ) );
$main_rows  = array_diff_key( $rows, $shade_rows );
$has_shades = array_flip( array_column( $shade_rows, 'parent_sku' ) );

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

/** Images column → main image + gallery on $product. */
$set_images = function ( WC_Product $product, string $images ) use ( $attach_image ) {
	$files = array_filter( array_map( 'trim', explode( '|', $images ) ) );
	if ( ! $files ) {
		return;
	}
	$ids = array_values( array_filter( array_map( fn( $f ) => $attach_image( $f, $product->get_id() ), $files ) ) );
	if ( $ids ) {
		$product->set_image_id( array_shift( $ids ) );
		if ( ! $product->is_type( 'variation' ) ) {
			$product->set_gallery_image_ids( $ids );
		}
		$product->save();
	}
};

$set_stock = function ( WC_Product $product, string $stock ) {
	if ( is_numeric( $stock ) ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( (int) $stock );
	} else {
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
	}
};

$created = $updated = $skipped = 0;
$prices  = []; // parent sku => [ price, sale_price ] for shades that leave theirs empty

foreach ( $main_rows as $line => $r ) {
	$sku = $r['sku'];

	if ( '' === $sku || '' === $r['name'] || ! is_numeric( $r['price'] ) ) {
		WP_CLI::warning( "line $line: needs sku, name and a numeric price — skipped" );
		$skipped++;
		continue;
	}

	$variable = isset( $has_shades[ $sku ] );
	$id       = wc_get_product_id_by_sku( $sku );
	if ( $id && $variable !== wc_get_product( $id )->is_type( 'variable' ) ) {
		wp_set_object_terms( $id, $variable ? 'variable' : 'simple', 'product_type' ); // switch type, keep the post
		wc_delete_product_transients( $id );
	}
	// Build by class, not wc_get_product(): WooCommerce caches the old type and saving would switch it back.
	$class   = $variable ? 'WC_Product_Variable' : 'WC_Product_Simple';
	$product = new $class( $id ?: 0 );
	$is_new  = ! $id;
	$prices[ $sku ] = [ (string) $r['price'], is_numeric( $r['sale_price'] ?? '' ) ? (string) $r['sale_price'] : '' ];

	$product->set_sku( $sku );
	$product->set_name( $r['name'] );
	$product->set_status( 'publish' );
	$product->set_regular_price( (string) $r['price'] );
	$product->set_sale_price( is_numeric( $r['sale_price'] ?? '' ) ? (string) $r['sale_price'] : '' );
	$product->set_short_description( $r['short_description'] ?? '' );
	$product->set_description( $r['description'] ?? '' );
	$product->set_featured( in_array( strtolower( $r['featured'] ?? '' ), [ 'yes', 'y', '1', 'true' ], true ) );

	// A shade product's stock lives on each shade.
	$set_stock( $product, $variable ? '' : ( $r['stock'] ?? '' ) );

	if ( ! empty( $r['category'] ) ) {
		$product->set_category_ids( $term_ids( $r['category'] ) );
	}

	$product->save();
	$set_images( $product, $r['images'] ?? '' );

	$is_new ? $created++ : $updated++;
	WP_CLI::log( sprintf( '  %s %s — %s', $is_new ? '+' : '~', $sku, $r['name'] ) );
}

/* Shades ---------------------------------------------------------------- */

if ( $shade_rows ) {
	if ( ! function_exists( 'isa_ensure_shade_attribute' ) ) {
		WP_CLI::error( 'Shade rows need the isa theme active (theme/isa/inc/shades.php).' );
	}
	isa_ensure_shade_attribute();
}

$by_parent = [];
foreach ( $shade_rows as $line => $r ) {
	$by_parent[ $r['parent_sku'] ][ $line ] = $r;
}

foreach ( $by_parent as $parent_sku => $shades ) {
	$parent_id = wc_get_product_id_by_sku( $parent_sku );
	$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
	if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
		WP_CLI::warning( "parent_sku $parent_sku has no product row in this CSV — its " . count( $shades ) . ' shade rows skipped' );
		$skipped += count( $shades );
		continue;
	}

	// Scent rows (body splash, hair mist) use the Scent attribute; colour rows use Shade.
	$scent    = (bool) array_filter( $shades, fn( $r ) => '' !== ( $r['scent'] ?? '' ) );
	$taxonomy = $scent ? ISA_SCENT : ISA_SHADE;
	$column   = $scent ? 'scent' : 'shade';
	$attribute_id = wc_attribute_taxonomy_id_by_name( $column );

	// Terms first: the product's attribute lists every option it has (old ones stay).
	$terms = [];
	foreach ( $shades as $line => $r ) {
		$r['shade'] = $r[ $column ] ?? '';
		if ( '' === $r['sku'] || '' === $r['shade'] ) {
			WP_CLI::warning( "line $line: a $column row needs sku and $column — skipped" );
			$skipped++;
			unset( $shades[ $line ] );
			continue;
		}
		$term = term_exists( $r['shade'], $taxonomy ) ?: wp_insert_term( $r['shade'], $taxonomy );
		if ( is_wp_error( $term ) ) {
			WP_CLI::warning( "line $line: shade {$r['shade']} — " . $term->get_error_message() );
			$skipped++;
			unset( $shades[ $line ] );
			continue;
		}
		$terms[ $line ] = get_term( (int) $term['term_id'], $taxonomy );
		if ( $color = sanitize_hex_color( $r['shade_color'] ?? '' ) ) {
			update_term_meta( $terms[ $line ]->term_id, 'isa_color', $color );
		}
	}
	$existing = $parent->get_attributes()[ $taxonomy ] ?? null;
	$options  = array_values( array_unique( array_merge( $existing ? $existing->get_options() : [], array_map( fn( $t ) => $t->term_id, $terms ) ) ) );
	$attr     = new WC_Product_Attribute();
	$attr->set_id( $attribute_id );
	$attr->set_name( $taxonomy );
	$attr->set_options( $options );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$parent->set_attributes( [ $taxonomy => $attr ] );
	$parent->save();

	foreach ( $shades as $line => $r ) {
		$term = $terms[ $line ];
		$id   = wc_get_product_id_by_sku( $r['sku'] );
		$v    = $id ? wc_get_product( $id ) : null;
		if ( $v && ! $v->is_type( 'variation' ) ) {
			WP_CLI::warning( "line $line: sku {$r['sku']} is already used by another product — skipped" );
			$skipped++;
			continue;
		}
		$is_new = ! $v;
		$v      = $v ?: new WC_Product_Variation();
		[ $price, $sale ] = is_numeric( $r['price'] ?? '' )
			? [ (string) $r['price'], is_numeric( $r['sale_price'] ?? '' ) ? (string) $r['sale_price'] : '' ]
			: $prices[ $parent_sku ];

		$v->set_parent_id( $parent->get_id() );
		$v->set_attributes( [ $taxonomy => $term->slug ] );
		$v->set_sku( $r['sku'] );
		$v->set_status( 'publish' );
		$v->set_regular_price( $price );
		$v->set_sale_price( $sale );
		$set_stock( $v, $r['stock'] ?? '' );
		$v->save();
		$set_images( $v, $r['images'] ?? '' );

		$is_new ? $created++ : $updated++;
		WP_CLI::log( sprintf( '  %s %s — %s / %s', $is_new ? '+' : '~', $r['sku'], $parent->get_name(), $term->name ) );
	}
	WC_Product_Variable::sync( $parent->get_id() );
}

WP_CLI::success( "created $created, updated $updated, skipped $skipped" );
