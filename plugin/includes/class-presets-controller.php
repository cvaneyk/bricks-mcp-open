<?php
/**
 * Presets controller for reusable section templates.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Presets
 *
 * Manages section presets: CRUD operations, variable expansion,
 * and ID regeneration for Bricks Builder templates.
 */
class Bricks_API_Bridge_Presets {

	/**
	 * Option key for storing presets.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'bab_section_presets';

	/**
	 * Generate a unique 6-char lowercase alphanumeric ID with at least one digit.
	 *
	 * @return string
	 */
	private function generate_id() {
		$chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$digits = '0123456789';
		$alpha  = 'abcdefghijklmnopqrstuvwxyz';

		do {
			$id        = '';
			$has_digit = false;
			for ( $i = 0; $i < 6; $i++ ) {
				$char = $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
				$id  .= $char;
				if ( false !== strpos( $digits, $char ) ) {
					$has_digit = true;
				}
			}
		} while ( ! $has_digit );

		return $id;
	}

	/**
	 * Get all presets (user + defaults).
	 *
	 * Supports optional `category` query param to filter by category.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function get_presets( $request = null ) {
		$user_presets = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $user_presets ) ) {
			$user_presets = array();
		}

		$defaults = self::get_default_presets();
		$merged   = array_merge( $defaults, $user_presets );

		// Filter by category if provided.
		$category = $request ? $request->get_param( 'category' ) : null;
		if ( ! empty( $category ) ) {
			$category = sanitize_text_field( $category );
			$merged   = array_filter( $merged, function ( $preset ) use ( $category ) {
				return isset( $preset['category'] ) && $preset['category'] === $category;
			} );
		}

		return rest_ensure_response(
			array(
				'presets' => $merged,
				'count'   => count( $merged ),
			)
		);
	}

	/**
	 * Save or update a preset.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_preset( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['name'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_name',
				__( 'Preset name is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $body['elements'] ) || ! is_array( $body['elements'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_elements',
				__( 'Preset elements array is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		$name    = sanitize_text_field( $body['name'] );
		$presets = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $presets ) ) {
			$presets = array();
		}

		$preset_data = array(
			'elements'    => $body['elements'],
			'variables'   => isset( $body['variables'] ) ? $body['variables'] : array(),
			'description' => isset( $body['description'] ) ? sanitize_text_field( $body['description'] ) : '',
		);

		if ( ! empty( $body['category'] ) ) {
			$preset_data['category'] = sanitize_text_field( $body['category'] );
		}

		if ( ! empty( $body['variants'] ) && is_array( $body['variants'] ) ) {
			$preset_data['variants'] = $body['variants'];
		}

		$presets[ $name ] = $preset_data;

		update_option( self::OPTION_KEY, $presets );

		return rest_ensure_response(
			array(
				'success' => true,
				'name'    => $name,
			)
		);
	}

	/**
	 * Delete a preset by name.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_preset( $request ) {
		$name   = sanitize_text_field( $request->get_param( 'name' ) );
		$presets = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $presets ) || ! isset( $presets[ $name ] ) ) {
			return new WP_Error(
				'bricks_api_bridge_preset_not_found',
				__( 'Preset not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		unset( $presets[ $name ] );
		update_option( self::OPTION_KEY, $presets );

		return rest_ensure_response(
			array(
				'success' => true,
				'name'    => $name,
			)
		);
	}

	/**
	 * Instantiate a preset with variables and new IDs.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function instantiate_preset( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['name'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_name',
				__( 'Preset name is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		$name = sanitize_text_field( $body['name'] );
		$vars = isset( $body['variables'] ) ? $body['variables'] : array();

		// Look up preset in user presets first, then defaults.
		$presets  = get_option( self::OPTION_KEY, array() );
		$defaults = self::get_default_presets();
		$all      = array_merge( $defaults, is_array( $presets ) ? $presets : array() );

		if ( ! isset( $all[ $name ] ) ) {
			return new WP_Error(
				'bricks_api_bridge_preset_not_found',
				__( 'Preset not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$preset            = $all[ $name ];
		$elements          = $preset['elements'];
		$use_learned       = isset( $body['use_learned_styles'] ) ? (bool) $body['use_learned_styles'] : false;

		// Apply variant overrides if specified.
		$variant_name = isset( $body['variant'] ) ? sanitize_text_field( $body['variant'] ) : '';
		if ( ! empty( $variant_name ) && ! empty( $preset['variants'][ $variant_name ] ) ) {
			$variant_overrides = $preset['variants'][ $variant_name ];
			// Merge variant variables into the provided variables.
			if ( isset( $variant_overrides['variables'] ) && is_array( $variant_overrides['variables'] ) ) {
				$vars = array_merge( $variant_overrides['variables'], $vars );
			}
			// Merge variant element overrides.
			if ( isset( $variant_overrides['overrides'] ) && is_array( $variant_overrides['overrides'] ) ) {
				$existing_overrides = isset( $body['overrides'] ) ? $body['overrides'] : array();
				$body['overrides']  = array_replace_recursive( $variant_overrides['overrides'], $existing_overrides );
			}
		}

		// Replace {{variable}} placeholders in the JSON string.
		$json = wp_json_encode( $elements );
		foreach ( $vars as $key => $value ) {
			// JSON-escape the value so special chars (newlines, quotes, backslashes) don't break the JSON.
			$escaped = substr( wp_json_encode( (string) $value ), 1, -1 );
			$json    = str_replace( '{{' . $key . '}}', $escaped, $json );
		}

		// Fill remaining placeholders with learned style preferences.
		if ( $use_learned && false !== strpos( $json, '{{' ) ) {
			$learn_data    = get_option( 'bab_autolearn_data', array() );
			$learned_styles = isset( $learn_data['styles'] ) ? $learn_data['styles'] : array();

			$learned_vars = array();

			// Top color → primary_color.
			if ( ! empty( $learned_styles['colors'] ) ) {
				arsort( $learned_styles['colors'] );
				$learned_vars['primary_color'] = key( $learned_styles['colors'] );
				$colors = array_keys( $learned_styles['colors'] );
				if ( isset( $colors[1] ) ) {
					$learned_vars['secondary_color'] = $colors[1];
				}
			}

			// Top font → font_family.
			if ( ! empty( $learned_styles['fonts'] ) ) {
				arsort( $learned_styles['fonts'] );
				$learned_vars['font_family'] = key( $learned_styles['fonts'] );
			}

			// Top spacing → section_spacing.
			if ( ! empty( $learned_styles['spacing']['padding_y'] ) ) {
				arsort( $learned_styles['spacing']['padding_y'] );
				$learned_vars['section_spacing'] = key( $learned_styles['spacing']['padding_y'] );
			}

			// Apply learned vars only for unfilled placeholders.
			foreach ( $learned_vars as $lkey => $lvalue ) {
				if ( ! isset( $vars[ $lkey ] ) ) {
					$json = str_replace( '{{' . $lkey . '}}', $lvalue, $json );
				}
			}
		}

		$elements = json_decode( $json, true );

		if ( null === $elements ) {
			return new WP_Error(
				'preset_decode_error',
				__( 'Failed to decode preset after variable substitution. Check for special characters in variable values.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Apply overrides to element settings.
		$overrides = isset( $body['overrides'] ) ? $body['overrides'] : array();
		if ( ! empty( $overrides ) && is_array( $overrides ) ) {
			foreach ( $overrides as $el_key => $override_settings ) {
				if ( ! is_array( $override_settings ) ) {
					continue;
				}
				// Match by numeric index or element name.
				if ( is_numeric( $el_key ) ) {
					$idx = (int) $el_key;
					if ( isset( $elements[ $idx ] ) ) {
						$elements[ $idx ]['settings'] = array_replace_recursive(
							isset( $elements[ $idx ]['settings'] ) ? $elements[ $idx ]['settings'] : array(),
							$override_settings
						);
					}
				} else {
					// Match by element name (applies to first match).
					foreach ( $elements as &$oel ) {
						if ( isset( $oel['name'] ) && $oel['name'] === $el_key ) {
							$oel['settings'] = array_replace_recursive(
								isset( $oel['settings'] ) ? $oel['settings'] : array(),
								$override_settings
							);
							break;
						}
					}
					unset( $oel );
				}
			}
		}

		// Generate new IDs and update parent/children references.
		$id_map = array();
		foreach ( $elements as $el ) {
			if ( isset( $el['id'] ) ) {
				$id_map[ $el['id'] ] = $this->generate_id();
			}
		}

		foreach ( $elements as &$el ) {
			// Update element ID.
			if ( isset( $el['id'] ) && isset( $id_map[ $el['id'] ] ) ) {
				$el['id'] = $id_map[ $el['id'] ];
			}

			// Update parent reference.
			if ( isset( $el['parent'] ) && is_string( $el['parent'] ) && isset( $id_map[ $el['parent'] ] ) ) {
				$el['parent'] = $id_map[ $el['parent'] ];
			}

			// Update children references.
			if ( isset( $el['children'] ) && is_array( $el['children'] ) ) {
				$el['children'] = array_map(
					function ( $child_id ) use ( $id_map ) {
						return isset( $id_map[ $child_id ] ) ? $id_map[ $child_id ] : $child_id;
					},
					$el['children']
				);
			}
		}
		unset( $el );

		return rest_ensure_response(
			array(
				'success'  => true,
				'elements' => $elements,
				'count'    => count( $elements ),
			)
		);
	}

	/**
	 * Get default presets from JSON files in the presets/ directory.
	 *
	 * Each JSON file represents one preset. The filename (without .json)
	 * becomes the preset key. Results are cached in the object cache
	 * to avoid repeated filesystem reads within the same request.
	 *
	 * @return array
	 */
	public static function get_default_presets() {
		$cached = wp_cache_get( 'default_presets', 'bricks_api_bridge' );
		if ( false !== $cached ) {
			return $cached;
		}

		$presets = array();
		$dir     = plugin_dir_path( __DIR__ ) . 'presets/';

		if ( ! is_dir( $dir ) ) {
			return $presets;
		}

		$files = glob( $dir . '*.json' );
		if ( empty( $files ) ) {
			return $presets;
		}

		foreach ( $files as $file ) {
			$name = basename( $file, '.json' );
			$raw  = file_get_contents( $file );
			$data = json_decode( $raw, true );

			if ( $data && isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
				$presets[ $name ] = $data;
			}
		}

		wp_cache_set( 'default_presets', $presets, 'bricks_api_bridge' );

		return $presets;
	}

	// --- Legacy hardcoded presets removed ---
	// All 21+ presets now live as individual JSON files in /presets/*.json
	// See scripts/extract-presets.js for the extraction tool.

	/*
	 * Previously: ~2700 lines of hardcoded PHP array data for 21 presets.
	 * Migrated to individual JSON files in /presets/ on 2026-02-16.
	 * See scripts/extract-presets.js for the extraction tool.
	 */

	/**
	 * Get learned style preferences from auto-learn data + theme styles.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function get_style_preferences( $request ) {
		$refresh = $request->get_param( 'refresh' );

		// Re-analyze all pages if requested.
		if ( $refresh ) {
			$pages = get_posts( array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 50,
			) );
			foreach ( $pages as $page ) {
				$bricks_data = get_post_meta( $page->ID, '_bricks_page_content_2', true );
				if ( ! empty( $bricks_data ) && is_array( $bricks_data ) ) {
					self::auto_learn_from_page( $page->ID, $bricks_data );
				}
			}
		}

		$learn_data = get_option( 'bab_autolearn_data', array() );
		$styles     = isset( $learn_data['styles'] ) ? $learn_data['styles'] : array( 'colors' => array(), 'fonts' => array(), 'spacing' => array() );

		// Sort by frequency (descending).
		if ( ! empty( $styles['colors'] ) ) {
			arsort( $styles['colors'] );
		}
		if ( ! empty( $styles['fonts'] ) ) {
			arsort( $styles['fonts'] );
		}
		// Sort structured spacing sub-arrays.
		if ( ! empty( $styles['spacing'] ) && is_array( $styles['spacing'] ) ) {
			foreach ( $styles['spacing'] as $key => &$values ) {
				if ( is_array( $values ) ) {
					arsort( $values );
				}
			}
			unset( $values );
		}

		// Extract typography_scale from all pages.
		$typography_scale = self::extract_typography_scale();

		// Include current theme styles and color palette for context.
		$theme_styles  = get_option( 'bricks_theme_styles', array() );
		$color_palette = get_option( 'bricks_color_palette', array() );

		// Build top spacing from structured data.
		$top_spacing = array();
		if ( isset( $styles['spacing']['padding_y'] ) && is_array( $styles['spacing']['padding_y'] ) ) {
			$top_spacing['padding_y'] = array_slice( $styles['spacing']['padding_y'], 0, 5, true );
		}
		if ( isset( $styles['spacing']['padding_x'] ) && is_array( $styles['spacing']['padding_x'] ) ) {
			$top_spacing['padding_x'] = array_slice( $styles['spacing']['padding_x'], 0, 5, true );
		}
		if ( isset( $styles['spacing']['margin_y'] ) && is_array( $styles['spacing']['margin_y'] ) ) {
			$top_spacing['margin_y'] = array_slice( $styles['spacing']['margin_y'], 0, 5, true );
		}
		if ( isset( $styles['spacing']['gap'] ) && is_array( $styles['spacing']['gap'] ) ) {
			$top_spacing['gap'] = array_slice( $styles['spacing']['gap'], 0, 5, true );
		}

		return rest_ensure_response( array(
			'learned'          => array(
				'top_colors'  => array_slice( $styles['colors'], 0, 10, true ),
				'top_fonts'   => array_slice( $styles['fonts'], 0, 5, true ),
				'top_spacing' => $top_spacing,
			),
			'typography_scale' => $typography_scale,
			'theme_styles'     => $theme_styles,
			'color_palette'    => $color_palette,
			'fingerprints'     => isset( $learn_data['fingerprints'] ) ? count( $learn_data['fingerprints'] ) : 0,
			'pages_tracked'    => isset( $learn_data['feedback'] ) ? count( $learn_data['feedback'] ) : 0,
		) );
	}

	/**
	 * Suggest a section flow for a page based on page type and goals.
	 *
	 * Uses learned composition flows when available, falls back to
	 * curated defaults per page type.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest_flow( $request ) {
		$body      = $request->get_json_params();
		$page_type = isset( $body['page_type'] ) ? sanitize_text_field( $body['page_type'] ) : '';
		$goals     = isset( $body['goals'] ) ? array_map( 'sanitize_text_field', (array) $body['goals'] ) : array();
		$style     = isset( $body['style'] ) ? sanitize_text_field( $body['style'] ) : 'auto';

		if ( empty( $page_type ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_page_type',
				__( 'page_type is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Default flows per page type.
		$default_flows = array(
			'landing'   => array(
				array( 'preset' => 'hero-aurora', 'reason' => 'Stunning aurora hero for immediate impact' ),
				array( 'preset' => 'logo-cloud', 'reason' => 'Social proof with trusted brands' ),
				array( 'preset' => 'features-grid-3col', 'reason' => 'Showcase key benefits' ),
				array( 'preset' => 'stats-counter-4', 'reason' => 'Credibility with big numbers' ),
				array( 'preset' => 'testimonials-slider', 'reason' => 'Build social proof' ),
				array( 'preset' => 'cta-dark', 'reason' => 'Drive conversions' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'about'     => array(
				array( 'preset' => 'hero-split-screen', 'reason' => 'Personal introduction with photo' ),
				array( 'preset' => 'stats-counter-4', 'reason' => 'Company milestones in numbers' ),
				array( 'preset' => 'content-text-image', 'reason' => 'Tell your story' ),
				array( 'preset' => 'team-grid-3', 'reason' => 'Meet the team' ),
				array( 'preset' => 'cta-light', 'reason' => 'Encourage contact' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'services'  => array(
				array( 'preset' => 'hero-gradient-dark', 'reason' => 'Service overview statement' ),
				array( 'preset' => 'services-grid-4', 'reason' => 'Showcase all services with hover cards' ),
				array( 'preset' => 'process-numbered-4', 'reason' => 'Clear step-by-step process' ),
				array( 'preset' => 'pricing-3col', 'reason' => 'Transparent pricing' ),
				array( 'preset' => 'faq-accordion', 'reason' => 'Address common questions' ),
				array( 'preset' => 'testimonials-slider', 'reason' => 'Client validation' ),
				array( 'preset' => 'cta-dark', 'reason' => 'Drive inquiries' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'portfolio' => array(
				array( 'preset' => 'hero-fullbleed-photo', 'reason' => 'Visual impact with best work' ),
				array( 'preset' => 'portfolio-grid-4', 'reason' => 'Showcase projects with hover reveals' ),
				array( 'preset' => 'stats-counter-4', 'reason' => 'Project metrics and results' ),
				array( 'preset' => 'testimonials-slider', 'reason' => 'Client feedback' ),
				array( 'preset' => 'cta-light', 'reason' => 'Invite collaboration' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'contact'   => array(
				array( 'preset' => 'hero-split-screen', 'reason' => 'Contact info with map or image' ),
				array( 'preset' => 'contact-form', 'reason' => 'Contact form with name, email, and message' ),
				array( 'preset' => 'faq-accordion', 'reason' => 'Address common questions' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'blog'      => array(
				array( 'preset' => 'hero-gradient-dark', 'reason' => 'Blog intro with bold heading' ),
				array( 'preset' => 'blog-grid-3', 'reason' => 'Featured articles in card grid' ),
				array( 'preset' => 'newsletter-dark', 'reason' => 'Newsletter signup for readers' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'saas'      => array(
				array( 'preset' => 'hero-gradient-text', 'reason' => 'Eye-catching gradient text hero' ),
				array( 'preset' => 'logo-cloud', 'reason' => 'Trusted by leading companies' ),
				array( 'preset' => 'bento-grid-4', 'reason' => 'Feature showcase in bento layout' ),
				array( 'preset' => 'stats-counter-4', 'reason' => 'Platform metrics and social proof' ),
				array( 'preset' => 'pricing-3col', 'reason' => 'Transparent pricing tiers' ),
				array( 'preset' => 'faq-accordion', 'reason' => 'Address onboarding questions' ),
				array( 'preset' => 'newsletter-dark', 'reason' => 'Product updates signup' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
			'agency'    => array(
				array( 'preset' => 'hero-video-dark', 'reason' => 'Showreel video hero' ),
				array( 'preset' => 'logo-cloud', 'reason' => 'Client logos for credibility' ),
				array( 'preset' => 'portfolio-grid-4', 'reason' => 'Selected case studies' ),
				array( 'preset' => 'process-numbered-4', 'reason' => 'How we work process' ),
				array( 'preset' => 'team-grid-3', 'reason' => 'Meet the creative team' ),
				array( 'preset' => 'testimonials-slider', 'reason' => 'Client testimonials' ),
				array( 'preset' => 'cta-dark', 'reason' => 'Start a project CTA' ),
				array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ),
			),
		);

		$flow = isset( $default_flows[ $page_type ] ) ? $default_flows[ $page_type ] : $default_flows['landing'];

		// Merge learned flows: prefer type-specific flows, fall back to global flows.
		$learn_data    = get_option( 'bab_autolearn_data', array() );
		$learned_flows = array();

		// Try type-specific flows first.
		if ( ! empty( $learn_data['flows_by_type'][ $page_type ] ) ) {
			$learned_flows = $learn_data['flows_by_type'][ $page_type ];
		}

		// Fall back to global flows.
		if ( empty( $learned_flows ) ) {
			$learned_flows = isset( $learn_data['flows'] ) ? $learn_data['flows'] : array();
		}

		if ( ! empty( $learned_flows ) ) {
			// Find the most-used flow (highest count).
			arsort( $learned_flows );
			$top_flow_key   = key( $learned_flows );
			$top_flow_count = current( $learned_flows );

			// Only use learned flows if they've been seen at least twice (not one-offs).
			if ( $top_flow_count >= 2 ) {
				$fingerprints = isset( $learn_data['fingerprints'] ) ? $learn_data['fingerprints'] : array();
				$hashes       = explode( '>', $top_flow_key );

				// Try to map hashes back to preset names via structure matching.
				$learned_presets = array();
				$all_presets     = array_merge( self::get_default_presets(), get_option( self::OPTION_KEY, array() ) );

				foreach ( $hashes as $hash ) {
					if ( ! isset( $fingerprints[ $hash ] ) ) {
						continue;
					}
					$structure = $fingerprints[ $hash ]['structure'];

					// Match against preset fingerprints.
					foreach ( $all_presets as $pname => $pdata ) {
						if ( empty( $pdata['elements'] ) ) {
							continue;
						}
						$p_children = array();
						foreach ( $pdata['elements'] as $pel ) {
							$pid = isset( $pel['parent'] ) ? $pel['parent'] : 0;
							if ( ! isset( $p_children[ $pid ] ) ) {
								$p_children[ $pid ] = array();
							}
							$p_children[ $pid ][] = $pel;
						}
						// Find root section.
						foreach ( $pdata['elements'] as $pel ) {
							if ( 'section' === ( $pel['name'] ?? '' ) && ( 0 === ( $pel['parent'] ?? 0 ) || '0' === ( $pel['parent'] ?? '' ) ) ) {
								$p_fp = self::fingerprint_subtree( $pel['id'], $p_children );
								if ( md5( $p_fp ) === $hash ) {
									$learned_presets[] = array(
										'preset' => $pname,
										'reason' => sprintf( 'Learned from %d previous builds', $top_flow_count ),
									);
									break 2;
								}
							}
						}
					}
				}

				// If we matched enough presets, offer the learned flow as an alternative.
				if ( count( $learned_presets ) >= 2 ) {
					// Prepend a note that this is a learned flow.
					$flow = array_merge(
						$learned_presets,
						array( array( 'preset' => 'footer-dark', 'reason' => 'Navigation and contact info' ) )
					);
				}
			}
		}

		// Style adjustments: swap dark/light variants based on style preference.
		if ( 'light-premium' === $style || 'light' === $style ) {
			foreach ( $flow as &$section ) {
				if ( 'hero-gradient-dark' === $section['preset'] ) {
					$section['preset'] = 'hero-split-screen';
					$section['reason'] = 'Clean split-screen hero for light aesthetic';
				}
				if ( 'cta-dark' === $section['preset'] ) {
					$section['preset'] = 'cta-light';
				}
			}
			unset( $section );
		}

		// Goal-based adjustments.
		$has_goal = function ( $keyword ) use ( $goals ) {
			foreach ( $goals as $g ) {
				if ( false !== stripos( $g, $keyword ) ) {
					return true;
				}
			}
			return false;
		};

		// If "pricing" or "convert" is a goal but pricing not in flow, add it.
		if ( ( $has_goal( 'pricing' ) || $has_goal( 'convert' ) ) && 'services' !== $page_type ) {
			$has_pricing = false;
			foreach ( $flow as $s ) {
				if ( 'pricing-3col' === $s['preset'] ) {
					$has_pricing = true;
					break;
				}
			}
			if ( ! $has_pricing ) {
				// Insert before the last CTA/footer.
				$insert_pos = max( 0, count( $flow ) - 2 );
				array_splice( $flow, $insert_pos, 0, array(
					array( 'preset' => 'pricing-3col', 'reason' => 'Added for conversion goal' ),
				) );
			}
		}

		// If "trust" is a goal but no testimonials, add them.
		if ( $has_goal( 'trust' ) ) {
			$has_testimonials = false;
			foreach ( $flow as $s ) {
				if ( 'testimonials-slider' === $s['preset'] ) {
					$has_testimonials = true;
					break;
				}
			}
			if ( ! $has_testimonials ) {
				$insert_pos = max( 0, count( $flow ) - 2 );
				array_splice( $flow, $insert_pos, 0, array(
					array( 'preset' => 'testimonials-slider', 'reason' => 'Added for trust-building goal' ),
				) );
			}
		}

		return rest_ensure_response( array(
			'page_type' => $page_type,
			'style'     => $style,
			'goals'     => $goals,
			'sections'  => $flow,
			'count'     => count( $flow ),
		) );
	}

	/**
	 * Auto-learn from saved page data.
	 *
	 * Extracts patterns from Bricks data:
	 * A) Section fingerprints (element structure hashes)
	 * B) Composition flow (section type sequences)
	 * C) Style preferences (colors, fonts, spacing)
	 * D) Feedback tracking (retention vs rejection)
	 *
	 * @param int   $post_id    The post ID.
	 * @param array $bricks_data The Bricks element array.
	 * @return void
	 */
	public static function auto_learn_from_page( $post_id, $bricks_data ) {
		if ( empty( $bricks_data ) || ! is_array( $bricks_data ) ) {
			return;
		}

		$learn_data = get_option( 'bab_autolearn_data', array() );
		if ( ! is_array( $learn_data ) ) {
			$learn_data = array();
		}

		// Initialize sub-arrays.
		if ( ! isset( $learn_data['fingerprints'] ) ) {
			$learn_data['fingerprints'] = array();
		}
		if ( ! isset( $learn_data['flows'] ) ) {
			$learn_data['flows'] = array();
		}
		if ( ! isset( $learn_data['flows_by_type'] ) ) {
			$learn_data['flows_by_type'] = array();
		}
		if ( ! isset( $learn_data['styles'] ) ) {
			$learn_data['styles'] = array( 'colors' => array(), 'fonts' => array(), 'spacing' => array() );
		}
		// Migrate flat spacing to structured format.
		if ( ! empty( $learn_data['styles']['spacing'] ) && ! isset( $learn_data['styles']['spacing']['padding_y'] ) ) {
			$old_spacing = $learn_data['styles']['spacing'];
			$learn_data['styles']['spacing'] = array(
				'padding_y' => $old_spacing,
				'padding_x' => array(),
				'margin_y'  => array(),
				'gap'       => array(),
			);
		}
		if ( ! isset( $learn_data['styles']['spacing']['padding_y'] ) ) {
			$learn_data['styles']['spacing'] = array(
				'padding_y' => array(),
				'padding_x' => array(),
				'margin_y'  => array(),
				'gap'       => array(),
			);
		}
		if ( ! isset( $learn_data['feedback'] ) ) {
			$learn_data['feedback'] = array();
		}

		// A) Section fingerprinting.
		$sections = array();
		$children_map = array();
		foreach ( $bricks_data as $el ) {
			$parent_id = isset( $el['parent'] ) ? $el['parent'] : 0;
			if ( ! isset( $children_map[ $parent_id ] ) ) {
				$children_map[ $parent_id ] = array();
			}
			$children_map[ $parent_id ][] = $el;
			if ( 'section' === $el['name'] ) {
				$sections[] = $el;
			}
		}

		$section_types = array();
		foreach ( $sections as $section ) {
			$fingerprint = self::fingerprint_subtree( $section['id'], $children_map );
			$hash        = md5( $fingerprint );

			if ( ! isset( $learn_data['fingerprints'][ $hash ] ) ) {
				$learn_data['fingerprints'][ $hash ] = array(
					'structure' => $fingerprint,
					'count'     => 0,
					'first_seen' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				);
			}
			$learn_data['fingerprints'][ $hash ]['count']++;
			$learn_data['fingerprints'][ $hash ]['last_seen'] = gmdate( 'Y-m-d\TH:i:s\Z' );

			$section_types[] = $hash;
		}

		// B) Composition flow.
		if ( count( $section_types ) > 1 ) {
			$flow_key = implode( '>', $section_types );
			if ( ! isset( $learn_data['flows'][ $flow_key ] ) ) {
				$learn_data['flows'][ $flow_key ] = 0;
			}
			$learn_data['flows'][ $flow_key ]++;

			// Also key by page_type for type-specific flow suggestions.
			$page_type = self::detect_page_type( $post_id );
			if ( ! empty( $page_type ) ) {
				if ( ! isset( $learn_data['flows_by_type'][ $page_type ] ) ) {
					$learn_data['flows_by_type'][ $page_type ] = array();
				}
				if ( ! isset( $learn_data['flows_by_type'][ $page_type ][ $flow_key ] ) ) {
					$learn_data['flows_by_type'][ $page_type ][ $flow_key ] = 0;
				}
				$learn_data['flows_by_type'][ $page_type ][ $flow_key ]++;
			}
		}

		// C) Style preferences.
		foreach ( $bricks_data as $el ) {
			if ( empty( $el['settings'] ) ) {
				continue;
			}
			$settings = $el['settings'];

			// Colors.
			$color_paths = array( '_background', '_typography' );
			foreach ( $color_paths as $path ) {
				if ( isset( $settings[ $path ]['color']['raw'] ) ) {
					$color = $settings[ $path ]['color']['raw'];
					if ( ! isset( $learn_data['styles']['colors'][ $color ] ) ) {
						$learn_data['styles']['colors'][ $color ] = 0;
					}
					$learn_data['styles']['colors'][ $color ]++;
				}
			}

			// Fonts.
			if ( isset( $settings['_typography']['font-family'] ) ) {
				$font = $settings['_typography']['font-family'];
				if ( ! isset( $learn_data['styles']['fonts'][ $font ] ) ) {
					$learn_data['styles']['fonts'][ $font ] = 0;
				}
				$learn_data['styles']['fonts'][ $font ]++;
			}

			// Spacing: padding (vertical + horizontal), margin (vertical), gap.
			if ( isset( $settings['_padding'] ) ) {
				foreach ( array( 'top', 'bottom' ) as $side ) {
					if ( isset( $settings['_padding'][ $side ] ) ) {
						$val = $settings['_padding'][ $side ];
						if ( ! isset( $learn_data['styles']['spacing']['padding_y'][ $val ] ) ) {
							$learn_data['styles']['spacing']['padding_y'][ $val ] = 0;
						}
						$learn_data['styles']['spacing']['padding_y'][ $val ]++;
					}
				}
				foreach ( array( 'left', 'right' ) as $side ) {
					if ( isset( $settings['_padding'][ $side ] ) ) {
						$val = $settings['_padding'][ $side ];
						if ( ! isset( $learn_data['styles']['spacing']['padding_x'][ $val ] ) ) {
							$learn_data['styles']['spacing']['padding_x'][ $val ] = 0;
						}
						$learn_data['styles']['spacing']['padding_x'][ $val ]++;
					}
				}
			}
			if ( isset( $settings['_margin'] ) ) {
				foreach ( array( 'top', 'bottom' ) as $side ) {
					if ( isset( $settings['_margin'][ $side ] ) ) {
						$val = $settings['_margin'][ $side ];
						if ( ! isset( $learn_data['styles']['spacing']['margin_y'][ $val ] ) ) {
							$learn_data['styles']['spacing']['margin_y'][ $val ] = 0;
						}
						$learn_data['styles']['spacing']['margin_y'][ $val ]++;
					}
				}
			}
			if ( isset( $settings['_gap'] ) ) {
				$gap_val = is_array( $settings['_gap'] ) ? ( isset( $settings['_gap']['column'] ) ? $settings['_gap']['column'] : '' ) : $settings['_gap'];
				if ( ! empty( $gap_val ) ) {
					if ( ! isset( $learn_data['styles']['spacing']['gap'][ $gap_val ] ) ) {
						$learn_data['styles']['spacing']['gap'][ $gap_val ] = 0;
					}
					$learn_data['styles']['spacing']['gap'][ $gap_val ]++;
				}
			}
		}

		// D) Feedback: track element count per page over time.
		$learn_data['feedback'][ $post_id ] = array(
			'element_count' => count( $bricks_data ),
			'section_count' => count( $sections ),
			'updated'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		// Cap stored data to prevent bloat.
		if ( count( $learn_data['fingerprints'] ) > 200 ) {
			arsort( $learn_data['fingerprints'] );
			$learn_data['fingerprints'] = array_slice( $learn_data['fingerprints'], 0, 200, true );
		}
		if ( count( $learn_data['styles']['colors'] ) > 50 ) {
			arsort( $learn_data['styles']['colors'] );
			$learn_data['styles']['colors'] = array_slice( $learn_data['styles']['colors'], 0, 50, true );
		}

		update_option( 'bab_autolearn_data', $learn_data, false );
	}

	/**
	 * Extract typography scale from all pages.
	 *
	 * Loops through published pages, finds heading elements (h1-h6),
	 * and extracts font-size, line-height, letter-spacing per heading level.
	 * Returns the most common values per level.
	 *
	 * @return array Typography scale keyed by heading tag (h1-h6).
	 */
	private static function extract_typography_scale() {
		$pages = get_posts( array(
			'post_type'   => array( 'page', 'post' ),
			'post_status' => 'publish',
			'numberposts' => 50,
			'fields'      => 'ids',
		) );

		$scale = array();

		foreach ( $pages as $page_id ) {
			$bricks_data = null;
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
				$bricks_data = \Bricks\Database::get_data( $page_id, 'content' );
			}
			if ( empty( $bricks_data ) ) {
				$bricks_data = get_post_meta( $page_id, '_bricks_page_content_2', true );
			}
			if ( empty( $bricks_data ) || ! is_array( $bricks_data ) ) {
				continue;
			}

			foreach ( $bricks_data as $el ) {
				if ( ! isset( $el['name'] ) || 'heading' !== $el['name'] ) {
					continue;
				}

				$settings = isset( $el['settings'] ) ? $el['settings'] : array();
				$tag      = isset( $settings['tag'] ) ? strtolower( $settings['tag'] ) : 'h2';

				// Only track h1-h6.
				if ( ! preg_match( '/^h[1-6]$/', $tag ) ) {
					continue;
				}

				if ( ! isset( $scale[ $tag ] ) ) {
					$scale[ $tag ] = array(
						'font_size'      => array(),
						'line_height'    => array(),
						'letter_spacing' => array(),
					);
				}

				$typo = isset( $settings['_typography'] ) ? $settings['_typography'] : array();

				if ( ! empty( $typo['font-size'] ) ) {
					$fs = $typo['font-size'];
					if ( ! isset( $scale[ $tag ]['font_size'][ $fs ] ) ) {
						$scale[ $tag ]['font_size'][ $fs ] = 0;
					}
					$scale[ $tag ]['font_size'][ $fs ]++;
				}

				if ( ! empty( $typo['line-height'] ) ) {
					$lh = $typo['line-height'];
					if ( ! isset( $scale[ $tag ]['line_height'][ $lh ] ) ) {
						$scale[ $tag ]['line_height'][ $lh ] = 0;
					}
					$scale[ $tag ]['line_height'][ $lh ]++;
				}

				if ( ! empty( $typo['letter-spacing'] ) ) {
					$ls = $typo['letter-spacing'];
					if ( ! isset( $scale[ $tag ]['letter_spacing'][ $ls ] ) ) {
						$scale[ $tag ]['letter_spacing'][ $ls ] = 0;
					}
					$scale[ $tag ]['letter_spacing'][ $ls ]++;
				}
			}
		}

		// Sort each metric by frequency and keep top value.
		$result = array();
		foreach ( $scale as $tag => $metrics ) {
			$result[ $tag ] = array();
			foreach ( $metrics as $metric => $values ) {
				if ( ! empty( $values ) ) {
					arsort( $values );
					$result[ $tag ][ $metric ] = key( $values );
				}
			}
		}

		// Sort by tag name (h1, h2, h3...).
		ksort( $result );

		return $result;
	}

	/**
	 * Detect page type from template or slug heuristics.
	 *
	 * @param int $post_id The post ID.
	 * @return string The detected page type or empty string.
	 */
	private static function detect_page_type( $post_id ) {
		// Try page template first.
		$template = get_page_template_slug( $post_id );
		if ( ! empty( $template ) ) {
			$template_lower = strtolower( $template );
			$type_map = array(
				'landing' => 'landing',
				'about'   => 'about',
				'contact' => 'contact',
				'blog'    => 'blog',
				'service' => 'services',
				'pricing' => 'services',
				'portfolio' => 'portfolio',
			);
			foreach ( $type_map as $keyword => $type ) {
				if ( false !== strpos( $template_lower, $keyword ) ) {
					return $type;
				}
			}
		}

		// Fallback: detect from slug.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$slug  = strtolower( $post->post_name );
		$title = strtolower( $post->post_title );

		$slug_map = array(
			'landing'   => 'landing',
			'home'      => 'landing',
			'about'     => 'about',
			'ueber'     => 'about',
			'contact'   => 'contact',
			'kontakt'   => 'contact',
			'blog'      => 'blog',
			'news'      => 'blog',
			'service'   => 'services',
			'leistung'  => 'services',
			'pricing'   => 'services',
			'preis'     => 'services',
			'portfolio' => 'portfolio',
			'work'      => 'portfolio',
			'projekte'  => 'portfolio',
			'agency'    => 'agency',
			'agentur'   => 'agency',
			'saas'      => 'saas',
		);

		foreach ( $slug_map as $keyword => $type ) {
			if ( false !== strpos( $slug, $keyword ) || false !== strpos( $title, $keyword ) ) {
				return $type;
			}
		}

		// If it's the front page, treat as landing.
		if ( (int) get_option( 'page_on_front' ) === $post_id ) {
			return 'landing';
		}

		return '';
	}

	/**
	 * Build a fingerprint string for a subtree (element names + nesting).
	 *
	 * @param string $parent_id    The parent element ID.
	 * @param array  $children_map Map of parent_id => child elements.
	 * @return string
	 */
	private static function fingerprint_subtree( $parent_id, $children_map ) {
		$children = isset( $children_map[ $parent_id ] ) ? $children_map[ $parent_id ] : array();
		if ( empty( $children ) ) {
			return '';
		}

		$parts = array();
		foreach ( $children as $child ) {
			$sub = self::fingerprint_subtree( $child['id'], $children_map );
			$parts[] = $child['name'] . ( $sub ? '(' . $sub . ')' : '' );
		}

		return implode( ',', $parts );
	}
}
