<?php
/**
 * Cross-page element search for Bricks Builder data.
 *
 * Searches elements across all published Bricks pages/posts
 * by text content, element type, CSS class, or setting values.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Element_Search
 */
class Bricks_API_Bridge_Element_Search {

	/**
	 * Settings keys that contain searchable text content.
	 *
	 * @var string[]
	 */
	private static $text_keys = array( 'text', 'content', 'tag', 'label', 'title', 'subtitle', 'placeholder' );

	/**
	 * Search elements across all Bricks pages.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( $request ) {
		$q             = sanitize_text_field( $request->get_param( 'q' ) );
		$element_type  = sanitize_text_field( $request->get_param( 'element_type' ) );
		$css_class     = sanitize_text_field( $request->get_param( 'css_class' ) );
		$setting_key   = sanitize_text_field( $request->get_param( 'setting_key' ) );
		$setting_value = sanitize_text_field( $request->get_param( 'setting_value' ) );
		$post_type     = sanitize_text_field( $request->get_param( 'post_type' ) );
		$limit         = (int) $request->get_param( 'limit' );
		$offset        = (int) $request->get_param( 'offset' );

		if ( empty( $post_type ) ) {
			$post_type = 'page';
		}
		if ( $limit < 1 || $limit > 200 ) {
			$limit = 50;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		// Must have at least one search criterion.
		if ( empty( $q ) && empty( $element_type ) && empty( $css_class ) && empty( $setting_key ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_query',
				__( 'At least one search parameter is required: q, element_type, css_class, or setting_key.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Pagination for post query (limit how many posts we scan).
		$per_page_posts = (int) $request->get_param( 'per_page_posts' );
		if ( $per_page_posts < 1 || $per_page_posts > 500 ) {
			$per_page_posts = 100; // Scan max 100 posts per request (was unlimited).
		}
		$page_num = (int) $request->get_param( 'page' );
		if ( $page_num < 1 ) {
			$page_num = 1;
		}

		// Query published posts with Bricks data.
		$use_bricks_db = class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' );
		$meta_keys     = array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_page_data' );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page_posts,
			'paged'          => $page_num,
			'fields'         => 'ids',
		);

		if ( ! $use_bricks_db ) {
			$meta_sub_queries = array( 'relation' => 'OR' );
			foreach ( $meta_keys as $key ) {
				$meta_sub_queries[] = array(
					'key'     => $key,
					'compare' => 'EXISTS',
				);
			}
			$args['meta_query'] = $meta_sub_queries; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$post_ids = get_posts( $args );

		if ( empty( $post_ids ) ) {
			return rest_ensure_response( array(
				'results' => array(),
				'total'   => 0,
				'query'   => $q ?: $element_type ?: $css_class ?: $setting_key,
			) );
		}

		// Prime meta cache in one query.
		update_meta_cache( 'post', $post_ids );

		$all_matches = array();

		foreach ( $post_ids as $pid ) {
			$bricks_data = null;
			if ( $use_bricks_db ) {
				$bricks_data = \Bricks\Database::get_data( $pid, 'content' );
			}
			if ( empty( $bricks_data ) ) {
				foreach ( $meta_keys as $key ) {
					$meta = get_post_meta( $pid, $key, true );
					if ( ! empty( $meta ) ) {
						$bricks_data = $meta;
						break;
					}
				}
			}

			if ( empty( $bricks_data ) || ! is_array( $bricks_data ) ) {
				continue;
			}

			$page_title = get_the_title( $pid );

			foreach ( $bricks_data as $element ) {
				$match = $this->match_element( $element, $q, $element_type, $css_class, $setting_key, $setting_value );

				if ( $match ) {
					$all_matches[] = array(
						'post_id'       => $pid,
						'page_title'    => $page_title,
						'element_id'    => $element['id'],
						'element_name'  => $element['name'],
						'matched_field' => $match['field'],
						'matched_value' => $match['value'],
						'parent_id'     => isset( $element['parent'] ) ? $element['parent'] : 0,
					);
				}
			}
		}

		$total   = count( $all_matches );
		$results = array_slice( $all_matches, $offset, $limit );

		return rest_ensure_response( array(
			'results' => $results,
			'total'   => $total,
			'offset'  => $offset,
			'limit'   => $limit,
			'query'   => $q ?: $element_type ?: $css_class ?: $setting_key,
		) );
	}

	/**
	 * Check if an element matches the search criteria.
	 *
	 * @param array  $element       The Bricks element.
	 * @param string $q             Free text search.
	 * @param string $element_type  Element type filter.
	 * @param string $css_class     CSS class filter.
	 * @param string $setting_key   Setting key filter.
	 * @param string $setting_value Setting value filter.
	 * @return array|false Match info or false.
	 */
	private function match_element( $element, $q, $element_type, $css_class, $setting_key, $setting_value ) {
		$settings = isset( $element['settings'] ) ? $element['settings'] : array();

		// Filter by element type (must match if specified).
		if ( ! empty( $element_type ) ) {
			if ( $element['name'] !== $element_type ) {
				return false;
			}
			// If only element_type is specified (no other criteria), it's a match.
			if ( empty( $q ) && empty( $css_class ) && empty( $setting_key ) ) {
				return array( 'field' => 'type', 'value' => $element['name'] );
			}
		}

		// Filter by CSS class.
		if ( ! empty( $css_class ) ) {
			$classes = isset( $settings['_cssGlobalClasses'] ) ? $settings['_cssGlobalClasses'] : array();
			$custom  = isset( $settings['_cssClasses'] ) ? $settings['_cssClasses'] : '';

			$all_classes = is_array( $classes ) ? $classes : array();
			if ( is_string( $custom ) && ! empty( $custom ) ) {
				$all_classes = array_merge( $all_classes, explode( ' ', $custom ) );
			}

			$found = false;
			foreach ( $all_classes as $cls ) {
				if ( stripos( $cls, $css_class ) !== false ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}

			if ( empty( $q ) && empty( $setting_key ) ) {
				return array( 'field' => 'css_class', 'value' => implode( ', ', $all_classes ) );
			}
		}

		// Filter by specific setting key/value.
		if ( ! empty( $setting_key ) ) {
			if ( ! isset( $settings[ $setting_key ] ) ) {
				return false;
			}

			$val = $settings[ $setting_key ];
			$val_str = is_array( $val ) ? wp_json_encode( $val ) : (string) $val;

			if ( ! empty( $setting_value ) ) {
				if ( stripos( $val_str, $setting_value ) === false ) {
					return false;
				}
			}

			return array( 'field' => $setting_key, 'value' => mb_substr( $val_str, 0, 200 ) );
		}

		// Free text search across text-content settings.
		if ( ! empty( $q ) ) {
			foreach ( self::$text_keys as $text_key ) {
				if ( ! empty( $settings[ $text_key ] ) ) {
					$haystack = is_string( $settings[ $text_key ] ) ? $settings[ $text_key ] : wp_json_encode( $settings[ $text_key ] );
					if ( stripos( $haystack, $q ) !== false ) {
						return array( 'field' => $text_key, 'value' => mb_substr( wp_strip_all_tags( $haystack ), 0, 200 ) );
					}
				}
			}
		}

		return false;
	}
}
