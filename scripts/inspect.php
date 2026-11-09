<?php
/**
 * WP-CLI adapter: dump Blocksy Bridge info, schema and/or a settings export.
 *
 * Usage:
 *   BLOCKSY_INSPECT=info   wp eval-file scripts/inspect.php
 *   BLOCKSY_INSPECT=schema wp eval-file scripts/inspect.php > schema.json
 *   BLOCKSY_INSPECT=export BLOCKSY_INSPECT_PORTABLE=1 wp eval-file scripts/inspect.php > backup.json
 *   BLOCKSY_INSPECT=tokens wp eval-file scripts/inspect.php > tokens.json
 *   BLOCKSY_INSPECT=all    wp eval-file scripts/inspect.php
 */

require_once __DIR__ . '/../bridge/blocksy-bridge.php';

$bridge = Blocksy_Bridge::instance();

$blocksy_bridge_source = (string) file_get_contents( __DIR__ . '/../bridge/blocksy-bridge.php' );
if ( preg_match( "/const VERSION\s*=\s*'([^']+)'/", $blocksy_bridge_source, $blocksy_bridge_match ) && Blocksy_Bridge::VERSION !== $blocksy_bridge_match[1] ) {
	fwrite( STDERR, 'Warning: the site has Blocksy Bridge ' . Blocksy_Bridge::VERSION . ' installed as a plugin, which overrides the bridge ' . $blocksy_bridge_match[1] . " shipped with this skill. Update the plugin from dist/blocksy-bridge.zip.\n" );
}

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

	if ( 'tokens' === $single ) {
		// Not part of "all": tokens are written flat so other skills can read them directly.
		echo wp_json_encode( $bridge->tokens(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		exit( 0 );
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
