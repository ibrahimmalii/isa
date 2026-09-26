<?php
/**
 * Search engines: page titles, meta descriptions, social previews, structured data,
 * canonical/x-default links, sitemap and robots.txt.
 *
 * Each product, page and product category has a "Search engines" box on its edit screen with a
 * title and description in English and Arabic. Empty fields fall back to the name, short
 * description or category description (Arabic: their TranslatePress translation).
 * TranslatePress free doesn't translate <title> or meta tags, so the Arabic is output here.
 */

defined( 'ABSPATH' ) || exit;

const ISA_SEO_TITLE = '_isa_seo_title';
const ISA_SEO_DESC  = '_isa_seo_desc';

/* -------------------------------------------------------------------------
 * What the current page is called and how it's described
 * ---------------------------------------------------------------------- */

/** The page the "Search engines" box belongs to: a post ID, a term, or null. */
function isa_seo_object() {
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return (int) wc_get_page_id( 'shop' );
	}
	if ( is_front_page() ) {
		return (int) get_option( 'page_on_front' ) ?: null;
	}
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	if ( is_category() || is_tag() || is_tax() ) {
		return get_queried_object();
	}
	return null;
}

/** A "Search engines" field in the page's language (Arabic falls back to nothing, not to English). */
function isa_seo_field( string $key ): string {
	$key    = isa_is_arabic() ? $key . '_ar' : $key;
	$object = isa_seo_object();
	if ( $object instanceof WP_Term ) {
		return trim( (string) get_term_meta( $object->term_id, $key, true ) );
	}
	return $object ? trim( (string) get_post_meta( $object, $key, true ) ) : '';
}

/**
 * On /ar/: the TranslatePress translation of an English text (product name, short description…),
 * or the text itself when there is none. Elsewhere: the text unchanged. Always plain text (entities decoded).
 */
function isa_seo_translate( string $text ): string {
	$text = trim( $text );
	if ( '' === $text || ! isa_is_arabic() || ! class_exists( 'TRP_Translate_Press' ) ) {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	// TranslatePress stores text as it appears in the page: HTML entities, curly quotes.
	$plain      = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$candidates = array_values( array_unique( [ $text, $plain, esc_html( $plain ), wptexturize( esc_html( $plain ) ), str_replace( '&amp;', '&#038;', esc_html( $plain ) ) ] ) );
	$found      = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_existing_translations( $candidates, 'ar' );
	foreach ( $candidates as $candidate ) {
		if ( ! empty( $found[ $candidate ]->translated ) ) {
			return html_entity_decode( $found[ $candidate ]->translated, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}
	return $plain;
}

/** First paragraph of some HTML as plain text: one TranslatePress string. */
function isa_seo_first_paragraph( string $html ): string {
	$html = strip_shortcodes( $html );
	if ( preg_match( '#<p[^>]*>(.*?)</p>#is', $html, $m ) ) {
		$html = $m[1];
	} else {
		$html = preg_split( '/\R\s*\R/', trim( $html ) )[0] ?? '';
	}
	return trim( wp_strip_all_tags( $html ) );
}

/** Plain text, one line, cut at a word boundary. */
function isa_seo_clean( string $text, int $max = 160 ): string {
	$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 1 );
	$cut = preg_replace( '/\s+\S*$/u', '', $cut );
	return rtrim( $cut, " ,;:،–—-" ) . '…';
}

/** Title for this page, plain text (not escaped). Empty = let WordPress decide. */
function isa_seo_title(): string {
	$custom = isa_seo_field( ISA_SEO_TITLE );
	if ( '' !== $custom ) {
		return $custom;
	}
	if ( is_front_page() ) {
		return __( 'isa skincare Egypt | Lip & Cheek Tint, Body Splash & Hair Mist', 'isa' );
	}
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return __( 'Shop Skin Care, Tints & Body Splash Online in Egypt | isa', 'isa' );
	}
	if ( is_singular( 'product' ) ) {
		/* translators: %s product name */
		return sprintf( __( '%s | isa Egypt', 'isa' ), isa_seo_translate( get_the_title( get_queried_object_id() ) ) );
	}
	if ( is_tax( 'product_cat' ) ) {
		/* translators: %s category name */
		return sprintf( __( '%s in Egypt | isa', 'isa' ), isa_seo_translate( get_queried_object()->name ) );
	}
	if ( is_singular() ) {
		$title = isa_seo_translate( get_the_title( get_queried_object_id() ) );
		return false !== stripos( $title, 'isa' ) ? $title : $title . ' | isa';
	}
	return '';
}

/** Meta description for this page, plain text. */
function isa_seo_description(): string {
	$text = isa_seo_field( ISA_SEO_DESC );
	if ( '' === $text ) {
		if ( is_front_page() ) {
			$text = __( 'isa skincare: lip & cheek tints, body splashes and hair mists that feel like you. Order online with delivery to every governorate in Egypt.', 'isa' );
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$text = __( 'Shop all isa products online: lip & cheek tints, body splashes and hair mists. Pay by InstaPay or Vodafone Cash, delivered anywhere in Egypt.', 'isa' );
		} elseif ( is_singular( 'product' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			$text    = $product ? isa_seo_translate( isa_seo_first_paragraph( $product->get_short_description() ?: $product->get_description() ) ) : '';
		} elseif ( is_tax() || is_category() ) {
			$text = isa_seo_translate( isa_seo_first_paragraph( get_queried_object()->description ) );
		} elseif ( is_singular() ) {
			$post = get_queried_object();
			$text = isa_seo_translate( isa_seo_first_paragraph( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content ) );
		}
	}
	return isa_seo_clean( (string) $text );
}

/** Main image for social previews: product photo, category photo, else the logo. */
function isa_seo_image(): array {
	$id = 0;
	if ( is_singular() ) {
		$id = (int) get_post_thumbnail_id( get_queried_object_id() );
	} elseif ( is_tax( 'product_cat' ) ) {
		$id = (int) get_term_meta( get_queried_object_id(), 'thumbnail_id', true );
	}
	if ( ! $id ) {
		$id = (int) get_theme_mod( 'custom_logo' );
	}
	$img = $id ? wp_get_attachment_image_src( $id, 'large' ) : false;
	if ( ! $img ) {
		return [];
	}
	return [ 'url' => $img[0], 'width' => $img[1], 'height' => $img[2], 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ];
}

/** The address search engines should index for this page (WordPress core only does singular pages). */
function isa_seo_canonical(): string {
	$url = '';
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		$url = get_permalink( wc_get_page_id( 'shop' ) );
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$url = get_term_link( get_queried_object() );
	} elseif ( is_singular() ) {
		$url = wp_get_canonical_url();
	} elseif ( is_front_page() ) {
		$url = home_url( '/' );
	}
	if ( ! $url || is_wp_error( $url ) ) {
		return '';
	}
	$paged = (int) get_query_var( 'paged' );
	return $paged > 1 && ! is_singular() ? trailingslashit( $url ) . 'page/' . $paged . '/' : $url;
}

/* -------------------------------------------------------------------------
 * <head>
 * ---------------------------------------------------------------------- */

add_filter( 'pre_get_document_title', function ( $title ) {
	$ours = isa_seo_title();
	return '' !== $ours ? esc_html( $ours ) : $title; // returned as-is by core, so escape here
}, 20 );

add_action( 'wp_head', function () {
	$title = isa_seo_title() ?: wp_get_document_title();
	$desc  = isa_seo_description();
	$url   = isa_seo_canonical();
	$image = isa_seo_image();
	$tags  = [];

	if ( $desc ) {
		$tags[] = [ 'name', 'description', $desc ];
	}
	$tags[] = [ 'property', 'og:site_name', 'isa skincare' ];
	$tags[] = [ 'property', 'og:locale', isa_is_arabic() ? 'ar_EG' : 'en_US' ];
	$tags[] = [ 'property', 'og:locale:alternate', isa_is_arabic() ? 'en_US' : 'ar_EG' ];
	$tags[] = [ 'property', 'og:type', is_singular( 'product' ) ? 'product' : 'website' ];
	$tags[] = [ 'property', 'og:title', $title ];
	if ( $desc ) {
		$tags[] = [ 'property', 'og:description', $desc ];
	}
	if ( $url ) {
		$tags[] = [ 'property', 'og:url', $url ];
	}
	if ( $image ) {
		$tags[] = [ 'property', 'og:image', $image['url'] ];
		$tags[] = [ 'property', 'og:image:width', $image['width'] ];
		$tags[] = [ 'property', 'og:image:height', $image['height'] ];
		if ( $image['alt'] ) {
			$tags[] = [ 'property', 'og:image:alt', $image['alt'] ];
		}
	}
	if ( is_singular( 'product' ) && ( $product = wc_get_product( get_queried_object_id() ) ) && '' !== $product->get_price() ) {
		$tags[] = [ 'property', 'product:price:amount', wc_format_decimal( $product->get_price() ) ];
		$tags[] = [ 'property', 'product:price:currency', get_woocommerce_currency() ];
		$tags[] = [ 'property', 'product:availability', $product->is_in_stock() ? 'instock' : 'oos' ];
	}
	$tags[] = [ 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' ];

	foreach ( $tags as [ $attr, $key, $value ] ) {
		printf( '<meta %s="%s" content="%s">' . "\n", $attr, esc_attr( $key ), esc_attr( (string) $value ) );
	}

	// Archives (shop, categories) get a canonical too; core prints it for singular pages.
	if ( $url && ! is_singular() ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
	}

	// Searchers whose language is neither English nor Arabic get the English page.
	if ( class_exists( 'TRP_Translate_Press' ) && ! is_404() && ! is_search() ) {
		$settings = get_option( 'trp_settings', [] );
		$default  = $settings['default-language'] ?? 'en_US';
		$en       = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' )->get_url_for_language( $default, null, '' );
		if ( $en ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( $en ) );
		}
	}
}, 2 );

/* -------------------------------------------------------------------------
 * Structured data
 * ---------------------------------------------------------------------- */

// The store itself, on the home page: name, logo, social profiles, contact, where it sells.
add_action( 'wp_head', function () {
	if ( ! is_front_page() ) {
		return;
	}
	$home   = home_url( '/' );
	$logo   = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' );
	$tiktok = ltrim( isa_setting( 'tiktok' ), '@' );
	$insta  = ltrim( isa_setting( 'instagram' ), '@' );
	$same   = array_values( array_filter( [
		$insta ? 'https://www.instagram.com/' . $insta . '/' : '',
		$tiktok ? 'https://www.tiktok.com/@' . $tiktok : '',
	] ) );
	$store = [
		'@type'               => 'OnlineStore',
		'@id'                 => $home . '#store',
		'name'                => 'isa skincare',
		'alternateName'       => 'isa',
		'url'                 => $home,
		'description'         => isa_seo_description(),
		'areaServed'          => [ '@type' => 'Country', 'name' => 'Egypt' ],
		'currenciesAccepted'  => 'EGP',
		'paymentAccepted'     => 'InstaPay, Vodafone Cash',
	];
	if ( $logo ) {
		$store['logo']  = $logo;
		$store['image'] = $logo;
	}
	if ( $same ) {
		$store['sameAs'] = $same;
	}
	$phone = preg_replace( '/\D/', '', isa_setting( 'whatsapp' ) );
	if ( $phone ) {
		$store['contactPoint'] = [
			'@type'             => 'ContactPoint',
			'telephone'         => '+' . $phone,
			'contactType'       => 'customer service',
			'areaServed'        => 'EG',
			'availableLanguage' => [ 'Arabic', 'English' ],
		];
	}
	$graph = [
		'@context' => 'https://schema.org',
		'@graph'   => [
			$store,
			[
				'@type'      => 'WebSite',
				'@id'        => $home . '#website',
				'name'       => 'isa skincare',
				'url'        => $home,
				'inLanguage' => isa_is_arabic() ? 'ar' : 'en',
				'publisher'  => [ '@id' => $home . '#store' ],
			],
		],
	];
	echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 3 );

// WooCommerce already describes each product; add the brand, which Google asks for.
add_filter( 'woocommerce_structured_data_product', function ( $markup ) {
	$markup['brand'] = [ '@type' => 'Brand', 'name' => 'isa' ];
	return $markup;
} );

/* -------------------------------------------------------------------------
 * Indexing: robots meta, robots.txt, sitemap
 * ---------------------------------------------------------------------- */

// Pages with nothing to rank: account, search results, sorted/filtered copies of the shop.
add_filter( 'wp_robots', function ( array $robots ): array {
	$sorted = isset( $_GET['orderby'] ) || isset( $_GET['min_price'] ) || isset( $_GET['max_price'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( is_search() || $sorted || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
} );

/*
 * robots.txt itself comes from core + WooCommerce (sitemap line, add-to-cart and wp-admin rules).
 * WordPress only adds the robots.txt rewrite rule when home_url() has no path. Under WP-CLI,
 * TranslatePress makes it /en/, so every `wp rewrite flush` dropped the rule and robots.txt
 * became a 404. Keep it in regardless of who flushes.
 */
add_filter( 'rewrite_rules_array', function ( array $rules ): array {
	return [ 'robots\\.txt$' => 'index.php?robots=1' ] + $rules;
} );

// No author sitemap: it lists admin usernames and has nothing to rank.
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );

function isa_seo_unlisted_pages(): array {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return [];
	}
	return array_filter( array_map( 'wc_get_page_id', [ 'cart', 'checkout', 'myaccount' ] ), fn( $id ) => $id > 0 );
}

add_filter( 'wp_sitemaps_posts_query_args', function ( array $args, string $post_type ): array {
	if ( 'page' === $post_type ) {
		$args['post__not_in'] = array_merge( $args['post__not_in'] ?? [], isa_seo_unlisted_pages() );
	}
	return $args;
}, 10, 2 );

add_filter( 'wp_sitemaps_taxonomies', function ( array $taxonomies ): array {
	return array_intersect_key( $taxonomies, [ 'product_cat' => true ] );
} );

add_filter( 'wp_sitemaps_taxonomies_query_args', function ( array $args ): array {
	$args['exclude'] = [ (int) get_option( 'default_product_cat' ) ];
	return $args;
} );

/** Home page address in a language (/en/, /ar/): the bare domain only redirects. */
function isa_seo_home_for( string $language ): string {
	$slugs = get_option( 'trp_settings', [] )['url-slugs'] ?? [];
	$slug  = $slugs[ $language ] ?? '';
	return untrailingslashit( (string) get_option( 'home' ) ) . '/' . ( $slug ? $slug . '/' : '' );
}

// Last-modified date helps Google recrawl what changed; the home page is listed as /en/.
add_filter( 'wp_sitemaps_posts_entry', function ( array $entry, WP_Post $post ): array {
	$entry['lastmod'] = get_post_modified_time( 'c', true, $post );
	if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
		$entry['loc'] = isa_seo_home_for( get_option( 'trp_settings', [] )['default-language'] ?? 'en_US' );
	}
	return $entry;
}, 10, 2 );

/**
 * Every indexable address in the default language: home, pages, products, categories.
 * The Arabic sitemap below is built from it.
 */
function isa_seo_sitemap_urls(): array {
	$urls   = [ home_url( '/' ) ];
	$skip   = array_merge( isa_seo_unlisted_pages(), [ (int) get_option( 'page_on_front' ) ] );
	$posts  = get_posts( [
		'post_type'      => [ 'page', 'product' ],
		'post_status'    => 'publish',
		'posts_per_page' => 500,
		'post__not_in'   => $skip,
		'has_password'   => false,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'fields'         => 'ids',
	] );
	foreach ( $posts as $id ) {
		$urls[] = get_permalink( $id );
	}
	$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => [ (int) get_option( 'default_product_cat' ) ] ] );
	foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
		$urls[] = get_term_link( $term );
	}
	return array_values( array_unique( array_filter( $urls, 'is_string' ) ) );
}

// Arabic pages get their own sitemap (wp-sitemap-arabic-1.xml): core only lists the default language.
add_action( 'init', function () {
	if ( ! class_exists( 'TRP_Translate_Press' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
		return;
	}
	$provider = new class() extends WP_Sitemaps_Provider {
		public function __construct() {
			$this->name        = 'arabic';
			$this->object_type = 'arabic';
		}

		public function get_url_list( $page_num, $object_subtype = '' ) {
			$converter = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
			$list      = [ [ 'loc' => isa_seo_home_for( 'ar' ) ] ];
			foreach ( array_slice( isa_seo_sitemap_urls(), 1 ) as $url ) {
				$list[] = [ 'loc' => $converter->get_url_for_language( 'ar', $url, '' ) ];
			}
			return $list;
		}

		public function get_max_num_pages( $object_subtype = '' ) {
			return 1;
		}
	};
	wp_register_sitemap_provider( 'arabic', $provider );
} );

/* -------------------------------------------------------------------------
 * The "Search engines" box: products, pages, product categories
 * ---------------------------------------------------------------------- */

/** The box's fields; $values holds the saved meta keyed by meta key. */
function isa_seo_fields_html( array $values ): string {
	$rows = [
		[ ISA_SEO_TITLE, __( 'Title in Google', 'isa' ), 'ltr', 90 ],
		[ ISA_SEO_DESC, __( 'Description in Google', 'isa' ), 'ltr', 320 ],
		[ ISA_SEO_TITLE . '_ar', __( 'Title in Google (Arabic)', 'isa' ), 'rtl', 90 ],
		[ ISA_SEO_DESC . '_ar', __( 'Description in Google (Arabic)', 'isa' ), 'rtl', 320 ],
	];
	$out = '';
	foreach ( $rows as [ $key, $label, $dir, $max ] ) {
		$is_desc = str_starts_with( $key, ISA_SEO_DESC );
		$field   = $is_desc
			? sprintf( '<textarea id="%1$s" name="%1$s" rows="3" class="widefat" dir="%2$s" maxlength="%3$d">%4$s</textarea>', esc_attr( $key ), $dir, $max, esc_textarea( $values[ $key ] ?? '' ) )
			: sprintf( '<input type="text" id="%1$s" name="%1$s" class="widefat" dir="%2$s" maxlength="%3$d" value="%4$s">', esc_attr( $key ), $dir, $max, esc_attr( $values[ $key ] ?? '' ) );
		$out    .= sprintf( '<p><label for="%s"><strong>%s</strong></label><br>%s</p>', esc_attr( $key ), esc_html( $label ), $field );
	}
	return $out . '<p class="description">' . esc_html__( 'Title: about 50–60 characters, main words first, e.g. "Lip & Cheek Tint – 3 Shades | isa Egypt". Description: about 140–160 characters, what it is and why to click. Empty = built from the name and short description.', 'isa' ) . '</p>';
}

function isa_seo_keys(): array {
	return [ ISA_SEO_TITLE, ISA_SEO_DESC, ISA_SEO_TITLE . '_ar', ISA_SEO_DESC . '_ar' ];
}

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'isa-seo', __( 'Search engines (Google)', 'isa' ), function ( WP_Post $post ) {
		wp_nonce_field( 'isa_seo', 'isa_seo_nonce' );
		echo isa_seo_fields_html( array_combine( isa_seo_keys(), array_map( fn( $k ) => (string) get_post_meta( $post->ID, $k, true ), isa_seo_keys() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}, [ 'product', 'page' ], 'normal', 'low' );
} );

add_action( 'save_post', function ( int $post_id ) {
	if ( ! isset( $_POST['isa_seo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['isa_seo_nonce'] ), 'isa_seo' )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	isa_seo_save( 'post', $post_id );
} );

add_action( 'product_cat_edit_form_fields', function ( WP_Term $term ) {
	echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Search engines (Google)', 'isa' ) . '</th><td>';
	wp_nonce_field( 'isa_seo', 'isa_seo_nonce' );
	echo isa_seo_fields_html( array_combine( isa_seo_keys(), array_map( fn( $k ) => (string) get_term_meta( $term->term_id, $k, true ), isa_seo_keys() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</td></tr>';
}, 20 );

add_action( 'edited_product_cat', function ( int $term_id ) {
	if ( isset( $_POST['isa_seo_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['isa_seo_nonce'] ), 'isa_seo' ) && current_user_can( 'manage_product_terms' ) ) {
		isa_seo_save( 'term', $term_id );
	}
} );

function isa_seo_save( string $type, int $id ): void {
	foreach ( isa_seo_keys() as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- verified by the callers
		$raw   = wp_unslash( $_POST[ $key ] ?? '' );
		$value = str_starts_with( $key, ISA_SEO_DESC ) ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		if ( '' === $value ) {
			'term' === $type ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
		} else {
			'term' === $type ? update_term_meta( $id, $key, $value ) : update_post_meta( $id, $key, $value );
		}
	}
}
