<?php
/**
 * Shades and scents: one product, several colours (tints) or smells (body splash, hair mist).
 *
 * A "Variable product" whose only attribute is Shade or Scent gets a list on its page instead of
 * WooCommerce's dropdown: every option with its colour dot, how many are left, and its own quantity,
 * then one button adds them all (2 Red + 1 Rose in one go). Sold-out options stay visible
 * but can't be picked. Shop cards show the dots under the name.
 *
 * Dot colour: Products → Attributes → Shade (or Scent) → Configure terms → edit one → "Swatch colour".
 * No colour set → the option's variation photo, else a plain dot.
 */

defined( 'ABSPATH' ) || exit;

const ISA_SHADE = 'pa_shade';
const ISA_SCENT = 'pa_scent';

/** Attribute taxonomies that get the list picker. */
function isa_picker_taxonomies(): array {
	return [ ISA_SHADE => 'Shade', ISA_SCENT => 'Scent' ];
}

/** Make sure the global Shade and Scent attributes exist and are registered for this request. */
function isa_ensure_shade_attribute(): void {
	if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
		return;
	}
	foreach ( isa_picker_taxonomies() as $taxonomy => $label ) {
		$slug = substr( $taxonomy, 3 );
		if ( wc_attribute_taxonomy_id_by_name( $slug ) ) {
			continue;
		}
		$id = wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order' ] );
		if ( ! is_wp_error( $id ) && ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false ] );
		}
	}
}
add_action( 'admin_init', 'isa_ensure_shade_attribute' );

/** Shopper-facing words for each picker. */
function isa_picker_text( string $taxonomy, string $key, int $n = 0 ): string {
	$scent = ISA_SCENT === $taxonomy;
	switch ( $key ) {
		case 'legend':
			return $scent ? __( 'Choose your scents', 'isa' ) : __( 'Choose your shades', 'isa' );
		case 'empty':
			return $scent ? __( 'Pick a scent', 'isa' ) : __( 'Pick a shade', 'isa' );
		case 'none':
			return $scent ? __( 'Pick at least one scent.', 'isa' ) : __( 'Pick at least one shade.', 'isa' );
		case 'button':
			return $scent ? __( 'Choose scents', 'isa' ) : __( 'Choose shades', 'isa' );
		case 'count':
			/* translators: %d number of scents / shades */
			return sprintf( $scent ? _n( '%d scent', '%d scents', $n, 'isa' ) : _n( '%d shade', '%d shades', $n, 'isa' ), $n );
	}
	return '';
}

/* -------------------------------------------------------------------------
 * Admin: swatch colour on each shade / scent
 * ---------------------------------------------------------------------- */

$isa_add_color_field = function () {
	printf(
		'<div class="form-field"><label for="isa_color">%s</label><input type="color" id="isa_color" name="isa_color" value="#c8a18a"><p>%s</p></div>',
		esc_html__( 'Swatch colour', 'isa' ),
		esc_html__( 'The dot customers see on the product page.', 'isa' )
	);
};

$isa_edit_color_field = function ( $term ) {
	printf(
		'<tr class="form-field"><th scope="row"><label for="isa_color">%s</label></th><td><input type="color" id="isa_color" name="isa_color" value="%s"><p class="description">%s</p></td></tr>',
		esc_html__( 'Swatch colour', 'isa' ),
		esc_attr( get_term_meta( $term->term_id, 'isa_color', true ) ?: '#c8a18a' ),
		esc_html__( 'The dot customers see on the product page.', 'isa' )
	);
};

$isa_save_color = function ( $term_id ) {
	if ( isset( $_POST['isa_color'] ) && current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- core verifies the term form nonce
		$color = sanitize_hex_color( wp_unslash( $_POST['isa_color'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$color ? update_term_meta( $term_id, 'isa_color', $color ) : delete_term_meta( $term_id, 'isa_color' );
	}
};
foreach ( array_keys( isa_picker_taxonomies() ) as $isa_tax ) {
	add_action( $isa_tax . '_add_form_fields', $isa_add_color_field );
	add_action( $isa_tax . '_edit_form_fields', $isa_edit_color_field );
	add_action( 'created_' . $isa_tax, $isa_save_color );
	add_action( 'edited_' . $isa_tax, $isa_save_color );
}

/* -------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------- */

/** The picker taxonomy of a variable product sold by shade or scent only, else ''. */
function isa_picker_taxonomy( $product ): string {
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return '';
	}
	$attrs = array_keys( $product->get_variation_attributes() );
	return 1 === count( $attrs ) && isset( isa_picker_taxonomies()[ $attrs[0] ] ) ? $attrs[0] : '';
}

function isa_is_shade_product( $product ): bool {
	return '' !== isa_picker_taxonomy( $product );
}

/**
 * Every published shade of a product: in stock first, each group in the attribute's term order.
 *
 * @return array<int, array{id:int, slug:string, name:string, color:string, image:int, price:string, stock:?int, buyable:bool, max:int}>
 */
function isa_product_shades( WC_Product_Variable $product ): array {
	$taxonomy = isa_picker_taxonomy( $product );
	$terms    = [];
	foreach ( wc_get_product_terms( $product->get_id(), $taxonomy, [ 'fields' => 'all' ] ) as $i => $t ) {
		$terms[ $t->slug ] = [ $i, $t ];
	}
	$shades = [];
	foreach ( $product->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || 'publish' !== $v->get_status() ) {
			continue;
		}
		$slug = $v->get_attributes()[ $taxonomy ] ?? '';
		if ( ! isset( $terms[ $slug ] ) ) {
			continue;
		}
		[ $order, $term ] = $terms[ $slug ];
		$stock   = $v->managing_stock() ? max( 0, (int) $v->get_stock_quantity() ) : null;
		$buyable = $v->is_purchasable() && $v->is_in_stock();
		$max     = $v->get_max_purchase_quantity(); // -1 = unlimited
		$shades[ $order ] = [
			'id'      => $vid,
			'slug'    => $slug,
			'name'    => $term->name,
			'color'   => (string) get_term_meta( $term->term_id, 'isa_color', true ),
			'image'   => $v->get_image_id() !== $product->get_image_id() ? (int) $v->get_image_id() : 0,
			'price'   => $v->get_price_html(),
			'stock'   => $stock,
			'buyable' => $buyable,
			'max'     => $buyable ? ( $max > 0 ? $max : 99 ) : 0,
		];
	}
	ksort( $shades );
	// Sold-out shades stay visible, after the ones you can buy.
	usort( $shades, fn( $a, $b ) => $b['buyable'] <=> $a['buyable'] );
	return $shades;
}

function isa_shade_swatch( array $shade, string $class = 'isa-swatch' ): string {
	if ( $shade['color'] ) {
		return sprintf( '<span class="%s" style="--swatch:%s" aria-hidden="true"></span>', esc_attr( $class ), esc_attr( $shade['color'] ) );
	}
	if ( $shade['image'] ) {
		return sprintf( '<span class="%s %s--img" aria-hidden="true">%s</span>', esc_attr( $class ), esc_attr( $class ), wp_get_attachment_image( $shade['image'], 'thumbnail', false, [ 'alt' => '', 'loading' => 'lazy' ] ) );
	}
	return sprintf( '<span class="%s" aria-hidden="true"></span>', esc_attr( $class ) );
}

/* -------------------------------------------------------------------------
 * Product page: shade list with a quantity per shade
 * ---------------------------------------------------------------------- */

// Our list replaces WooCommerce's dropdown form for shade products; other variable products keep it.
add_action( 'init', function () {
	remove_action( 'woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart', 30 );
	add_action( 'woocommerce_variable_add_to_cart', 'isa_variable_add_to_cart', 30 );
} );

function isa_variable_add_to_cart(): void {
	global $product;
	if ( ! isa_is_shade_product( $product ) ) {
		woocommerce_variable_add_to_cart();
		return;
	}
	$shades = isa_product_shades( $product );
	if ( ! $shades ) {
		return;
	}
	$taxonomy  = isa_picker_taxonomy( $product );
	$any       = (bool) array_filter( $shades, fn( $s ) => $s['buyable'] );
	$one_price = 1 === count( array_unique( array_column( $shades, 'price' ) ) );
	?>
	<form class="isa-shades" method="post" action="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>">
		<fieldset>
			<legend><?php echo esc_html( isa_picker_text( $taxonomy, 'legend' ) ); ?></legend>
			<ul class="isa-shades__list">
				<?php foreach ( $shades as $s ) : $input = 'isa-shade-' . $s['id']; ?>
					<li class="isa-shade<?php echo $s['buyable'] ? '' : ' is-out'; ?>">
						<?php echo isa_shade_swatch( $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<label class="isa-shade__name" for="<?php echo esc_attr( $input ); ?>">
							<?php echo esc_html( $s['name'] ); ?>
							<?php if ( ! $one_price ) : ?><span class="isa-shade__price"><?php echo wp_kses_post( $s['price'] ); ?></span><?php endif; ?>
						</label>
						<span class="isa-shade__stock">
							<?php
							if ( ! $s['buyable'] ) {
								esc_html_e( 'Sold out', 'isa' );
							} elseif ( null !== $s['stock'] ) {
								/* translators: %d: pieces left of this shade */
								echo esc_html( sprintf( _n( '%d left', '%d left', $s['stock'], 'isa' ), $s['stock'] ) );
							}
							?>
						</span>
						<span class="isa-qty">
							<button type="button" class="isa-qty__btn" data-step="-1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s shade name */ __( 'One less %s', 'isa' ), $s['name'] ) ); ?>" <?php disabled( ! $s['buyable'] ); ?>>−</button>
							<input type="number" id="<?php echo esc_attr( $input ); ?>" name="isa_shades[<?php echo esc_attr( $s['id'] ); ?>]" value="0" min="0" max="<?php echo esc_attr( $s['max'] ); ?>" step="1" inputmode="numeric" <?php disabled( ! $s['buyable'] ); ?>>
							<button type="button" class="isa-qty__btn" data-step="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s shade name */ __( 'One more %s', 'isa' ), $s['name'] ) ); ?>" <?php disabled( ! $s['buyable'] ); ?>>+</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</fieldset>
		<input type="hidden" name="isa_shades_product" value="<?php echo esc_attr( $product->get_id() ); ?>">
		<button type="submit" class="single_add_to_cart_button button alt" disabled data-empty="<?php echo esc_attr( isa_picker_text( $taxonomy, 'empty' ) ); ?>" data-label="<?php esc_attr_e( 'Add to cart', 'isa' ); ?>">
			<?php echo esc_html( $any ? isa_picker_text( $taxonomy, 'empty' ) : __( 'Sold out', 'isa' ) ); ?>
		</button>
	</form>
	<script>
	(function (form) {
		var btn = form.querySelector('[type=submit]');
		function total() {
			var n = 0;
			form.querySelectorAll('input[type=number]').forEach(function (i) {
				var v = Math.max(0, Math.min(parseInt(i.value, 10) || 0, +i.max));
				if (String(v) !== i.value) i.value = v;
				i.closest('.isa-shade').classList.toggle('is-picked', v > 0);
				n += v;
			});
			btn.disabled = !n;
			btn.textContent = n ? btn.dataset.label + ' (' + n + ')' : btn.dataset.empty;
		}
		form.addEventListener('click', function (e) {
			var b = e.target.closest('.isa-qty__btn');
			if (!b) return;
			var i = b.parentNode.querySelector('input');
			i.value = (parseInt(i.value, 10) || 0) + +b.dataset.step;
			total();
		});
		form.addEventListener('input', total);
		total();
	})(document.currentScript.previousElementSibling);
	</script>
	<?php
}

// Add every picked shade to the cart, then come back to the page (no resubmit on refresh).
// No nonce, like WooCommerce's own add-to-cart: guests get cached pages, and a stale nonce would block buying.
add_action( 'wp_loaded', function () {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['isa_shades_product'] ) || ! isset( $_POST['isa_shades'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$product = wc_get_product( absint( $_POST['isa_shades_product'] ) );
	if ( ! isa_is_shade_product( $product ) ) {
		return;
	}
	$children = $product->get_children();
	$added    = [];
	foreach ( (array) wp_unslash( $_POST['isa_shades'] ) as $vid => $qty ) {
		$vid = absint( $vid );
		$qty = absint( $qty );
		if ( ! $qty || ! in_array( $vid, $children, true ) ) {
			continue;
		}
		$variation = wc_get_product( $vid );
		// Shows its own notice (e.g. "only 3 left") when it can't add.
		if ( $variation && WC()->cart->add_to_cart( $product->get_id(), $qty, $vid, $variation->get_variation_attributes() ) ) {
			$added[ $vid ] = $qty;
		}
	}
	if ( $added ) {
		wc_add_to_cart_message( $added, true );
	} elseif ( ! wc_notice_count( 'error' ) ) {
		wc_add_notice( isa_picker_text( isa_picker_taxonomy( $product ), 'none' ), 'error' );
	}
	$to = $added && 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ? wc_get_cart_url() : ( wp_get_referer() ?: get_permalink( $product->get_id() ) );
	wp_safe_redirect( $to );
	exit;
}, 25 );

/* -------------------------------------------------------------------------
 * Shop cards: shade dots under the name
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( ! isa_is_shade_product( $product ) ) {
		return;
	}
	$shades = isa_product_shades( $product );
	if ( count( $shades ) < 2 ) {
		return;
	}
	$dots = '';
	foreach ( array_slice( $shades, 0, 6 ) as $s ) {
		$dots .= isa_shade_swatch( $s, 'isa-dot' . ( $s['buyable'] ? '' : ' is-out' ) );
	}
	$more = count( $shades ) > 6 ? '<span class="isa-dots__more">+' . ( count( $shades ) - 6 ) . '</span>' : '';
	printf(
		'<span class="isa-dots" title="%1$s">%2$s%3$s<span class="screen-reader-text">%1$s</span></span>',
		esc_attr( isa_picker_text( isa_picker_taxonomy( $product ), 'count', count( $shades ) ) ),
		$dots, // phpcs:ignore WordPress.Security.EscapeOutput
		$more  // phpcs:ignore WordPress.Security.EscapeOutput
	);
}, 7 );

// "Select options" reads oddly for shades and scents.
add_filter( 'woocommerce_product_add_to_cart_text', function ( $text, $product ) {
	return isa_is_shade_product( $product ) && $product->is_in_stock() ? isa_picker_text( isa_picker_taxonomy( $product ), 'button' ) : $text;
}, 10, 2 );
