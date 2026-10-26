<?php
/**
 * WP-CLI adapter: dump Blocksy Bridge info, schema and/or a settings export.
 *
 * Usage:
 *   BLOCKSY_INSPECT=info   wp eval-file scripts/inspect.php
 *   BLOCKSY_INSPECT=schema wp eval-file scripts/inspect.php > schema.json
 *   BLOCKSY_INSPECT=export BLOCKSY_INSPECT_PORTABLE=1 wp eval-file scripts/inspect.php > backup.json
 *   BLOCKSY_INSPECT=all    wp eval-file scripts/inspect.php
 */

require_once __DIR__ . '/../bridge/blocksy-bridge.php';

$bridge = Blocksy_Bridge::instance();

if ( ! $bridge->blocksy_is_active() ) {
	fwrite( STDERR, "Blocksy is not active on this site.\n" );
	exit( 1 );
}

if ( ! is_user_logged_in() ) {
	$admins = get_users(
		[
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		]
	);

	if ( ! empty( $admins ) ) {
		wp_set_current_user( (int) $admins[0] );
	}
}

$mode = getenv( 'BLOCKSY_INSPECT' );
if ( ! $mode ) {
	$mode = 'all';
}

$modes = array_filter( array_map( 'trim', explode( ',', $mode ) ) );
if ( empty( $modes ) ) {
	$modes = [ 'all' ];
}

$result = [];

foreach ( $modes as $single ) {
	if ( 'all' === $single || 'info' === $single ) {
		$result['info'] = $bridge->info();
	}

	if ( 'all' === $single || 'schema' === $single ) {
		$result['schema'] = $bridge->schema();
	}

	if ( 'all' === $single || 'export' === $single ) {
		$result['export'] = $bridge->export(
			[
				'portable'  => '1' === getenv( 'BLOCKSY_INSPECT_PORTABLE' ),
				'post_meta' => '1' === getenv( 'BLOCKSY_INSPECT_POST_META' ),
			]
		);
	}
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
