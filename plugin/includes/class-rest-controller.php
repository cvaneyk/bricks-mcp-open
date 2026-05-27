<?php
/**
 * REST controller that registers all API routes.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_REST_Controller
 *
 * Registers all REST API routes under the bricks-bridge/v1 namespace
 * and delegates to the appropriate handler classes.
 */
class Bricks_API_Bridge_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'bricks-bridge/v1';

	/**
	 * Pages controller instance.
	 *
	 * @var Bricks_API_Bridge_Pages
	 */
	private $pages;

	/**
	 * Templates controller instance.
	 *
	 * @var Bricks_API_Bridge_Templates
	 */
	private $templates;

	/**
	 * Backup manager instance.
	 *
	 * @var Bricks_API_Bridge_Backup
	 */
	private $backup;

	/**
	 * Presets controller instance.
	 *
	 * @var Bricks_API_Bridge_Presets
	 */
	private $presets;

	/**
	 * Global classes controller instance.
	 *
	 * @var Bricks_API_Bridge_Global_Classes
	 */
	private $global_classes;

	/**
	 * Element search instance.
	 *
	 * @var Bricks_API_Bridge_Element_Search
	 */
	private $element_search;

	/**
	 * Constructor. Instantiate handler classes.
	 */
	public function __construct() {
		$this->pages          = new Bricks_API_Bridge_Pages();
		$this->templates      = new Bricks_API_Bridge_Templates();
		$this->backup         = new Bricks_API_Bridge_Backup();
		$this->presets        = new Bricks_API_Bridge_Presets();
		$this->global_classes = new Bricks_API_Bridge_Global_Classes();
		$this->element_search = new Bricks_API_Bridge_Element_Search();
	}

	/**
	 * Register all REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Pages routes.
		register_rest_route(
			self::NAMESPACE,
			'/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->pages, 'list_pages' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'default'           => 'page',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'    => array(
						'type'              => 'string',
						'default'           => 'publish',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'fields'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->pages, 'get_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this->pages, 'update_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id'             => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
						'regenerate_css' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'pre_validated'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this->pages, 'patch_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id'             => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
						'regenerate_css' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		// Build page route.
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->pages, 'build_page' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
				),
			)
		);

		// Append elements route.
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/elements',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->pages, 'append_elements' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
				),
			)
		);

		// Clone page route.
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/clone',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->pages, 'clone_page' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
				),
			)
		);

		// Sign code signatures for API-pushed elements (form, code, etc.).
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/sign-code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->pages, 'sign_code' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
				),
			)
		);

		// Cross-page element search.
		register_rest_route(
			self::NAMESPACE,
			'/elements/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->element_search, 'search' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'q'             => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'element_type'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'css_class'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'setting_key'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'setting_value' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_type'     => array(
						'type'              => 'string',
						'default'           => 'page',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'         => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Templates routes.
		register_rest_route(
			self::NAMESPACE,
			'/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->templates, 'list_templates' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'template_type' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'fields'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->templates, 'create_template' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/templates/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->templates, 'import_template' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Clone template route.
		register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/clone',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->templates, 'clone_template' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->templates, 'get_template' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this->templates, 'update_template' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this->templates, 'delete_template' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id'    => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		// Batch route.
		register_rest_route(
			self::NAMESPACE,
			'/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_batch' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Backup routes.
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/backups',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->backup, 'list_backups' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/backup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->backup, 'get_backup' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
					'slot' => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->backup, 'restore_backup' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
					'slot' => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Named snapshot routes.
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/snapshots',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->backup, 'list_snapshots' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->backup, 'create_snapshot' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_positive_integer' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/snapshots/(?P<snapshot_id>[a-zA-Z0-9_-]+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->backup, 'restore_snapshot' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id'          => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
					'snapshot_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/snapshots/(?P<snapshot_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this->backup, 'delete_snapshot' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id'          => array(
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_positive_integer' ),
					),
					'snapshot_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// Presets routes.
		register_rest_route(
			self::NAMESPACE,
			'/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->presets, 'get_presets' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'category' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->presets, 'save_preset' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/presets/(?P<name>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this->presets, 'delete_preset' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/presets/instantiate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->presets, 'instantiate_preset' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/presets/suggest-flow',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->presets, 'suggest_flow' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/presets/style-preferences',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->presets, 'get_style_preferences' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Global CSS Classes routes.
		register_rest_route(
			self::NAMESPACE,
			'/global-classes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->global_classes, 'list_classes' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->global_classes, 'save_class' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// Global Classes sub-routes (MUST be registered before /{id} catch-all).
		register_rest_route(
			self::NAMESPACE,
			'/global-classes/auto-apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->global_classes, 'auto_apply_classes' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/global-classes/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->global_classes, 'bulk_save_classes' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/global-classes/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->global_classes, 'apply_to_elements' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/global-classes/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->global_classes, 'get_usage' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'class_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/global-classes/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->global_classes, 'get_class' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this->global_classes, 'update_class' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this->global_classes, 'delete_class' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// Self-update: upload ZIP to replace plugin files.
		register_rest_route(
			self::NAMESPACE,
			'/self-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_self_update' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' );
				},
			)
		);
	}

	/**
	 * Handle plugin self-update via ZIP upload.
	 *
	 * Accepts a multipart file upload containing the plugin ZIP,
	 * extracts it, and replaces the current plugin files.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_self_update( $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['plugin_zip']['tmp_name'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_file',
				__( 'No plugin_zip file uploaded.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		$tmp_file = $files['plugin_zip']['tmp_name'];

		// Validate it's a ZIP.
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = finfo_file( $finfo, $tmp_file );
		finfo_close( $finfo );

		if ( ! in_array( $mime, array( 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ), true ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_file',
				__( 'Uploaded file is not a valid ZIP archive.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Store current version before update.
		$old_version = defined( 'BRICKS_API_BRIDGE_VERSION' ) ? BRICKS_API_BRIDGE_VERSION : 'unknown';

		// Extract ZIP to temp directory.
		$temp_dir = get_temp_dir() . 'bab-update-' . wp_generate_password( 8, false );

		// Load filesystem API (not auto-loaded in REST context).
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		$unzip_result = unzip_file( $tmp_file, $temp_dir );

		if ( is_wp_error( $unzip_result ) ) {
			$wp_filesystem->delete( $temp_dir, true );
			return new WP_Error(
				'bricks_api_bridge_unzip_failed',
				$unzip_result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Find the plugin folder in extracted contents.
		$extracted_plugin_dir = $temp_dir . '/bricks-api-bridge';

		if ( ! $wp_filesystem->is_dir( $extracted_plugin_dir ) ) {
			$wp_filesystem->delete( $temp_dir, true );
			return new WP_Error(
				'bricks_api_bridge_invalid_zip',
				__( 'ZIP must contain a bricks-api-bridge/ folder at root level.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Validate the main plugin file exists.
		if ( ! $wp_filesystem->exists( $extracted_plugin_dir . '/bricks-api-bridge.php' ) ) {
			$wp_filesystem->delete( $temp_dir, true );
			return new WP_Error(
				'bricks_api_bridge_invalid_zip',
				__( 'ZIP does not contain bricks-api-bridge.php.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Read new version from extracted plugin header.
		$new_header  = $wp_filesystem->get_contents( $extracted_plugin_dir . '/bricks-api-bridge.php' );
		$new_version = 'unknown';
		if ( preg_match( '/Version:\s*(\S+)/', $new_header, $m ) ) {
			$new_version = $m[1];
		}

		// Replace plugin files: delete current, copy new.
		$plugin_dir = BRICKS_API_BRIDGE_PLUGIN_DIR;

		// Delete current plugin contents (but not the directory itself).
		$dir_list = $wp_filesystem->dirlist( $plugin_dir );
		if ( is_array( $dir_list ) ) {
			foreach ( $dir_list as $entry => $info ) {
				$wp_filesystem->delete( $plugin_dir . $entry, true );
			}
		}

		// Copy new files.
		$copy_result = copy_dir( $extracted_plugin_dir, $plugin_dir );

		// Cleanup temp directory.
		$wp_filesystem->delete( $temp_dir, true );

		if ( is_wp_error( $copy_result ) ) {
			return new WP_Error(
				'bricks_api_bridge_copy_failed',
				$copy_result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'old_version'  => $old_version,
				'new_version'  => $new_version,
				'message'      => sprintf( 'Plugin updated from %s to %s.', $old_version, $new_version ),
			)
		);
	}

	/**
	 * Handle a batch of API operations in a single request.
	 *
	 * Accepts an array of operations, each with method, endpoint, and optional body.
	 * Maximum 20 operations per batch.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_batch( $request ) {
		$body       = $request->get_json_params();
		$operations = isset( $body['operations'] ) ? $body['operations'] : array();

		if ( empty( $operations ) || ! is_array( $operations ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_batch',
				__( 'operations must be a non-empty array', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $operations ) > 20 ) {
			return new WP_Error(
				'bricks_api_bridge_batch_limit',
				__( 'Maximum 20 operations per batch', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		$stop_on_error = ! empty( $body['stop_on_error'] );
		$results       = array();

		foreach ( $operations as $op ) {
			$method   = isset( $op['method'] ) ? strtoupper( $op['method'] ) : 'GET';
			$endpoint = isset( $op['endpoint'] ) ? $op['endpoint'] : '';
			$op_body  = isset( $op['body'] ) ? $op['body'] : null;

			$internal_request = new WP_REST_Request( $method, '/bricks-bridge/v1' . $endpoint );
			if ( $op_body ) {
				$internal_request->set_body( wp_json_encode( $op_body ) );
				$internal_request->set_header( 'Content-Type', 'application/json' );
			}

			$response  = rest_do_request( $internal_request );
			$status    = $response->get_status();
			$results[] = array(
				'status' => $status,
				'body'   => $response->get_data(),
			);

			// Stop on first error if flag is set.
			if ( $stop_on_error && $status >= 400 ) {
				break;
			}
		}

		$response_data = array(
			'results'   => $results,
			'executed'  => count( $results ),
			'total'     => count( $operations ),
		);
		if ( $stop_on_error && count( $results ) < count( $operations ) ) {
			$response_data['stopped_early'] = true;
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Global settings routes that require manage_options capability.
	 *
	 * @var string[]
	 */
	private static $admin_routes = array(
		'/theme-styles',
		'/color-palette',
		'/global-css',
		'/global-classes/bulk',
		'/fonts',
		'/css-variables',
		'/breakpoints',
		'/self-update',
	);

	/**
	 * Permission callback for all routes.
	 *
	 * Read operations (GET) and content writes require edit_posts.
	 * Global settings endpoints require manage_options for write operations.
	 *
	 * @param WP_REST_Request $request The REST request (auto-injected by WP).
	 * @return bool|WP_Error
	 */
	public function check_permissions( $request = null ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'bricks_api_bridge_forbidden',
				__( 'You do not have permission to access this endpoint.', 'bricks-api-bridge' ),
				array( 'status' => 403 )
			);
		}

		// For write operations on global settings, require manage_options.
		if ( $request instanceof WP_REST_Request ) {
			$method = $request->get_method();
			$route  = $request->get_route();

			if ( 'GET' !== $method ) {
				foreach ( self::$admin_routes as $admin_route ) {
					if ( false !== strpos( $route, $admin_route ) ) {
						if ( ! current_user_can( 'manage_options' ) ) {
							return new WP_Error(
								'bricks_api_bridge_forbidden',
								__( 'This operation requires administrator privileges.', 'bricks-api-bridge' ),
								array( 'status' => 403 )
							);
						}
						break;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Validate that a parameter is a positive integer.
	 *
	 * @param mixed           $value   The parameter value.
	 * @param WP_REST_Request $request The REST request.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error
	 */
	public function validate_positive_integer( $value, $request, $param ) {
		if ( ! is_numeric( $value ) || (int) $value < 1 ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '"%s" must be a positive integer.', 'bricks-api-bridge' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
