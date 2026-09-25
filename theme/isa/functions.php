<?php
/**
 * isa child theme (parent: Storefront).
 */

defined( 'ABSPATH' ) || exit;

define( 'ISA_URI', get_stylesheet_directory_uri() );

// Theme strings (header, footer, home sections, checkout messages). Arabic lives in languages/ar.po.
add_action( 'after_setup_theme', function () {
	load_child_theme_textdomain( 'isa', get_stylesheet_directory() . '/languages' );
} );

// Storefront has no Arabic pack on wordpress.org; ours covers the shopper-facing strings.
add_filter( 'load_textdomain_mofile', function ( $mofile, $domain ) {
	if ( 'storefront' === $domain && str_starts_with( determine_locale(), 'ar' ) ) {
		$ours = get_stylesheet_directory() . '/languages/storefront-ar.mo';
		return file_exists( $ours ) ? $ours : $mofile;
	}
	return $mofile;
}, 10, 2 );

function isa_is_arabic(): bool {
	return str_starts_with( determine_locale(), 'ar' );
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'isa-fonts',
		'https://fonts.googleapis.com/css2?family=Bodoni+Moda:opsz,wght@6..96,400;6..96,500&family=Jost:wght@400;500' . ( isa_is_arabic() ? '&family=Amiri:wght@400;700&family=IBM+Plex+Sans+Arabic:wght@400;500' : '' ) . '&display=swap',
		[],
		null
	);
	wp_dequeue_style( 'storefront-child-style' ); // Storefront auto-enqueues the child CSS too; keep one copy
	wp_enqueue_style( 'isa', get_stylesheet_uri(), [ 'storefront-style' ], (string) filemtime( get_stylesheet_directory() . '/style.css' ) );
}, 40 ); // after Storefront's own child-style enqueue (priority 30)

add_action( 'wp_head', function () {
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	echo '<meta name="theme-color" content="#f6efe7">' . "\n";
}, 1 );

/* -------------------------------------------------------------------------
 * Settings (Settings → General): WhatsApp, Instagram, announcement text
 * ---------------------------------------------------------------------- */

function isa_setting( string $key, string $default = '' ): string {
	$value = trim( (string) get_option( "isa_$key", '' ) );
	return '' === $value ? $default : $value;
}

add_action( 'admin_init', function () {
	$fields = [
		'whatsapp'     => __( 'WhatsApp number (with country code, e.g. 201001234567)', 'isa' ),
		'instagram'    => __( 'Instagram handle (without @)', 'isa' ),
		'announcement' => __( 'Top announcement bar text', 'isa' ),
	];
	foreach ( $fields as $key => $label ) {
		register_setting( 'general', "isa_$key", [ 'sanitize_callback' => 'sanitize_text_field' ] );
		add_settings_field( "isa_$key", $label, function () use ( $key ) {
			printf( '<input type="text" class="regular-text" name="isa_%1$s" value="%2$s">', esc_attr( $key ), esc_attr( get_option( "isa_$key", '' ) ) );
		}, 'general' );
	}
} );

function isa_whatsapp_url(): string {
	$number = preg_replace( '/\D/', '', isa_setting( 'whatsapp' ) );
	return $number ? 'https://wa.me/' . $number : '';
}

/* -------------------------------------------------------------------------
 * Header: announcement bar, centred logo, no search
 * ---------------------------------------------------------------------- */

add_action( 'storefront_before_header', function () {
	$text = isa_setting( 'announcement', __( 'Pay cash on delivery, anywhere in Egypt', 'isa' ) );
	printf( '<div class="isa-announcement">%s</div>', esc_html( $text ) );
} );

/**
 * Language switcher: one link to the other language, same page.
 * Rendered in the nav row (desktop) and next to the menu button (mobile).
 */
function isa_language_switcher(): string {
	if ( ! function_exists( 'trp_custom_language_switcher' ) ) {
		return '';
	}
	$current = get_locale();
	$out     = '';
	foreach ( trp_custom_language_switcher() as $code => $lang ) {
		if ( $code === $current ) {
			continue;
		}
		$label = str_starts_with( $code, 'ar' ) ? 'العربية' : 'English';
		$out  .= sprintf(
			'<a class="isa-lang" href="%s" hreflang="%s" lang="%s" data-no-translation>%s</a>',
			esc_url( $lang['current_page_url'] ),
			esc_attr( $lang['short_language_name'] ),
			esc_attr( $lang['short_language_name'] ),
			esc_html( $label )
		);
	}
	return $out;
}
add_action( 'storefront_header', function () {
	echo '<div class="isa-lang-wrap">' . isa_language_switcher() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}, 65 );

add_action( 'init', function () {
	remove_action( 'storefront_header', 'storefront_product_search', 40 );
	remove_action( 'storefront_header', 'storefront_secondary_navigation', 30 );
	// Our own footer replaces Storefront's widget columns + credit.
	remove_action( 'storefront_footer', 'storefront_footer_widgets', 10 );
	remove_action( 'storefront_footer', 'storefront_credit', 20 );
} );

// Mobile bottom bar: account + cart only (search is off).
add_filter( 'storefront_handheld_footer_bar_links', function ( array $links ): array {
	unset( $links['search'] );
	return $links;
} );

/* -------------------------------------------------------------------------
 * Footer
 * ---------------------------------------------------------------------- */

add_action( 'storefront_footer', function () {
	$wa    = isa_whatsapp_url();
	$ig    = isa_setting( 'instagram' );
	$cats  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => [ (int) get_option( 'default_product_cat' ) ], 'number' => 6 ] );
	$help  = wp_get_nav_menu_object( 'Footer' );
	?>
	<div class="isa-footer">
		<div class="isa-footer__brand">
			<img src="<?php echo esc_url( ISA_URI . '/assets/logo-light.png' ); ?>" alt="isa skincare" width="120" height="128" loading="lazy">
			<p><?php esc_html_e( 'Gentle, effective skin care — delivered anywhere in Egypt.', 'isa' ); ?></p>
		</div>
		<nav class="isa-footer__col" aria-label="<?php esc_attr_e( 'Shop', 'isa' ); ?>">
			<h4><?php esc_html_e( 'Shop', 'isa' ); ?></h4>
			<ul>
				<li><a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'All products', 'isa' ); ?></a></li>
				<?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $cat ) : ?>
					<li><a href="<?php echo esc_url( get_term_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a></li>
				<?php endforeach; endif; ?>
			</ul>
		</nav>
		<nav class="isa-footer__col" aria-label="<?php esc_attr_e( 'Help', 'isa' ); ?>">
			<h4><?php esc_html_e( 'Help', 'isa' ); ?></h4>
			<?php
			if ( $help ) {
				wp_nav_menu( [ 'menu' => $help->term_id, 'container' => false, 'depth' => 1 ] );
			}
			?>
		</nav>
		<div class="isa-footer__col">
			<h4><?php esc_html_e( 'Talk to us', 'isa' ); ?></h4>
			<ul>
				<?php if ( $wa ) : ?><li><a href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener">WhatsApp</a></li><?php endif; ?>
				<?php if ( $ig ) : ?><li><a href="<?php echo esc_url( 'https://instagram.com/' . ltrim( $ig, '@' ) ); ?>" target="_blank" rel="noopener">Instagram</a></li><?php endif; ?>
				<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact page', 'isa' ); ?></a></li>
			</ul>
			<p class="isa-footer__pay"><?php esc_html_e( 'We accept cash on delivery, InstaPay and Vodafone Cash.', 'isa' ); ?></p>
		</div>
	</div>
	<div class="isa-footer__legal">&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> isa skincare</div>
	<?php
}, 10 );

/* -------------------------------------------------------------------------
 * Floating WhatsApp button
 * ---------------------------------------------------------------------- */

add_action( 'wp_footer', function () {
	$url = isa_whatsapp_url();
	if ( ! $url ) {
		return;
	}
	printf(
		'<a class="isa-whatsapp" href="%s" target="_blank" rel="noopener" aria-label="%s"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.87 9.87 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.41 1.3-1.94 1.35-.5.05-.97.23-3.27-.68-2.77-1.09-4.52-3.93-4.66-4.11-.13-.18-1.11-1.48-1.11-2.82 0-1.34.7-2 .95-2.27.25-.27.54-.34.72-.34h.52c.17 0 .39-.06.61.47.24.56.79 1.93.86 2.07.07.14.11.3.02.48-.09.18-.14.3-.27.46-.14.16-.29.36-.41.48-.14.14-.28.29-.12.56.16.27.71 1.17 1.52 1.9 1.05.93 1.93 1.22 2.2 1.36.27.14.43.12.59-.07.16-.18.68-.79.86-1.07.18-.27.36-.23.61-.14.25.09 1.59.75 1.86.89.27.14.45.2.52.32.07.11.07.66-.17 1.34Z"/></svg></a>',
		esc_url( $url ),
		esc_attr__( 'Chat on WhatsApp', 'isa' )
	);
} );

/* -------------------------------------------------------------------------
 * Shortcodes used by the home page
 * ---------------------------------------------------------------------- */

// [isa_hero] — brand hero. Picks up theme/isa/assets/hero.jpg automatically when you add one.
add_shortcode( 'isa_hero', function () {
	$photo = file_exists( get_stylesheet_directory() . '/assets/hero.jpg' ) ? ISA_URI . '/assets/hero.jpg' : '';
	ob_start();
	?>
	<section class="isa-hero<?php echo $photo ? ' isa-hero--photo' : ''; ?>">
		<div class="isa-hero__text">
			<h1><?php esc_html_e( 'Skin care that feels like you', 'isa' ); ?></h1>
			<p class="isa-hero__lead"><?php esc_html_e( 'Gentle, effective products for every skin type — delivered to your door anywhere in Egypt.', 'isa' ); ?></p>
			<div class="isa-hero__cta">
				<a class="button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Shop the collection', 'isa' ); ?></a>
				<?php if ( isa_whatsapp_url() ) : ?>
					<a class="isa-link" href="<?php echo esc_url( isa_whatsapp_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Not sure what suits you? Ask us', 'isa' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<div class="isa-hero__art" aria-hidden="true">
			<?php if ( $photo ) : ?>
				<img src="<?php echo esc_url( $photo ); ?>" alt="" loading="eager">
			<?php else : ?>
				<img class="isa-hero__mark" src="<?php echo esc_url( ISA_URI . '/assets/logo-mark.png' ); ?>" alt="" width="343" height="310">
			<?php endif; ?>
		</div>
	</section>
	<?php
	return ob_get_clean();
} );

// [isa_heading eyebrow="" title="" link="" link_text=""]
add_shortcode( 'isa_heading', function ( $atts ) {
	$a = shortcode_atts( [ 'eyebrow' => '', 'title' => '', 'link' => '', 'link_text' => '' ], $atts );
	$link = $a['link'] ? sprintf( '<a class="isa-link" href="%s">%s</a>', esc_url( home_url( $a['link'] ) ), esc_html( $a['link_text'] ) ) : '';
	return sprintf(
		'<header class="isa-heading"><div>%s<h2>%s</h2></div>%s</header>',
		$a['eyebrow'] ? '<p class="isa-heading__note">' . esc_html( $a['eyebrow'] ) . '</p>' : '',
		esc_html( $a['title'] ),
		$link
	);
} );

// [isa_categories] — category tiles (only categories that have products).
add_shortcode( 'isa_categories', function () {
	$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => [ (int) get_option( 'default_product_cat' ) ], 'number' => 4 ] );
	if ( is_wp_error( $cats ) || ! $cats ) {
		return '';
	}
	$out = '<div class="isa-cats">';
	foreach ( $cats as $cat ) {
		$thumb = (int) get_term_meta( $cat->term_id, 'thumbnail_id', true );
		if ( ! $thumb ) { // fall back to the newest product image in the category
			$p = wc_get_products( [ 'category' => [ $cat->slug ], 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ] );
			$thumb = $p ? (int) $p[0]->get_image_id() : 0;
		}
		$img  = $thumb ? wp_get_attachment_image( $thumb, 'woocommerce_thumbnail', false, [ 'loading' => 'lazy' ] ) : '';
		$out .= sprintf(
			'<a class="isa-cat" href="%s">%s<span>%s <small>%s</small></span></a>',
			esc_url( get_term_link( $cat ) ),
			$img,
			esc_html( $cat->name ),
			/* translators: %d products */
			esc_html( sprintf( _n( '%d product', '%d products', $cat->count, 'isa' ), $cat->count ) )
		);
	}
	return $out . '</div>';
} );

// [isa_promise] — trust strip.
add_shortcode( 'isa_promise', function () {
	$icons = [
		'cash'  => '<path d="M3 7h18v10H3z"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4M18 10v4"/>',
		'truck' => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="1.8"/><circle cx="17" cy="17" r="1.8"/>',
		'leaf'  => '<path d="M5 19c0-8 5-13 14-14-1 9-6 14-14 14Z"/><path d="M5 19 13 11"/>',
		'chat'  => '<path d="M4 5h16v11H9l-5 4z"/>',
	];
	$items = [
		[ 'cash', __( 'Cash on delivery', 'isa' ), __( 'Pay when your order arrives', 'isa' ) ],
		[ 'truck', __( 'All over Egypt', 'isa' ), __( 'Delivery to every governorate', 'isa' ) ],
		[ 'leaf', __( '100% original', 'isa' ), __( 'Sourced directly, never repacked', 'isa' ) ],
		[ 'chat', __( 'Real advice', 'isa' ), __( 'Ask us anything on WhatsApp', 'isa' ) ],
	];
	$out = '<section class="isa-promise">';
	foreach ( $items as [ $icon, $title, $text ] ) {
		$out .= sprintf(
			'<div><svg viewBox="0 0 24 24" aria-hidden="true">%s</svg><strong>%s</strong><span>%s</span></div>',
			$icons[ $icon ],
			esc_html( $title ),
			esc_html( $text )
		);
	}
	return $out . '</section>';
} );

/* -------------------------------------------------------------------------
 * Shop tweaks
 * ---------------------------------------------------------------------- */

// Sale badge shows the discount instead of "Sale!".
add_filter( 'woocommerce_sale_flash', function ( $html, $post, $product ) {
	$regular = (float) $product->get_regular_price();
	$sale    = (float) $product->get_sale_price();
	if ( $regular > 0 && $sale > 0 ) {
		return '<span class="onsale">−' . round( 100 - $sale / $regular * 100 ) . '%</span>';
	}
	return $html;
}, 10, 3 );

// Four products per row on the shop page.
add_filter( 'loop_shop_columns', fn() => 4 );
add_filter( 'storefront_loop_columns', fn() => 4 );
