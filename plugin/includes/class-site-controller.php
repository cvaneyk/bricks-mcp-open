<?php
/**
 * Site Controller — global settings, page creation, cache, menus, stats, and utilities.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_Site_Controller {

	const NAMESPACE = 'bricks-bridge/v1';

	public function register_routes() {

		// ─── Tier 1: High Priority ───────────────────────────

		// 1. Bricks Global Settings (GET/PUT).
		register_rest_route( self::NAMESPACE, '/settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		));

		// 2. Create Page with Bricks data.
		register_rest_route( self::NAMESPACE, '/pages/create', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_page' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 3. Page-Level Settings (GET/PUT).
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/page-settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_page_settings' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_page_settings' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
		));

		// 4. WP Navigation Menus (GET/POST/DELETE).
		register_rest_route( self::NAMESPACE, '/menus', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_menus' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_menu' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		));

		register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_menu' ),
			'permission_callback' => array( $this, 'can_manage' ),
		));

		register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)/items', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_menu_items' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_menu_item' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_menu_items' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		));

		register_rest_route( self::NAMESPACE, '/menus/items/(?P<item_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_menu_item' ),
			'permission_callback' => array( $this, 'can_manage' ),
		));

		// 5. Cache Purge.
		register_rest_route( self::NAMESPACE, '/cache/purge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'purge_cache' ),
			'permission_callback' => array( $this, 'can_manage' ),
		));

		// 6. Compiled CSS for element.
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/compiled-css', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_compiled_css' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// ─── Tier 2: Medium Priority ─────────────────────────

		// 7. Template Conditions.
		register_rest_route( self::NAMESPACE, '/templates/(?P<id>\d+)/conditions', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_template_conditions' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_template_conditions' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		));

		// 8. Server-Side Validate.
		register_rest_route( self::NAMESPACE, '/pages/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate_elements' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 9. Site Stats.
		register_rest_route( self::NAMESPACE, '/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_stats' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 10. Page Dependencies.
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/dependencies', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_page_dependencies' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 11. Bulk Export.
		register_rest_route( self::NAMESPACE, '/bulk/export', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_export' ),
			'permission_callback' => array( $this, 'can_manage' ),
		));

		// 12. Per-Element Compiled CSS.
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/element-css/(?P<element_id>[a-zA-Z0-9]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_element_css' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// ─── Tier 3: Nice-to-Have ────────────────────────────

		// 13. Edit Lock.
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/lock', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lock_page' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'unlock_page' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_lock_status' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
		));

		// 14. Post Types.
		register_rest_route( self::NAMESPACE, '/post-types', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_post_types' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// ─── Tier 3 continued ────────────────────────────────

		// 15. Performance History (GET/PUT).
		register_rest_route( self::NAMESPACE, '/performance-history', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_performance_history' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_performance_history' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		));
	}

	// ═══════════════════════════════════════════════════════════
	// 1. BRICKS GLOBAL SETTINGS
	// ═══════════════════════════════════════════════════════════

	public function get_settings() {
		$settings = get_option( 'bricks_global_settings', array() );
		return new WP_REST_Response( array( 'settings' => $settings ), 200 );
	}

	public function update_settings( $request ) {
		$body   = $request->get_json_params();
		$merge  = $body['merge'] ?? true;
		$update = $body['settings'] ?? null;

		if ( ! $update || ! is_array( $update ) ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_data',
				'message' => 'settings object is required.',
			), 400 );
		}

		$current = get_option( 'bricks_global_settings', array() );

		if ( $merge ) {
			$settings = array_replace_recursive( $current, $update );
		} else {
			$settings = $update;
		}

		update_option( 'bricks_global_settings', $settings );

		// Regenerate CSS if Bricks function available.
		if ( function_exists( '\Bricks\Assets::generate_css_file' ) ) {
			\Bricks\Assets::generate_css_file( 0 );
		}

		return new WP_REST_Response( array(
			'success'  => true,
			'settings' => $settings,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 2. CREATE PAGE
	// ═══════════════════════════════════════════════════════════

	public function create_page( $request ) {
		$body = $request->get_json_params();

		$title     = $body['title'] ?? 'Untitled';
		$slug      = $body['slug'] ?? '';
		$status    = $body['status'] ?? 'draft';
		$template  = $body['template'] ?? '';
		$elements  = $body['elements'] ?? array();
		$parent    = $body['parent'] ?? 0;
		$post_type = $body['post_type'] ?? 'page';

		$post_data = array(
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'draft',
			'post_type'   => sanitize_key( $post_type ),
			'post_parent' => absint( $parent ),
		);

		if ( $slug ) {
			$post_data['post_name'] = sanitize_title( $slug );
		}

		if ( $template ) {
			$post_data['page_template'] = sanitize_text_field( $template );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array(
				'code'    => 'create_failed',
				'message' => $post_id->get_error_message(),
			), 500 );
		}

		// Set Bricks editor mode.
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		// Save Bricks elements if provided.
		if ( ! empty( $elements ) ) {
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
				\Bricks\Database::set_data( $post_id, $elements, 'content' );
			} else {
				update_post_meta( $post_id, '_bricks_page_content_2', $elements );
			}

			if ( function_exists( '\Bricks\Assets::generate_css_file' ) ) {
				\Bricks\Assets::generate_css_file( $post_id );
			}
		}

		return new WP_REST_Response( array(
			'success'       => true,
			'page_id'       => $post_id,
			'url'           => get_permalink( $post_id ),
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'element_count' => count( $elements ),
		), 201 );
	}

	// ═══════════════════════════════════════════════════════════
	// 3. PAGE-LEVEL SETTINGS
	// ═══════════════════════════════════════════════════════════

	public function get_page_settings( $request ) {
		$id       = (int) $request['id'];
		$settings = get_post_meta( $id, '_bricks_page_settings', true );

		return new WP_REST_Response( array(
			'page_id'  => $id,
			'settings' => $settings ?: new stdClass(),
		), 200 );
	}

	public function update_page_settings( $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params();

		$merge  = $body['merge'] ?? true;
		$update = $body['settings'] ?? null;

		if ( ! $update ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_data',
				'message' => 'settings object is required.',
			), 400 );
		}

		if ( $merge ) {
			$current  = get_post_meta( $id, '_bricks_page_settings', true ) ?: array();
			$settings = array_replace_recursive( $current, $update );
		} else {
			$settings = $update;
		}

		update_post_meta( $id, '_bricks_page_settings', $settings );

		return new WP_REST_Response( array(
			'success'  => true,
			'page_id'  => $id,
			'settings' => $settings,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 4. WP NAVIGATION MENUS
	// ═══════════════════════════════════════════════════════════

	public function get_menus() {
		$menus  = wp_get_nav_menus();
		$result = array();

		foreach ( $menus as $menu ) {
			$locations    = get_nav_menu_locations();
			$menu_locales = array();

			foreach ( $locations as $loc => $menu_id ) {
				if ( $menu_id === $menu->term_id ) {
					$menu_locales[] = $loc;
				}
			}

			$items = wp_get_nav_menu_items( $menu->term_id );

			$result[] = array(
				'id'         => $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'count'      => $menu->count,
				'locations'  => $menu_locales,
				'item_count' => is_array( $items ) ? count( $items ) : 0,
			);
		}

		return new WP_REST_Response( array( 'menus' => $result ), 200 );
	}

	public function get_menu_items( $request ) {
		$menu_id = (int) $request['id'];
		$items   = wp_get_nav_menu_items( $menu_id );

		if ( ! $items ) {
			return new WP_REST_Response( array(
				'menu_id' => $menu_id,
				'items'   => array(),
			), 200 );
		}

		$result = array();
		foreach ( $items as $item ) {
			$result[] = array(
				'id'          => $item->ID,
				'title'       => $item->title,
				'url'         => $item->url,
				'target'      => $item->target,
				'classes'     => array_filter( $item->classes ),
				'parent'      => (int) $item->menu_item_parent,
				'order'       => (int) $item->menu_order,
				'type'        => $item->type,
				'object'      => $item->object,
				'object_id'   => (int) $item->object_id,
			);
		}

		return new WP_REST_Response( array(
			'menu_id' => $menu_id,
			'items'   => $result,
		), 200 );
	}

	public function update_menu_items( $request ) {
		$menu_id = (int) $request['id'];
		$body    = $request->get_json_params();
		$items   = $body['items'] ?? array();

		if ( ! is_nav_menu( $menu_id ) ) {
			return new WP_REST_Response( array(
				'code'    => 'menu_not_found',
				'message' => "Menu {$menu_id} not found.",
			), 404 );
		}

		// Delete existing items.
		$existing = wp_get_nav_menu_items( $menu_id );
		if ( $existing ) {
			foreach ( $existing as $item ) {
				wp_delete_post( $item->ID, true );
			}
		}

		// Insert new items.
		$created = array();
		foreach ( $items as $i => $item ) {
			$args = array(
				'menu-item-title'     => $item['title'] ?? '',
				'menu-item-url'       => $item['url'] ?? '',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $i + 1,
				'menu-item-target'    => $item['target'] ?? '',
				'menu-item-classes'   => implode( ' ', $item['classes'] ?? array() ),
				'menu-item-parent-id' => $item['parent'] ?? 0,
				'menu-item-type'      => $item['type'] ?? 'custom',
			);

			if ( isset( $item['object'] ) && isset( $item['object_id'] ) ) {
				$args['menu-item-object']    = $item['object'];
				$args['menu-item-object-id'] = $item['object_id'];
				$args['menu-item-type']      = $item['type'] ?? 'post_type';
			}

			$item_id   = wp_update_nav_menu_item( $menu_id, 0, $args );
			$created[] = $item_id;
		}

		return new WP_REST_Response( array(
			'success'      => true,
			'menu_id'      => $menu_id,
			'items_count'  => count( $created ),
		), 200 );
	}

	public function create_menu( $request ) {
		$body = $request->get_json_params();
		$name = sanitize_text_field( $body['name'] ?? '' );

		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', 'Menu name is required.', array( 'status' => 400 ) );
		}

		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}

		if ( ! empty( $body['locations'] ) && is_array( $body['locations'] ) ) {
			$locations = get_theme_mod( 'nav_menu_locations', array() );
			foreach ( $body['locations'] as $loc ) {
				$locations[ sanitize_key( $loc ) ] = $menu_id;
			}
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'menu_id' => $menu_id,
			'name'    => $name,
		), 201 );
	}

	public function delete_menu( $request ) {
		$menu_id = (int) $request['id'];

		if ( ! is_nav_menu( $menu_id ) ) {
			return new WP_Error( 'menu_not_found', "Menu {$menu_id} not found.", array( 'status' => 404 ) );
		}

		$result = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $menu_id ), 200 );
	}

	public function add_menu_item( $request ) {
		$menu_id = (int) $request['id'];
		$body    = $request->get_json_params();

		if ( ! is_nav_menu( $menu_id ) ) {
			return new WP_Error( 'menu_not_found', "Menu {$menu_id} not found.", array( 'status' => 404 ) );
		}

		$args = array(
			'menu-item-title'     => sanitize_text_field( $body['title'] ?? '' ),
			'menu-item-url'       => esc_url_raw( $body['url'] ?? '' ),
			'menu-item-status'    => 'publish',
			'menu-item-position'  => intval( $body['position'] ?? 0 ),
			'menu-item-target'    => sanitize_text_field( $body['target'] ?? '' ),
			'menu-item-classes'   => implode( ' ', array_map( 'sanitize_html_class', $body['classes'] ?? array() ) ),
			'menu-item-parent-id' => intval( $body['parent'] ?? 0 ),
			'menu-item-type'      => sanitize_text_field( $body['type'] ?? 'custom' ),
		);

		if ( isset( $body['object'] ) && isset( $body['object_id'] ) ) {
			$args['menu-item-object']    = sanitize_text_field( $body['object'] );
			$args['menu-item-object-id'] = intval( $body['object_id'] );
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );

		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		return new WP_REST_Response( array(
			'success' => true,
			'item_id' => $item_id,
			'menu_id' => $menu_id,
		), 201 );
	}

	public function delete_menu_item( $request ) {
		$item_id = (int) $request['item_id'];
		$result  = wp_delete_post( $item_id, true );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', "Could not delete menu item {$item_id}.", array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $item_id ), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 5. CACHE PURGE
	// ═══════════════════════════════════════════════════════════

	public function purge_cache( $request ) {
		$body    = $request->get_json_params();
		$page_id = $body['page_id'] ?? null;
		$purged  = array();

		// Bricks CSS regeneration.
		if ( function_exists( '\Bricks\Assets::generate_css_file' ) ) {
			\Bricks\Assets::generate_css_file( $page_id ?: 0 );
			$purged[] = 'bricks_css';
		}

		// BAB-specific transients (targeted, not full object cache flush).
		delete_transient( 'bab_compiled_css_tpl_ids' );
		$purged[] = 'bab_transient_cache';

		// Bricks API Bridge transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bab_%' OR option_name LIKE '_transient_timeout_bab_%'" );
		$purged[] = 'bab_transients';

		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			if ( $page_id ) {
				\LiteSpeed\Purge::purge_post( $page_id );
			} else {
				do_action( 'litespeed_purge_all' );
			}
			$purged[] = 'litespeed';
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			if ( $page_id ) {
				wp_cache_post_change( $page_id );
			} else {
				wp_cache_clear_cache();
			}
			$purged[] = 'wp_super_cache';
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			if ( $page_id ) {
				rocket_clean_post( $page_id );
			} else {
				rocket_clean_domain();
			}
			$purged[] = 'wp_rocket';
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'w3_total_cache';
		}

		return new WP_REST_Response( array(
			'success' => true,
			'purged'  => $purged,
			'page_id' => $page_id,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 6. COMPILED CSS
	// ═══════════════════════════════════════════════════════════

	public function get_compiled_css( $request ) {
		$id = (int) $request['id'];

		$elements = $this->get_bricks_data( $id );
		if ( ! $elements ) {
			return new WP_REST_Response( array(
				'code'    => 'no_data',
				'message' => "No Bricks data found for page {$id}.",
			), 404 );
		}

		// Compile %root% selectors and collect _cssCustom.
		$css_blocks = array();
		foreach ( $elements as $el ) {
			$custom_css = $el['settings']['_cssCustom'] ?? '';
			if ( ! $custom_css ) {
				continue;
			}

			$selector = '#brxe-' . $el['id'];
			$compiled = str_replace( '%root%', $selector, $custom_css );
			$css_blocks[ $el['id'] ] = array(
				'element_type' => $el['name'],
				'selector'     => $selector,
				'css'          => $compiled,
			);
		}

		// Also get global classes used.
		$global_classes = get_option( 'bricks_global_classes', array() );
		$class_css      = array();

		foreach ( $elements as $el ) {
			$classes = $el['settings']['_cssGlobalClasses'] ?? array();
			foreach ( $classes as $class_id ) {
				foreach ( $global_classes as $gc ) {
					if ( ( $gc['id'] ?? '' ) === $class_id && ! isset( $class_css[ $class_id ] ) ) {
						$class_css[ $class_id ] = array(
							'name'     => $gc['name'] ?? $class_id,
							'settings' => $gc['settings'] ?? array(),
						);
					}
				}
			}
		}

		return new WP_REST_Response( array(
			'page_id'        => $id,
			'element_css'    => $css_blocks,
			'global_classes'  => $class_css,
			'element_count'  => count( $css_blocks ),
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 7. TEMPLATE CONDITIONS
	// ═══════════════════════════════════════════════════════════

	public function get_template_conditions( $request ) {
		$id         = (int) $request['id'];
		$conditions = get_post_meta( $id, '_bricks_template_conditions', true );
		$type       = get_post_meta( $id, '_bricks_template_type', true );

		return new WP_REST_Response( array(
			'template_id' => $id,
			'type'        => $type ?: 'section',
			'conditions'  => $conditions ?: array(),
		), 200 );
	}

	public function update_template_conditions( $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params();

		$conditions = $body['conditions'] ?? null;
		if ( ! is_array( $conditions ) ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_data',
				'message' => 'conditions array is required.',
			), 400 );
		}

		update_post_meta( $id, '_bricks_template_conditions', $conditions );

		// Also update type if provided.
		if ( isset( $body['type'] ) ) {
			update_post_meta( $id, '_bricks_template_type', sanitize_text_field( $body['type'] ) );
		}

		return new WP_REST_Response( array(
			'success'     => true,
			'template_id' => $id,
			'conditions'  => $conditions,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 8. SERVER-SIDE VALIDATION
	// ═══════════════════════════════════════════════════════════

	public function validate_elements( $request ) {
		$body     = $request->get_json_params();
		$elements = $body['elements'] ?? array();

		if ( empty( $elements ) ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_data',
				'message' => 'elements array is required.',
			), 400 );
		}

		$errors   = array();
		$warnings = array();
		$ids_seen = array();

		foreach ( $elements as $i => $el ) {
			$id = $el['id'] ?? null;

			// ID validation.
			if ( ! $id ) {
				$errors[] = "Element {$i}: missing id";
			} elseif ( ! preg_match( '/^[a-z0-9]{6}$/', $id ) && ! preg_match( '/^[a-z]{2,4}[a-z0-9]{2,4}$/', $id ) ) {
				$errors[] = "Element {$id}: invalid ID format (must be 6 chars, lowercase alphanumeric, at least 1 digit)";
			}

			// Duplicate ID check.
			if ( $id && in_array( $id, $ids_seen, true ) ) {
				$errors[] = "Element {$id}: duplicate ID";
			}
			$ids_seen[] = $id;

			// Name validation.
			$name = $el['name'] ?? '';
			if ( $name === 'div' ) {
				$errors[] = "Element {$id}: 'div' is not a valid Bricks element name — use 'block'";
			}
			if ( ! $name ) {
				$errors[] = "Element {$id}: missing name";
			}

			// Children array required.
			if ( ! isset( $el['children'] ) || ! is_array( $el['children'] ) ) {
				$errors[] = "Element {$id}: missing children array (use [] for leaf nodes)";
			}

			// Parent validation.
			if ( ! isset( $el['parent'] ) ) {
				$warnings[] = "Element {$id}: no parent set (use 0 for root sections)";
			}

			// line-height check.
			$lh = $el['settings']['_typography']['line-height'] ?? null;
			if ( $lh && preg_match( '/px$/i', $lh ) ) {
				$warnings[] = "Element {$id}: line-height '{$lh}' uses px — Bricks strips units, use unitless ratio instead";
			}

			// _display: grid check.
			$display = $el['settings']['_display'] ?? '';
			if ( $display === 'grid' ) {
				$warnings[] = "Element {$id}: _display='grid' may cause brx-grid bugs — use _cssCustom instead";
			}
		}

		// Parent/child consistency.
		$all_ids = array_map( function ( $el ) {
			return $el['id'] ?? '';
		}, $elements );

		foreach ( $elements as $el ) {
			$parent = $el['parent'] ?? 0;
			if ( $parent !== 0 && ! in_array( (string) $parent, $all_ids, true ) ) {
				$errors[] = "Element {$el['id']}: parent '{$parent}' not found in element list";
			}
			foreach ( $el['children'] ?? array() as $child_id ) {
				if ( ! in_array( $child_id, $all_ids, true ) ) {
					$errors[] = "Element {$el['id']}: child '{$child_id}' not found in element list";
				}
			}
		}

		$valid = empty( $errors );

		return new WP_REST_Response( array(
			'valid'         => $valid,
			'element_count' => count( $elements ),
			'errors'        => $errors,
			'warnings'      => $warnings,
			'error_count'   => count( $errors ),
			'warning_count' => count( $warnings ),
		), $valid ? 200 : 422 );
	}

	// ═══════════════════════════════════════════════════════════
	// 9. SITE STATS
	// ═══════════════════════════════════════════════════════════

	public function get_stats() {
		global $wpdb;

		// Count pages with Bricks data.
		$bricks_pages = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bricks_page_content_2'"
		);

		// Count templates.
		$templates = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'bricks_template' AND post_status = 'publish'"
		);

		// Total elements across all pages (cached for 10 min — expensive full-table deserialization).
		$total_elements = get_transient( 'bab_stats_total_elements' );
		if ( false === $total_elements ) {
			$total_elements = 0;
			$meta_rows      = $wpdb->get_results(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bricks_page_content_2'",
				ARRAY_A
			);
			foreach ( $meta_rows as $row ) {
				$data = maybe_unserialize( $row['meta_value'] );
				if ( is_array( $data ) ) {
					$total_elements += count( $data );
				}
			}
			set_transient( 'bab_stats_total_elements', $total_elements, 600 );
		}

		// Global classes.
		$global_classes = get_option( 'bricks_global_classes', array() );
		$class_count    = is_array( $global_classes ) ? count( $global_classes ) : 0;

		// Fonts.
		$fonts      = get_option( 'bricks_custom_fonts', array() );
		$font_count = is_array( $fonts ) ? count( $fonts ) : 0;

		// CSS Variables.
		$css_vars      = get_option( 'bricks_global_variables', array() );
		$css_var_count = is_array( $css_vars ) ? count( $css_vars ) : 0;

		// Colors.
		$palette      = get_option( 'bricks_color_palette', array() );
		$color_count  = is_array( $palette ) ? count( $palette ) : 0;

		// Presets.
		$presets      = get_option( 'bab_section_presets', array() );
		$preset_count = is_array( $presets ) ? count( $presets ) : 0;

		// Media.
		$media_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// Active plugins.
		$active_plugins = get_option( 'active_plugins', array() );

		return new WP_REST_Response( array(
			'pages'          => (int) $bricks_pages,
			'templates'      => (int) $templates,
			'total_elements' => $total_elements,
			'global_classes' => $class_count,
			'fonts'          => $font_count,
			'css_variables'  => $css_var_count,
			'colors'         => $color_count,
			'presets'        => $preset_count,
			'media'          => (int) $media_count,
			'active_plugins' => count( $active_plugins ),
			'bab_version'    => BRICKS_API_BRIDGE_VERSION,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 10. PAGE DEPENDENCIES
	// ═══════════════════════════════════════════════════════════

	public function get_page_dependencies( $request ) {
		$id = (int) $request['id'];

		$elements = $this->get_bricks_data( $id );
		if ( ! $elements ) {
			return new WP_REST_Response( array( 'code' => 'no_data' ), 404 );
		}

		$fonts          = array();
		$global_classes = array();
		$images         = array();
		$css_vars_used  = array();
		$has_gsap       = false;
		$has_custom_css = false;

		foreach ( $elements as $el ) {
			$settings = $el['settings'] ?? array();

			// Fonts.
			$font = $settings['_typography']['font-family'] ?? '';
			if ( $font ) {
				$fonts[ $font ] = ( $fonts[ $font ] ?? 0 ) + 1;
			}

			// Global classes.
			foreach ( $settings['_cssGlobalClasses'] ?? array() as $class_id ) {
				$global_classes[ $class_id ] = ( $global_classes[ $class_id ] ?? 0 ) + 1;
			}

			// Images.
			if ( $el['name'] === 'image' && isset( $settings['image']['id'] ) ) {
				$images[] = (int) $settings['image']['id'];
			}

			// CSS custom.
			$custom_css = $settings['_cssCustom'] ?? '';
			if ( $custom_css ) {
				$has_custom_css = true;
				// Extract var() references.
				if ( preg_match_all( '/var\(\s*--([a-zA-Z0-9_-]+)/', $custom_css, $m ) ) {
					foreach ( $m[1] as $var_name ) {
						$css_vars_used[ $var_name ] = ( $css_vars_used[ $var_name ] ?? 0 ) + 1;
					}
				}
			}

			// Color var() references in settings.
			$color_raw = $settings['_background']['color']['raw'] ?? '';
			if ( $color_raw && preg_match( '/var\(\s*--([a-zA-Z0-9_-]+)/', $color_raw, $m ) ) {
				$css_vars_used[ $m[1] ] = ( $css_vars_used[ $m[1] ] ?? 0 ) + 1;
			}
			$text_color = $settings['_typography']['color']['raw'] ?? '';
			if ( $text_color && preg_match( '/var\(\s*--([a-zA-Z0-9_-]+)/', $text_color, $m ) ) {
				$css_vars_used[ $m[1] ] = ( $css_vars_used[ $m[1] ] ?? 0 ) + 1;
			}
		}

		// Check per-page scripts for GSAP.
		$scripts = get_post_meta( $id, '_bab_footer_scripts', true );
		if ( $scripts && ( strpos( $scripts, 'gsap' ) !== false || strpos( $scripts, 'ScrollTrigger' ) !== false ) ) {
			$has_gsap = true;
		}
		$gsap_flag = get_post_meta( $id, '_bab_needs_gsap', true );
		if ( $gsap_flag ) {
			$has_gsap = true;
		}

		return new WP_REST_Response( array(
			'page_id'        => $id,
			'element_count'  => count( $elements ),
			'fonts'          => $fonts,
			'global_classes' => $global_classes,
			'images'         => array_unique( $images ),
			'css_variables'  => $css_vars_used,
			'has_custom_css' => $has_custom_css,
			'has_gsap'       => $has_gsap,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 11. BULK EXPORT
	// ═══════════════════════════════════════════════════════════

	public function bulk_export( $request ) {
		$body = $request->get_json_params();

		$include_pages     = $body['include_pages'] ?? true;
		$include_templates = $body['include_templates'] ?? true;
		$include_globals   = $body['include_globals'] ?? true;
		$page_ids          = $body['page_ids'] ?? null; // null = all.

		$export = array(
			'version'   => BRICKS_API_BRIDGE_VERSION,
			'site_url'  => get_site_url(),
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		// Pages.
		if ( $include_pages && class_exists( '\Bricks\Database' ) ) {
			$args = array(
				'post_type'      => 'page',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_key'       => '_bricks_page_content_2',
			);

			if ( $page_ids ) {
				$args['post__in'] = array_map( 'absint', $page_ids );
			}

			$pages     = get_posts( $args );
			$page_data = array();

			foreach ( $pages as $page ) {
				$elements = $this->get_bricks_data( $page->ID );
				$scripts  = get_post_meta( $page->ID, '_bab_footer_scripts', true );
				$assets   = get_post_meta( $page->ID, '_bab_page_assets', true );

				$page_data[] = array(
					'id'       => $page->ID,
					'title'    => $page->post_title,
					'slug'     => $page->post_name,
					'status'   => $page->post_status,
					'elements' => $elements ?: array(),
					'scripts'  => $scripts ?: '',
					'assets'   => $assets ?: null,
				);
			}

			$export['pages'] = $page_data;
		}

		// Templates.
		if ( $include_templates && class_exists( '\Bricks\Database' ) ) {
			$templates = get_posts( array(
				'post_type'      => 'bricks_template',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			));

			$tmpl_data = array();
			foreach ( $templates as $tmpl ) {
				$elements   = $this->get_bricks_data( $tmpl->ID );
				$type       = get_post_meta( $tmpl->ID, '_bricks_template_type', true );
				$conditions = get_post_meta( $tmpl->ID, '_bricks_template_conditions', true );

				$tmpl_data[] = array(
					'id'         => $tmpl->ID,
					'title'      => $tmpl->post_title,
					'type'       => $type ?: 'section',
					'conditions' => $conditions ?: array(),
					'elements'   => $elements ?: array(),
				);
			}

			$export['templates'] = $tmpl_data;
		}

		// Globals.
		if ( $include_globals ) {
			$export['globals'] = array(
				'theme_styles'    => get_option( 'bricks_theme_styles', array() ),
				'global_classes'  => get_option( 'bricks_global_classes', array() ),
				'color_palette'   => get_option( 'bricks_color_palette', array() ),
				'fonts'           => get_option( 'bricks_custom_fonts', array() ),
				'css_variables'   => get_option( 'bricks_global_variables', array() ),
				'global_css'      => get_option( 'bricks_global_custom_css', '' ),
				'global_settings' => get_option( 'bricks_global_settings', array() ),
			);
		}

		return new WP_REST_Response( $export, 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 12. PER-ELEMENT CSS
	// ═══════════════════════════════════════════════════════════

	public function get_element_css( $request ) {
		$page_id    = (int) $request['id'];
		$element_id = $request['element_id'];

		if ( ! class_exists( '\Bricks\Database' ) ) {
			return new WP_REST_Response( array( 'code' => 'bricks_not_active' ), 500 );
		}

		$elements = $this->get_bricks_data( $page_id );
		$target   = null;

		foreach ( $elements ?: array() as $el ) {
			if ( $el['id'] === $element_id ) {
				$target = $el;
				break;
			}
		}

		if ( ! $target ) {
			return new WP_REST_Response( array(
				'code'    => 'element_not_found',
				'message' => "Element '{$element_id}' not found on page {$page_id}.",
			), 404 );
		}

		$selector   = '#brxe-' . $element_id;
		$custom_css = $target['settings']['_cssCustom'] ?? '';
		$compiled   = $custom_css ? str_replace( '%root%', $selector, $custom_css ) : '';

		// Extract Bricks settings that generate CSS.
		$style_settings = array();
		$setting_keys   = array( '_typography', '_background', '_padding', '_margin', '_border',
			'_width', '_height', '_display', '_direction', '_alignItems', '_justifyContent', '_gap',
			'_position', '_top', '_right', '_bottom', '_left', '_zIndex', '_opacity', '_overflow',
		);

		foreach ( $setting_keys as $key ) {
			if ( isset( $target['settings'][ $key ] ) ) {
				$style_settings[ $key ] = $target['settings'][ $key ];
			}
		}

		// Global classes.
		$classes        = $target['settings']['_cssGlobalClasses'] ?? array();
		$global_classes = get_option( 'bricks_global_classes', array() );
		$applied        = array();

		foreach ( $classes as $class_id ) {
			foreach ( $global_classes as $gc ) {
				if ( ( $gc['id'] ?? '' ) === $class_id ) {
					$applied[] = array(
						'id'       => $class_id,
						'name'     => $gc['name'] ?? $class_id,
						'settings' => $gc['settings'] ?? array(),
					);
				}
			}
		}

		return new WP_REST_Response( array(
			'page_id'        => $page_id,
			'element_id'     => $element_id,
			'element_type'   => $target['name'],
			'selector'       => $selector,
			'compiled_css'   => $compiled,
			'style_settings' => $style_settings,
			'global_classes'  => $applied,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 13. EDIT LOCK
	// ═══════════════════════════════════════════════════════════

	public function lock_page( $request ) {
		$id   = (int) $request['id'];
		$user = get_current_user_id();
		$lock = time() . ':' . $user;

		update_post_meta( $id, '_edit_lock', $lock );

		return new WP_REST_Response( array(
			'success' => true,
			'page_id' => $id,
			'locked_by' => $user,
			'locked_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		), 200 );
	}

	public function unlock_page( $request ) {
		$id = (int) $request['id'];
		delete_post_meta( $id, '_edit_lock' );

		return new WP_REST_Response( array(
			'success' => true,
			'page_id' => $id,
			'locked'  => false,
		), 200 );
	}

	public function get_lock_status( $request ) {
		$id   = (int) $request['id'];
		$lock = get_post_meta( $id, '_edit_lock', true );

		if ( ! $lock ) {
			return new WP_REST_Response( array(
				'page_id' => $id,
				'locked'  => false,
			), 200 );
		}

		$parts     = explode( ':', $lock );
		$lock_time = (int) $parts[0];
		$lock_user = (int) ( $parts[1] ?? 0 );
		$user_data = get_userdata( $lock_user );

		// WP considers locks older than 150 seconds as stale.
		$is_stale = ( time() - $lock_time ) > 150;

		return new WP_REST_Response( array(
			'page_id'   => $id,
			'locked'    => ! $is_stale,
			'stale'     => $is_stale,
			'user_id'   => $lock_user,
			'user_name' => $user_data ? $user_data->display_name : 'Unknown',
			'locked_at' => gmdate( 'Y-m-d\TH:i:s\Z', $lock_time ),
			'age_seconds' => time() - $lock_time,
		), 200 );
	}

	// ═══════════════════════════════════════════════════════════
	// 14. POST TYPES
	// ═══════════════════════════════════════════════════════════

	public function get_post_types() {
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		$result = array();

		foreach ( $types as $type ) {
			$result[] = array(
				'name'       => $type->name,
				'label'      => $type->label,
				'rest_base'  => $type->rest_base ?: $type->name,
				'has_bricks' => in_array( $type->name, array( 'page', 'post' ), true ) ||
					get_option( "bricks_post_type_{$type->name}", false ),
				'hierarchical' => $type->hierarchical,
				'count'      => wp_count_posts( $type->name )->publish ?? 0,
			);
		}

		return new WP_REST_Response( array( 'post_types' => $result ), 200 );
	}


	// ═══════════════════════════════════════════════════════════
	// 17. PERFORMANCE HISTORY
	// ═══════════════════════════════════════════════════════════

	const PERF_HISTORY_OPTION = '_bab_perf_history';
	const PERF_HISTORY_MAX    = 30;

	/**
	 * GET /performance-history — read stored performance sweep entries.
	 */
	public function get_performance_history() {
		$history = get_option( self::PERF_HISTORY_OPTION, array() );

		return new WP_REST_Response( array(
			'entries' => is_array( $history ) ? $history : array(),
			'count'   => is_array( $history ) ? count( $history ) : 0,
		), 200 );
	}

	/**
	 * PUT /performance-history — append a new performance sweep entry, cap at 30.
	 */
	public function update_performance_history( $request ) {
		$body  = $request->get_json_params();
		$entry = $body['entry'] ?? null;

		if ( empty( $entry ) || ! is_array( $entry ) ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_data',
				'message' => 'entry object is required with date, viewport, and pages array.',
			), 400 );
		}

		$history = get_option( self::PERF_HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = $entry;

		// Cap at max entries (keep most recent).
		if ( count( $history ) > self::PERF_HISTORY_MAX ) {
			$history = array_slice( $history, -self::PERF_HISTORY_MAX );
		}

		update_option( self::PERF_HISTORY_OPTION, $history, false );

		return new WP_REST_Response( array(
			'success' => true,
			'count'   => count( $history ),
		), 200 );
	}

	// HELPERS
	// ═══════════════════════════════════════════════════════════

	/**
	 * Get Bricks elements for a post, with fallback to raw meta.
	 */
	private function get_bricks_data( $post_id ) {
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$data = \Bricks\Database::get_data( $post_id, 'content' );
			if ( ! empty( $data ) ) {
				return $data;
			}
		}
		$data = get_post_meta( $post_id, '_bricks_page_content_2', true );
		return ! empty( $data ) && is_array( $data ) ? $data : null;
	}

	public function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Standardized error response helper.
	 * Use for new endpoints to maintain consistent error format.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new \WP_Error( 'bab_' . $code, $message, array( 'status' => $status ) );
	}
}
