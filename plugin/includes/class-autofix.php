<?php
/**
 * Bricks data auto-fixer.
 *
 * Repairs common structural issues in Bricks element arrays before validation.
 * Mirrors the logic in bricks-mcp/utils/autofix.js — keep both in sync.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Autofix
 */
class Bricks_API_Bridge_Autofix {

	/**
	 * Keys whose string values should never have "px" stripped.
	 *
	 * @var string[]
	 */
	private static $px_safe_keys = array(
		'content', '_cssCustom', 'url', 'name', 'label', 'tag',
		'link', 'href', 'src', 'alt', 'placeholder', 'icon', 'class',
		'text', 'title', 'description',
	);

	/**
	 * Flex-related settings that indicate a block should be a container.
	 *
	 * @var string[]
	 */
	private static $flex_properties = array(
		'_direction', '_alignItems', '_justifyContent', '_gap',
		'_flexWrap', '_alignContent',
	);

	/**
	 * Run all 9 fix passes on a Bricks content array.
	 *
	 * @param array $content Array of Bricks elements.
	 * @return array{content: array, log: string[], fixed: bool}
	 */
	public static function autofix( $content ) {
		$log = array();

		if ( ! is_array( $content ) || empty( $content ) ) {
			return array(
				'content' => $content,
				'log'     => array(),
				'fixed'   => false,
			);
		}

		// Collect existing IDs.
		$existing_ids = array();
		foreach ( $content as $el ) {
			if ( is_array( $el ) && ! empty( $el['id'] ) ) {
				$existing_ids[ $el['id'] ] = true;
			}
		}

		// === Pass 1: Strip bare px values ===
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) || empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				continue;
			}
			$el['settings'] = self::strip_px_values( $el['settings'], '', $log, isset( $el['id'] ) ? $el['id'] : '?' );
		}
		unset( $el );

		// === Pass 2: Rename "div" → "block" ===
		foreach ( $content as &$el ) {
			if ( is_array( $el ) && isset( $el['name'] ) && 'div' === $el['name'] ) {
				$log[] = sprintf( 'Renamed "div" → "block" on element %s', isset( $el['id'] ) ? $el['id'] : '?' );
				$el['name'] = 'block';
			}
		}
		unset( $el );

		// === Pass 3: Fix missing/invalid IDs ===
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( empty( $el['id'] ) || ! self::is_acceptable_bricks_id( $el['id'] ) ) {
				$old_id = isset( $el['id'] ) ? $el['id'] : null;
				$new_id = self::generate_bricks_id( $existing_ids );
				// Update references.
				if ( $old_id ) {
					foreach ( $content as &$other ) {
						if ( ! is_array( $other ) ) {
							continue;
						}
						if ( isset( $other['parent'] ) && $other['parent'] === $old_id ) {
							$other['parent'] = $new_id;
						}
						if ( ! empty( $other['children'] ) && is_array( $other['children'] ) ) {
							$idx = array_search( $old_id, $other['children'], true );
							if ( false !== $idx ) {
								$other['children'][ $idx ] = $new_id;
							}
						}
					}
					unset( $other );
				}
				$log[] = sprintf( 'Fixed ID: "%s" → "%s"', $old_id ? $old_id : '(missing)', $new_id );
				$el['id'] = $new_id;
			}
		}
		unset( $el );

		// === Pass 4: Ensure settings object exists ===
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$log[]          = sprintf( 'Added missing settings on element %s', $el['id'] );
				$el['settings'] = array();
			}
		}
		unset( $el );

		// === Pass 5: Promote block → container when flex properties present ===
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) || ! isset( $el['name'] ) || 'block' !== $el['name'] ) {
				continue;
			}
			$has_flex = false;
			foreach ( self::$flex_properties as $prop ) {
				if ( isset( $el['settings'][ $prop ] ) ) {
					$has_flex = true;
					break;
				}
			}
			if ( $has_flex ) {
				$log[]      = sprintf( 'Promoted "block" → "container" on element %s (has flex properties)', $el['id'] );
				$el['name'] = 'container';
			}
		}
		unset( $el );

		// === Pass 6: Force root sections parent: 0 (integer) ===
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) || ! isset( $el['name'] ) || 'section' !== $el['name'] ) {
				continue;
			}
			if ( ! isset( $el['parent'] ) || null === $el['parent'] || '0' === $el['parent'] || '' === $el['parent'] || 0 === $el['parent'] ) {
				if ( ! isset( $el['parent'] ) || 0 !== $el['parent'] ) {
					$log[]       = sprintf( 'Fixed parent: %s → 0 (integer) on section %s', wp_json_encode( isset( $el['parent'] ) ? $el['parent'] : null ), $el['id'] );
					$el['parent'] = 0;
				}
			}
		}
		unset( $el );

		// === Pass 7: Ensure every element has children array ===
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! isset( $el['children'] ) || ! is_array( $el['children'] ) ) {
				$log[]          = sprintf( 'Added missing children[] on element %s', $el['id'] );
				$el['children'] = array();
			}
		}
		unset( $el );

		// === Pass 8: Rebuild children arrays from parent refs ===
		$parent_to_children = array();
		foreach ( $content as $el ) {
			if ( ! is_array( $el ) || empty( $el['parent'] ) || 0 === $el['parent'] ) {
				continue;
			}
			$pid = $el['parent'];
			if ( ! isset( $parent_to_children[ $pid ] ) ) {
				$parent_to_children[ $pid ] = array();
			}
			$parent_to_children[ $pid ][] = $el['id'];
		}
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$children_from_refs = isset( $parent_to_children[ $el['id'] ] ) ? $parent_to_children[ $el['id'] ] : array();
			$ref_set            = array_flip( $children_from_refs );
			$existing_children  = $el['children'];
			// Keep existing order for children still referenced.
			$merged = array_values( array_filter( $existing_children, function ( $cid ) use ( $ref_set ) {
				return isset( $ref_set[ $cid ] );
			} ) );
			// Add new children not in existing array.
			foreach ( $children_from_refs as $cid ) {
				if ( ! in_array( $cid, $merged, true ) ) {
					$merged[] = $cid;
				}
			}
			if ( $merged !== $existing_children ) {
				$log[]          = sprintf( 'Rebuilt children[] on element %s: [%s] → [%s]', $el['id'], implode( ',', $existing_children ), implode( ',', $merged ) );
				$el['children'] = $merged;
			}
		}
		unset( $el );

		// === Pass 9: Add spacer to empty containers ===
		$spacers = array();
		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) || 'container' !== ( $el['name'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $el['children'] ) ) {
				continue;
			}
			// Check if any element claims this as parent.
			$has_child_ref = false;
			foreach ( $content as $other ) {
				if ( is_array( $other ) && isset( $other['parent'] ) && $other['parent'] === $el['id'] ) {
					$has_child_ref = true;
					break;
				}
			}
			if ( ! $has_child_ref ) {
				$spacer_id    = self::generate_bricks_id( $existing_ids );
				$spacers[]    = array(
					'id'       => $spacer_id,
					'name'     => 'text-basic',
					'parent'   => $el['id'],
					'children' => array(),
					'settings' => array(
						'text'       => '&nbsp;',
						'_cssCustom' => '%root% { position: absolute; opacity: 0; pointer-events: none; }',
					),
					'label'    => 'Spacer',
				);
				$el['children'][] = $spacer_id;
				$log[]            = sprintf( 'Added spacer child %s to empty container %s', $spacer_id, $el['id'] );
			}
		}
		unset( $el );
		$content = array_merge( $content, $spacers );

		return array(
			'content' => array_values( $content ),
			'log'     => $log,
			'fixed'   => ! empty( $log ),
		);
	}

	/**
	 * Lenient ID check for autofix — accepts any lowercase alphanumeric string (3-12 chars)
	 * with at least one digit. Many existing presets use 5-char or 7-char IDs.
	 *
	 * @param mixed $id The ID to check.
	 * @return bool
	 */
	public static function is_acceptable_bricks_id( $id ) {
		if ( ! is_string( $id ) ) {
			return false;
		}
		$len = strlen( $id );
		if ( $len < 3 || $len > 12 ) {
			return false;
		}
		if ( ! ctype_alnum( $id ) || $id !== strtolower( $id ) ) {
			return false;
		}
		if ( ! preg_match( '/[0-9]/', $id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Generate a random 6-char lowercase alphanumeric ID with at least one digit.
	 *
	 * @param array $existing_ids Reference to existing IDs map (keys are IDs).
	 * @return string
	 */
	public static function generate_bricks_id( &$existing_ids ) {
		$chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$digits = '0123456789';
		$attempts = 0;
		do {
			$id = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$id .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
			}
			// Ensure at least one digit.
			if ( ! preg_match( '/[0-9]/', $id ) ) {
				$pos = wp_rand( 0, 5 );
				$id  = substr( $id, 0, $pos ) . $digits[ wp_rand( 0, 9 ) ] . substr( $id, $pos + 1 );
			}
			$attempts++;
		} while ( isset( $existing_ids[ $id ] ) && $attempts < 100 );
		$existing_ids[ $id ] = true;
		return $id;
	}

	/**
	 * Recursively strip bare "px" values from settings.
	 *
	 * @param mixed  $value      The value to process.
	 * @param string $current_key Current key name for safe-key checks.
	 * @param array  $log         Log array (passed by reference).
	 * @param string $element_id  Element ID for logging.
	 * @return mixed
	 */
	private static function strip_px_values( $value, $current_key, &$log, $element_id ) {
		if ( is_null( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			if ( ! in_array( $current_key, self::$px_safe_keys, true ) && preg_match( '/^\d+px$/', $value ) ) {
				$fixed = preg_replace( '/px$/', '', $value );
				$log[] = sprintf( 'Stripped px: "%s" → "%s" on element %s, key "%s"', $value, $fixed, $element_id, $current_key );
				return $fixed;
			}
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::strip_px_values( $v, is_string( $k ) ? $k : $current_key, $log, $element_id );
			}
			return $value;
		}

		return $value;
	}
}
