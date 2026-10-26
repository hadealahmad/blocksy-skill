<?php
/**
 * Plugin Name: Blocksy Bridge
 * Plugin URI:  https://github.com/your-org/blocksy-bridge
 * Description: A narrow, authenticated REST bridge for reading and applying Blocksy theme, builder, menu, and Content Block settings. Intended for the Blocksy skill.
 * Version:     0.1.0
 * Author:      Blocksy Skill
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Blocksy_Bridge' ) ) {

	class Blocksy_Bridge {

		const VERSION           = '0.1.0';
		const REST_NAMESPACE    = 'blocksy-bridge/v1';
		const MAX_PLAN_BYTES    = 2097152; // 2 MB
		const OPTION_LOG        = 'blocksy_bridge_last_apply';
		const META_KEY          = '_blocksy_bridge_key';

		private static $instance = null;
		private $schema_cache = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		private function __construct() {
			add_action( 'rest_api_init', [ $this, 'register_routes' ] );
			add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
			add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		}

		public static function activate() {
			if ( ! get_option( 'blocksy_bridge_token' ) ) {
				update_option( 'blocksy_bridge_token', wp_generate_password( 48, false, false ), false );
			}
		}

		public function register_admin_page() {
			add_options_page(
				'Blocksy Bridge',
				'Blocksy Bridge',
				'manage_options',
				'blocksy-bridge',
				[ $this, 'render_admin_page' ]
			);
		}

		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( isset( $_POST['blocksy_bridge_regenerate'] ) && check_admin_referer( 'blocksy_bridge_regenerate' ) ) {
				update_option( 'blocksy_bridge_token', wp_generate_password( 48, false, false ), false );
				echo '<div class="notice notice-success"><p>Bridge token regenerated. Update your client environment.</p></div>';
			}

			$token = get_option( 'blocksy_bridge_token' );
			if ( ! is_string( $token ) || '' === $token ) {
				$token = wp_generate_password( 48, false, false );
				update_option( 'blocksy_bridge_token', $token, false );
			}
			$rest = esc_url_raw( rest_url( self::REST_NAMESPACE ) );
			$health = esc_url_raw( rest_url( self::REST_NAMESPACE . '/health' ) );
			?>
			<div class="wrap">
				<h1>Blocksy Bridge</h1>
				<p>This plugin exposes Blocksy settings to an authenticated client. It does not expose arbitrary PHP, plugin activation, or user management.</p>

				<h2>Status</h2>
				<table class="widefat striped" style="max-width: 900px">
					<tbody>
						<tr><td>Blocksy active</td><td><?php echo $this->blocksy_is_active() ? 'Yes' : 'No'; ?></td></tr>
						<tr><td>Companion active</td><td><?php echo $this->companion_is_active() ? 'Yes' : 'No'; ?></td></tr>
						<tr><td>Plan</td><td><?php echo esc_html( function_exists( 'blocksy_companion_get_capabilities' ) ? blocksy_companion_get_capabilities()->get_plan() : 'n/a' ); ?></td></tr>
						<tr><td>REST base</td><td><code><?php echo esc_html( $rest ); ?></code></td></tr>
						<tr><td>Health</td><td><code><?php echo esc_html( $health ); ?></code></td></tr>
					</tbody>
				</table>

				<h2>Authentication</h2>
				<p>Preferred: a WordPress <strong>Application Password</strong> for an administrator (Users → Profile → Application Passwords). Send it with HTTP Basic auth over HTTPS.</p>
				<p>Fallback: the bridge token below. The client sends it as <code>X-Blocksy-Bridge-Token</code>. It also accepts <code>Authorization: Bearer &lt;token&gt;</code> and the <code>blocksy_bridge_token</code> query parameter. Use the query form only if a host strips custom headers; it can appear in access logs. Use HTTPS and rotate the token if it leaks.</p>

				<p>
					<label for="blocksy-bridge-token"><strong>Bridge token</strong></label><br>
					<input id="blocksy-bridge-token" type="text" readonly value="<?php echo esc_attr( $token ); ?>" style="width: 100%; max-width: 720px; font-family: monospace" onclick="this.select()">
				</p>

				<form method="post">
					<?php wp_nonce_field( 'blocksy_bridge_regenerate' ); ?>
					<p>
						<button class="button" name="blocksy_bridge_regenerate" value="1" onclick="return confirm('Regenerate the bridge token? Existing clients will stop working.');">Regenerate token</button>
					</p>
				</form>

				<h2>CLI client</h2>
				<pre>BLOCKSY_URL=<?php echo esc_html( home_url( '/' ) ); ?> \
BLOCKSY_TOKEN=&lt;token&gt; \
node scripts/remote.mjs info</pre>
			</div>
			<?php
		}

		/* ---------------------------------------------------------------------
		 * Availability
		 * ------------------------------------------------------------------ */

		public function blocksy_is_active() {
			return function_exists( 'blocksy_get_theme_mod' ) && function_exists( 'blocksy_manager' );
		}

		public function companion_is_active() {
			return class_exists( '\Blocksy\Plugin' ) && function_exists( 'blocksy_companion_get_version' );
		}

		public function can_manage() {
			return current_user_can( 'manage_options' );
		}

		public function maybe_render_notice() {
			if ( $this->blocksy_is_active() ) {
				return;
			}

			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Blocksy Bridge is active, but the Blocksy theme is not. Activate Blocksy (or its child theme) to use the bridge.', 'blocksy-bridge' );
			echo '</p></div>';
		}

		/* ---------------------------------------------------------------------
		 * REST
		 * ------------------------------------------------------------------ */

		public function register_routes() {
			register_rest_route(
				self::REST_NAMESPACE,
				'/health',
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'rest_health' ],
					'permission_callback' => '__return_true',
				]
			);

			$routes = [
				'/info'   => [ 'GET', 'rest_info' ],
				'/schema' => [ 'GET', 'rest_schema' ],
				'/export' => [ 'GET', 'rest_export' ],
				'/apply'  => [ 'POST', 'rest_apply' ],
				'/cache'  => [ 'POST', 'rest_cache' ],
			];

			foreach ( $routes as $path => $route ) {
				register_rest_route(
					self::REST_NAMESPACE,
					$path,
					[
						'methods'             => $route[0],
						'callback'            => [ $this, $route[1] ],
						'permission_callback' => [ $this, 'rest_permission' ],
					]
				);
			}
		}

		public function rest_permission( $request = null ) {
			if ( ! $this->blocksy_is_active() ) {
				return new WP_Error(
					'blocksy_bridge_no_blocksy',
					'Blocksy is not active on this site.',
					[ 'status' => 409 ]
				);
			}

			if ( $this->can_manage() ) {
				return true;
			}

			$token = $this->get_request_token( $request );
			$stored = get_option( 'blocksy_bridge_token' );

			if ( is_string( $token ) && '' !== $token && is_string( $stored ) && '' !== $stored && hash_equals( $stored, $token ) ) {
				$admins = get_users(
					[
						'role'   => 'administrator',
						'number' => 1,
						'fields' => 'ID',
					]
				);

				if ( ! empty( $admins ) ) {
					wp_set_current_user( (int) $admins[0] );

					return true;
				}
			}

			return new WP_Error(
				'blocksy_bridge_forbidden',
				'Authentication required. Use an administrator Application Password or the bridge token.',
				[ 'status' => 403 ]
			);
		}

		private function get_request_token( $request ) {
			if ( ! $request instanceof WP_REST_Request ) {
				return '';
			}

			$token = $request->get_header( 'x_blocksy_bridge_token' );
			if ( is_string( $token ) && '' !== $token ) {
				return $token;
			}

			$authorization = $request->get_header( 'authorization' );
			if ( is_string( $authorization ) && 0 === stripos( $authorization, 'Bearer ' ) ) {
				return trim( substr( $authorization, 7 ) );
			}

			$param = $request->get_param( 'blocksy_bridge_token' );
			if ( is_string( $param ) && '' !== $param ) {
				return $param;
			}

			return '';
		}

		public function rest_health() {
			return rest_ensure_response(
				[
					'ok'      => true,
					'bridge'  => true,
					'version' => self::VERSION,
				]
			);
		}

		public function rest_info() {
			return rest_ensure_response( $this->info() );
		}

		public function rest_schema() {
			return rest_ensure_response( $this->schema() );
		}

		public function rest_export( WP_REST_Request $request ) {
			$args = [
				'post_meta' => (bool) $request->get_param( 'post_meta' ),
				'portable'  => (bool) $request->get_param( 'portable' ),
			];

			return rest_ensure_response( $this->export( $args ) );
		}

		public function rest_apply( WP_REST_Request $request ) {
			$plan = $request->get_json_params();

			if ( ! is_array( $plan ) ) {
				return new WP_Error(
					'blocksy_bridge_invalid_json',
					'Send a JSON object as the request body.',
					[ 'status' => 400 ]
				);
			}

			$dry_run = (bool) $request->get_param( 'dry_run' );

			$report = $this->apply( $plan, $dry_run );

			if ( is_wp_error( $report ) ) {
				return $report;
			}

			return rest_ensure_response( $report );
		}

		public function rest_cache() {
			$result = $this->refresh_caches();

			return rest_ensure_response(
				[
					'ok'    => true,
					'cache' => $result,
				]
			);
		}

		/* ---------------------------------------------------------------------
		 * Info
		 * ------------------------------------------------------------------ */

		public function info() {
			$theme = wp_get_theme();
			$parent = $theme->parent();

			$result = [
				'bridge' => [
					'version'        => self::VERSION,
					'rest_namespace' => self::REST_NAMESPACE,
					'max_plan_bytes' => self::MAX_PLAN_BYTES,
				],
				'site'   => [
					'name'        => get_bloginfo( 'name' ),
					'url'         => home_url( '/' ),
					'home'        => home_url( '/' ),
					'siteurl'     => site_url( '/' ),
					'wp_version'  => get_bloginfo( 'version' ),
					'php_version' => PHP_VERSION,
					'locale'      => get_locale(),
					'is_multisite'=> is_multisite(),
				],
				'theme'  => [
					'stylesheet'    => get_stylesheet(),
					'template'      => get_template(),
					'name'          => $theme->get( 'Name' ),
					'version'       => $theme->get( 'Version' ),
					'is_child'      => (bool) $parent,
					'parent_name'   => $parent ? $parent->get( 'Name' ) : null,
					'parent_version'=> $parent ? $parent->get( 'Version' ) : null,
					'theme_mods_option' => 'theme_mods_' . get_stylesheet(),
				],
				'blocksy' => [
					'active'            => $this->blocksy_is_active(),
					'theme_version'     => $this->blocksy_theme_version(),
					'companion_active'  => $this->companion_is_active(),
					'companion_version' => function_exists( 'blocksy_companion_get_version' ) ? blocksy_companion_get_version() : null,
					'plan'              => function_exists( 'blocksy_companion_get_capabilities' ) ? blocksy_companion_get_capabilities()->get_plan() : null,
					'premium_code'      => function_exists( 'blocksy_companion_can_use_premium_code' ) ? (bool) blocksy_companion_can_use_premium_code() : false,
					'active_extensions' => $this->get_active_extensions(),
					'content_blocks_available' => post_type_exists( 'ct_content_block' ),
				],
				'css' => [
					'dynamic_css_file' => function_exists( 'blocksy_get_theme_mod' ) ? blocksy_get_theme_mod( 'dynamic_css_file', 'file' ) : null,
					'has_css_in_files' => function_exists( 'blocksy_has_css_in_files' ) ? (bool) blocksy_has_css_in_files() : null,
					'global_css_path'  => $this->global_css_path(),
					'global_css_exists'=> file_exists( $this->global_css_path() ),
				],
				'menus' => $this->menus_summary(),
			];

			return $result;
		}

		private function get_active_extensions() {
			$value = get_option( 'blocksy_active_extensions', [] );

			return is_array( $value ) ? array_values( $value ) : [];
		}

		private function blocksy_theme_version() {
			$theme = wp_get_theme();
			$parent = $theme->parent();

			if ( $parent ) {
				return $parent->get( 'Version' );
			}

			return $theme->get( 'Version' );
		}

		private function global_css_path() {
			$uploads = wp_upload_dir();

			return trailingslashit( $uploads['basedir'] ) . 'blocksy/css/global.css';
		}

		private function menus_summary() {
			$menus = [];

			foreach ( wp_get_nav_menus() as $menu ) {
				$menus[] = [
					'id'    => (int) $menu->term_id,
					'name'  => $menu->name,
					'slug'  => $menu->slug,
					'count' => (int) $menu->count,
				];
			}

			return [
				'locations' => get_registered_nav_menus(),
				'assigned'  => get_nav_menu_locations(),
				'menus'     => $menus,
			];
		}

		/* ---------------------------------------------------------------------
		 * Schema
		 * ------------------------------------------------------------------ */

		public function schema() {
			if ( null !== $this->schema_cache ) {
				return $this->schema_cache;
			}

			$this->ensure_customizer_helpers();

			$theme_options = $this->theme_options_schema();
			$builder = $this->builder_schema();
			$post_meta = $this->post_meta_schema();
			$content_blocks = $this->content_block_schema();
			$extensions = $this->extensions_schema();

			$this->schema_cache = [
				'bridge' => [
					'version'        => self::VERSION,
					'rest_namespace' => self::REST_NAMESPACE,
				],
				'site' => [
					'url'        => home_url( '/' ),
					'wp_version' => get_bloginfo( 'version' ),
					'locale'     => get_locale(),
				],
				'blocksy' => [
					'theme_version'      => $this->blocksy_theme_version(),
					'companion_version'  => function_exists( 'blocksy_companion_get_version' ) ? blocksy_companion_get_version() : null,
					'plan'               => function_exists( 'blocksy_companion_get_capabilities' ) ? blocksy_companion_get_capabilities()->get_plan() : null,
					'premium_code'       => function_exists( 'blocksy_companion_can_use_premium_code' ) ? (bool) blocksy_companion_can_use_premium_code() : false,
					'content_blocks_available' => post_type_exists( 'ct_content_block' ),
				],
				'theme_options'       => $theme_options,
				'builder'             => $builder,
				'post_meta_options'   => $post_meta,
				'content_block_options'=> $content_blocks,
				'extensions'          => $extensions,
				'special_theme_mods'  => $this->special_theme_mods(),
				'allowed_options'     => $this->allowed_options(),
				'menu_locations'      => get_registered_nav_menus(),
				'content_block_types' => $this->content_block_types(),
			];

			return $this->schema_cache;
		}

		private function ensure_customizer_helpers() {
			if ( ! function_exists( 'blocksy_sync_single_post_container' ) ) {
				/**
				 * The customizer-only sync helper is not loaded on REST requests.
				 * Some featured-image option files call it while defining defaults.
				 * A no-op is enough for schema extraction.
				 */
				function blocksy_sync_single_post_container( $args = [] ) {
					return [];
				}
			}
		}

		private function theme_options_schema() {
			if ( ! function_exists( 'blocksy_get_options' ) ) {
				return [];
			}

			$this->ensure_customizer_helpers();

			$options = blocksy_get_options( 'customizer' );

			if ( ! is_array( $options ) ) {
				return [];
			}

			$out = [];
			$this->walk_options( $options, [], $out );

			return $this->unique_options( $out );
		}

		private function builder_schema() {
			if ( ! function_exists( 'blocksy_manager' ) ) {
				return [];
			}

			$data = blocksy_manager()->builder->get_data_for_customizer();

			$result = [
				'header'           => [],
				'footer'           => [],
				'header_settings'  => [],
				'footer_settings'  => [],
				'default_header'   => blocksy_manager()->header_builder->get_default_value(),
				'default_footer'   => blocksy_manager()->footer_builder->get_default_value(),
			];

			foreach ( [ 'header', 'footer' ] as $type ) {
				foreach ( (array) ( $data[ $type ] ?? [] ) as $item ) {
					$out = [];
					$this->walk_options( $item['options'] ?? [], [], $out );

					$result[ $type ][] = [
						'id'         => $item['id'],
						'is_primary' => ! empty( $item['is_primary'] ),
						'devices'    => $item['config']['devices'] ?? null,
						'clone'      => $item['config']['clone'] ?? false,
						'allowed_in' => $item['config']['allowed_in'] ?? [],
						'options'    => $this->unique_options( $out ),
					];
				}
			}

			$header_settings_path = defined( 'BLOCKSY_PATH' )
				? BLOCKSY_PATH . 'framework/features/header/header-options.php'
				: WP_PLUGIN_DIR . '/blocksy-companion-pro/framework/features/header/header-options.php';

			$header_settings = $this->load_options_file( $header_settings_path );
			$out = [];
			$this->walk_options( $header_settings, [], $out );
			$result['header_settings'] = $this->unique_options( $out );

			$footer_settings = $this->load_options_file( get_template_directory() . '/inc/panel-builder/footer/options.php' );
			$out = [];
			$this->walk_options( $footer_settings, [], $out );
			$result['footer_settings'] = $this->unique_options( $out );

			return $result;
		}

		private function post_meta_schema() {
			$result = [];

			foreach ( [ 'post', 'page', 'blog' ] as $type ) {
				$options = function_exists( 'blocksy_get_options' )
					? blocksy_get_options( 'meta/' . $type )
					: [];

				$out = [];
				$this->walk_options( is_array( $options ) ? $options : [], [], $out );
				$result[ $type ] = $this->unique_options( $out );
			}

			return $result;
		}

		private function content_block_schema() {
			$result = [];
			$base = defined( 'BLOCKSY_PATH' )
				? BLOCKSY_PATH . 'framework/premium/features/content-blocks/options/'
				: WP_PLUGIN_DIR . '/blocksy-companion-pro/framework/premium/features/content-blocks/options/';

			global $post;
			$previous_post = $post;
			$post = (object) [ 'ID' => 0 ];

			foreach ( $this->content_block_types() as $type ) {
				$options = $this->load_options_file( $base . $type . '.php', [], false, 'blocksy_companion_get_options' );
				$out = [];
				$this->walk_options( is_array( $options ) ? $options : [], [], $out );
				$result[ $type ] = $this->unique_options( $out );
			}

			$post = $previous_post;

			return $result;
		}

		private function load_options_file( $path, $pass_inside = [], $relative = false, $function = 'blocksy_get_options' ) {
			if ( ! function_exists( $function ) ) {
				return [];
			}

			return call_user_func( $function, $path, $pass_inside, $relative );
		}

		private function content_block_types() {
			return [
				'header',
				'footer',
				'single',
				'archive',
				'404',
				'hook',
				'popup',
				'maintenance',
				'nothing_found',
			];
		}

		private function walk_options( $opts, $path, &$out ) {
			foreach ( (array) $opts as $id => $opt ) {
				if ( ! is_array( $opt ) ) {
					continue;
				}

				if ( is_int( $id ) ) {
					$this->walk_options( $opt, $path, $out );
					continue;
				}

				$is_container = isset( $opt['options'] ) || isset( $opt['inner-options'] );
				$is_panel_switch = isset( $opt['type'] ) && 'ct-panel' === $opt['type'] && ! empty( $opt['switch'] );
				$is_md5 = is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/', $id );

				if ( $is_container ) {
					if ( $is_panel_switch && ! $is_md5 ) {
						$out[] = [
							'id'         => $id,
							'type'       => $opt['type'],
							'label'      => isset( $opt['label'] ) && is_string( $opt['label'] ) ? $opt['label'] : null,
							'default'    => array_key_exists( 'value', $opt ) ? $opt['value'] : null,
							'responsive' => false,
							'path'       => implode( ' > ', $path ),
							'section'    => $path[0] ?? null,
							'switch'     => true,
						];
					}

					$child = $path;
					if ( ! $is_md5 ) {
						$child[] = $id;
					}

					if ( isset( $opt['options'] ) ) {
						$this->walk_options( $opt['options'], $child, $out );
					}
					if ( isset( $opt['inner-options'] ) ) {
						$this->walk_options( $opt['inner-options'], $child, $out );
					}
					continue;
				}

				if ( ! isset( $opt['type'] ) ) {
					continue;
				}

				if ( in_array( $opt['type'], [ 'ct-divider', 'ct-title', 'ct-group-title', 'ct-customize-section-title-actions' ], true ) ) {
					continue;
				}

				if ( $is_md5 ) {
					continue;
				}

				$item = [
					'id'         => $id,
					'type'       => $opt['type'],
					'label'      => isset( $opt['label'] ) && is_string( $opt['label'] ) ? $opt['label'] : null,
					'default'    => array_key_exists( 'value', $opt ) ? $opt['value'] : null,
					'responsive' => ! empty( $opt['responsive'] ),
					'path'       => implode( ' > ', $path ),
					'section'    => $path[0] ?? null,
				];

				if ( isset( $opt['choices'] ) && is_array( $opt['choices'] ) ) {
					$item['choices'] = $opt['choices'];
				}
				if ( isset( $opt['min'] ) ) {
					$item['min'] = $opt['min'];
				}
				if ( isset( $opt['max'] ) ) {
					$item['max'] = $opt['max'];
				}
				if ( isset( $opt['unit'] ) ) {
					$item['unit'] = $opt['unit'];
				}
				if ( isset( $opt['hidden'] ) || 'hidden' === $opt['type'] ) {
					$item['hidden'] = true;
				}

				$out[] = $item;
			}
		}

		private function unique_options( $options ) {
			$seen = [];
			$result = [];

			foreach ( $options as $option ) {
				if ( ! isset( $option['id'] ) || isset( $seen[ $option['id'] ] ) ) {
					continue;
				}

				$seen[ $option['id'] ] = true;
				$result[] = $option;
			}

			return $result;
		}

		private function extensions_schema() {
			if ( ! $this->companion_is_active() ) {
				return [];
			}

			$manager = \Blocksy\Plugin::instance()->extensions;

			if ( ! $manager ) {
				return [];
			}

			$extensions = $manager->get_extensions( [ 'require_config' => true ] );
			$result = [];
			$storage_map = $this->extension_storage_map();
			$requires_map = $this->extension_requires_map();

			foreach ( $extensions as $id => $extension ) {
				$config = $extension['config'] ?? [];
				$path = $extension['path'] ?? '';
				$is_pro = ( is_string( $path ) && false !== strpos( $path, '/premium/' ) ) || ! empty( $config['pro'] );
				$plans = [];

				if ( $is_pro && function_exists( 'blocksy_companion_exts_get_preliminary_config' ) ) {
					$preliminary = blocksy_companion_exts_get_preliminary_config( $id );
					if ( is_array( $preliminary ) && ! empty( $preliminary['plans'] ) ) {
						$plans = $preliminary['plans'];
					}
				}

				$result[] = [
					'id'          => $id,
					'name'        => $config['name'] ?? $id,
					'description' => $config['description'] ?? '',
					'tier'        => $is_pro ? 'pro' : 'free',
					'plans'       => $plans,
					'available'   => ! empty( $path ),
					'active'      => null !== $manager->get( $id ),
					'requires'    => $requires_map[ $id ] ?? [],
					'storage'     => $storage_map[ $id ] ?? [],
					'documentation' => $config['documentation'] ?? null,
				];
			}

			usort(
				$result,
				function ( $a, $b ) {
					return strcmp( $a['id'], $b['id'] );
				}
			);

			return $result;
		}

		private function extension_storage_map() {
			return [
				'cookies-consent'     => [ 'kind' => 'theme_mods', 'prefixes' => [ 'cookie_consent_', 'forms_cookie_consent_' ] ],
				'newsletter-subscribe'=> [ 'kind' => 'theme_mods', 'prefixes' => [ 'newsletter_subscribe_' ], 'options' => [ 'blocksy_ext_mailchimp_credentials' ] ],
				'product-reviews'     => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_product_reviews_settings' ], 'post_type' => 'blc-product-review', 'post_meta' => [ 'blocksy_product_review_options' ] ],
				'trending'            => [ 'kind' => 'theme_mods', 'prefixes' => [ 'trending_', 'trendingBlock' ] ],
				'code-snippets'       => [ 'kind' => 'theme_mods', 'keys' => [ 'header_scripts', 'header_after_body_scripts', 'footer_scripts' ] ],
				'color-mode-switch'   => [ 'kind' => 'mixed', 'theme_mods' => [ 'darkColorPalette' ], 'builder_item' => 'color-mode-switcher' ],
				'custom-fonts'        => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_custom_fonts_settings' ] ],
				'local-google-fonts'  => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_local_google_fonts_settings' ] ],
				'adobe-typekit'       => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_adobe_typekit_settings' ] ],
				'mega-menu'           => [ 'kind' => 'nav_menu_item_meta', 'post_meta' => [ 'blocksy_post_meta_options' ], 'options' => [ 'has_mega_menu', 'mega_menu_columns' ] ],
				'post-types-extra'    => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_post_types_extra_settings' ] ],
				'shortcuts'           => [ 'kind' => 'theme_mods', 'prefixes' => [ 'shortcuts_' ] ],
				'sidebars'            => [ 'kind' => 'option', 'options' => [ 'blocksy_dynamic_sidebars_storage' ] ],
				'white-label'         => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_white_label_settings' ] ],
				'woocommerce-extra'   => [ 'kind' => 'option', 'options' => [ 'blocksy_ext_woocommerce_extra_settings' ], 'requires' => 'woocommerce' ],
			];
		}

		private function extension_requires_map() {
			return [
				'woocommerce-extra' => [ 'woocommerce' ],
			];
		}

		private function special_theme_mods() {
			return [
				'header_placements',
				'footer_placements',
				'blocksy_premium_header_conditions',
				'blocksy_premium_footer_conditions',
				'darkColorPalette',
				'custom_logo',
				'nav_menu_locations',
			];
		}

		private function allowed_options() {
			return [
				'blocksy_custom_palettes',
				'blocksy_dynamic_sidebars_storage',
				'blocksy_ext_custom_fonts_settings',
				'blocksy_ext_local_google_fonts_settings',
				'blocksy_ext_adobe_typekit_settings',
				'blocksy_ext_white_label_settings',
				'blocksy_ext_post_types_extra_settings',
				'blocksy_ext_woocommerce_extra_settings',
				'blocksy_ext_product_reviews_settings',
				'blocksy_ext_mailchimp_credentials',
				'blocksy_ext_demos_current_demo',
			];
		}

		/* ---------------------------------------------------------------------
		 * Export
		 * ------------------------------------------------------------------ */

		public function export( $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'post_meta' => false,
					'portable'  => false,
				]
			);

			$theme_mods = get_theme_mods();

			if ( ! is_array( $theme_mods ) ) {
				$theme_mods = [];
			}

			$theme_mods = array_filter(
				$theme_mods,
				function ( $key ) {
					if ( is_int( $key ) || preg_match( '/^\d+$/', (string) $key ) ) {
						return false;
					}

					if ( in_array( $key, [ 'custom_css_post_id', 'sidebars_widgets' ], true ) ) {
						return false;
					}

					return true;
				},
				ARRAY_FILTER_USE_KEY
			);

			$options = [];
			foreach ( $this->allowed_options() as $key ) {
				$value = get_option( $key, '__missing__' );
				if ( '__missing__' !== $value ) {
					$options[ $key ] = $value;
				}
			}

			$options['blocksy_active_extensions'] = $this->get_active_extensions();

			$menus = $this->export_menus();
			$content_blocks = $this->export_content_blocks();

			if ( $args['portable'] ) {
				unset(
					$theme_mods['custom_css_post_id'],
					$theme_mods['nav_menu_locations'],
					$theme_mods['sidebars_widgets']
				);

				foreach ( $content_blocks as $index => $block ) {
					unset( $content_blocks[ $index ]['id'] );
				}

				foreach ( $menus as $menu_index => $menu ) {
					foreach ( $menu['items'] as $item_index => $item ) {
						unset( $menus[ $menu_index ]['items'][ $item_index ]['id'] );
					}
				}
			}

			$result = [
				'version'      => 1,
				'exported_at'  => gmdate( 'c' ),
				'portable'     => (bool) $args['portable'],
				'site'         => $this->info(),
				'theme_mods'   => $theme_mods,
				'options'      => $options,
				'menus'        => $menus,
				'content_blocks' => $content_blocks,
			];

			if ( $args['post_meta'] ) {
				$result['post_meta'] = $this->export_post_meta();
			}

			return $result;
		}

		private function export_menus() {
			$locations = get_nav_menu_locations();
			$result = [];

			foreach ( wp_get_nav_menus() as $menu ) {
				$menu_items = wp_get_nav_menu_items( $menu->term_id );
				$menu_items = is_array( $menu_items ) ? $menu_items : [];

				$id_to_key = [];
				foreach ( $menu_items as $item ) {
					$key = get_post_meta( $item->ID, self::META_KEY, true );
					if ( '' === $key || ! is_string( $key ) ) {
						$key = 'item-' . $item->ID;
					}
					$id_to_key[ (int) $item->ID ] = $key;
				}

				$items = [];

				foreach ( $menu_items as $item ) {
					$key = $id_to_key[ (int) $item->ID ];
					$parent_key = '';
					if ( ! empty( $item->menu_item_parent ) && isset( $id_to_key[ (int) $item->menu_item_parent ] ) ) {
						$parent_key = $id_to_key[ (int) $item->menu_item_parent ];
					}

					$items[] = [
						'key'       => $key,
						'title'     => $item->title,
						'type'      => $item->type,
						'object'    => $item->object,
						'object_id' => (int) $item->object_id,
						'url'       => $item->url,
						'parent'    => $parent_key,
						'position'  => (int) $item->menu_order,
						'classes'   => $item->classes,
						'target'    => $item->target,
						'description' => $item->description,
						'meta'      => get_post_meta( $item->ID, 'blocksy_post_meta_options', true ),
					];
				}

				$menu_locations = [];
				foreach ( $locations as $location => $menu_id ) {
					if ( (int) $menu_id === (int) $menu->term_id ) {
						$menu_locations[] = $location;
					}
				}

				$result[] = [
					'name'      => $menu->name,
					'slug'      => $menu->slug,
					'locations' => $menu_locations,
					'items'     => $items,
				];
			}

			return $result;
		}

		private function export_content_blocks() {
			$posts = get_posts(
				[
					'post_type'      => 'ct_content_block',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				]
			);

			$result = [];

			foreach ( $posts as $post ) {
				$key = get_post_meta( $post->ID, self::META_KEY, true );
				if ( '' === $key || ! is_string( $key ) ) {
					$key = 'content-block-' . $post->ID;
				}

				$result[] = [
					'id'            => (int) $post->ID,
					'key'           => $key,
					'title'         => $post->post_title,
					'status'        => $post->post_status,
					'template_type' => get_post_meta( $post->ID, 'template_type', true ),
					'content'       => $post->post_content,
					'meta'          => get_post_meta( $post->ID, 'blocksy_post_meta_options', true ),
				];
			}

			return $result;
		}

		private function export_post_meta() {
			$posts = get_posts(
				[
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'meta_key'       => 'blocksy_post_meta_options',
				]
			);

			$result = [];

			foreach ( $posts as $post_id ) {
				$result[] = [
					'id'        => (int) $post_id,
					'post_type' => get_post_type( $post_id ),
					'slug'      => get_post_field( 'post_name', $post_id ),
					'title'     => get_the_title( $post_id ),
					'meta'      => get_post_meta( $post_id, 'blocksy_post_meta_options', true ),
				];
			}

			return $result;
		}

		/* ---------------------------------------------------------------------
		 * Apply
		 * ------------------------------------------------------------------ */

		public function apply( $plan, $dry_run = false ) {
			if ( ! $this->blocksy_is_active() ) {
				return new WP_Error(
					'blocksy_bridge_no_blocksy',
					'Blocksy is not active.',
					[ 'status' => 409 ]
				);
			}

			$encoded = wp_json_encode( $plan );
			if ( false === $encoded || strlen( $encoded ) > self::MAX_PLAN_BYTES ) {
				return new WP_Error(
					'blocksy_bridge_plan_too_large',
					'The plan is too large or not JSON-serializable.',
					[ 'status' => 413 ]
				);
			}

			if ( isset( $plan['version'] ) && 1 !== (int) $plan['version'] ) {
				return new WP_Error(
					'blocksy_bridge_plan_version',
					'Unsupported plan version. Expected version 1.',
					[ 'status' => 400 ]
				);
			}

			$report = [
				'dry_run'  => (bool) $dry_run,
				'ok'       => true,
				'actions'  => [],
				'warnings' => [],
				'errors'   => [],
				'cache'    => [],
			];

			$snapshot = null;
			if ( ! empty( $plan['snapshot'] ) ) {
				$snapshot = $this->export( [ 'post_meta' => true ] );
			}

			$this->apply_extensions( $plan['extensions'] ?? [], $dry_run, $report );
			$this->apply_theme_mods( $plan['theme_mods'] ?? [], $plan['remove_theme_mods'] ?? [], $dry_run, $report );
			$this->apply_options( $plan['options'] ?? [], $plan['remove_options'] ?? [], $dry_run, $report );
			$this->apply_menus( $plan['menus'] ?? [], $dry_run, $report );
			$this->apply_content_blocks( $plan['content_blocks'] ?? [], $dry_run, $report );
			$this->apply_post_meta( $plan['post_meta'] ?? [], $dry_run, $report );
			$this->remove_post_meta( $plan['remove_post_meta'] ?? [], $dry_run, $report );

			$report['ok'] = empty( $report['errors'] );

			if ( ! $dry_run && $report['ok'] && ( ! isset( $plan['cache'] ) || $plan['cache'] ) ) {
				$report['cache'] = $this->refresh_caches();
			}

			if ( ! $dry_run ) {
				update_option(
					self::OPTION_LOG,
					[
						'time'      => gmdate( 'c' ),
						'user_id'   => get_current_user_id(),
						'dry_run'   => false,
						'ok'        => $report['ok'],
						'actions'   => count( $report['actions'] ),
						'warnings'  => count( $report['warnings'] ),
						'errors'    => count( $report['errors'] ),
					],
					false
				);
			}

			if ( $snapshot ) {
				$report['snapshot'] = $snapshot;
			}

			return $report;
		}

		private function apply_extensions( $extensions, $dry_run, &$report ) {
			if ( empty( $extensions ) ) {
				return;
			}

			$activate = $extensions['activate'] ?? [];
			$deactivate = $extensions['deactivate'] ?? [];

			$manager = $this->companion_is_active() ? \Blocksy\Plugin::instance()->extensions : null;
			$available = $manager ? $manager->get_extensions( [ 'require_config' => true ] ) : [];
			$current_plan = function_exists( 'blocksy_companion_get_capabilities' )
				? blocksy_companion_get_capabilities()->get_plan()
				: null;
			$premium_code = function_exists( 'blocksy_companion_can_use_premium_code' )
				? (bool) blocksy_companion_can_use_premium_code()
				: false;

			foreach ( (array) $activate as $id ) {
				if ( ! $manager ) {
					$report['errors'][] = "Extension '$id' cannot be activated: Blocksy Companion is not active.";
					continue;
				}

				if ( ! isset( $available[ $id ] ) ) {
					$report['errors'][] = "Unknown extension '$id'.";
					continue;
				}

				if ( empty( $available[ $id ]['path'] ) ) {
					$report['errors'][] = "Extension '$id' is not available (it may require Blocksy Companion Pro or a higher plan).";
					continue;
				}

				$is_pro = false !== strpos( (string) $available[ $id ]['path'], '/premium/' );
				if ( $is_pro && ! $premium_code ) {
					$report['errors'][] = "Extension '$id' requires a paid Blocksy Companion licence.";
					continue;
				}

				if ( $is_pro && function_exists( 'blocksy_companion_exts_get_preliminary_config' ) ) {
					$preliminary = blocksy_companion_exts_get_preliminary_config( $id );
					if ( ! empty( $preliminary['plans'] ) && ! in_array( $current_plan, $preliminary['plans'], true ) ) {
						$report['errors'][] = "Extension '$id' requires one of these plans: " . implode( ', ', $preliminary['plans'] ) . '.';
						continue;
					}
				}

				$report['actions'][] = [
					'type' => 'extension',
					'op'   => 'activate',
					'id'   => $id,
				];

				if ( ! $dry_run ) {
					$manager->activate_extension( $id );
					$this->schema_cache = null;
				}
			}

			foreach ( (array) $deactivate as $id ) {
				if ( ! $manager ) {
					$report['errors'][] = "Extension '$id' cannot be deactivated: Blocksy Companion is not active.";
					continue;
				}

				$report['actions'][] = [
					'type' => 'extension',
					'op'   => 'deactivate',
					'id'   => $id,
				];

				if ( ! $dry_run ) {
					$manager->deactivate_extension( $id );
					$this->schema_cache = null;
				}
			}
		}

		private function apply_active_extensions( $ids, $dry_run, &$report ) {
			if ( ! is_array( $ids ) ) {
				$report['errors'][] = 'blocksy_active_extensions must be an array.';
				return;
			}

			$current = $this->get_active_extensions();
			$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );

			$to_activate = array_diff( $ids, $current );
			$to_deactivate = array_diff( $current, $ids );

			$this->apply_extensions(
				[
					'activate'   => array_values( $to_activate ),
					'deactivate' => array_values( $to_deactivate ),
				],
				$dry_run,
				$report
			);
		}

		private function apply_theme_mods( $mods, $remove, $dry_run, &$report ) {
			if ( ! is_array( $mods ) ) {
				$report['errors'][] = 'theme_mods must be an object.';
				return;
			}

			$allowed = $this->allowed_theme_mod_keys();

			foreach ( $mods as $key => $value ) {
				if ( ! is_string( $key ) || '' === $key ) {
					$report['errors'][] = 'Theme mod keys must be non-empty strings.';
					continue;
				}

				if ( ! $this->is_allowed_theme_mod( $key, $allowed ) ) {
					$report['errors'][] = "Theme mod '$key' is not in the Blocksy allowlist.";
					continue;
				}

				if ( ! $this->is_json_safe( $value ) ) {
					$report['errors'][] = "Theme mod '$key' is not JSON-safe.";
					continue;
				}

				if ( in_array( $key, [ 'header_placements', 'footer_placements' ], true ) ) {
					$builder_error = $this->validate_builder_value( $key, $value );
					if ( $builder_error ) {
						$report['errors'][] = "Theme mod '$key': $builder_error";
						continue;
					}

					if ( function_exists( 'blocksy_sanitize_builder_value' ) ) {
						$value = blocksy_sanitize_builder_value( [], $value );
					}
				}

				$before = get_theme_mod( $key, '__missing__' );
				$after = $value;

				if ( $this->values_equal( $before, $after ) ) {
					continue;
				}

				$report['actions'][] = [
					'type'   => 'theme_mod',
					'op'     => 'set',
					'key'    => $key,
					'before' => '__missing__' === $before ? null : $before,
					'after'  => $after,
				];

				if ( ! $dry_run ) {
					set_theme_mod( $key, $after );
				}
			}

			foreach ( (array) $remove as $key ) {
				if ( ! is_string( $key ) || ! $this->is_allowed_theme_mod( $key, $allowed ) ) {
					$report['errors'][] = "Theme mod '$key' is not in the Blocksy allowlist.";
					continue;
				}

				$before = get_theme_mod( $key, '__missing__' );
				if ( '__missing__' === $before ) {
					continue;
				}

				$report['actions'][] = [
					'type'   => 'theme_mod',
					'op'     => 'remove',
					'key'    => $key,
					'before' => $before,
				];

				if ( ! $dry_run ) {
					remove_theme_mod( $key );
				}
			}
		}

		private function apply_options( $options, $remove, $dry_run, &$report ) {
			if ( ! is_array( $options ) ) {
				$report['errors'][] = 'options must be an object.';
				return;
			}

			$allowed = $this->allowed_options();

			foreach ( $options as $key => $value ) {
				if ( 'blocksy_active_extensions' === $key ) {
					$this->apply_active_extensions( $value, $dry_run, $report );
					continue;
				}

				if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
					$report['errors'][] = "Option '$key' is not in the Blocksy Bridge allowlist.";
					continue;
				}

				if ( ! $this->is_json_safe( $value ) ) {
					$report['errors'][] = "Option '$key' is not JSON-safe.";
					continue;
				}

				$before = get_option( $key, '__missing__' );

				if ( $this->values_equal( $before, $value ) ) {
					continue;
				}

				$report['actions'][] = [
					'type'   => 'option',
					'op'     => 'set',
					'key'    => $key,
					'before' => '__missing__' === $before ? null : $before,
					'after'  => $value,
				];

				if ( ! $dry_run ) {
					update_option( $key, $value );
				}
			}

			foreach ( (array) $remove as $key ) {
				if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
					$report['errors'][] = "Option '$key' is not in the Blocksy Bridge allowlist.";
					continue;
				}

				$before = get_option( $key, '__missing__' );
				if ( '__missing__' === $before ) {
					continue;
				}

				$report['actions'][] = [
					'type'   => 'option',
					'op'     => 'remove',
					'key'    => $key,
					'before' => $before,
				];

				if ( ! $dry_run ) {
					delete_option( $key );
				}
			}
		}

		private function apply_menus( $menus, $dry_run, &$report ) {
			if ( empty( $menus ) ) {
				return;
			}

			if ( ! is_array( $menus ) ) {
				$report['errors'][] = 'menus must be an array.';
				return;
			}

			$locations = get_registered_nav_menus();

			foreach ( $menus as $menu_plan ) {
				if ( ! is_array( $menu_plan ) || empty( $menu_plan['name'] ) ) {
					$report['errors'][] = 'Each menu needs a name.';
					continue;
				}

				$name = (string) $menu_plan['name'];
				$menu = wp_get_nav_menu_object( $name );

				if ( ! empty( $menu_plan['delete'] ) ) {
					if ( ! $menu ) {
						$report['warnings'][] = "Menu '$name' was not found for deletion.";
						continue;
					}

					$report['actions'][] = [
						'type' => 'menu',
						'op'   => 'delete',
						'name' => $name,
					];

					if ( ! $dry_run ) {
						wp_delete_nav_menu( $menu->term_id );
					}
					continue;
				}

				$report['actions'][] = [
					'type' => 'menu',
					'op'   => $menu ? 'update' : 'create',
					'name' => $name,
				];

				if ( $dry_run ) {
					continue;
				}

				if ( ! $menu ) {
					$menu_id = wp_create_nav_menu( $name );
					if ( is_wp_error( $menu_id ) ) {
						$report['errors'][] = "Could not create menu '$name': " . $menu_id->get_error_message();
						continue;
					}
				} else {
					$menu_id = (int) $menu->term_id;
				}

				$replace = ! isset( $menu_plan['replace'] ) || (bool) $menu_plan['replace'];

				if ( $replace ) {
					$existing_items = wp_get_nav_menu_items( $menu_id );
					foreach ( (array) $existing_items as $existing_item ) {
						wp_delete_post( $existing_item->ID, true );
					}
				}

				$items = $menu_plan['items'] ?? [];
				$created = $this->create_menu_items( $menu_id, $items, $report );

				if ( ! empty( $menu_plan['location'] ) || ! empty( $menu_plan['locations'] ) ) {
					$requested_locations = ! empty( $menu_plan['locations'] )
						? (array) $menu_plan['locations']
						: [ (string) $menu_plan['location'] ];

					foreach ( $requested_locations as $location ) {
						$location = (string) $location;

						if ( ! isset( $locations[ $location ] ) ) {
							$report['warnings'][] = "Menu location '$location' is not registered on this site.";
							continue;
						}

						$assigned = get_theme_mod( 'nav_menu_locations', [] );
						if ( ! is_array( $assigned ) ) {
							$assigned = [];
						}
						$assigned[ $location ] = $menu_id;
						set_theme_mod( 'nav_menu_locations', $assigned );

						$report['actions'][] = [
							'type'     => 'menu_location',
							'op'       => 'assign',
							'location' => $location,
							'menu'     => $name,
						];
					}
				}

				if ( empty( $created ) && ! empty( $items ) ) {
					$report['warnings'][] = "Menu '$name' was created but no items were added.";
				}
			}
		}

		private function create_menu_items( $menu_id, $items, &$report ) {
			if ( ! is_array( $items ) ) {
				$report['errors'][] = 'Menu items must be an array.';
				return [];
			}

			$by_key = [];
			$tree = [];

			foreach ( $items as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$key = isset( $item['key'] ) ? (string) $item['key'] : 'item-' . $index;
				$parent = isset( $item['parent'] ) ? (string) $item['parent'] : '';

				$item['__key'] = $key;
				$item['__parent'] = $parent;
				$by_key[ $key ] = $item;

				if ( '' !== $parent ) {
					$tree[ $parent ][] = $key;
				} else {
					$tree[''][] = $key;
				}
			}

			$created = [];

			$create = function ( $key, $parent_id = 0, $position = 0 ) use ( &$create, &$by_key, &$tree, &$report, &$created, $menu_id ) {
				if ( ! isset( $by_key[ $key ] ) ) {
					return;
				}

				$item = $by_key[ $key ];
				$item_type = isset( $item['type'] ) ? (string) $item['type'] : 'custom';

				if ( 'post_type' === $item_type && empty( $item['page_id'] ) && empty( $item['object_id'] ) && empty( $item['slug'] ) ) {
					$report['errors'][] = "Menu item '{$item['__key']}' is post_type but has no page_id, object_id, or slug.";
					return;
				}

				if ( 'taxonomy' === $item_type && empty( $item['term_id'] ) ) {
					$report['errors'][] = "Menu item '{$item['__key']}' is taxonomy but has no term_id.";
					return;
				}

				$args = $this->menu_item_args( $item, $parent_id, $position );

				$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );

				if ( is_wp_error( $item_id ) ) {
					$report['errors'][] = "Could not create menu item '{$item['__key']}': " . $item_id->get_error_message();
					return;
				}

				update_post_meta( $item_id, self::META_KEY, $item['__key'] );

				if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
					update_post_meta( $item_id, 'blocksy_post_meta_options', $item['meta'] );
				}

				$created[ $item['__key'] ] = $item_id;

				$position = 0;
				foreach ( ( $tree[ $key ] ?? [] ) as $child_key ) {
					$create( $child_key, $item_id, $position );
					$position++;
				}
			};

			$position = 0;
			foreach ( ( $tree[''] ?? [] ) as $key ) {
				$create( $key, 0, $position );
				$position++;
			}

			return $created;
		}

		private function menu_item_args( $item, $parent_id, $position ) {
			$args = [
				'menu-item-title'       => isset( $item['title'] ) ? (string) $item['title'] : '',
				'menu-item-parent-id'   => (int) $parent_id,
				'menu-item-position'    => (int) $position + 1,
				'menu-item-status'      => isset( $item['status'] ) ? (string) $item['status'] : 'publish',
				'menu-item-description' => isset( $item['description'] ) ? (string) $item['description'] : '',
				'menu-item-target'      => isset( $item['target'] ) ? (string) $item['target'] : '',
				'menu-item-classes'     => isset( $item['classes'] ) ? ( is_array( $item['classes'] ) ? implode( ' ', $item['classes'] ) : (string) $item['classes'] ) : '',
				'menu-item-attr-title'  => isset( $item['attr_title'] ) ? (string) $item['attr_title'] : '',
			];

			$type = isset( $item['type'] ) ? (string) $item['type'] : '';

			if ( 'post_type' === $type ) {
				$post_id = 0;
				if ( ! empty( $item['page_id'] ) ) {
					$post_id = (int) $item['page_id'];
				} elseif ( ! empty( $item['object_id'] ) ) {
					$post_id = (int) $item['object_id'];
				} elseif ( ! empty( $item['slug'] ) ) {
					$post = get_page_by_path( (string) $item['slug'] );
					if ( $post ) {
						$post_id = (int) $post->ID;
					}
				}

				$post_type = $post_id ? get_post_type( $post_id ) : ( $item['object'] ?? 'page' );

				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = $post_type;
				$args['menu-item-object-id'] = $post_id;

				return $args;
			}

			if ( 'taxonomy' === $type ) {
				$args['menu-item-type']      = 'taxonomy';
				$args['menu-item-object']    = isset( $item['taxonomy'] ) ? (string) $item['taxonomy'] : 'category';
				$args['menu-item-object-id'] = isset( $item['term_id'] ) ? (int) $item['term_id'] : 0;

				return $args;
			}

			if ( 'custom' === $type || empty( $type ) ) {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = isset( $item['url'] ) ? (string) $item['url'] : '#';

				return $args;
			}

			$args['menu-item-type'] = $type;

			return $args;
		}

		private function apply_content_blocks( $blocks, $dry_run, &$report ) {
			if ( empty( $blocks ) ) {
				return;
			}

			if ( ! post_type_exists( 'ct_content_block' ) ) {
				$report['errors'][] = 'Content Blocks are not available on this site. They require Blocksy Companion Pro (and the Pro licence).';
				return;
			}

			if ( ! is_array( $blocks ) ) {
				$report['errors'][] = 'content_blocks must be an array.';
				return;
			}

			$types = $this->content_block_types();
			$schemas = $this->content_block_schema();

			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$key = isset( $block['key'] ) ? (string) $block['key'] : '';
				$id = isset( $block['id'] ) ? (int) $block['id'] : 0;

				if ( empty( $block['delete'] ) && '' === $key && ! $id ) {
					$report['errors'][] = 'Each content block needs a stable key (or an explicit id).';
					continue;
				}

				if ( ! empty( $block['content'] ) && ! current_user_can( 'unfiltered_html' ) ) {
					$report['warnings'][] = "Content block '$key' may be sanitized because the current user lacks unfiltered_html.";
				}

				if ( ! empty( $block['delete'] ) ) {
					$post_id = $this->find_content_block( $key, $id );
					if ( ! $post_id ) {
						$report['warnings'][] = "Content block '$key' was not found for deletion.";
						continue;
					}

					$report['actions'][] = [
						'type' => 'content_block',
						'op'   => 'delete',
						'key'  => $key,
						'id'   => $post_id,
					];

					if ( ! $dry_run ) {
						wp_delete_post( $post_id, true );
					}
					continue;
				}

				$template_type = isset( $block['template_type'] ) ? (string) $block['template_type'] : '';
				if ( ! in_array( $template_type, $types, true ) ) {
					$report['errors'][] = "Content block '$key' has an invalid template_type '$template_type'.";
					continue;
				}

				$meta = isset( $block['meta'] ) && is_array( $block['meta'] ) ? $block['meta'] : [];
				$allowed_meta = [];

				foreach ( ( $schemas[ $template_type ] ?? [] ) as $option ) {
					$allowed_meta[ $option['id'] ] = true;
				}

				foreach ( $meta as $meta_key => $meta_value ) {
					if ( ! is_string( $meta_key ) || ! isset( $allowed_meta[ $meta_key ] ) ) {
						$report['errors'][] = "Content block '$key' has unknown meta key '$meta_key' for type '$template_type'.";
					}
				}

				if ( isset( $meta['conditions'] ) ) {
					$condition_error = $this->validate_conditions( $meta['conditions'] );
					if ( $condition_error ) {
						$report['errors'][] = "Content block '$key': $condition_error";
					} else {
						$unknown_rules = $this->unknown_condition_rules( $meta['conditions'] );
						foreach ( $unknown_rules as $unknown_rule ) {
							$report['warnings'][] = "Content block '$key' uses condition rule '$unknown_rule' which is not registered on this site.";
						}
					}
				} else {
					$report['warnings'][] = "Content block '$key' has no conditions; it will not display.";
				}

				$post_id = $this->find_content_block( $key, $id );
				$op = $post_id ? 'update' : 'create';

				$report['actions'][] = [
					'type' => 'content_block',
					'op'   => $op,
					'key'  => $key,
					'id'   => $post_id ?: null,
					'name' => $block['title'] ?? null,
				];

				if ( $dry_run ) {
					continue;
				}

				$postarr = [
					'post_type'    => 'ct_content_block',
					'post_status'  => isset( $block['status'] ) ? (string) $block['status'] : 'publish',
					'post_title'   => isset( $block['title'] ) ? (string) $block['title'] : $key,
					'post_content' => isset( $block['content'] ) ? (string) $block['content'] : '',
				];

				if ( $post_id ) {
					$postarr['ID'] = $post_id;
					$result = wp_update_post( $postarr, true );
				} else {
					$result = wp_insert_post( $postarr, true );
				}

				if ( is_wp_error( $result ) ) {
					$report['errors'][] = "Could not save content block '$key': " . $result->get_error_message();
					continue;
				}

				$post_id = (int) $result;

				update_post_meta( $post_id, 'template_type', $template_type );

				$existing_meta = get_post_meta( $post_id, 'blocksy_post_meta_options', true );
				if ( ! is_array( $existing_meta ) ) {
					$existing_meta = [];
				}

				$merged = array_merge( $existing_meta, $meta );
				unset( $merged['styles_descriptor'] );

				if ( function_exists( 'blocksy_sanitize_post_meta_options' ) ) {
					$merged = blocksy_sanitize_post_meta_options( $merged );
				}

				update_post_meta( $post_id, 'blocksy_post_meta_options', $merged );

				if ( '' !== $key ) {
					update_post_meta( $post_id, self::META_KEY, $key );
				}
			}
		}

		private function find_content_block( $key, $id = 0 ) {
			if ( $id ) {
				$post = get_post( $id );
				if ( $post && 'ct_content_block' === $post->post_type ) {
					return (int) $post->ID;
				}
			}

			if ( '' === $key ) {
				return 0;
			}

			$query = new WP_Query(
				[
					'post_type'      => 'ct_content_block',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::META_KEY,
					'meta_value'     => $key,
				]
			);

			return $query->posts ? (int) $query->posts[0] : 0;
		}

		private function unknown_condition_rules( $conditions, &$unknown = [] ) {
			if ( ! is_array( $conditions ) ) {
				return $unknown;
			}

			$known = $this->known_condition_rules();

			foreach ( $conditions as $condition ) {
				if ( ! is_array( $condition ) ) {
					continue;
				}

				if ( isset( $condition['conditions'] ) && is_array( $condition['conditions'] ) ) {
					$this->unknown_condition_rules( $condition['conditions'], $unknown );
					continue;
				}

				if ( isset( $condition['rule'] ) && is_string( $condition['rule'] ) && ! in_array( $condition['rule'], $known, true ) ) {
					$unknown[ $condition['rule'] ] = $condition['rule'];
				}
			}

			return array_values( $unknown );
		}

		private function known_condition_rules() {
			static $rules = null;

			if ( null !== $rules ) {
				return $rules;
			}

			$rules = [];

			if ( class_exists( '\Blocksy\ConditionsManager' ) ) {
				$manager = new \Blocksy\ConditionsManager();
				$all = $manager->get_all_rules( [ 'filter' => 'all' ] );

				foreach ( (array) $all as $group ) {
					foreach ( (array) ( $group['rules'] ?? [] ) as $rule ) {
						if ( isset( $rule['id'] ) ) {
							$rules[] = $rule['id'];
						}
					}
				}
			}

			$rules[] = 'everywhere';
			$rules = array_values( array_unique( $rules ) );

			return $rules;
		}

		private function validate_conditions( $conditions ) {
			if ( ! is_array( $conditions ) ) {
				return 'conditions must be an array.';
			}

			foreach ( $conditions as $condition ) {
				if ( ! is_array( $condition ) ) {
					return 'Each condition must be an object.';
				}

				if ( ! isset( $condition['type'] ) || ! in_array( $condition['type'], [ 'include', 'exclude' ], true ) ) {
					return 'Condition type must be include or exclude.';
				}

				if ( isset( $condition['conditions'] ) ) {
					$nested = $this->validate_conditions( $condition['conditions'] );
					if ( $nested ) {
						return $nested;
					}
					continue;
				}

				if ( ! isset( $condition['rule'] ) || ! is_string( $condition['rule'] ) || '' === $condition['rule'] ) {
					return 'Condition rule must be a non-empty string.';
				}

				if ( isset( $condition['payload'] ) && ! is_array( $condition['payload'] ) ) {
					return 'Condition payload must be an object.';
				}
			}

			return null;
		}

		private function apply_post_meta( $post_meta, $dry_run, &$report ) {
			if ( empty( $post_meta ) ) {
				return;
			}

			if ( ! is_array( $post_meta ) ) {
				$report['errors'][] = 'post_meta must be an object.';
				return;
			}

			foreach ( $post_meta as $post_ref => $meta ) {
				$post_id = $this->resolve_post( $post_ref );

				if ( ! $post_id ) {
					$report['errors'][] = "Could not resolve post '$post_ref'.";
					continue;
				}

				if ( ! is_array( $meta ) ) {
					$report['errors'][] = "post_meta for '$post_ref' must be an object.";
					continue;
				}

				$post_type = get_post_type( $post_id );
				$allowed = $this->allowed_post_meta_keys( $post_id, $post_type );

				foreach ( $meta as $key => $value ) {
					if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) ) {
						$report['errors'][] = "Post meta key '$key' is not allowed for post $post_id ($post_type).";
					}
				}

				$before = get_post_meta( $post_id, 'blocksy_post_meta_options', true );
				if ( ! is_array( $before ) ) {
					$before = [];
				}

				$after = array_merge( $before, $meta );
				unset( $after['styles_descriptor'] );

				if ( function_exists( 'blocksy_sanitize_post_meta_options' ) ) {
					$after = blocksy_sanitize_post_meta_options( $after );
				}

				if ( $this->values_equal( $before, $after ) ) {
					continue;
				}

				$report['actions'][] = [
					'type' => 'post_meta',
					'op'   => 'set',
					'post' => $post_id,
					'key'  => 'blocksy_post_meta_options',
				];

				if ( ! $dry_run ) {
					update_post_meta( $post_id, 'blocksy_post_meta_options', $after );
				}
			}
		}

		private function remove_post_meta( $post_meta, $dry_run, &$report ) {
			if ( empty( $post_meta ) ) {
				return;
			}

			if ( ! is_array( $post_meta ) ) {
				$report['errors'][] = 'remove_post_meta must be an object.';
				return;
			}

			foreach ( $post_meta as $post_ref => $keys ) {
				$post_id = $this->resolve_post( $post_ref );

				if ( ! $post_id ) {
					$report['errors'][] = "Could not resolve post '$post_ref'.";
					continue;
				}

				$before = get_post_meta( $post_id, 'blocksy_post_meta_options', true );
				if ( ! is_array( $before ) ) {
					continue;
				}

				$after = $before;

				foreach ( (array) $keys as $key ) {
					if ( is_string( $key ) && array_key_exists( $key, $after ) ) {
						unset( $after[ $key ] );
					}
				}

				unset( $after['styles_descriptor'] );

				$report['actions'][] = [
					'type' => 'post_meta',
					'op'   => 'remove',
					'post' => $post_id,
					'keys' => array_values( (array) $keys ),
				];

				if ( $dry_run ) {
					continue;
				}

				if ( empty( $after ) ) {
					delete_post_meta( $post_id, 'blocksy_post_meta_options' );
				} else {
					update_post_meta( $post_id, 'blocksy_post_meta_options', $after );
				}
			}
		}

		private function resolve_post( $ref ) {			if ( is_numeric( $ref ) ) {
				$post = get_post( (int) $ref );
				return $post ? (int) $post->ID : 0;
			}

			$ref = (string) $ref;

			$page = get_page_by_path( $ref );
			if ( $page ) {
				return (int) $page->ID;
			}

			$posts = get_posts(
				[
					'name'           => sanitize_title( $ref ),
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);

			return $posts ? (int) $posts[0] : 0;
		}

		private function allowed_post_meta_keys( $post_id, $post_type ) {
			$schema = $this->post_meta_schema();
			$allowed = [];

			$type = 'post';
			if ( 'page' === $post_type ) {
				$type = 'page';
			}

			if ( 'ct_content_block' === $post_type ) {
				$block_type = get_post_meta( $post_id, 'template_type', true );
				$block_schema = $this->content_block_schema();
				foreach ( ( $block_schema[ $block_type ] ?? [] ) as $option ) {
					$allowed[ $option['id'] ] = true;
				}
				return $allowed;
			}

			foreach ( ( $schema[ $type ] ?? [] ) as $option ) {
				$allowed[ $option['id'] ] = true;
			}

			$allowed['disable_header'] = true;
			$allowed['disable_footer'] = true;

			return $allowed;
		}

		private function allowed_theme_mod_keys() {
			$schema = $this->theme_options_schema();
			$keys = [];

			foreach ( $schema as $option ) {
				$keys[ $option['id'] ] = true;
			}

			foreach ( $this->special_theme_mods() as $key ) {
				$keys[ $key ] = true;
			}

			foreach ( $this->extension_theme_mod_prefixes() as $prefix ) {
				$keys[ 'prefix:' . $prefix ] = true;
			}

			return $keys;
		}

		private function extension_theme_mod_prefixes() {
			return [
				'cookie_consent_',
				'forms_cookie_consent_',
				'trending_',
				'trendingBlock',
				'newsletter_subscribe_',
				'shortcuts_',
			];
		}

		private function is_allowed_theme_mod( $key, $allowed ) {
			if ( isset( $allowed[ $key ] ) ) {
				return true;
			}

			// Existing mods are allowed so a backup can be restored without
			// losing legacy or third-party keys that are no longer in the schema.
			if ( '__missing__' !== get_theme_mod( $key, '__missing__' ) ) {
				return true;
			}

			foreach ( $allowed as $allowed_key => $unused ) {
				if ( 0 !== strpos( $allowed_key, 'prefix:' ) ) {
					continue;
				}

				$prefix = substr( $allowed_key, 7 );
				if ( 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			}

			return false;
		}

		private function validate_builder_value( $key, $value ) {
			if ( ! is_array( $value ) ) {
				return 'the value must be an array.';
			}

			if ( ! isset( $value['sections'] ) || ! is_array( $value['sections'] ) ) {
				return 'the value must contain a sections array.';
			}

			foreach ( $value['sections'] as $index => $section ) {
				if ( ! is_array( $section ) ) {
					return "section $index must be an object.";
				}

				if ( empty( $section['id'] ) || ! is_string( $section['id'] ) ) {
					return "section $index must have a string id.";
				}

				if ( ! isset( $section['items'] ) || ! is_array( $section['items'] ) ) {
					return "section $index must have an items array.";
				}

				if ( 'header_placements' === $key ) {
					if ( isset( $section['mode'] ) && 'placements' !== $section['mode'] ) {
						return "header section $index must use mode 'placements'.";
					}

					foreach ( [ 'desktop', 'mobile' ] as $device ) {
						if ( ! isset( $section[ $device ] ) || ! is_array( $section[ $device ] ) ) {
							return "header section $index must have a $device array.";
						}
					}
				}

				if ( 'footer_placements' === $key ) {
					if ( isset( $section['mode'] ) && 'columns' !== $section['mode'] ) {
						return "footer section $index must use mode 'columns'.";
					}

					if ( ! isset( $section['rows'] ) || ! is_array( $section['rows'] ) ) {
						return "footer section $index must have a rows array.";
					}
				}
			}

			return null;
		}

		private function is_json_safe( $value ) {
			$encoded = wp_json_encode( $value );

			return false !== $encoded;
		}

		private function values_equal( $a, $b ) {
			if ( '__missing__' === $a ) {
				return false;
			}

			return wp_json_encode( $a ) === wp_json_encode( $b );
		}

		/* ---------------------------------------------------------------------
		 * Cache
		 * ------------------------------------------------------------------ */

		public function refresh_caches() {
			$result = [
				'blocksy_cache_wiped' => false,
				'dynamic_css'         => false,
				'page_caches'         => false,
			];

			if ( function_exists( 'blocksy_manager' ) ) {
				$manager = blocksy_manager();
				if ( $manager && isset( $manager->db ) && method_exists( $manager->db, 'wipe_cache' ) ) {
					$manager->db->wipe_cache();
					$result['blocksy_cache_wiped'] = true;
				}
			}

			do_action( 'blocksy:dynamic-css:refresh-caches' );
			$result['dynamic_css'] = true;

			do_action( 'blocksy:cache-manager:purge-all' );
			$result['page_caches'] = true;

			wp_cache_flush();

			return $result;
		}
	}

	Blocksy_Bridge::instance();
}

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, [ 'Blocksy_Bridge', 'activate' ] );
}
