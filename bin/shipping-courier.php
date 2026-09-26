<?php
/**
 * Every flat-rate delivery method: cost 0, title "Home delivery". The customer pays
 * the courier on delivery; the theme shows the 80–150 EGP estimate. Safe to re-run.
 *
 *   docker compose run --rm cli wp eval-file /bin-isa/shipping-courier.php
 */

defined( 'ABSPATH' ) || exit;

$zones = array_merge( [ new WC_Shipping_Zone( 0 ) ], array_map( fn( $z ) => new WC_Shipping_Zone( $z['id'] ), WC_Shipping_Zones::get_zones() ) );
foreach ( $zones as $zone ) {
	foreach ( $zone->get_shipping_methods() as $method ) {
		if ( 'flat_rate' !== $method->id ) {
			continue;
		}
		$key                      = $method->get_instance_option_key();
		$settings                 = (array) get_option( $key, [] );
		$settings['title']        = 'Home delivery';
		$settings['cost']         = '0';
		$settings['tax_status']   = 'none';
		update_option( $key, $settings );
		WP_CLI::log( sprintf( '  %s: Home delivery, 0 EGP at checkout', $zone->get_zone_name() ) );
	}
}
WC_Cache_Helper::get_transient_version( 'shipping', true ); // drop cached rates
WP_CLI::success( 'Shipping: paid to the courier' );
