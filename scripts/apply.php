<?php
/**
 * WP-CLI adapter: apply a Blocksy Bridge plan JSON.
 *
 * Usage:
 *   BLOCKSY_PLAN=examples/example-plan.json wp eval-file scripts/apply.php
 *   BLOCKSY_PLAN=examples/example-plan.json BLOCKSY_DRY_RUN=1 wp eval-file scripts/apply.php
 *
 * Or pass the plan path as the first extra argument:
 *   wp eval-file scripts/apply.php examples/example-plan.json
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

$plan_path = getenv( 'BLOCKSY_PLAN' );

if ( ! $plan_path && isset( $args[0] ) ) {
	$plan_path = $args[0];
}

if ( ! $plan_path ) {
	fwrite( STDERR, "No plan file supplied. Set BLOCKSY_PLAN or pass a path as the first argument.\n" );
	exit( 2 );
}

if ( ! is_readable( $plan_path ) ) {
	fwrite( STDERR, "Plan file is not readable: $plan_path\n" );
	exit( 2 );
}

$plan = json_decode( file_get_contents( $plan_path ), true );

if ( ! is_array( $plan ) ) {
	fwrite( STDERR, "Plan file is not a JSON object: $plan_path\n" );
	exit( 2 );
}

$dry_run = '1' === getenv( 'BLOCKSY_DRY_RUN' );

$report = $bridge->apply( $plan, $dry_run );

if ( is_wp_error( $report ) ) {
	fwrite( STDERR, $report->get_error_message() . "\n" );
	exit( 1 );
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

if ( empty( $report['ok'] ) ) {
	exit( 1 );
}
