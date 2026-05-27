<?php
/**
 * Advanced SEO Controller — extended SEO features.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_SEO_Controller {

	const NAMESPACE = 'bricks-bridge/v1';
	const REDIRECT_OPTION = 'bab_seo_redirects';

	public function register_routes() {
		// 1. Auto-Fix
		register_rest_route( self::NAMESPACE, '/seo/auto-fix', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'auto_fix' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 2. Bulk Update
		register_rest_route( self::NAMESPACE, '/seo/bulk-update', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'bulk_update' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 3. Readability
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/readability', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'readability' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 4. Sitemap Ping
		register_rest_route( self::NAMESPACE, '/seo/sitemap-ping', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'sitemap_ping' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 5. Social Preview
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/social-preview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'social_preview' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 6. SEO Plugin Detection
		register_rest_route( self::NAMESPACE, '/seo/plugin-info', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'plugin_info' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 7. Broken Link Checker
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/check-links', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'check_links' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 8. Sitemap Analysis
		register_rest_route( self::NAMESPACE, '/seo/sitemap-analyze', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sitemap_analyze' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 9. Competitor SEO Extract
		register_rest_route( self::NAMESPACE, '/seo/competitor-extract', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'competitor_extract' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 10. Redirects CRUD
		register_rest_route( self::NAMESPACE, '/seo/redirects', array(
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_redirects' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'create_redirect' ),
				'permission_callback' => array( $this, 'can_edit' ),
			),
		));
		register_rest_route( self::NAMESPACE, '/seo/redirects/(?P<id>[a-zA-Z0-9]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_redirect' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));

		// 11. Internal Linking Suggestions
		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/internal-links', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'internal_links' ),
			'permission_callback' => array( $this, 'can_edit' ),
		));
	}

	public function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Validate a URL against SSRF attacks.
	 *
	 * Blocks internal/private IPs (RFC1918, loopback, link-local)
	 * and non-http(s) schemes.
	 *
	 * @param string $url The URL to validate.
	 * @return true|WP_REST_Response True if safe, or error response.
	 */
	private function validate_url_ssrf( $url ) {
		$parsed = wp_parse_url( $url );
		$scheme = strtolower( $parsed['scheme'] ?? '' );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_scheme',
				'message' => 'Only http and https URLs are allowed.',
			), 400 );
		}

		$host       = $parsed['host'] ?? '';
		$host_clean = trim( $host, '[]' );

		// Block IPv6 private/loopback.
		if ( filter_var( $host_clean, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$blocked_ipv6 = array( '::1', 'fc', 'fd', 'fe80:' );
			$ip_lower     = strtolower( $host_clean );
			foreach ( $blocked_ipv6 as $prefix ) {
				if ( strpos( $ip_lower, $prefix ) === 0 || $ip_lower === '::1' ) {
					return new WP_REST_Response( array(
						'code'    => 'blocked_url',
						'message' => 'URLs pointing to internal/private networks are not allowed.',
					), 400 );
				}
			}
		}

		// Resolve hostname to IP and check IPv4 private ranges.
		$ip = gethostbyname( $host_clean );
		if ( $ip !== $host_clean || filter_var( $host_clean, FILTER_VALIDATE_IP ) ) {
			$check_ip = ( $ip !== $host_clean ) ? $ip : $host_clean;

			if ( filter_var( $check_ip, FILTER_VALIDATE_IP ) ) {
				$blocked_ipv4 = array( '127.', '10.', '192.168.', '0.', '169.254.' );
				foreach ( $blocked_ipv4 as $range ) {
					if ( strpos( $check_ip, $range ) === 0 ) {
						return new WP_REST_Response( array(
							'code'    => 'blocked_url',
							'message' => 'URLs pointing to internal/private networks are not allowed.',
						), 400 );
					}
				}
				// Block 172.16.0.0/12.
				$octets = explode( '.', $check_ip );
				if ( count( $octets ) === 4 && (int) $octets[0] === 172 && (int) $octets[1] >= 16 && (int) $octets[1] <= 31 ) {
					return new WP_REST_Response( array(
						'code'    => 'blocked_url',
						'message' => 'URLs pointing to internal/private networks are not allowed.',
					), 400 );
				}
			}
		}

		return true;
	}

	/**
	 * 1. Auto-Fix — generate missing SEO data from page content.
	 * POST /seo/auto-fix
	 * Body: { "page_ids": [1638, 1652] } or { "all": true }
	 */
	public function auto_fix( $request ) {
		$body    = $request->get_json_params();
		$all     = ! empty( $body['all'] );
		$dry_run = ! empty( $body['dry_run'] );

		if ( $all ) {
			$page_ids = get_posts( array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			));
		} else {
			$page_ids = isset( $body['page_ids'] ) ? array_map( 'intval', (array) $body['page_ids'] ) : array();
		}

		if ( empty( $page_ids ) ) {
			return new WP_REST_Response( array( 'code' => 'no_pages', 'message' => 'Provide page_ids array or set all:true' ), 400 );
		}

		$results = array();

		foreach ( $page_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$fixes = array();

			// Auto-generate SEO title from post title if missing.
			$existing_title = get_post_meta( $pid, '_bab_seo_title', true );
			if ( ! $existing_title ) {
				$auto_title = $post->post_title;
				$site_name  = get_bloginfo( 'name' );
				if ( $site_name && mb_strlen( $auto_title . ' — ' . $site_name ) <= 60 ) {
					$auto_title .= ' — ' . $site_name;
				}
				if ( ! $dry_run ) {
					update_post_meta( $pid, '_bab_seo_title', sanitize_text_field( $auto_title ) );
				}
				$fixes[] = array( 'field' => 'seo_title', 'value' => $auto_title );
			}

			// Auto-generate description from Bricks content if missing.
			$existing_desc = get_post_meta( $pid, '_bab_seo_description', true );
			if ( ! $existing_desc ) {
				$content = get_post_meta( $pid, '_bricks_page_content_2', true );
				$text    = '';
				if ( is_array( $content ) ) {
					foreach ( $content as $el ) {
						if ( isset( $el['name'] ) && in_array( $el['name'], array( 'text-basic', 'text', 'rich-text', 'heading' ), true ) ) {
							$el_text = isset( $el['settings']['text'] ) ? wp_strip_all_tags( $el['settings']['text'] ) : '';
							if ( $el_text ) {
								$text .= ' ' . $el_text;
							}
						}
					}
				}
				$text = trim( preg_replace( '/\s+/', ' ', $text ) );
				if ( mb_strlen( $text ) > 10 ) {
					$auto_desc = mb_strlen( $text ) > 155 ? mb_substr( $text, 0, 152 ) . '...' : $text;
					if ( ! $dry_run ) {
						update_post_meta( $pid, '_bab_seo_description', sanitize_text_field( $auto_desc ) );
					}
					$fixes[] = array( 'field' => 'description', 'value' => $auto_desc );
				}
			}

			// Auto-set canonical URL if missing.
			$existing_canonical = get_post_meta( $pid, '_bab_seo_canonical', true );
			if ( ! $existing_canonical ) {
				$canonical = get_permalink( $pid );
				if ( ! $dry_run ) {
					update_post_meta( $pid, '_bab_seo_canonical', esc_url_raw( $canonical ) );
				}
				$fixes[] = array( 'field' => 'canonical', 'value' => $canonical );
			}

			// Auto-set og_type if missing.
			$existing_og_type = get_post_meta( $pid, '_bab_seo_og_type', true );
			if ( ! $existing_og_type ) {
				$og_type = ( $post->post_type === 'post' ) ? 'article' : 'website';
				if ( ! $dry_run ) {
					update_post_meta( $pid, '_bab_seo_og_type', $og_type );
				}
				$fixes[] = array( 'field' => 'og_type', 'value' => $og_type );
			}

			if ( ! empty( $fixes ) ) {
				$results[] = array(
					'page_id' => $pid,
					'title'   => $post->post_title,
					'fixes'   => $fixes,
				);
			}
		}

		return new WP_REST_Response( array(
			'success'     => true,
			'dry_run'     => $dry_run,
			'pages_fixed' => count( $results ),
			'results'     => $results,
		), 200 );
	}

	/**
	 * 2. Bulk Update — update SEO fields for multiple pages at once.
	 * PUT /seo/bulk-update
	 * Body: { "page_ids": [1638, 1652], "fields": { "noindex": true, "og_type": "website" } }
	 */
	public function bulk_update( $request ) {
		$body     = $request->get_json_params();
		$page_ids = isset( $body['page_ids'] ) ? array_map( 'intval', (array) $body['page_ids'] ) : array();
		$fields   = isset( $body['fields'] ) ? (array) $body['fields'] : array();

		if ( empty( $page_ids ) ) {
			return new WP_REST_Response( array( 'code' => 'no_pages', 'message' => 'page_ids array is required' ), 400 );
		}
		if ( empty( $fields ) ) {
			return new WP_REST_Response( array( 'code' => 'no_fields', 'message' => 'fields object is required' ), 400 );
		}

		$field_map = array(
			'seo_title'           => '_bab_seo_title',
			'description'         => '_bab_seo_description',
			'og_image'            => '_bab_seo_og_image',
			'keywords'            => '_bab_seo_keywords',
			'og_type'             => '_bab_seo_og_type',
			'canonical'           => '_bab_seo_canonical',
			'focus_keyword'       => '_bab_seo_focus_keyword',
			'og_title'            => '_bab_seo_og_title',
			'twitter_title'       => '_bab_seo_twitter_title',
			'twitter_description' => '_bab_seo_twitter_description',
			'twitter_image'       => '_bab_seo_twitter_image',
		);
		$bool_map = array(
			'noindex'  => '_bab_seo_noindex',
			'nofollow' => '_bab_seo_nofollow',
		);

		$updated_count = 0;
		$updated_pages = array();

		foreach ( $page_ids as $pid ) {
			if ( ! get_post( $pid ) ) {
				continue;
			}
			$page_updated = array();
			foreach ( $fields as $key => $value ) {
				if ( isset( $field_map[ $key ] ) ) {
					update_post_meta( $pid, $field_map[ $key ], sanitize_text_field( $value ) );
					$page_updated[] = $key;
				} elseif ( isset( $bool_map[ $key ] ) ) {
					update_post_meta( $pid, $bool_map[ $key ], $value ? '1' : '' );
					$page_updated[] = $key;
				}
			}
			if ( ! empty( $page_updated ) ) {
				$updated_count++;
				$updated_pages[] = array( 'page_id' => $pid, 'fields' => $page_updated );
			}
		}

		return new WP_REST_Response( array(
			'success'       => true,
			'pages_updated' => $updated_count,
			'results'       => $updated_pages,
		), 200 );
	}

	/**
	 * 3. Readability — Flesch-Kincaid (EN) + Flesch-Amstad (DE) scoring.
	 * GET /pages/{id}/readability
	 */
	public function readability( $request ) {
		$id = (int) $request['id'];

		if ( ! get_post( $id ) ) {
			return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
		}

		$content = get_post_meta( $id, '_bricks_page_content_2', true );
		$text    = '';
		if ( is_array( $content ) ) {
			foreach ( $content as $el ) {
				if ( isset( $el['name'] ) && in_array( $el['name'], array( 'text-basic', 'text', 'rich-text', 'heading' ), true ) ) {
					$el_text = isset( $el['settings']['text'] ) ? wp_strip_all_tags( $el['settings']['text'] ) : '';
					if ( $el_text ) {
						$text .= ' ' . $el_text;
					}
				}
			}
		}

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( mb_strlen( $text ) < 20 ) {
			return new WP_REST_Response( array(
				'page_id' => $id,
				'error'   => 'Not enough text content for readability analysis (minimum 20 chars).',
			), 200 );
		}

		// Split into sentences (by . ! ? followed by space or end).
		$sentences = preg_split( '/[.!?]+(?:\s|$)/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$sentence_count = max( count( $sentences ), 1 );

		// Split into words.
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$word_count = count( $words );

		// Count syllables.
		$total_syllables = 0;
		foreach ( $words as $word ) {
			$total_syllables += $this->count_syllables( $word );
		}

		$avg_sentence_length = $word_count / $sentence_count;
		$avg_syllables_word  = $word_count > 0 ? $total_syllables / $word_count : 0;

		// Flesch-Kincaid Reading Ease (English).
		$fk_score = 206.835 - ( 1.015 * $avg_sentence_length ) - ( 84.6 * $avg_syllables_word );
		$fk_score = round( max( 0, min( 100, $fk_score ) ), 1 );

		// Flesch-Amstad (German adaptation).
		$fa_score = 180 - $avg_sentence_length - ( 58.5 * $avg_syllables_word );
		$fa_score = round( max( 0, min( 100, $fa_score ) ), 1 );

		// Grade levels.
		$fk_grade = $this->flesch_grade( $fk_score );
		$fa_grade = $this->flesch_grade( $fa_score );

		return new WP_REST_Response( array(
			'page_id'              => $id,
			'word_count'           => $word_count,
			'sentence_count'       => $sentence_count,
			'syllable_count'       => $total_syllables,
			'avg_sentence_length'  => round( $avg_sentence_length, 1 ),
			'avg_syllables_word'   => round( $avg_syllables_word, 2 ),
			'flesch_kincaid'       => array(
				'score' => $fk_score,
				'grade' => $fk_grade,
				'label' => 'Flesch-Kincaid Reading Ease (English)',
			),
			'flesch_amstad'        => array(
				'score' => $fa_score,
				'grade' => $fa_grade,
				'label' => 'Flesch-Amstad (German)',
			),
			'recommendation'       => $this->readability_recommendation( $fa_score ),
		), 200 );
	}

	private function count_syllables( $word ) {
		$word = mb_strtolower( preg_replace( '/[^a-zA-ZäöüÄÖÜß]/', '', $word ) );
		if ( mb_strlen( $word ) <= 2 ) {
			return 1;
		}
		// Count vowel groups (including German umlauts).
		$count = preg_match_all( '/[aeiouyäöü]+/i', $word, $matches );
		// Subtract silent e at end (English pattern).
		if ( preg_match( '/[^aeiouyäöü]e$/i', $word ) && $count > 1 ) {
			$count--;
		}
		return max( $count, 1 );
	}

	private function flesch_grade( $score ) {
		if ( $score >= 90 ) return 'Very Easy (Grade 5)';
		if ( $score >= 80 ) return 'Easy (Grade 6)';
		if ( $score >= 70 ) return 'Fairly Easy (Grade 7)';
		if ( $score >= 60 ) return 'Standard (Grade 8-9)';
		if ( $score >= 50 ) return 'Fairly Difficult (Grade 10-12)';
		if ( $score >= 30 ) return 'Difficult (College)';
		return 'Very Difficult (Professional)';
	}

	private function readability_recommendation( $score ) {
		if ( $score >= 60 ) {
			return 'Good readability — text is accessible to a broad audience.';
		}
		if ( $score >= 40 ) {
			return 'Moderate readability — consider shortening sentences or using simpler words for wider reach.';
		}
		return 'Low readability — text is complex. Use shorter sentences and simpler vocabulary.';
	}

	/**
	 * 4. Sitemap Ping — notify Google and Bing about sitemap updates.
	 * POST /seo/sitemap-ping
	 */
	public function sitemap_ping( $request ) {
		$body        = $request->get_json_params();
		$sitemap_url = ! empty( $body['sitemap_url'] )
			? $body['sitemap_url']
			: trailingslashit( home_url() ) . 'sitemap.xml';

		$engines = array(
			'Google'     => 'https://www.google.com/ping?sitemap=' . urlencode( $sitemap_url ),
			'Bing'       => 'https://www.bing.com/ping?sitemap=' . urlencode( $sitemap_url ),
			'IndexNow'   => 'https://api.indexnow.org/indexnow?url=' . urlencode( home_url() ) . '&urlList=' . urlencode( $sitemap_url ),
		);

		$results = array();
		foreach ( $engines as $name => $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => true ) );
			$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$results[ $name ] = array(
				'status'  => ( $code >= 200 && $code < 400 ) ? 'ok' : 'error',
				'code'    => $code,
				'message' => is_wp_error( $response ) ? $response->get_error_message() : '',
			);
		}

		return new WP_REST_Response( array(
			'success'     => true,
			'sitemap_url' => $sitemap_url,
			'pinged'      => $results,
		), 200 );
	}

	/**
	 * 5. Social Preview — check OG image dimensions and preview data.
	 * GET /pages/{id}/social-preview
	 */
	public function social_preview( $request ) {
		$id = (int) $request['id'];

		if ( ! get_post( $id ) ) {
			return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
		}

		$seo_title           = get_post_meta( $id, '_bab_seo_title', true ) ?: get_the_title( $id );
		$description         = get_post_meta( $id, '_bab_seo_description', true );
		$og_image            = get_post_meta( $id, '_bab_seo_og_image', true );
		$og_title            = get_post_meta( $id, '_bab_seo_og_title', true ) ?: $seo_title;
		$twitter_title       = get_post_meta( $id, '_bab_seo_twitter_title', true ) ?: $seo_title;
		$twitter_description = get_post_meta( $id, '_bab_seo_twitter_description', true ) ?: $description;
		$twitter_image       = get_post_meta( $id, '_bab_seo_twitter_image', true ) ?: $og_image;

		$issues = array();

		// Check OG image dimensions.
		$og_image_info = null;
		if ( $og_image ) {
			$image_path = $this->url_to_local_path( $og_image );
			if ( $image_path && file_exists( $image_path ) ) {
				$size = getimagesize( $image_path );
				if ( $size ) {
					$og_image_info = array(
						'width'  => $size[0],
						'height' => $size[1],
						'ratio'  => round( $size[0] / max( $size[1], 1 ), 2 ),
					);
					if ( $size[0] < 1200 || $size[1] < 630 ) {
						$issues[] = "OG image is {$size[0]}x{$size[1]} — recommended: 1200x630 minimum";
					}
					if ( abs( ( $size[0] / max( $size[1], 1 ) ) - 1.905 ) > 0.3 ) {
						$issues[] = 'OG image aspect ratio should be close to 1.91:1 (1200x628)';
					}
				}
			}
		} else {
			$issues[] = 'No OG image set';
		}

		// Title length checks.
		if ( mb_strlen( $og_title ) > 60 ) {
			$issues[] = 'OG title may be truncated on Facebook (>' . mb_strlen( $og_title ) . ' chars, max ~60)';
		}
		if ( mb_strlen( $twitter_title ) > 70 ) {
			$issues[] = 'Twitter title may be truncated (>' . mb_strlen( $twitter_title ) . ' chars, max ~70)';
		}

		// Description length checks.
		if ( $description && mb_strlen( $description ) > 200 ) {
			$issues[] = 'Description may be truncated on some platforms (' . mb_strlen( $description ) . ' chars)';
		}

		return new WP_REST_Response( array(
			'page_id'  => $id,
			'facebook' => array(
				'title'       => $og_title,
				'description' => $description ?: '',
				'image'       => $og_image ?: '',
				'image_info'  => $og_image_info,
				'url'         => get_permalink( $id ),
			),
			'twitter'  => array(
				'card'        => 'summary_large_image',
				'title'       => $twitter_title,
				'description' => $twitter_description ?: '',
				'image'       => $twitter_image ?: '',
			),
			'issues'   => $issues,
		), 200 );
	}

	private function url_to_local_path( $url ) {
		$upload_dir = wp_upload_dir();
		if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
			return str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
		}
		return null;
	}

	/**
	 * 6. SEO Plugin Detection — detect installed SEO plugins and their meta keys.
	 * GET /seo/plugin-info
	 */
	public function plugin_info( $request ) {
		$plugins = array();

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) || is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			$plugins[] = array(
				'name'      => 'Yoast SEO',
				'active'    => true,
				'version'   => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : 'unknown',
				'meta_keys' => array(
					'title'       => '_yoast_wpseo_title',
					'description' => '_yoast_wpseo_metadesc',
					'focus_kw'    => '_yoast_wpseo_focuskw',
					'canonical'   => '_yoast_wpseo_canonical',
					'noindex'     => '_yoast_wpseo_meta-robots-noindex',
				),
			);
		}

		// Rank Math.
		if ( class_exists( 'RankMath' ) || is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			$plugins[] = array(
				'name'      => 'Rank Math',
				'active'    => true,
				'version'   => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'unknown',
				'meta_keys' => array(
					'title'       => 'rank_math_title',
					'description' => 'rank_math_description',
					'focus_kw'    => 'rank_math_focus_keyword',
					'canonical'   => 'rank_math_canonical_url',
					'robots'      => 'rank_math_robots',
				),
			);
		}

		// All in One SEO.
		if ( class_exists( 'AIOSEO\Plugin\AIOSEO' ) || is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) {
			$plugins[] = array(
				'name'      => 'All in One SEO',
				'active'    => true,
				'version'   => defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : 'unknown',
				'meta_keys' => array(
					'title'       => '_aioseo_title',
					'description' => '_aioseo_description',
					'canonical'   => '_aioseo_canonical_url',
				),
			);
		}

		// The SEO Framework.
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$plugins[] = array(
				'name'      => 'The SEO Framework',
				'active'    => true,
				'version'   => THE_SEO_FRAMEWORK_VERSION,
				'meta_keys' => array(
					'title'       => '_genesis_title',
					'description' => '_genesis_description',
					'canonical'   => '_genesis_canonical_uri',
					'noindex'     => '_genesis_noindex',
				),
			);
		}

		return new WP_REST_Response( array(
			'success'           => true,
			'seo_plugins_found' => count( $plugins ),
			'plugins'           => $plugins,
			'bab_active'        => true,
			'recommendation'    => empty( $plugins )
				? 'No SEO plugins detected — BAB handles all SEO output.'
				: 'SEO plugin detected — BAB output may conflict. Consider using plugin meta keys or disabling BAB SEO output.',
		), 200 );
	}

	/**
	 * 7. Broken Link Checker — check all links on a page.
	 * POST /pages/{id}/check-links
	 */
	public function check_links( $request ) {
		$id = (int) $request['id'];

		if ( ! get_post( $id ) ) {
			return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
		}

		$content = get_post_meta( $id, '_bricks_page_content_2', true );
		$links   = array();

		if ( is_array( $content ) ) {
			foreach ( $content as $el ) {
				$settings = isset( $el['settings'] ) ? $el['settings'] : array();

				// Links in text content.
				if ( isset( $settings['text'] ) ) {
					preg_match_all( '/<a[^>]+href=["\']([^"\'#][^"\']*)["\'][^>]*>/i', $settings['text'], $m );
					if ( ! empty( $m[1] ) ) {
						foreach ( $m[1] as $url ) {
							$links[] = array( 'url' => $url, 'source_element' => $el['id'], 'type' => 'content' );
						}
					}
				}

				// Link field on element.
				if ( isset( $settings['link']['url'] ) && $settings['link']['url'] ) {
					$links[] = array( 'url' => $settings['link']['url'], 'source_element' => $el['id'], 'type' => 'element_link' );
				}
			}
		}

		// Deduplicate by URL.
		$unique_urls = array();
		$link_map    = array();
		foreach ( $links as $link ) {
			$url = $link['url'];
			if ( ! isset( $link_map[ $url ] ) ) {
				$link_map[ $url ] = array();
			}
			$link_map[ $url ][] = $link['source_element'];
			$unique_urls[ $url ] = true;
		}

		$results = array();
		$broken  = 0;

		foreach ( array_keys( $unique_urls ) as $url ) {
			// Skip mailto:, tel:, javascript:
			if ( preg_match( '/^(mailto|tel|javascript):/i', $url ) ) {
				continue;
			}

			// Make relative URLs absolute.
			$check_url = $url;
			if ( strpos( $url, '/' ) === 0 ) {
				$check_url = home_url( $url );
			}

			$response = wp_remote_head( $check_url, array(
				'timeout'     => 8,
				'sslverify'   => true,
				'redirection' => 3,
			));

			$status = 0;
			$error  = '';
			if ( is_wp_error( $response ) ) {
				$status = 0;
				$error  = $response->get_error_message();
			} else {
				$status = wp_remote_retrieve_response_code( $response );
			}

			$is_broken = ( $status === 0 || $status >= 400 );
			if ( $is_broken ) {
				$broken++;
			}

			$entry = array(
				'url'      => $url,
				'status'   => $status,
				'ok'       => ! $is_broken,
				'elements' => $link_map[ $url ],
			);
			if ( $error ) {
				$entry['error'] = $error;
			}

			$results[] = $entry;
		}

		// Sort: broken first.
		usort( $results, function ( $a, $b ) {
			if ( $a['ok'] === $b['ok'] ) return 0;
			return $a['ok'] ? 1 : -1;
		});

		return new WP_REST_Response( array(
			'success'      => true,
			'page_id'      => $id,
			'links_total'  => count( $results ),
			'links_broken' => $broken,
			'links_ok'     => count( $results ) - $broken,
			'results'      => $results,
		), 200 );
	}

	/**
	 * 8. Sitemap Analysis — fetch and analyze the XML sitemap.
	 * GET /seo/sitemap-analyze
	 */
	public function sitemap_analyze( $request ) {
		$sitemap_url = $request->get_param( 'url' )
			?: trailingslashit( home_url() ) . 'sitemap.xml';

		// SSRF protection for user-supplied sitemap URLs.
		$ssrf_check = $this->validate_url_ssrf( $sitemap_url );
		if ( $ssrf_check !== true ) {
			return $ssrf_check;
		}

		$response = wp_remote_get( $sitemap_url, array(
			'timeout'   => 15,
			'sslverify' => true,
		));

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'code'    => 'fetch_error',
				'message' => 'Could not fetch sitemap: ' . $response->get_error_message(),
			), 400 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_REST_Response( array(
				'code'    => 'sitemap_error',
				'message' => "Sitemap returned HTTP {$code}",
			), 400 );
		}

		$body = wp_remote_retrieve_body( $response );
		$xml  = @simplexml_load_string( $body );

		if ( ! $xml ) {
			return new WP_REST_Response( array(
				'code'    => 'parse_error',
				'message' => 'Could not parse XML sitemap.',
			), 400 );
		}

		// Detect sitemap index vs. regular sitemap.
		$is_index  = isset( $xml->sitemap );
		$urls      = array();
		$sub_maps  = array();

		if ( $is_index ) {
			foreach ( $xml->sitemap as $entry ) {
				$sub_maps[] = array(
					'loc'     => (string) $entry->loc,
					'lastmod' => isset( $entry->lastmod ) ? (string) $entry->lastmod : null,
				);
			}
		} else {
			$ns = $xml->getNamespaces( true );
			foreach ( $xml->url as $entry ) {
				$url_entry = array(
					'loc'     => (string) $entry->loc,
					'lastmod' => isset( $entry->lastmod ) ? (string) $entry->lastmod : null,
				);
				if ( isset( $entry->changefreq ) ) {
					$url_entry['changefreq'] = (string) $entry->changefreq;
				}
				if ( isset( $entry->priority ) ) {
					$url_entry['priority'] = (string) $entry->priority;
				}
				$urls[] = $url_entry;
			}
		}

		// Compare with published pages to find missing ones.
		$published_pages = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
		));

		$sitemap_urls = array_map( function ( $u ) { return rtrim( $u['loc'], '/' ); }, $urls );
		$missing      = array();
		$noindex_in_sitemap = array();

		foreach ( $published_pages as $pid ) {
			$permalink = rtrim( get_permalink( $pid ), '/' );
			$noindex   = get_post_meta( $pid, '_bab_seo_noindex', true );

			if ( ! in_array( $permalink, $sitemap_urls, true ) && ! $noindex ) {
				$missing[] = array(
					'page_id' => $pid,
					'title'   => get_the_title( $pid ),
					'url'     => $permalink,
				);
			}

			if ( in_array( $permalink, $sitemap_urls, true ) && $noindex ) {
				$noindex_in_sitemap[] = array(
					'page_id' => $pid,
					'title'   => get_the_title( $pid ),
					'url'     => $permalink,
					'issue'   => 'Page is noindex but listed in sitemap',
				);
			}
		}

		return new WP_REST_Response( array(
			'success'             => true,
			'sitemap_url'         => $sitemap_url,
			'is_index'            => $is_index,
			'sub_sitemaps'        => $is_index ? $sub_maps : null,
			'url_count'           => count( $urls ),
			'urls'                => $is_index ? null : $urls,
			'missing_from_sitemap'=> $missing,
			'noindex_in_sitemap'  => $noindex_in_sitemap,
			'issues_count'        => count( $missing ) + count( $noindex_in_sitemap ),
		), 200 );
	}

	/**
	 * 9. Competitor SEO Extract — fetch meta tags from an external URL.
	 * POST /seo/competitor-extract
	 * Body: { "url": "https://example.com" }
	 */
	public function competitor_extract( $request ) {
		$body = $request->get_json_params();
		$url  = isset( $body['url'] ) ? esc_url_raw( $body['url'] ) : '';

		if ( ! $url ) {
			return new WP_REST_Response( array( 'code' => 'no_url', 'message' => 'url is required' ), 400 );
		}

		// SSRF protection: block internal/private network URLs.
		$ssrf_check = $this->validate_url_ssrf( $url );
		if ( $ssrf_check !== true ) {
			return $ssrf_check;
		}

		$response = wp_remote_get( $url, array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'User-Agent' => 'Mozilla/5.0 (compatible; BricksBot/1.0)',
			),
		));

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'code'    => 'fetch_error',
				'message' => 'Could not fetch URL: ' . $response->get_error_message(),
			), 400 );
		}

		$html = wp_remote_retrieve_body( $response );

		// Parse meta tags.
		$data = array( 'url' => $url );

		// Title.
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
			$data['title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Meta tags.
		preg_match_all( '/<meta[^>]+>/i', $html, $meta_tags );
		foreach ( $meta_tags[0] as $tag ) {
			$name    = '';
			$prop    = '';
			$content = '';

			if ( preg_match( '/name=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				$name = strtolower( $m[1] );
			}
			if ( preg_match( '/property=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				$prop = strtolower( $m[1] );
			}
			if ( preg_match( '/content=["\']([^"\']*(?:["\'][^"\']*)*?)["\']/i', $tag, $m ) ) {
				$content = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			}

			if ( $name === 'description' ) $data['description'] = $content;
			if ( $name === 'keywords' ) $data['keywords'] = $content;
			if ( $name === 'robots' ) $data['robots'] = $content;
			if ( $prop === 'og:title' ) $data['og_title'] = $content;
			if ( $prop === 'og:description' ) $data['og_description'] = $content;
			if ( $prop === 'og:image' ) $data['og_image'] = $content;
			if ( $prop === 'og:type' ) $data['og_type'] = $content;
			if ( $prop === 'og:url' ) $data['og_url'] = $content;
			if ( $name === 'twitter:card' ) $data['twitter_card'] = $content;
			if ( $name === 'twitter:title' ) $data['twitter_title'] = $content;
			if ( $name === 'twitter:description' ) $data['twitter_description'] = $content;
			if ( $name === 'twitter:image' ) $data['twitter_image'] = $content;
		}

		// Canonical.
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$data['canonical'] = $m[1];
		}

		// H1.
		preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $h1s );
		$data['h1_tags'] = array_map( function ( $h ) {
			return trim( wp_strip_all_tags( $h ) );
		}, $h1s[1] );

		// JSON-LD.
		preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ld );
		$data['json_ld'] = array();
		foreach ( $ld[1] as $json_str ) {
			$decoded = json_decode( trim( $json_str ), true );
			if ( $decoded ) {
				$data['json_ld'][] = $decoded;
			}
		}

		// Title/Description quality.
		$analysis = array();
		if ( isset( $data['title'] ) ) {
			$len = mb_strlen( $data['title'] );
			$analysis['title_length'] = $len;
			$analysis['title_quality'] = ( $len >= 30 && $len <= 60 ) ? 'good' : 'suboptimal';
		}
		if ( isset( $data['description'] ) ) {
			$len = mb_strlen( $data['description'] );
			$analysis['description_length'] = $len;
			$analysis['description_quality'] = ( $len >= 120 && $len <= 160 ) ? 'good' : 'suboptimal';
		}
		$data['analysis'] = $analysis;

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * 10a. Get Redirects.
	 * GET /seo/redirects
	 */
	public function get_redirects( $request ) {
		$redirects = get_option( self::REDIRECT_OPTION, array() );
		return new WP_REST_Response( array(
			'success'   => true,
			'count'     => count( $redirects ),
			'redirects' => $redirects,
		), 200 );
	}

	/**
	 * 10b. Create Redirect.
	 * POST /seo/redirects
	 * Body: { "source": "/old-page", "target": "https://example.com/new", "type": 301 }
	 */
	public function create_redirect( $request ) {
		$body   = $request->get_json_params();
		$source = isset( $body['source'] ) ? trim( $body['source'] ) : '';
		$target = isset( $body['target'] ) ? trim( $body['target'] ) : '';
		$type   = isset( $body['type'] ) ? intval( $body['type'] ) : 301;

		if ( ! $source || ! $target ) {
			return new WP_REST_Response( array( 'code' => 'missing_fields', 'message' => 'source and target are required' ), 400 );
		}
		if ( ! in_array( $type, array( 301, 302, 307 ), true ) ) {
			return new WP_REST_Response( array( 'code' => 'invalid_type', 'message' => 'type must be 301, 302, or 307' ), 400 );
		}

		$redirects = get_option( self::REDIRECT_OPTION, array() );

		// Check for duplicate source.
		foreach ( $redirects as $r ) {
			if ( $r['source'] === $source ) {
				return new WP_REST_Response( array(
					'code'    => 'duplicate',
					'message' => "Redirect for '{$source}' already exists (ID: {$r['id']})",
				), 409 );
			}
		}

		$redirect = array(
			'id'       => substr( md5( uniqid( '', true ) ), 0, 8 ),
			'source'   => $source,
			'target'   => $target,
			'type'     => $type,
			'hits'     => 0,
			'created'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'last_hit' => null,
		);

		$redirects[] = $redirect;
		update_option( self::REDIRECT_OPTION, $redirects );

		return new WP_REST_Response( array(
			'success'  => true,
			'redirect' => $redirect,
		), 201 );
	}

	/**
	 * 10c. Delete Redirect.
	 * DELETE /seo/redirects/{id}
	 */
	public function delete_redirect( $request ) {
		$id        = $request['id'];
		$redirects = get_option( self::REDIRECT_OPTION, array() );
		$found     = false;

		$redirects = array_values( array_filter( $redirects, function ( $r ) use ( $id, &$found ) {
			if ( $r['id'] === $id ) {
				$found = true;
				return false;
			}
			return true;
		}));

		if ( ! $found ) {
			return new WP_REST_Response( array( 'code' => 'not_found', 'message' => "Redirect '{$id}' not found" ), 404 );
		}

		update_option( self::REDIRECT_OPTION, $redirects );

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $id ), 200 );
	}

	/**
	 * Perform redirects on frontend.
	 */
	public static function handle_redirects() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return;
		}

		$redirects = get_option( self::REDIRECT_OPTION, array() );
		if ( empty( $redirects ) ) {
			return;
		}

		$request_path = rtrim( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
		if ( ! $request_path ) {
			$request_path = '/';
		}

		foreach ( $redirects as &$r ) {
			$source = rtrim( $r['source'], '/' );
			if ( ! $source ) {
				$source = '/';
			}
			if ( $request_path === $source ) {
				// Increment hit counter.
				$r['hits']++;
				$r['last_hit'] = gmdate( 'Y-m-d\TH:i:s\Z' );
				update_option( self::REDIRECT_OPTION, $redirects );

				wp_redirect( $r['target'], $r['type'] );
				exit;
			}
		}
	}

	/**
	 * 11. Internal Linking Suggestions — find related pages based on shared keywords.
	 * GET /pages/{id}/internal-links
	 */
	public function internal_links( $request ) {
		$id = (int) $request['id'];

		if ( ! get_post( $id ) ) {
			return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
		}

		// Extract text from current page.
		$source_text  = $this->extract_page_text( $id );
		$source_words = $this->extract_keywords( $source_text );

		if ( empty( $source_words ) ) {
			return new WP_REST_Response( array(
				'page_id'     => $id,
				'suggestions' => array(),
				'message'     => 'Not enough text content for linking suggestions.',
			), 200 );
		}

		// Get all other published pages.
		$other_pages = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'exclude'        => array( $id ),
			'fields'         => 'ids',
		));

		$suggestions = array();

		foreach ( $other_pages as $pid ) {
			$noindex = get_post_meta( $pid, '_bab_seo_noindex', true );
			if ( $noindex ) {
				continue;
			}

			$target_text  = $this->extract_page_text( $pid );
			$target_words = $this->extract_keywords( $target_text );

			// Calculate keyword overlap.
			$common = array_intersect_key( $source_words, $target_words );
			if ( empty( $common ) ) {
				continue;
			}

			// Score: sum of min frequencies of common keywords.
			$score = 0;
			$shared_keywords = array();
			foreach ( $common as $word => $freq ) {
				$score += min( $source_words[ $word ], $target_words[ $word ] );
				$shared_keywords[] = $word;
			}

			if ( $score >= 2 ) {
				$suggestions[] = array(
					'page_id'         => $pid,
					'title'           => get_the_title( $pid ),
					'url'             => get_permalink( $pid ),
					'relevance_score' => $score,
					'shared_keywords' => array_slice( $shared_keywords, 0, 10 ),
				);
			}
		}

		// Sort by score descending.
		usort( $suggestions, function ( $a, $b ) {
			return $b['relevance_score'] - $a['relevance_score'];
		});

		// Top 10.
		$suggestions = array_slice( $suggestions, 0, 10 );

		return new WP_REST_Response( array(
			'page_id'     => $id,
			'page_title'  => get_the_title( $id ),
			'suggestions' => $suggestions,
		), 200 );
	}

	private function extract_page_text( $page_id ) {
		$content = get_post_meta( $page_id, '_bricks_page_content_2', true );
		$text    = '';
		if ( is_array( $content ) ) {
			foreach ( $content as $el ) {
				if ( isset( $el['name'] ) && in_array( $el['name'], array( 'text-basic', 'text', 'rich-text', 'heading' ), true ) ) {
					$el_text = isset( $el['settings']['text'] ) ? wp_strip_all_tags( $el['settings']['text'] ) : '';
					if ( $el_text ) {
						$text .= ' ' . $el_text;
					}
				}
			}
		}
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private function extract_keywords( $text ) {
		if ( ! $text ) return array();

		$text  = mb_strtolower( $text );
		$words = preg_split( '/[\s,.\-:;!?()]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		// Stop words (DE + EN).
		$stop = array_flip( array(
			'der','die','das','den','dem','des','ein','eine','einer','eines','einem','einen',
			'und','oder','aber','als','auch','auf','aus','bei','bin','bis','da','dann','denn',
			'doch','du','durch','er','es','für','hat','ich','ihm','ihn','ihr','im','in','ist',
			'ja','kann','kein','keine','man','mit','nach','nicht','noch','nun','nur','ob','so',
			'sie','sind','um','uns','von','vor','was','wir','wird','zu','zum','zur',
			'the','a','an','and','or','but','is','are','was','were','be','been','being',
			'have','has','had','do','does','did','will','would','shall','should','may','might',
			'can','could','of','in','to','for','with','on','at','by','from','as','into','this',
			'that','it','its','not','no','if','all','any','each','our','your','their','we','you',
		));

		$freq = array();
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) < 3 || isset( $stop[ $w ] ) ) {
				continue;
			}
			if ( ! isset( $freq[ $w ] ) ) {
				$freq[ $w ] = 0;
			}
			$freq[ $w ]++;
		}

		// Only keep words that appear at least twice, or are longer than 5 chars.
		$keywords = array();
		foreach ( $freq as $w => $count ) {
			if ( $count >= 2 || mb_strlen( $w ) > 5 ) {
				$keywords[ $w ] = $count;
			}
		}

		arsort( $keywords );
		return array_slice( $keywords, 0, 50, true );
	}
}
