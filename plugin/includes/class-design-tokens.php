<?php
/**
 * Design Tokens Import/Export for Bricks Builder.
 *
 * Converts between external token formats (ACSS, Tailwind, Figma Tokens,
 * Style Dictionary) and Bricks Builder's native CSS variables, color palette,
 * and typography system.
 *
 * @package Bricks_API_Bridge
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Design_Tokens
 *
 * Provides import/export endpoints for design token conversion.
 */
class Bricks_API_Bridge_Design_Tokens {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'bricks-bridge/v1';

	/**
	 * Supported import formats.
	 *
	 * @var array
	 */
	const FORMATS = array( 'acss', 'tailwind', 'figma-tokens', 'style-dictionary', 'json' );

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/design-tokens/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_tokens' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'format' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::FORMATS,
					),
					'tokens' => array(
						'type'     => 'object',
						'required' => true,
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'merge' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/design-tokens/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_tokens' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'format' => array(
						'type'    => 'string',
						'default' => 'json',
						'enum'    => array( 'json', 'acss', 'tailwind', 'style-dictionary', 'css' ),
					),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Import
	// ------------------------------------------------------------------

	/**
	 * Import design tokens from an external format.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function import_tokens( $request ) {
		$format  = $request->get_param( 'format' );
		$tokens  = $request->get_param( 'tokens' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$merge   = (bool) $request->get_param( 'merge' );

		// Convert to normalized format.
		$normalized = $this->normalize_tokens( $format, $tokens );

		if ( is_wp_error( $normalized ) ) {
			return new WP_REST_Response( array( 'error' => $normalized->get_error_message() ), 400 );
		}

		$changes = array(
			'css_variables' => array(),
			'color_palette' => array(),
			'typography'    => array(),
		);

		// Map normalized tokens to Bricks data structures.
		if ( ! empty( $normalized['colors'] ) ) {
			$changes['color_palette'] = $this->map_colors_to_palette( $normalized['colors'] );
			$changes['css_variables'] = array_merge(
				$changes['css_variables'],
				$this->map_colors_to_variables( $normalized['colors'] )
			);
		}

		if ( ! empty( $normalized['spacing'] ) ) {
			$changes['css_variables'] = array_merge(
				$changes['css_variables'],
				$this->map_spacing_to_variables( $normalized['spacing'] )
			);
		}

		if ( ! empty( $normalized['typography'] ) ) {
			$changes['typography'] = $normalized['typography'];
			$changes['css_variables'] = array_merge(
				$changes['css_variables'],
				$this->map_typography_to_variables( $normalized['typography'] )
			);
		}

		if ( ! empty( $normalized['radii'] ) ) {
			$changes['css_variables'] = array_merge(
				$changes['css_variables'],
				$this->map_generic_to_variables( $normalized['radii'], 'radius' )
			);
		}

		if ( ! empty( $normalized['shadows'] ) ) {
			$changes['css_variables'] = array_merge(
				$changes['css_variables'],
				$this->map_generic_to_variables( $normalized['shadows'], 'shadow' )
			);
		}

		if ( $dry_run ) {
			return new WP_REST_Response( array(
				'dry_run' => true,
				'changes' => $changes,
				'summary' => array(
					'css_variables' => count( $changes['css_variables'] ),
					'colors'        => count( $changes['color_palette'] ),
					'typography'    => count( $changes['typography'] ),
				),
			), 200 );
		}

		// Apply changes.
		$applied = array();

		// CSS Variables.
		if ( ! empty( $changes['css_variables'] ) ) {
			$existing = get_option( 'bricks_global_variables', array() );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			if ( $merge ) {
				// Merge: add new, update existing by name.
				$existing_map = array();
				foreach ( $existing as $i => $var ) {
					if ( isset( $var['name'] ) ) {
						$existing_map[ $var['name'] ] = $i;
					}
				}
				foreach ( $changes['css_variables'] as $new_var ) {
					if ( isset( $existing_map[ $new_var['name'] ] ) ) {
						$existing[ $existing_map[ $new_var['name'] ] ] = $new_var;
					} else {
						$existing[] = $new_var;
					}
				}
			} else {
				$existing = $changes['css_variables'];
			}

			update_option( 'bricks_global_variables', $existing );
			$applied['css_variables'] = count( $changes['css_variables'] );
		}

		// Color Palette.
		if ( ! empty( $changes['color_palette'] ) ) {
			$existing_palette = get_option( 'bricks_color_palette', array() );
			if ( ! is_array( $existing_palette ) ) {
				$existing_palette = array();
			}

			if ( $merge ) {
				$existing_names = array_column( $existing_palette, 'name' );
				foreach ( $changes['color_palette'] as $color ) {
					$idx = array_search( $color['name'], $existing_names, true );
					if ( false !== $idx ) {
						$existing_palette[ $idx ] = $color;
					} else {
						$existing_palette[] = $color;
					}
				}
			} else {
				$existing_palette = $changes['color_palette'];
			}

			update_option( 'bricks_color_palette', $existing_palette );
			$applied['color_palette'] = count( $changes['color_palette'] );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'format'  => $format,
			'applied' => $applied,
			'changes' => $changes,
		), 200 );
	}

	// ------------------------------------------------------------------
	// Export
	// ------------------------------------------------------------------

	/**
	 * Export design tokens in the requested format.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function export_tokens( $request ) {
		$format = $request->get_param( 'format' );

		// Gather all Bricks design data.
		$variables    = get_option( 'bricks_global_variables', array() );
		$palette      = get_option( 'bricks_color_palette', array() );
		$fonts        = get_option( 'bricks_custom_fonts', array() );
		$theme_styles = get_option( 'bricks_theme_styles', array() );

		if ( ! is_array( $variables ) ) {
			$variables = array();
		}
		if ( ! is_array( $palette ) ) {
			$palette = array();
		}
		if ( ! is_array( $fonts ) ) {
			$fonts = array();
		}
		if ( ! is_array( $theme_styles ) ) {
			$theme_styles = array();
		}

		// Build normalized token set.
		$normalized = array(
			'colors'     => $this->extract_colors( $variables, $palette ),
			'spacing'    => $this->extract_by_category( $variables, 'spacing' ),
			'typography' => $this->extract_typography( $variables, $fonts, $theme_styles ),
			'radii'      => $this->extract_by_category( $variables, 'radius' ),
			'shadows'    => $this->extract_by_category( $variables, 'shadow' ),
			'raw'        => array(
				'variables'    => $variables,
				'palette'      => $palette,
				'fonts'        => $fonts,
			),
		);

		// Convert to output format.
		switch ( $format ) {
			case 'acss':
				$output = $this->to_acss_format( $normalized );
				break;

			case 'tailwind':
				$output = $this->to_tailwind_format( $normalized );
				break;

			case 'style-dictionary':
				$output = $this->to_style_dictionary_format( $normalized );
				break;

			case 'css':
				$output = $this->to_css_format( $normalized );
				break;

			default: // json.
				$output = $normalized;
				break;
		}

		return new WP_REST_Response( array(
			'format' => $format,
			'tokens' => $output,
		), 200 );
	}

	// ------------------------------------------------------------------
	// Token normalization (import)
	// ------------------------------------------------------------------

	/**
	 * Normalize tokens from various formats to a common structure.
	 *
	 * @param string $format Source format.
	 * @param array  $tokens Raw token data.
	 * @return array|WP_Error Normalized tokens.
	 */
	private function normalize_tokens( $format, $tokens ) {
		switch ( $format ) {
			case 'acss':
				return $this->normalize_acss( $tokens );

			case 'tailwind':
				return $this->normalize_tailwind( $tokens );

			case 'figma-tokens':
				return $this->normalize_figma_tokens( $tokens );

			case 'style-dictionary':
				return $this->normalize_style_dictionary( $tokens );

			case 'json':
				// Already normalized format.
				return $tokens;

			default:
				return new WP_Error( 'unsupported_format', 'Unsupported token format: ' . $format );
		}
	}

	/**
	 * Normalize ACSS (Automatic CSS) tokens.
	 *
	 * ACSS uses CSS custom properties with --action, --text, --primary, etc.
	 *
	 * @param array $tokens ACSS token data.
	 * @return array Normalized tokens.
	 */
	private function normalize_acss( $tokens ) {
		$result = array(
			'colors'     => array(),
			'spacing'    => array(),
			'typography' => array(),
			'radii'      => array(),
		);

		// ACSS color tokens.
		$color_keys = array(
			'primary', 'secondary', 'accent', 'base', 'neutral',
			'shade', 'text', 'heading', 'body', 'link',
			'action', 'action-dark', 'white', 'black',
		);

		foreach ( $tokens as $key => $value ) {
			$clean_key = ltrim( $key, '-' );

			// Detect colors.
			$is_color = false;
			foreach ( $color_keys as $ck ) {
				if ( strpos( $clean_key, $ck ) === 0 ) {
					$is_color = true;
					break;
				}
			}

			if ( $is_color && $this->looks_like_color( $value ) ) {
				$result['colors'][ $clean_key ] = $value;
				continue;
			}

			// Detect spacing (s-*, space-*, section-space-*).
			if ( preg_match( '/^(s-|space-|section-space|content-space|grid-gap)/', $clean_key ) ) {
				$result['spacing'][ $clean_key ] = $value;
				continue;
			}

			// Detect radii.
			if ( preg_match( '/^(radius|border-radius)/', $clean_key ) ) {
				$result['radii'][ $clean_key ] = $value;
				continue;
			}

			// Detect typography.
			if ( preg_match( '/^(h[1-6]-|text-|body-|heading-)/', $clean_key ) ) {
				$result['typography'][ $clean_key ] = $value;
				continue;
			}
		}

		return $result;
	}

	/**
	 * Normalize Tailwind config tokens.
	 *
	 * @param array $tokens Tailwind config (theme.extend or theme).
	 * @return array
	 */
	private function normalize_tailwind( $tokens ) {
		$result = array(
			'colors'     => array(),
			'spacing'    => array(),
			'typography' => array(),
			'radii'      => array(),
			'shadows'    => array(),
		);

		// Colors — can be nested (e.g., { primary: { 500: "#..." } }).
		if ( isset( $tokens['colors'] ) ) {
			$result['colors'] = $this->flatten_nested_tokens( $tokens['colors'], '' );
		}

		// Spacing.
		if ( isset( $tokens['spacing'] ) ) {
			foreach ( $tokens['spacing'] as $key => $val ) {
				$result['spacing'][ 'space-' . $key ] = $val;
			}
		}

		// Font families.
		if ( isset( $tokens['fontFamily'] ) ) {
			foreach ( $tokens['fontFamily'] as $key => $val ) {
				$font_value = is_array( $val ) ? implode( ', ', $val ) : $val;
				$result['typography'][ 'font-' . $key ] = $font_value;
			}
		}

		// Font sizes.
		if ( isset( $tokens['fontSize'] ) ) {
			foreach ( $tokens['fontSize'] as $key => $val ) {
				$size = is_array( $val ) ? $val[0] : $val;
				$result['typography'][ 'text-' . $key ] = $size;
			}
		}

		// Border radius.
		if ( isset( $tokens['borderRadius'] ) ) {
			foreach ( $tokens['borderRadius'] as $key => $val ) {
				$result['radii'][ 'radius-' . $key ] = $val;
			}
		}

		// Box shadows.
		if ( isset( $tokens['boxShadow'] ) ) {
			foreach ( $tokens['boxShadow'] as $key => $val ) {
				$result['shadows'][ 'shadow-' . $key ] = $val;
			}
		}

		return $result;
	}

	/**
	 * Normalize Figma Tokens (Token Studio format).
	 *
	 * @param array $tokens Figma tokens data.
	 * @return array
	 */
	private function normalize_figma_tokens( $tokens ) {
		$result = array(
			'colors'     => array(),
			'spacing'    => array(),
			'typography' => array(),
			'radii'      => array(),
			'shadows'    => array(),
		);

		// Figma tokens use nested object with $type and $value.
		$this->walk_figma_tokens( $tokens, '', $result );

		return $result;
	}

	/**
	 * Recursively walk Figma tokens.
	 *
	 * @param array  $obj    Token object.
	 * @param string $prefix Current path prefix.
	 * @param array  &$result Result accumulator.
	 */
	private function walk_figma_tokens( $obj, $prefix, &$result ) {
		if ( ! is_array( $obj ) ) {
			return;
		}

		// Check if this is a leaf token.
		if ( isset( $obj['$value'] ) || isset( $obj['value'] ) ) {
			$type  = isset( $obj['$type'] ) ? $obj['$type'] : ( isset( $obj['type'] ) ? $obj['type'] : '' );
			$value = isset( $obj['$value'] ) ? $obj['$value'] : $obj['value'];
			$key   = ltrim( $prefix, '.' );

			switch ( $type ) {
				case 'color':
					$result['colors'][ $key ] = $value;
					break;
				case 'spacing':
				case 'dimension':
					$result['spacing'][ $key ] = $value;
					break;
				case 'fontFamilies':
				case 'fontWeights':
				case 'fontSizes':
				case 'lineHeights':
				case 'typography':
					$result['typography'][ $key ] = $value;
					break;
				case 'borderRadius':
					$result['radii'][ $key ] = $value;
					break;
				case 'boxShadow':
					$result['shadows'][ $key ] = is_array( $value ) ? $this->figma_shadow_to_css( $value ) : $value;
					break;
				default:
					// Try to detect type from value.
					if ( is_string( $value ) && $this->looks_like_color( $value ) ) {
						$result['colors'][ $key ] = $value;
					}
					break;
			}
			return;
		}

		// Recurse into children.
		foreach ( $obj as $key => $child ) {
			if ( is_array( $child ) ) {
				$new_prefix = $prefix ? $prefix . '.' . $key : $key;
				$this->walk_figma_tokens( $child, $new_prefix, $result );
			}
		}
	}

	/**
	 * Normalize Style Dictionary tokens.
	 *
	 * @param array $tokens Style Dictionary token data.
	 * @return array
	 */
	private function normalize_style_dictionary( $tokens ) {
		// Style Dictionary uses same nested format as Figma tokens
		// with $value and $type. Reuse the Figma walker.
		return $this->normalize_figma_tokens( $tokens );
	}

	// ------------------------------------------------------------------
	// Mapping helpers (normalized → Bricks)
	// ------------------------------------------------------------------

	/**
	 * Map color tokens to Bricks color palette entries.
	 *
	 * @param array $colors Normalized colors.
	 * @return array
	 */
	private function map_colors_to_palette( $colors ) {
		$palette = array();
		foreach ( $colors as $name => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$palette[] = array(
				'id'   => sanitize_title( $name ),
				'name' => ucwords( str_replace( array( '-', '_', '.' ), ' ', $name ) ),
				'raw'  => $value,
			);
		}
		return $palette;
	}

	/**
	 * Map color tokens to CSS variables.
	 *
	 * @param array $colors Normalized colors.
	 * @return array
	 */
	private function map_colors_to_variables( $colors ) {
		$vars = array();
		foreach ( $colors as $name => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$css_name = '--' . sanitize_title( str_replace( '.', '-', $name ) );
			$vars[]   = array(
				'name'     => $css_name,
				'value'    => $value,
				'category' => 'color',
			);
		}
		return $vars;
	}

	/**
	 * Map spacing tokens to CSS variables.
	 *
	 * @param array $spacing Normalized spacing.
	 * @return array
	 */
	private function map_spacing_to_variables( $spacing ) {
		$vars = array();
		foreach ( $spacing as $name => $value ) {
			$css_name = '--' . sanitize_title( str_replace( '.', '-', $name ) );
			$vars[]   = array(
				'name'     => $css_name,
				'value'    => is_string( $value ) ? $value : $value . 'px',
				'category' => 'spacing',
			);
		}
		return $vars;
	}

	/**
	 * Map typography tokens to CSS variables.
	 *
	 * @param array $typography Normalized typography.
	 * @return array
	 */
	private function map_typography_to_variables( $typography ) {
		$vars = array();
		foreach ( $typography as $name => $value ) {
			$css_name = '--' . sanitize_title( str_replace( '.', '-', $name ) );
			$val_str  = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			$vars[]   = array(
				'name'     => $css_name,
				'value'    => $val_str,
				'category' => 'typography',
			);
		}
		return $vars;
	}

	/**
	 * Map generic tokens (radii, shadows) to CSS variables.
	 *
	 * @param array  $tokens   Normalized tokens.
	 * @param string $category Category label.
	 * @return array
	 */
	private function map_generic_to_variables( $tokens, $category ) {
		$vars = array();
		foreach ( $tokens as $name => $value ) {
			$css_name = '--' . sanitize_title( str_replace( '.', '-', $name ) );
			$vars[]   = array(
				'name'     => $css_name,
				'value'    => is_string( $value ) ? $value : (string) $value,
				'category' => $category,
			);
		}
		return $vars;
	}

	// ------------------------------------------------------------------
	// Export format converters
	// ------------------------------------------------------------------

	/**
	 * Convert to ACSS format.
	 *
	 * @param array $normalized Normalized tokens.
	 * @return array
	 */
	private function to_acss_format( $normalized ) {
		$acss = array();
		foreach ( $normalized['colors'] as $name => $value ) {
			$acss[ '--' . $name ] = $value;
		}
		foreach ( $normalized['spacing'] as $name => $value ) {
			$acss[ '--' . $name ] = $value;
		}
		foreach ( $normalized['typography'] as $name => $value ) {
			$acss[ '--' . $name ] = $value;
		}
		foreach ( $normalized['radii'] as $name => $value ) {
			$acss[ '--' . $name ] = $value;
		}
		return $acss;
	}

	/**
	 * Convert to Tailwind config format.
	 *
	 * @param array $normalized Normalized tokens.
	 * @return array
	 */
	private function to_tailwind_format( $normalized ) {
		$tw = array(
			'theme' => array(
				'extend' => array(),
			),
		);

		if ( ! empty( $normalized['colors'] ) ) {
			$tw['theme']['extend']['colors'] = $normalized['colors'];
		}
		if ( ! empty( $normalized['spacing'] ) ) {
			$tw['theme']['extend']['spacing'] = $normalized['spacing'];
		}
		if ( ! empty( $normalized['radii'] ) ) {
			$tw['theme']['extend']['borderRadius'] = $normalized['radii'];
		}
		if ( ! empty( $normalized['shadows'] ) ) {
			$tw['theme']['extend']['boxShadow'] = $normalized['shadows'];
		}

		return $tw;
	}

	/**
	 * Convert to Style Dictionary format.
	 *
	 * @param array $normalized Normalized tokens.
	 * @return array
	 */
	private function to_style_dictionary_format( $normalized ) {
		$sd = array();

		foreach ( $normalized['colors'] as $name => $value ) {
			$sd['color'][ $name ] = array(
				'$value' => $value,
				'$type'  => 'color',
			);
		}

		foreach ( $normalized['spacing'] as $name => $value ) {
			$sd['spacing'][ $name ] = array(
				'$value' => $value,
				'$type'  => 'spacing',
			);
		}

		foreach ( $normalized['radii'] as $name => $value ) {
			$sd['borderRadius'][ $name ] = array(
				'$value' => $value,
				'$type'  => 'borderRadius',
			);
		}

		return $sd;
	}

	/**
	 * Convert to raw CSS :root output.
	 *
	 * @param array $normalized Normalized tokens.
	 * @return string
	 */
	private function to_css_format( $normalized ) {
		$lines = array( ':root {' );

		$sections = array(
			'colors'  => 'Colors',
			'spacing' => 'Spacing',
			'radii'   => 'Border Radius',
			'shadows' => 'Shadows',
		);

		foreach ( $sections as $key => $label ) {
			if ( ! empty( $normalized[ $key ] ) ) {
				$lines[] = '  /* ' . $label . ' */';
				foreach ( $normalized[ $key ] as $name => $value ) {
					$css_name = '--' . sanitize_title( str_replace( '.', '-', $name ) );
					$lines[]  = '  ' . $css_name . ': ' . ( is_string( $value ) ? $value : $value ) . ';';
				}
				$lines[] = '';
			}
		}

		$lines[] = '}';
		return implode( "\n", $lines );
	}

	// ------------------------------------------------------------------
	// Extraction helpers (Bricks → normalized)
	// ------------------------------------------------------------------

	/**
	 * Extract colors from variables and palette.
	 *
	 * @param array $variables Bricks CSS variables.
	 * @param array $palette   Bricks color palette.
	 * @return array
	 */
	private function extract_colors( $variables, $palette ) {
		$colors = array();

		// From palette — Bricks stores hex in 'raw' or 'hex' key.
		foreach ( $palette as $entry ) {
			$name = isset( $entry['id'] ) ? $entry['id'] : ( isset( $entry['name'] ) ? sanitize_title( $entry['name'] ) : '' );
			$raw  = isset( $entry['raw'] ) ? $entry['raw'] : ( isset( $entry['hex'] ) ? $entry['hex'] : '' );
			if ( $name && $raw ) {
				$colors[ $name ] = $raw;
			}
		}

		// From variables — check category first, then detect by value.
		foreach ( $variables as $var ) {
			if ( ! isset( $var['name'], $var['value'] ) ) {
				continue;
			}
			$cat = isset( $var['category'] ) ? $var['category'] : '';
			$key = ltrim( $var['name'], '-' );
			if ( 'color' === $cat || ( '' === $cat && $this->looks_like_color( $var['value'] ) ) ) {
				$colors[ $key ] = $var['value'];
			}
		}

		return $colors;
	}

	/**
	 * Extract variables by category.
	 *
	 * @param array  $variables Bricks variables.
	 * @param string $category  Category filter.
	 * @return array
	 */
	private function extract_by_category( $variables, $category ) {
		$result = array();

		// Name-based heuristics when category is not set.
		$category_patterns = array(
			'spacing' => '/^(space-|gap-|padding-|margin-|section-space|content-space)/',
			'radius'  => '/^(radius|border-radius|rounded)/',
			'shadow'  => '/^(shadow|elevation|drop-shadow)/',
		);

		$pattern = isset( $category_patterns[ $category ] ) ? $category_patterns[ $category ] : null;

		foreach ( $variables as $var ) {
			if ( ! isset( $var['name'], $var['value'] ) ) {
				continue;
			}
			$cat = isset( $var['category'] ) ? $var['category'] : '';
			$key = ltrim( $var['name'], '-' );

			if ( $category === $cat ) {
				$result[ $key ] = $var['value'];
			} elseif ( '' === $cat && $pattern && preg_match( $pattern, $key ) ) {
				$result[ $key ] = $var['value'];
			}
		}
		return $result;
	}

	/**
	 * Extract typography from variables, fonts, and theme styles.
	 *
	 * @param array $variables    Bricks variables.
	 * @param array $fonts        Bricks fonts.
	 * @param array $theme_styles Bricks theme styles.
	 * @return array
	 */
	private function extract_typography( $variables, $fonts, $theme_styles ) {
		$typo = array();

		// From variables.
		foreach ( $variables as $var ) {
			$cat = isset( $var['category'] ) ? $var['category'] : '';
			if ( 'typography' === $cat && isset( $var['name'], $var['value'] ) ) {
				$key = ltrim( $var['name'], '-' );
				$typo[ $key ] = $var['value'];
			}
		}

		// From fonts.
		foreach ( $fonts as $font ) {
			if ( isset( $font['family'] ) ) {
				$typo[ 'font-' . sanitize_title( $font['family'] ) ] = $font['family'];
			}
		}

		// From theme styles typography.
		foreach ( $theme_styles as $style ) {
			if ( isset( $style['settings']['typography'] ) ) {
				$ts = $style['settings']['typography'];
				if ( isset( $ts['font-family'] ) ) {
					$typo['body-font'] = $ts['font-family'];
				}
				if ( isset( $ts['font-size'] ) ) {
					$typo['body-size'] = $ts['font-size'];
				}
			}
			if ( isset( $style['settings']['headings'] ) ) {
				$hs = $style['settings']['headings'];
				if ( isset( $hs['font-family'] ) ) {
					$typo['heading-font'] = $hs['font-family'];
				}
			}
		}

		return $typo;
	}

	// ------------------------------------------------------------------
	// Utility methods
	// ------------------------------------------------------------------

	/**
	 * Flatten nested token objects (e.g. Tailwind colors).
	 *
	 * @param array  $obj    Nested tokens.
	 * @param string $prefix Current prefix.
	 * @return array Flat key-value map.
	 */
	private function flatten_nested_tokens( $obj, $prefix ) {
		$flat = array();
		foreach ( $obj as $key => $value ) {
			$full_key = $prefix ? $prefix . '-' . $key : $key;
			if ( is_array( $value ) && ! isset( $value[0] ) ) {
				$flat = array_merge( $flat, $this->flatten_nested_tokens( $value, $full_key ) );
			} else {
				$flat[ $full_key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
			}
		}
		return $flat;
	}

	/**
	 * Check if a string value looks like a CSS color.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	private function looks_like_color( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		// Hex, rgb, rgba, hsl, hsla, oklch.
		return (bool) preg_match( '/^(#[0-9a-f]{3,8}|rgba?\(|hsla?\(|oklch\(|transparent|inherit|currentColor)/i', $value );
	}

	/**
	 * Convert Figma shadow array to CSS box-shadow string.
	 *
	 * @param array $shadow Figma shadow definition.
	 * @return string
	 */
	private function figma_shadow_to_css( $shadow ) {
		if ( isset( $shadow[0] ) ) {
			// Array of shadows.
			return implode( ', ', array_map( array( $this, 'figma_shadow_to_css' ), $shadow ) );
		}
		$x      = isset( $shadow['x'] ) ? $shadow['x'] . 'px' : '0';
		$y      = isset( $shadow['y'] ) ? $shadow['y'] . 'px' : '0';
		$blur   = isset( $shadow['blur'] ) ? $shadow['blur'] . 'px' : '0';
		$spread = isset( $shadow['spread'] ) ? $shadow['spread'] . 'px' : '0';
		$color  = isset( $shadow['color'] ) ? $shadow['color'] : 'rgba(0,0,0,0.1)';
		return "$x $y $blur $spread $color";
	}
}
