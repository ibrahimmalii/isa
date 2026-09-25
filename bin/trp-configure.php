<?php
/**
 * TranslatePress: English (default) + Arabic, both with a URL prefix (/en/…, /ar/…).
 * Mirrors what saving the TranslatePress settings screen does, so it's scriptable.
 *
 *   docker compose run --rm cli wp eval-file /bin-isa/trp-configure.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TRP_Translate_Press' ) ) {
	WP_CLI::error( 'TranslatePress is not active.' );
}

$settings = get_option( 'trp_settings', [] );
$settings = array_merge( $settings, [
	'default-language'                     => 'en_US',
	'translation-languages'                => [ 'en_US', 'ar' ],
	'publish-languages'                    => [ 'en_US', 'ar' ],
	'url-slugs'                            => [ 'en_US' => 'en', 'ar' => 'ar' ],
	'add-subdirectory-to-default-language' => 'yes',
	'force-language-to-custom-links'       => 'yes',
	'native_or_english_name'               => 'native_name',
	'trp-ls-floater'                       => 'no', // the theme renders its own switcher
	'trp-ls-show-poweredby'                => 'no',
] );
update_option( 'trp_settings', $settings );

$trp   = TRP_Translate_Press::get_trp_instance();
$query = $trp->get_component( 'query' );
foreach ( $settings['translation-languages'] as $lang ) {
	if ( $lang === $settings['default-language'] ) {
		continue;
	}
	$query->check_table( $settings['default-language'], $lang );
}
$gettext = $query->get_query_component( 'gettext_table_creation' );
foreach ( $settings['translation-languages'] as $lang ) {
	$gettext->check_gettext_table( $lang );
}
if ( method_exists( $query, 'check_original_table' ) ) {
	$query->check_original_table();
}
if ( method_exists( $query, 'check_original_meta_table' ) ) {
	$query->check_original_meta_table();
}


// TranslatePress 3.x keeps the floating switcher in its own option.
$ls = get_option( 'trp_language_switcher_settings', [] );
if ( is_array( $ls ) && isset( $ls['floater'] ) ) {
	$ls['floater']['enabled'] = false;
	update_option( 'trp_language_switcher_settings', $ls );
}

flush_rewrite_rules( false );
WP_CLI::success( 'TranslatePress: en (default, /en/) + ar (/ar/)' );
