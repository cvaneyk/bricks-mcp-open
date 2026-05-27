<?php
/**
 * Responsive Inference Engine for Bricks Builder elements.
 *
 * Automatically generates tablet and mobile responsive overrides based on
 * deterministic rules learned from 12+ website rebuilds. Applies typography
 * scaling, padding/margin reduction, grid column simplification, flex
 * direction changes, gap scaling, and container side-padding fixes.
 *
 * Called after autofix, before validation. Does NOT overwrite existing
 * responsive overrides — manual settings are always preserved.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Responsive_Inference
 */
class Bricks_API_Bridge_Responsive_Inference {

	// ──────────────────────────────────────────────
	//  Typography scaling table: desktop font-size → multiplier
	//  [min, max, tablet_factor, mobile_factor]
	// ──────────────────────────────────────────────
	private static $typography_scale = array(
		array( 120, PHP_INT_MAX, 0.55, 0.38 ),
		array(  80,         120, 0.60, 0.42 ),
		array(  48,          80, 0.70, 0.50 ),
		array(  32,          48, 0.80, 0.65 ),
		array(  24,          32, 0.90, 0.80 ),
	);

	// ──────────────────────────────────────────────
	//  Padding / margin scaling table
	//  [min, max, tablet_factor, mobile_factor, mobile_floor]
	// ──────────────────────────────────────────────
	private static $spacing_scale = array(
		array( 80, PHP_INT_MAX, 0.50, 0.25, 16 ),
		array( 40,          80, 0.65, 0.40, 16 ),
		array( 20,          40, 0.80, 0.70,  0 ),
	);

	// ──────────────────────────────────────────────
	//  Gap scaling table
	//  [min, max, tablet_factor, mobile_value_or_null]
	// ──────────────────────────────────────────────
	private static $gap_scale = array(
		array( 48, PHP_INT_MAX, 0.60, 32 ),
		array( 24,          48, 0.75, 16 ),
	);

	/**
	 * Run responsive inference on a Bricks content array.
	 *
	 * @param array $content Array of Bricks elements.
	 * @return array{content: array, log: string[], changed: bool}
	 */
	public static function infer( $content ) {
		$log = array();

		if ( ! is_array( $content ) || empty( $content ) ) {
			return array(
				'content' => $content,
				'log'     => array(),
				'changed' => false,
			);
		}

		// Build a children-count map so we can decide flex→column.
		$children_count = array();
		foreach ( $content as $el ) {
			if ( ! is_array( $el ) || empty( $el['parent'] ) || 0 === $el['parent'] ) {
				continue;
			}
			$pid = $el['parent'];
			if ( ! isset( $children_count[ $pid ] ) ) {
				$children_count[ $pid ] = 0;
			}
			$children_count[ $pid ]++;
		}

		foreach ( $content as &$el ) {
			if ( ! is_array( $el ) || empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				continue;
			}

			$id = isset( $el['id'] ) ? $el['id'] : '?';

			// --- Typography: font-size ---
			self::infer_typography( $el, $id, $log );

			// --- Letter spacing ---
			self::infer_letter_spacing( $el, $id, $log );

			// --- Padding ---
			self::infer_spacing( $el, '_padding', $id, $log );

			// --- Margin ---
			self::infer_spacing( $el, '_margin', $id, $log );

			// --- Container horizontal padding → mobile 16px ---
			self::infer_container_side_padding( $el, $id, $log );

			// --- Grid columns in _cssCustom ---
			self::infer_grid_columns( $el, $id, $log );

			// --- Flex direction row → column on mobile ---
			self::infer_flex_direction( $el, $id, $children_count, $log );

			// --- Gap ---
			self::infer_gap( $el, $id, $log );

			// --- Image fixed width ---
			self::infer_image_width( $el, $id, $log );
		}
		unset( $el );

		return array(
			'content' => array_values( $content ),
			'log'     => $log,
			'changed' => ! empty( $log ),
		);
	}

	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
	//  Private helpers — one per rule category
	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

	/**
	 * Typography: scale font-size for tablet & mobile.
	 *
	 * @param array  $el  Element (by reference via caller).
	 * @param string $id  Element ID for logging.
	 * @param array  $log Log array (by reference).
	 */
	private static function infer_typography( &$el, $id, &$log ) {
		if ( empty( $el['settings']['_typography']['font-size'] ) ) {
			return;
		}

		// Skip if responsive overrides already exist.
		if ( self::has_responsive_key( $el['settings'], '_typography', 'font-size' ) ) {
			return;
		}

		$size = self::parse_numeric( $el['settings']['_typography']['font-size'] );
		if ( null === $size || $size < 24 ) {
			return;
		}

		foreach ( self::$typography_scale as $rule ) {
			list( $min, $max, $tablet_factor, $mobile_factor ) = $rule;
			if ( $size >= $min && $size < $max ) {
				$tablet_size = (string) round( $size * $tablet_factor );
				$mobile_size = (string) round( $size * $mobile_factor );

				self::set_responsive_typography( $el['settings'], 'font-size', $tablet_size, $mobile_size );

				$log[] = sprintf(
					'Typography: %s font-size %s → tablet %s, mobile %s',
					$id,
					$el['settings']['_typography']['font-size'],
					$tablet_size,
					$mobile_size
				);

				// For text >=24px, inject CSS clamp() for smooth scaling.
				// Bricks 2.3: Lowered from 32px to 24px — fluid typography
				// is now default for all headings and large text.
				if ( $size >= 24 ) {
					$clamp = self::generate_clamp( (float) $mobile_size, $size );
					if ( $clamp ) {
						$clamp_rule = '%root% { font-size: ' . $clamp . ' !important; }';
						if ( ! empty( $el['settings']['_cssCustom'] ) ) {
							// Only add if no existing clamp for font-size.
							if ( false === strpos( $el['settings']['_cssCustom'], 'clamp(' ) ||
								 false === strpos( $el['settings']['_cssCustom'], 'font-size' ) ) {
								$el['settings']['_cssCustom'] = rtrim( $el['settings']['_cssCustom'] ) . ' ' . $clamp_rule;
							}
						} else {
							$el['settings']['_cssCustom'] = $clamp_rule;
						}
						$log[] = sprintf(
							'Typography clamp: %s font-size %s → %s',
							$id,
							$el['settings']['_typography']['font-size'],
							$clamp
						);
					}
				}

				break;
			}
		}
	}

	/**
	 * Letter spacing: ease tight tracking on mobile for readability.
	 *
	 * @param array  $el  Element (by reference via caller).
	 * @param string $id  Element ID for logging.
	 * @param array  $log Log array (by reference).
	 */
	private static function infer_letter_spacing( &$el, $id, &$log ) {
		if ( empty( $el['settings']['_typography']['letter-spacing'] ) ) {
			return;
		}

		if ( self::has_responsive_key( $el['settings'], '_typography', 'letter-spacing' ) ) {
			return;
		}

		$val = $el['settings']['_typography']['letter-spacing'];
		// Handle em values like "-0.04em" or "-0.03em".
		if ( is_string( $val ) && preg_match( '/^-?([\d.]+)em$/', $val, $m ) ) {
			$em_val = -1 * floatval( $m[1] );
			if ( $em_val < -0.03 ) {
				self::set_responsive_typography( $el['settings'], 'letter-spacing', null, '-0.02em' );
				$log[] = sprintf(
					'Letter-spacing: %s %s → mobile -0.02em (readability)',
					$id,
					$val
				);
			}
		}
	}

	/**
	 * Padding/margin: scale all sides for tablet & mobile.
	 *
	 * @param array  $el        Element (by reference via caller).
	 * @param string $prop_key  '_padding' or '_margin'.
	 * @param string $id        Element ID for logging.
	 * @param array  $log       Log array (by reference).
	 */
	private static function infer_spacing( &$el, $prop_key, $id, &$log ) {
		if ( empty( $el['settings'][ $prop_key ] ) || ! is_array( $el['settings'][ $prop_key ] ) ) {
			return;
		}

		// Skip if responsive override already exists for this spacing key.
		$tablet_key = ':tablet_portrait';
		$mobile_key = ':mobile_portrait';
		if (
			isset( $el['settings'][ $tablet_key . $prop_key ] ) ||
			isset( $el['settings'][ $mobile_key . $prop_key ] )
		) {
			return;
		}

		$sides         = array( 'top', 'right', 'bottom', 'left' );
		$tablet_values = array();
		$mobile_values = array();
		$any_change    = false;

		foreach ( $sides as $side ) {
			if ( ! isset( $el['settings'][ $prop_key ][ $side ] ) ) {
				continue;
			}

			$val = self::parse_numeric( $el['settings'][ $prop_key ][ $side ] );
			if ( null === $val || $val < 20 ) {
				continue;
			}

			foreach ( self::$spacing_scale as $rule ) {
				list( $min, $max, $tablet_factor, $mobile_factor, $mobile_floor ) = $rule;
				if ( $val >= $min && $val < $max ) {
					$tablet_val = (string) round( $val * $tablet_factor );
					$mobile_val = (string) max( $mobile_floor, round( $val * $mobile_factor ) );

					$tablet_values[ $side ] = $tablet_val;
					$mobile_values[ $side ] = $mobile_val;
					$any_change             = true;
					break;
				}
			}
		}

		if ( $any_change ) {
			if ( ! empty( $tablet_values ) ) {
				$el['settings'][ $tablet_key . $prop_key ] = $tablet_values;
			}
			if ( ! empty( $mobile_values ) ) {
				$el['settings'][ $mobile_key . $prop_key ] = $mobile_values;
			}

			$log[] = sprintf(
				'Spacing: %s %s → tablet [%s], mobile [%s]',
				$id,
				$prop_key,
				self::format_sides( $tablet_values ),
				self::format_sides( $mobile_values )
			);
		}
	}

	/**
	 * Container side padding: horizontal padding > 40px → mobile 16px.
	 *
	 * Based on the KR Taubenabwehr learning: desktop side padding of 60-80px
	 * is too wide on mobile (390px viewport = ~31%). Force 16px on mobile.
	 *
	 * @param array  $el  Element (by reference via caller).
	 * @param string $id  Element ID for logging.
	 * @param array  $log Log array (by reference).
	 */
	private static function infer_container_side_padding( &$el, $id, &$log ) {
		if ( empty( $el['settings']['_padding'] ) || ! is_array( $el['settings']['_padding'] ) ) {
			return;
		}

		$mobile_key = ':mobile_portrait_padding';
		if ( isset( $el['settings'][ $mobile_key ] ) ) {
			return;
		}

		$right = self::parse_numeric( isset( $el['settings']['_padding']['right'] ) ? $el['settings']['_padding']['right'] : null );
		$left  = self::parse_numeric( isset( $el['settings']['_padding']['left'] ) ? $el['settings']['_padding']['left'] : null );

		$needs_fix = false;
		$overrides = array();

		if ( null !== $right && $right > 40 ) {
			$overrides['right'] = '16';
			$needs_fix          = true;
		}
		if ( null !== $left && $left > 40 ) {
			$overrides['left'] = '16';
			$needs_fix         = true;
		}

		if ( $needs_fix ) {
			// Merge with any mobile padding already set by infer_spacing.
			$existing = isset( $el['settings'][':mobile_portrait_padding'] ) ? $el['settings'][':mobile_portrait_padding'] : array();
			$el['settings'][':mobile_portrait_padding'] = array_merge( $existing, $overrides );

			$log[] = sprintf(
				'Container side padding: %s horizontal padding → mobile 16px',
				$id
			);
		}
	}

	/**
	 * Grid columns: simplify grid-template-columns for smaller screens.
	 *
	 * Detects `repeat(N,` patterns in _cssCustom and appends @media rules
	 * for tablet (2 cols) and mobile (1 col) if not already present.
	 *
	 * @param array  $el  Element (by reference via caller).
	 * @param string $id  Element ID for logging.
	 * @param array  $log Log array (by reference).
	 */
	private static function infer_grid_columns( &$el, $id, &$log ) {
		if ( empty( $el['settings']['_cssCustom'] ) ) {
			return;
		}

		$css = $el['settings']['_cssCustom'];

		// Already has responsive grid rules — skip.
		if ( false !== strpos( $css, '@media' ) && false !== strpos( $css, 'grid-template-columns' ) ) {
			// Check if any @media block contains grid-template-columns.
			if ( preg_match( '/@media[^{]*\{[^}]*grid-template-columns/s', $css ) ) {
				return;
			}
		}

		// auto-fit/auto-fill grids are self-responsive — skip inference.
		if ( preg_match( '/grid-template-columns\s*:\s*repeat\(\s*(auto-fit|auto-fill)\s*,/i', $css ) ) {
			return;
		}

		// Detect repeat(N, ...) pattern.
		if ( ! preg_match( '/grid-template-columns\s*:\s*repeat\(\s*(\d+)\s*,/i', $css, $m ) ) {
			return;
		}

		$cols = intval( $m[1] );
		if ( $cols < 2 ) {
			return;
		}

		$tablet_cols = ( $cols >= 3 ) ? 2 : 2;
		$mobile_cols = 1;

		$tablet_rule = sprintf(
			' @media (max-width: 991px) { %%root%% { grid-template-columns: repeat(%d, minmax(0, 1fr)) !important; } }',
			$tablet_cols
		);
		$mobile_rule = sprintf(
			' @media (max-width: 767px) { %%root%% { grid-template-columns: repeat(%d, minmax(0, 1fr)) !important; } }',
			$mobile_cols
		);

		$el['settings']['_cssCustom'] = rtrim( $css ) . $tablet_rule . $mobile_rule;

		$log[] = sprintf(
			'Grid: %s %d cols → tablet %d, mobile %d',
			$id,
			$cols,
			$tablet_cols,
			$mobile_cols
		);
	}

	/**
	 * Flex direction: row → column on mobile when element has >2 children.
	 *
	 * @param array  $el              Element (by reference via caller).
	 * @param string $id              Element ID for logging.
	 * @param array  $children_count  Map of element ID → number of children.
	 * @param array  $log             Log array (by reference).
	 */
	private static function infer_flex_direction( &$el, $id, $children_count, &$log ) {
		if ( empty( $el['settings']['_direction'] ) || 'row' !== $el['settings']['_direction'] ) {
			return;
		}

		// Skip if mobile direction already set.
		if ( isset( $el['settings'][':mobile_portrait_direction'] ) ) {
			return;
		}

		$num_children = isset( $children_count[ $id ] ) ? $children_count[ $id ] : 0;
		if ( $num_children <= 2 ) {
			return;
		}

		$el['settings'][':mobile_portrait_direction'] = 'column';

		$log[] = sprintf(
			'Flex direction: %s row with %d children → mobile column',
			$id,
			$num_children
		);
	}

	/**
	 * Gap: scale gap values for tablet and mobile.
	 *
	 * Bricks stores gap as a string value (e.g. "48") without units.
	 *
	 * @param array  $el  Element (by reference via caller).
	 * @param string $id  Element ID for logging.
	 * @param array  $log Log array (by reference).
	 */
	private static function infer_gap( &$el, $id, &$log ) {
		if ( empty( $el['settings']['_gap'] ) ) {
			return;
		}

		// Skip if responsive gap already set.
		if (
			isset( $el['settings'][':tablet_portrait_gap'] ) ||
			isset( $el['settings'][':mobile_portrait_gap'] )
		) {
			return;
		}

		$val = self::parse_numeric( $el['settings']['_gap'] );
		if ( null === $val || $val < 24 ) {
			return;
		}

		foreach ( self::$gap_scale as $rule ) {
			list( $min, $max, $tablet_factor, $mobile_max ) = $rule;
			if ( $val >= $min && $val < $max ) {
				$tablet_val = (string) round( $val * $tablet_factor );
				$mobile_val = (string) $mobile_max;

				$el['settings'][':tablet_portrait_gap']  = $tablet_val;
				$el['settings'][':mobile_portrait_gap']   = $mobile_val;

				$log[] = sprintf(
					'Gap: %s %s → tablet %s, mobile %s',
					$id,
					$el['settings']['_gap'],
					$tablet_val,
					$mobile_val
				);
				break;
			}
		}
	}

	/**
	 * Image width: fixed width > 600px → 100% on tablet & mobile.
	 *
	 * @param array  $el  Element (by reference via caller).
	 * @param string $id  Element ID for logging.
	 * @param array  $log Log array (by reference).
	 */
	private static function infer_image_width( &$el, $id, &$log ) {
		if ( ! isset( $el['name'] ) || 'image' !== $el['name'] ) {
			return;
		}

		if ( empty( $el['settings']['_width'] ) ) {
			return;
		}

		// Skip if responsive width already set.
		if (
			isset( $el['settings'][':tablet_portrait_width'] ) ||
			isset( $el['settings'][':mobile_portrait_width'] )
		) {
			return;
		}

		$width = self::parse_numeric( $el['settings']['_width'] );
		if ( null === $width || $width <= 600 ) {
			return;
		}

		$el['settings'][':tablet_portrait_width']  = '100%';
		$el['settings'][':mobile_portrait_width']   = '100%';

		$log[] = sprintf(
			'Image width: %s %s → tablet 100%%, mobile 100%%',
			$id,
			$el['settings']['_width']
		);
	}

	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
	//  Utility helpers
	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

	/**
	 * Parse a numeric value from a Bricks setting string.
	 *
	 * Handles plain numbers ("48"), pixel values ("48px"), and em values.
	 * Returns null for non-numeric or percentage values.
	 *
	 * @param mixed $value The setting value.
	 * @return float|null
	 */
	private static function parse_numeric( $value ) {
		if ( is_numeric( $value ) ) {
			return floatval( $value );
		}
		if ( is_string( $value ) && preg_match( '/^(\d+(?:\.\d+)?)\s*px$/i', $value, $m ) ) {
			return floatval( $m[1] );
		}
		return null;
	}

	/**
	 * Check if a responsive override already exists for a typography sub-property.
	 *
	 * Bricks stores responsive typography overrides as:
	 *   settings[':tablet_portrait_typography']['font-size']
	 *   settings[':mobile_portrait_typography']['font-size']
	 *
	 * @param array  $settings    Element settings.
	 * @param string $prop_key    Top-level key (e.g. '_typography').
	 * @param string $sub_key     Sub-property key (e.g. 'font-size').
	 * @return bool
	 */
	private static function has_responsive_key( $settings, $prop_key, $sub_key ) {
		$tablet_key = ':tablet_portrait' . $prop_key;
		$mobile_key = ':mobile_portrait' . $prop_key;

		if ( isset( $settings[ $tablet_key ][ $sub_key ] ) ) {
			return true;
		}
		if ( isset( $settings[ $mobile_key ][ $sub_key ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Set responsive typography values for a sub-property.
	 *
	 * @param array       $settings    Element settings (by reference).
	 * @param string      $sub_key     Sub-property key (e.g. 'font-size').
	 * @param string|null $tablet_val  Tablet value (null = skip).
	 * @param string|null $mobile_val  Mobile value (null = skip).
	 */
	private static function set_responsive_typography( &$settings, $sub_key, $tablet_val, $mobile_val ) {
		$tablet_key = ':tablet_portrait_typography';
		$mobile_key = ':mobile_portrait_typography';

		if ( null !== $tablet_val ) {
			if ( ! isset( $settings[ $tablet_key ] ) ) {
				$settings[ $tablet_key ] = array();
			}
			$settings[ $tablet_key ][ $sub_key ] = $tablet_val;
		}

		if ( null !== $mobile_val ) {
			if ( ! isset( $settings[ $mobile_key ] ) ) {
				$settings[ $mobile_key ] = array();
			}
			$settings[ $mobile_key ][ $sub_key ] = $mobile_val;
		}
	}

	/**
	 * Generate a CSS clamp() expression for fluid scaling.
	 *
	 * Formula: clamp(minRem, intercept + slopeVw, maxRem)
	 * where slope = (max - min) / (maxVw - minVw)
	 * and intercept = min - slope * minVw
	 *
	 * @param float $min_px  Minimum size in px (mobile).
	 * @param float $max_px  Maximum size in px (desktop).
	 * @param int   $min_vw  Minimum viewport width (default 390).
	 * @param int   $max_vw  Maximum viewport width (default 1440).
	 * @return string|null CSS clamp() expression or null on error.
	 */
	private static function generate_clamp( $min_px, $max_px, $min_vw = 390, $max_vw = 1440 ) {
		if ( $max_px <= $min_px || $max_vw <= $min_vw ) {
			return null;
		}

		$slope     = ( $max_px - $min_px ) / ( $max_vw - $min_vw );
		$intercept = $min_px - $slope * $min_vw;

		$min_rem       = round( $min_px / 16, 4 );
		$max_rem       = round( $max_px / 16, 4 );
		$intercept_rem = round( $intercept / 16, 4 );
		$slope_vw      = round( $slope * 100, 4 );

		return sprintf(
			'clamp(%srem, %srem + %svw, %srem)',
			$min_rem,
			$intercept_rem,
			$slope_vw,
			$max_rem
		);
	}

	/**
	 * Format spacing sides array for log output.
	 *
	 * @param array $sides Associative array of side → value.
	 * @return string
	 */
	private static function format_sides( $sides ) {
		$parts = array();
		foreach ( $sides as $side => $val ) {
			$parts[] = $side . ':' . $val;
		}
		return implode( ' ', $parts );
	}
}
