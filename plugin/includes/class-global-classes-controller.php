<?php
/**
 * Global CSS Classes controller for Bricks Builder.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Global_Classes
 *
 * Provides CRUD operations for Bricks Builder global CSS classes
 * stored in the wp_option 'bricks_global_classes_locked'.
 */
class Bricks_API_Bridge_Global_Classes {

	/**
	 * Option key for global classes.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'bricks_global_classes';

	/**
	 * Default type-to-classes mapping for auto-apply.
	 *
	 * @var array
	 */
	const DEFAULT_AUTO_MAPPING = array(
		'section'   => array( 'ds-section-md' ),
		'container' => array( 'ds-gap-md' ),
	);

	/**
	 * Option key for build learnings.
	 *
	 * @var string
	 */
	const LEARNINGS_KEY = 'bab_build_learnings';

	/**
	 * Legacy option key (locked classes, not visible in Bricks editor).
	 */
	const LEGACY_OPTION_KEY = 'bricks_global_classes_locked';

	/**
	 * Migrate classes from legacy locked store to editable store.
	 *
	 * Merges any legacy locked classes into the main store (by ID, no duplicates).
	 * Runs once per deployment via a transient flag.
	 */
	private static function maybe_migrate() {
		if ( get_transient( 'bab_classes_migrated' ) ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION_KEY, array() );
		if ( empty( $legacy ) || ! is_array( $legacy ) ) {
			set_transient( 'bab_classes_migrated', 1, DAY_IN_SECONDS );
			return;
		}

		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// Build index of existing IDs.
		$existing_ids = array();
		foreach ( $current as $c ) {
			if ( isset( $c['id'] ) ) {
				$existing_ids[ $c['id'] ] = true;
			}
		}

		// Merge legacy classes that don't already exist.
		$merged = 0;
		foreach ( $legacy as $lc ) {
			if ( isset( $lc['id'] ) && ! isset( $existing_ids[ $lc['id'] ] ) ) {
				$current[] = $lc;
				$merged++;
			}
		}

		if ( $merged > 0 ) {
			update_option( self::OPTION_KEY, $current );
		}
		delete_option( self::LEGACY_OPTION_KEY );
		set_transient( 'bab_classes_migrated', 1, DAY_IN_SECONDS );
	}

	/**
	 * Ensure all classes have required Bricks fields (modified, user_id)
	 * and settings is an object (not empty array).
	 *
	 * Runs once per deployment via transient flag.
	 *
	 * @return bool True if classes were normalized.
	 */
	private static function maybe_normalize() {
		if ( get_transient( 'bab_classes_normalized_v2' ) ) {
			return false;
		}

		$classes = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $classes ) || empty( $classes ) ) {
			set_transient( 'bab_classes_normalized_v2', 1, DAY_IN_SECONDS );
			return false;
		}

		$now     = time();
		$changed = false;

		foreach ( $classes as &$c ) {
			// Add modified timestamp if missing.
			if ( ! isset( $c['modified'] ) ) {
				$c['modified'] = $now;
				$changed       = true;
			}

			// Add user_id if missing.
			if ( ! isset( $c['user_id'] ) ) {
				$c['user_id'] = 1; // Default to admin.
				$changed      = true;
			}

			// Ensure settings is always an associative array (never stdClass).
			if ( isset( $c['settings'] ) && $c['settings'] instanceof \stdClass ) {
				$c['settings'] = (array) $c['settings'];
				$changed       = true;
			} elseif ( ! isset( $c['settings'] ) || ! is_array( $c['settings'] ) ) {
				$c['settings'] = array();
				$changed       = true;
			}
		}
		unset( $c );

		if ( $changed ) {
			update_option( self::OPTION_KEY, $classes, true );
		}

		set_transient( 'bab_classes_normalized_v2', 1, DAY_IN_SECONDS );
		return $changed;
	}

	/**
	 * List all global CSS classes.
	 *
	 * @return WP_REST_Response
	 */
	public function list_classes() {
		self::maybe_migrate();
		self::maybe_normalize();

		$classes = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		return rest_ensure_response(
			array(
				'classes' => $classes,
				'count'   => count( $classes ),
			)
		);
	}

	/**
	 * Get a single global class by ID.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_class( $request ) {
		$class_id = sanitize_text_field( $request->get_param( 'id' ) );
		$classes  = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		foreach ( $classes as $class ) {
			if ( isset( $class['id'] ) && $class['id'] === $class_id ) {
				return rest_ensure_response( $class );
			}
		}

		return new WP_Error(
			'bricks_api_bridge_class_not_found',
			__( 'Global class not found.', 'bricks-api-bridge' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Create or update a global CSS class.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_class( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['name'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_class',
				__( 'Class name is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $body['id'] ) ) {
			$body['id'] = sanitize_title( $body['name'] );
		}

		$classes = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		$class_data = array(
			'id'       => sanitize_text_field( $body['id'] ),
			'name'     => sanitize_text_field( $body['name'] ),
			'settings' => isset( $body['settings'] ) ? $body['settings'] : array(),
			'modified' => time(),
			'user_id'  => get_current_user_id(),
		);

		// Update existing or add new.
		$found = false;
		foreach ( $classes as &$existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $class_data['id'] ) {
				$existing = $class_data;
				$found    = true;
				break;
			}
		}
		unset( $existing );

		if ( ! $found ) {
			$classes[] = $class_data;
		}

		update_option( self::OPTION_KEY, $classes, true );

		return rest_ensure_response(
			array(
				'success' => true,
				'class'   => $class_data,
			)
		);
	}

	/**
	 * Update a global CSS class by ID (partial merge).
	 *
	 * Unlike save_class() which replaces the entire class,
	 * this merges settings recursively so individual properties
	 * can be updated without losing the rest.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_class( $request ) {
		$class_id = sanitize_text_field( $request->get_param( 'id' ) );
		$body     = $request->get_json_params();
		$classes  = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		$found   = false;
		$updated = null;
		foreach ( $classes as &$existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $class_id ) {
				if ( ! empty( $body['name'] ) ) {
					$existing['name'] = sanitize_text_field( $body['name'] );
				}
				if ( isset( $body['settings'] ) ) {
					$existing['settings'] = array_replace_recursive(
						isset( $existing['settings'] ) ? $existing['settings'] : array(),
						$body['settings']
					);
				}
				$existing['modified'] = time();
				$existing['user_id']  = get_current_user_id();
				$found   = true;
				$updated = $existing;
				break;
			}
		}
		unset( $existing );

		if ( ! $found ) {
			return new WP_Error(
				'bricks_api_bridge_class_not_found',
				__( 'Global class not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		update_option( self::OPTION_KEY, $classes, true );

		return rest_ensure_response( array(
			'success' => true,
			'class'   => $updated,
		) );
	}

	/**
	 * Bulk create or update multiple global classes at once.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_save_classes( $request ) {
		$body        = $request->get_json_params();
		$new_classes = isset( $body['classes'] ) ? $body['classes'] : array();

		if ( empty( $new_classes ) || ! is_array( $new_classes ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_input',
				__( 'classes array required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		$classes = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		// Build index by ID for fast lookup.
		$index = array();
		foreach ( $classes as $i => $c ) {
			if ( isset( $c['id'] ) ) {
				$index[ $c['id'] ] = $i;
			}
		}

		$created = 0;
		$updated = 0;
		foreach ( $new_classes as $nc ) {
			if ( empty( $nc['id'] ) || empty( $nc['name'] ) ) {
				continue;
			}

			$now     = time();
			$user_id = get_current_user_id();
			$class_data = array(
				'id'       => sanitize_text_field( $nc['id'] ),
				'name'     => sanitize_text_field( $nc['name'] ),
				'settings' => isset( $nc['settings'] ) ? $nc['settings'] : array(),
				'modified' => $now,
				'user_id'  => $user_id,
			);

			if ( isset( $index[ $class_data['id'] ] ) ) {
				$classes[ $index[ $class_data['id'] ] ] = $class_data;
				$updated++;
			} else {
				$classes[]                        = $class_data;
				$index[ $class_data['id'] ] = count( $classes ) - 1;
				$created++;
			}
		}

		update_option( self::OPTION_KEY, $classes, true );

		return rest_ensure_response( array(
			'success' => true,
			'created' => $created,
			'updated' => $updated,
			'total'   => count( $classes ),
		) );
	}

	/**
	 * Apply or remove global classes on page elements.
	 *
	 * Supports single operation (element_id + class_id) and bulk
	 * operations array for multiple elements at once.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_to_elements( $request ) {
		$body    = $request->get_json_params();
		$page_id = isset( $body['page_id'] ) ? (int) $body['page_id'] : 0;

		if ( ! $page_id || ! get_post( $page_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_page',
				__( 'Valid page_id required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Normalize to operations array.
		$operations = array();
		if ( isset( $body['operations'] ) ) {
			$operations = $body['operations'];
		} elseif ( isset( $body['element_id'] ) && isset( $body['class_id'] ) ) {
			$operations = array(
				array(
					'element_id' => $body['element_id'],
					'class_ids'  => array( $body['class_id'] ),
					'action'     => 'add',
				),
			);
		}

		if ( empty( $operations ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_input',
				__( 'operations array or element_id + class_id required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Load page content (use Bricks Database class if available).
		$current = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$current = \Bricks\Database::get_data( $page_id, 'content' );
		}
		if ( empty( $current ) ) {
			$current = get_post_meta( $page_id, '_bricks_page_content_2', true );
		}
		if ( empty( $current ) || ! is_array( $current ) ) {
			return new WP_Error(
				'bricks_api_bridge_empty_page',
				__( 'Page has no Bricks content.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$modified = 0;
		foreach ( $operations as $op ) {
			$el_id     = sanitize_text_field( isset( $op['element_id'] ) ? $op['element_id'] : '' );
			$class_ids = isset( $op['class_ids'] ) ? $op['class_ids'] : ( isset( $op['class_id'] ) ? array( $op['class_id'] ) : array() );
			$action    = isset( $op['action'] ) ? $op['action'] : 'add';

			foreach ( $current as &$el ) {
				if ( $el['id'] !== $el_id ) {
					continue;
				}

				$existing = isset( $el['settings']['_cssGlobalClasses'] ) ? $el['settings']['_cssGlobalClasses'] : array();

				if ( 'add' === $action ) {
					foreach ( $class_ids as $cid ) {
						if ( ! in_array( $cid, $existing, true ) ) {
							$existing[] = $cid;
						}
					}
				} elseif ( 'remove' === $action ) {
					$existing = array_values( array_diff( $existing, $class_ids ) );
				}

				$el['settings']['_cssGlobalClasses'] = $existing;
				$modified++;
				break;
			}
			unset( $el );
		}

		// Save via Bricks Database class (syncs all internal caches).
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $page_id, $current, 'content' );
		} else {
			update_post_meta( $page_id, '_bricks_page_content_2', $current );
		}

		// Regenerate CSS.
		if ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'generate_css_file' ) ) {
			\Bricks\Assets::generate_css_file( $page_id );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'modified' => $modified,
		) );
	}

	/**
	 * Get usage report for global classes across all pages/templates.
	 *
	 * Optionally filter by class_id query parameter.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function get_usage( $request ) {
		$filter_class = $request->get_param( 'class_id' );

		global $wpdb;

		// Use a direct DB query to avoid loading all posts into memory.
		if ( $filter_class ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_bricks_page_content_2', '_bricks_page_content') AND meta_value LIKE %s",
					'%' . $wpdb->esc_like( $filter_class ) . '%'
				)
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_bricks_page_content_2', '_bricks_page_content') AND meta_value LIKE %s",
					'%' . $wpdb->esc_like( '_cssGlobalClasses' ) . '%'
				)
			);
		}

		$usage = array();

		foreach ( $results as $row ) {
			$pid     = (int) $row->post_id;
			$content = maybe_unserialize( $row->meta_value );
			if ( empty( $content ) || ! is_array( $content ) ) {
				continue;
			}

			foreach ( $content as $el ) {
				$gc = isset( $el['settings']['_cssGlobalClasses'] ) ? $el['settings']['_cssGlobalClasses'] : array();
				if ( empty( $gc ) ) {
					continue;
				}

				foreach ( $gc as $cid ) {
					if ( $filter_class && $cid !== $filter_class ) {
						continue;
					}

					if ( ! isset( $usage[ $cid ] ) ) {
						$usage[ $cid ] = array();
					}
					if ( ! isset( $usage[ $cid ][ $pid ] ) ) {
						$usage[ $cid ][ $pid ] = array(
							'page_id'     => $pid,
							'page_title'  => get_the_title( $pid ),
							'element_ids' => array(),
						);
					}
					$usage[ $cid ][ $pid ]['element_ids'][] = $el['id'];
				}
			}
		}

		// Flatten pages arrays.
		$result = array();
		foreach ( $usage as $cid => $pages ) {
			$result[ $cid ] = array(
				'total_elements' => array_sum( array_map( function ( $p ) {
					return count( $p['element_ids'] );
				}, $pages ) ),
				'total_pages'    => count( $pages ),
				'pages'          => array_values( $pages ),
			);
		}

		return rest_ensure_response( array(
			'usage'         => $result,
			'classes_found' => count( $result ),
		) );
	}

	/**
	 * Auto-apply design system classes to all elements on a page.
	 *
	 * Analyzes each element's type and context, then assigns appropriate
	 * global classes. Smart rules:
	 * - Sections get section spacing classes
	 * - Containers get gap classes
	 * - Containers with row direction get col-on-mobile
	 * - Top-level containers (direct child of section) get flex-col + items-center
	 * - Existing class assignments are preserved (additive only)
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function auto_apply_classes( $request ) {
		$body    = $request->get_json_params();
		$page_id = isset( $body['page_id'] ) ? (int) $body['page_id'] : 0;
		$prefix  = isset( $body['prefix'] ) ? sanitize_text_field( $body['prefix'] ) : 'ds-';

		if ( ! $page_id || ! get_post( $page_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_page',
				__( 'Valid page_id required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Merge user mapping with defaults + learnings.
		$mapping = self::DEFAULT_AUTO_MAPPING;

		// Enhance mapping with learnings (add + remove).
		$learnings = get_option( self::LEARNINGS_KEY, array() );
		if ( is_array( $learnings ) ) {
			foreach ( $learnings as $learning ) {
				$el_type        = isset( $learning['element_type'] ) ? $learning['element_type'] : '';
				$correction     = isset( $learning['correction'] ) ? $learning['correction'] : array();
				$add_classes    = isset( $correction['add_classes'] ) ? $correction['add_classes'] : array();
				$remove_classes = isset( $correction['remove_classes'] ) ? $correction['remove_classes'] : array();

				if ( ! empty( $el_type ) && ! empty( $add_classes ) ) {
					if ( ! isset( $mapping[ $el_type ] ) ) {
						$mapping[ $el_type ] = array();
					}
					$mapping[ $el_type ] = array_unique(
						array_merge( $mapping[ $el_type ], $add_classes )
					);
				}

				// Remove learned negative classes from mapping.
				if ( ! empty( $el_type ) && ! empty( $remove_classes ) && isset( $mapping[ $el_type ] ) ) {
					$mapping[ $el_type ] = array_values(
						array_diff( $mapping[ $el_type ], $remove_classes )
					);
				}
			}
		}

		// User mapping overrides everything.
		if ( ! empty( $body['mapping'] ) && is_array( $body['mapping'] ) ) {
			$mapping = array_merge( $mapping, $body['mapping'] );
		}

		// Verify that referenced classes actually exist.
		$existing_classes = get_option( self::OPTION_KEY, array() );
		$existing_ids     = array();
		if ( is_array( $existing_classes ) ) {
			foreach ( $existing_classes as $c ) {
				if ( isset( $c['id'] ) ) {
					$existing_ids[ $c['id'] ] = true;
				}
			}
		}

		// Load page content (use Bricks Database class if available).
		$content = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$content = \Bricks\Database::get_data( $page_id, 'content' );
		}
		if ( empty( $content ) ) {
			$content = get_post_meta( $page_id, '_bricks_page_content_2', true );
		}
		if ( empty( $content ) || ! is_array( $content ) ) {
			return new WP_Error(
				'bricks_api_bridge_empty_page',
				__( 'Page has no Bricks content.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		// Build parent lookup for context analysis.
		$parent_map = array();
		foreach ( $content as $el ) {
			$parent_map[ $el['id'] ] = $el;
		}

		$modified        = 0;
		$classes_applied = 0;
		$details         = array();

		foreach ( $content as &$el ) {
			$type = isset( $el['name'] ) ? $el['name'] : '';
			if ( empty( $type ) ) {
				continue;
			}

			// Determine which classes to assign.
			$to_add = array();

			// Base mapping.
			if ( isset( $mapping[ $type ] ) ) {
				$to_add = array_merge( $to_add, $mapping[ $type ] );
			}

			// Smart container rules.
			if ( 'container' === $type ) {
				$settings  = isset( $el['settings'] ) ? $el['settings'] : array();
				$parent_id = isset( $el['parent'] ) ? $el['parent'] : 0;

				// Direct child of section → main content wrapper.
				if ( $parent_id && isset( $parent_map[ $parent_id ] ) ) {
					$parent_type = isset( $parent_map[ $parent_id ]['name'] ) ? $parent_map[ $parent_id ]['name'] : '';
					if ( 'section' === $parent_type ) {
						$to_add[] = $prefix . 'flex-col';
						$to_add[] = $prefix . 'items-center';
					}
				}

				// Row direction → add col-on-mobile for responsive.
				$direction = isset( $settings['_direction'] ) ? $settings['_direction'] : '';
				if ( 'row' === $direction ) {
					$to_add[] = $prefix . 'col-on-mobile';
					// Remove flex-col if we just added it (row takes priority).
					$to_add = array_values( array_diff( $to_add, array( $prefix . 'flex-col' ) ) );
				}
			}

			if ( empty( $to_add ) ) {
				continue;
			}

			// Filter to only classes that actually exist.
			$to_add = array_filter( $to_add, function ( $cid ) use ( $existing_ids ) {
				return isset( $existing_ids[ $cid ] );
			} );

			if ( empty( $to_add ) ) {
				continue;
			}

			// Additive: preserve existing assignments.
			$existing_gc = isset( $el['settings']['_cssGlobalClasses'] ) ? $el['settings']['_cssGlobalClasses'] : array();
			$added       = array();
			foreach ( $to_add as $cid ) {
				if ( ! in_array( $cid, $existing_gc, true ) ) {
					$existing_gc[] = $cid;
					$added[]       = $cid;
				}
			}

			if ( ! empty( $added ) ) {
				$el['settings']['_cssGlobalClasses'] = $existing_gc;
				$modified++;
				$classes_applied += count( $added );
				$details[]        = array(
					'element_id' => $el['id'],
					'type'       => $type,
					'added'      => $added,
				);
			}
		}
		unset( $el );

		// Save via Bricks Database class (syncs all internal caches).
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $page_id, $content, 'content' );
		} else {
			update_post_meta( $page_id, '_bricks_page_content_2', $content );
		}

		// Regenerate CSS.
		if ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'generate_css_file' ) ) {
			\Bricks\Assets::generate_css_file( $page_id );
		}

		return rest_ensure_response( array(
			'success'         => true,
			'modified'        => $modified,
			'classes_applied' => $classes_applied,
			'details'         => $details,
		) );
	}

	/**
	 * Apply design system classes to an element array (in-memory).
	 *
	 * Used by build_page to auto-apply classes before saving.
	 *
	 * @param array  $elements The elements array.
	 * @param string $prefix   Class ID prefix.
	 * @param array  $mapping  Type-to-classes mapping.
	 * @return array Modified elements array.
	 */
	public static function apply_classes_to_elements( $elements, $prefix = 'ds-', $mapping = null ) {
		if ( null === $mapping ) {
			$mapping = self::DEFAULT_AUTO_MAPPING;
		}

		// Enhance mapping with learnings (add + remove).
		$learnings = get_option( self::LEARNINGS_KEY, array() );
		if ( is_array( $learnings ) ) {
			foreach ( $learnings as $key => $learning ) {
				$el_type        = isset( $learning['element_type'] ) ? $learning['element_type'] : '';
				$correction     = isset( $learning['correction'] ) ? $learning['correction'] : array();
				$add_classes    = isset( $correction['add_classes'] ) ? $correction['add_classes'] : array();
				$remove_classes = isset( $correction['remove_classes'] ) ? $correction['remove_classes'] : array();

				if ( ! empty( $el_type ) && ! empty( $add_classes ) ) {
					if ( ! isset( $mapping[ $el_type ] ) ) {
						$mapping[ $el_type ] = array();
					}
					$mapping[ $el_type ] = array_unique(
						array_merge( $mapping[ $el_type ], $add_classes )
					);
				}

				// Remove learned negative classes from mapping.
				if ( ! empty( $el_type ) && ! empty( $remove_classes ) && isset( $mapping[ $el_type ] ) ) {
					$mapping[ $el_type ] = array_values(
						array_diff( $mapping[ $el_type ], $remove_classes )
					);
				}
			}
		}

		// Verify that referenced classes actually exist.
		$existing_classes = get_option( self::OPTION_KEY, array() );
		$existing_ids     = array();
		if ( is_array( $existing_classes ) ) {
			foreach ( $existing_classes as $c ) {
				if ( isset( $c['id'] ) ) {
					$existing_ids[ $c['id'] ] = true;
				}
			}
		}

		// If no design system classes exist, skip.
		if ( empty( $existing_ids ) ) {
			return $elements;
		}

		// Build parent lookup.
		$parent_map = array();
		foreach ( $elements as $el ) {
			$parent_map[ $el['id'] ] = $el;
		}

		foreach ( $elements as &$el ) {
			$type = isset( $el['name'] ) ? $el['name'] : '';
			if ( empty( $type ) ) {
				continue;
			}

			$to_add = array();

			if ( isset( $mapping[ $type ] ) ) {
				$to_add = array_merge( $to_add, $mapping[ $type ] );
			}

			if ( 'container' === $type ) {
				$settings  = isset( $el['settings'] ) ? $el['settings'] : array();
				$parent_id = isset( $el['parent'] ) ? $el['parent'] : 0;

				if ( $parent_id && isset( $parent_map[ $parent_id ] ) ) {
					$parent_type = isset( $parent_map[ $parent_id ]['name'] ) ? $parent_map[ $parent_id ]['name'] : '';
					if ( 'section' === $parent_type ) {
						$to_add[] = $prefix . 'flex-col';
						$to_add[] = $prefix . 'items-center';
					}
				}

				$direction = isset( $settings['_direction'] ) ? $settings['_direction'] : '';
				if ( 'row' === $direction ) {
					$to_add[] = $prefix . 'col-on-mobile';
					$to_add   = array_values( array_diff( $to_add, array( $prefix . 'flex-col' ) ) );
				}
			}

			// Filter to existing classes only.
			$to_add = array_filter( $to_add, function ( $cid ) use ( $existing_ids ) {
				return isset( $existing_ids[ $cid ] );
			} );

			if ( empty( $to_add ) ) {
				continue;
			}

			$existing_gc = isset( $el['settings']['_cssGlobalClasses'] ) ? $el['settings']['_cssGlobalClasses'] : array();
			foreach ( $to_add as $cid ) {
				if ( ! in_array( $cid, $existing_gc, true ) ) {
					$existing_gc[] = $cid;
				}
			}
			$el['settings']['_cssGlobalClasses'] = $existing_gc;
		}
		unset( $el );

		return $elements;
	}

	/**
	 * Delete a global CSS class by ID.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_class( $request ) {
		$class_id = sanitize_text_field( $request->get_param( 'id' ) );
		$classes  = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		// Backup the class being deleted.
		foreach ( $classes as $class ) {
			if ( isset( $class['id'] ) && $class['id'] === $class_id ) {
				if ( function_exists( 'bricks_api_bridge_rotate_global_backup' ) ) {
					bricks_api_bridge_rotate_global_backup( 'global_class_' . $class_id, $class );
				}
				break;
			}
		}

		$filtered = array_values(
			array_filter(
				$classes,
				function ( $class ) use ( $class_id ) {
					return ! isset( $class['id'] ) || $class['id'] !== $class_id;
				}
			)
		);

		if ( count( $filtered ) === count( $classes ) ) {
			return new WP_Error(
				'bricks_api_bridge_class_not_found',
				__( 'Global class not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		update_option( self::OPTION_KEY, $filtered );

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $class_id,
			)
		);
	}
}
