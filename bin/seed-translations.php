<?php
/**
 * Writes bin/translations-ar.php into TranslatePress as human-reviewed translations.
 * Existing originals are updated; missing ones are inserted first. Re-runnable.
 *
 *   docker compose run --rm cli wp eval-file /bin-isa/seed-translations.php /bin-isa/translations-ar.php
 *
 * Translations someone already edited in the TranslatePress editor are NOT overwritten
 * unless --force is passed as the second argument.
 */

defined( 'ABSPATH' ) || exit;

$file  = $args[0] ?? '/bin-isa/translations-ar.php';
$force = in_array( '--force', $args, true );
$lang  = 'ar';

$pairs = require $file;
if ( ! is_array( $pairs ) ) {
	WP_CLI::error( "$file must return an array" );
}

$trp   = TRP_Translate_Press::get_trp_instance();
$query = $trp->get_component( 'query' );

$originals = array_keys( $pairs );
$existing  = $query->get_string_ids( $originals, $lang );
$missing   = array_values( array_diff( $originals, array_keys( (array) $existing ) ) );
if ( $missing ) {
	$query->insert_strings( $missing, $lang );
}

global $wpdb;
$table = $query->get_table_name( $lang );
$rows  = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, original, translated, status, block_type, original_id FROM `$table` WHERE original IN (" . implode( ',', array_fill( 0, count( $originals ), '%s' ) ) . ')',
	$originals
) );

$updates = [];
$kept    = 0;
foreach ( $rows as $row ) {
	$ar = $pairs[ $row->original ] ?? null;
	if ( null === $ar ) {
		continue;
	}
	// Someone translated this by hand in the editor and it differs from our file: keep theirs.
	if ( ! $force && (int) $row->status === 2 && '' !== (string) $row->translated && $row->translated !== $ar ) {
		$kept++;
		continue;
	}
	$updates[] = [
		'id'          => (int) $row->id,
		'original'    => $row->original,
		'translated'  => $ar,
		'status'      => 2, // human reviewed
		'block_type'  => (int) $row->block_type,
		'original_id' => (int) $row->original_id,
	];
}
$query->update_strings( $updates, $lang );

WP_CLI::success( sprintf( 'Arabic: %d written (%d new), %d hand-edited kept', count( $updates ), count( $missing ), $kept ) );
