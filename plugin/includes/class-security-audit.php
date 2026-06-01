<?php
/**
 * Bricks API Bridge — Security Audit.
 *
 * Read-only, scored security posture report. Mirrors the SEO-audit family:
 * each check contributes points to a category max; the overall score is the
 * point-weighted percentage. Any open CRITICAL finding hard-caps the grade
 * to F so a fatal exposure can never show green.
 *
 * Positioning: posture + exposure + outdated-component layer. This is NOT a
 * malware scanner (no filesystem integrity / shell access) and does NOT apply
 * hardening — the bridge ships hardening always-on via the hardening class.
 *
 * Detection happens entirely plugin-side here (versions, config constants,
 * page-data code elements, bridge route/permission self-diff). Remote-HTTP
 * probes (exposed files, header survival) are performed MCP-side by the tool.
 *
 * Loaded + instantiated only when get_option('bab_security_audit_enabled')
 * is truthy (default true), so flipping the option to false unregisters the
 * routes for a sub-2-minute rollback without a code revert.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_Security_Audit {

	const NAMESPACE = 'bricks-bridge/v1';

	/**
	 * Bricks core CVE fix-lines. Each entry: the last affected version, the
	 * version that fixes it, severity, auth requirement, and a note. Detection
	 * is version-only — the audit NEVER sends an exploit payload.
	 *
	 * @var array[]
	 */
	private static $bricks_cves = array(
		array(
			'cve'        => 'CVE-2024-25600',
			'affected'   => '1.9.6',   // <= affected
			'fixed_in'   => '1.9.6.1',
			'severity'   => 'critical',
			'auth'       => 'unauthenticated',
			'note'       => 'Unauthenticated RCE via eval() in render_element. Actively exploited since 2024-02.',
		),
		array(
			'cve'        => 'CVE-2024-2297',
			'affected'   => '1.9.6.1',
			'fixed_in'   => '1.9.7',
			'severity'   => 'high',
			'auth'       => 'authenticated (Contributor+), conditional',
			'note'       => 'Privilege escalation via create_autosave. Only exploitable with Builder-access + code-execution enabled for the role.',
		),
		array(
			'cve'        => 'CVE-2025-6495',
			'affected'   => '1.12.4',
			'fixed_in'   => '1.12.5',
			'severity'   => 'high',
			'auth'       => 'unauthenticated',
			'note'       => 'Blind SQLi via the p parameter. Real-world exploitation is complex (blind, no direct read/write).',
		),
	);

	/**
	 * PHP branch end-of-life dates (security support). Used to flag EOL runtimes.
	 *
	 * @var array<string,string>
	 */
	private static $php_eol = array(
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
	);

	/**
	 * Register the security routes (called on rest_api_init).
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes. Both require manage_options — a software inventory
	 * and a security posture report are administrator-level information.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/security/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'run_audit' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/security/inventory/components',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	/**
	 * Permission callback — administrators only.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'bricks_api_bridge_forbidden',
				__( 'The security audit requires administrator privileges.', 'bricks-api-bridge' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Component inventory
	 * ------------------------------------------------------------------- */

	/**
	 * Exact software inventory — slugs + versions, active and inactive, plus
	 * known-available updates (read from WordPress' own update transients, so
	 * no outbound network call is made here).
	 *
	 * @return WP_REST_Response
	 */
	public function get_inventory( $request = null ) {
		return new WP_REST_Response( $this->build_inventory(), 200 );
	}

	/**
	 * Build the inventory array (shared by the inventory route and the audit).
	 *
	 * @return array
	 */
	private function build_inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$core_updates   = get_site_transient( 'update_core' );

		$plugins = array();
		foreach ( $all_plugins as $file => $data ) {
			$slug      = dirname( $file );
			$slug      = ( '.' === $slug ) ? $file : $slug;
			$available = '';
			if ( isset( $plugin_updates->response[ $file ]->new_version ) ) {
				$available = $plugin_updates->response[ $file ]->new_version;
			}
			$plugins[] = array(
				'slug'              => $slug,
				'file'              => $file,
				'name'              => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version'           => isset( $data['Version'] ) ? $data['Version'] : '',
				'active'            => in_array( $file, $active_plugins, true ),
				'update_available'  => $available,
			);
		}

		$themes = array();
		if ( function_exists( 'wp_get_themes' ) ) {
			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				$available = '';
				if ( isset( $theme_updates->response[ $stylesheet ]['new_version'] ) ) {
					$available = $theme_updates->response[ $stylesheet ]['new_version'];
				}
				$themes[] = array(
					'slug'             => $stylesheet,
					'name'             => $theme->get( 'Name' ),
					'version'          => $theme->get( 'Version' ),
					'is_child'         => (bool) $theme->parent(),
					'update_available' => $available,
				);
			}
		}

		$active_theme  = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$bricks_theme  = function_exists( 'wp_get_theme' ) ? wp_get_theme( 'bricks' ) : null;
		$bricks_ver    = ( $bricks_theme && $bricks_theme->exists() ) ? $bricks_theme->get( 'Version' ) : '';

		$core_available = '';
		if ( isset( $core_updates->updates[0]->current ) && isset( $core_updates->updates[0]->response )
			&& 'upgrade' === $core_updates->updates[0]->response ) {
			$core_available = $core_updates->updates[0]->current;
		}

		return array(
			'site_url'      => get_site_url(),
			'core'          => array(
				'version'          => get_bloginfo( 'version' ),
				'update_available' => $core_available,
			),
			'php'           => array(
				'version' => PHP_VERSION,
			),
			'theme_active'  => $active_theme ? $active_theme->get_stylesheet() : '',
			'bricks'        => array(
				'version' => $bricks_ver,
			),
			'themes'        => $themes,
			'plugins'       => $plugins,
			'generated_at'  => gmdate( 'c' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Audit
	 * ------------------------------------------------------------------- */

	/**
	 * Run the full scored security audit.
	 *
	 * @return WP_REST_Response
	 */
	public function run_audit( $request = null ) {
		$categories = array();

		$categories[] = $this->cat_bricks_core();
		$categories[] = $this->cat_bridge_self_audit();
		$categories[] = $this->cat_code_exposure();
		$categories[] = $this->cat_platform();
		$categories[] = $this->cat_config_hygiene();
		$categories[] = $this->cat_access_transport();
		$categories[] = $this->cat_hardening_status();

		// Aggregate score (point-weighted percentage across scoring categories).
		$total_score = 0;
		$total_max   = 0;
		$counts      = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0, 'passed' => 0 );
		$findings    = array();
		$has_open_critical = false;

		foreach ( $categories as $cat ) {
			$total_score += $cat['score'];
			$total_max   += $cat['max'];
			foreach ( $cat['checks'] as $chk ) {
				if ( 'pass' === $chk['status'] ) {
					$counts['passed']++;
				} elseif ( 'info' === $chk['status'] ) {
					$counts['info']++;
				} else {
					$sev = isset( $chk['severity'] ) ? $chk['severity'] : 'low';
					if ( isset( $counts[ $sev ] ) ) {
						$counts[ $sev ]++;
					}
					if ( 'critical' === $sev && 'fail' === $chk['status'] ) {
						$has_open_critical = true;
					}
					$findings[] = array(
						'category'    => $cat['category'],
						'check'       => $chk['check'],
						'status'      => $chk['status'],
						'severity'    => $sev,
						'detail'      => isset( $chk['detail'] ) ? $chk['detail'] : '',
						'remediation' => isset( $chk['remediation'] ) ? $chk['remediation'] : '',
						'ref_url'     => isset( $chk['ref_url'] ) ? $chk['ref_url'] : '',
						'deduction'   => $chk['max'] - $chk['points'],
					);
				}
			}
		}

		$pct = ( $total_max > 0 ) ? (int) round( ( $total_score / $total_max ) * 100 ) : 0;

		// Worst-first: severity rank, then larger point deduction.
		$rank = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4 );
		usort(
			$findings,
			function ( $a, $b ) use ( $rank ) {
				$ra = isset( $rank[ $a['severity'] ] ) ? $rank[ $a['severity'] ] : 5;
				$rb = isset( $rank[ $b['severity'] ] ) ? $rank[ $b['severity'] ] : 5;
				if ( $ra !== $rb ) {
					return $ra - $rb;
				}
				return $b['deduction'] - $a['deduction'];
			}
		);

		$grade = $this->grade_for( $pct, $has_open_critical );

		return new WP_REST_Response(
			array(
				'success'              => true,
				'generated_at'         => gmdate( 'c' ),
				'site_url'             => get_site_url(),
				'overall_score'        => $pct,
				'grade'                => $grade,
				'grade_capped'         => ( $has_open_critical && $pct >= 60 ),
				'summary_counts'       => $counts,
				'categories'           => $categories,
				'findings_worst_first' => $findings,
				'disclaimers'          => array(
					'BRICKSTORM (CISA AR25-338A, 2025) is an unrelated APT backdoor — a name collision only, NOT the Bricks theme.',
					'This is a posture/exposure report, not a malware scan or filesystem integrity check.',
				),
			),
			200
		);
	}

	/**
	 * Map a percentage + critical-flag to a letter grade. Any open CRITICAL
	 * hard-caps the grade to F regardless of points.
	 *
	 * @param int  $pct               Point-weighted percentage.
	 * @param bool $has_open_critical Whether any CRITICAL finding is open.
	 * @return string
	 */
	private function grade_for( $pct, $has_open_critical ) {
		if ( $has_open_critical ) {
			return 'F';
		}
		if ( $pct >= 90 ) {
			return 'A';
		}
		if ( $pct >= 80 ) {
			return 'B';
		}
		if ( $pct >= 70 ) {
			return 'C';
		}
		if ( $pct >= 60 ) {
			return 'D';
		}
		return 'F';
	}

	/**
	 * Build a single check result.
	 *
	 * @param string $check       Short check label.
	 * @param string $status      pass|warn|fail|info.
	 * @param int    $points      Points earned.
	 * @param int    $max         Max points for this check.
	 * @param string $severity    critical|high|medium|low|info.
	 * @param string $detail      Human-readable detail.
	 * @param string $remediation Suggested fix.
	 * @param string $ref_url     Optional reference URL.
	 * @return array
	 */
	private function check( $check, $status, $points, $max, $severity, $detail, $remediation = '', $ref_url = '' ) {
		return array(
			'check'       => $check,
			'status'      => $status,
			'points'      => $points,
			'max'         => $max,
			'severity'    => $severity,
			'detail'      => $detail,
			'remediation' => $remediation,
			'ref_url'     => $ref_url,
		);
	}

	/**
	 * Wrap a set of checks into a scored category.
	 *
	 * @param string $name   Category name.
	 * @param array  $checks Check arrays.
	 * @return array
	 */
	private function category( $name, $checks ) {
		$score = 0;
		$max   = 0;
		foreach ( $checks as $c ) {
			$score += $c['points'];
			$max   += $c['max'];
		}
		return array(
			'category' => $name,
			'score'    => $score,
			'max'      => $max,
			'checks'   => $checks,
		);
	}

	/* ---------------------------------------------------------------------
	 * Categories
	 * ------------------------------------------------------------------- */

	/**
	 * Bricks core CVE version checks.
	 *
	 * @return array
	 */
	private function cat_bricks_core() {
		$checks      = array();
		$bricks      = function_exists( 'wp_get_theme' ) ? wp_get_theme( 'bricks' ) : null;
		$has_bricks  = ( $bricks && $bricks->exists() );
		$version     = $has_bricks ? $bricks->get( 'Version' ) : '';

		if ( ! $has_bricks ) {
			$checks[] = $this->check(
				'Bricks theme present',
				'info',
				0,
				0,
				'info',
				'Bricks theme not detected on this site.',
				''
			);
			return $this->category( 'Bricks Core', $checks );
		}

		foreach ( self::$bricks_cves as $cve ) {
			$vulnerable = version_compare( $version, $cve['affected'], '<=' );
			$status     = $vulnerable ? 'fail' : 'pass';
			$points     = $vulnerable ? 0 : 10;
			$detail     = $vulnerable
				? sprintf( 'Installed Bricks %s is affected by %s (%s). %s', $version, $cve['cve'], $cve['auth'], $cve['note'] )
				: sprintf( 'Bricks %s is not affected by %s (fixed in %s).', $version, $cve['cve'], $cve['fixed_in'] );
			$checks[]   = $this->check(
				$cve['cve'],
				$status,
				$points,
				10,
				$cve['severity'],
				$detail,
				$vulnerable ? sprintf( 'Update Bricks to %s or later.', $cve['fixed_in'] ) : '',
				'https://academy.bricksbuilder.io/article/code-signatures'
			);
		}

		return $this->category( 'Bricks Core', $checks );
	}

	/**
	 * Bridge self-audit — verifies the code-surface routes (sign-code, page
	 * assets, scripts) require administrator capabilities. Reflects the actual
	 * bab_harden_code_routes flag state.
	 *
	 * @return array
	 */
	private function cat_bridge_self_audit() {
		$checks   = array();
		$hardened = (bool) get_option( 'bab_harden_code_routes', true );
		$helper   = function_exists( 'bricks_api_bridge_can_write_code_surface' );

		// sign-code + assets are gated by the shared helper when hardening is on.
		$status = ( $hardened && $helper ) ? 'pass' : 'fail';
		$points = ( $hardened && $helper ) ? 15 : 0;
		$detail = ( $hardened && $helper )
			? 'sign-code and page-asset writes require manage_options (raw code surfaces hardened).'
			: 'sign-code and/or page-asset writes resolve to edit_posts — a Contributor-level Application Password could sign executable Bricks code or inject raw JS/CSS.';
		$checks[] = $this->check(
			'Code-surface routes require admin',
			$status,
			$points,
			15,
			'high',
			$detail,
			$status === 'fail' ? 'Set option bab_harden_code_routes to true (default) so sign-code/assets require manage_options.' : ''
		);

		// /self-update accepts an unsigned ZIP — advisory only (already admin-gated).
		$checks[] = $this->check(
			'Self-update package integrity',
			'info',
			0,
			0,
			'info',
			'The /self-update route is manage_options-gated but accepts an unsigned ZIP (MIME + presence check only).',
			'Only trigger self-update from trusted ZIPs; consider a checksum/shared-secret gate for the route.'
		);

		return $this->category( 'Bridge Self-Audit', $checks );
	}

	/**
	 * Code-element exposure — counts Bricks code/PHP elements stored in page
	 * data across published content (defensive: scans the known Bricks meta
	 * keys; reports info when none are present).
	 *
	 * @return array
	 */
	private function cat_code_exposure() {
		$checks    = array();
		$meta_keys = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );
		$code_count = 0;
		$scanned    = 0;

		$ids = get_posts(
			array(
				'post_type'      => array( 'page', 'post', 'bricks_template' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			$scanned++;
			foreach ( $meta_keys as $key ) {
				$data = get_post_meta( $id, $key, true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( $data as $element ) {
					if ( isset( $element['name'] ) && 'code' === $element['name'] ) {
						$code_count++;
					}
				}
			}
		}

		if ( $code_count > 0 ) {
			$checks[] = $this->check(
				'Bricks code elements in page data',
				'warn',
				5,
				10,
				'medium',
				sprintf( '%d code element(s) found across %d published items.', $code_count, $scanned ),
				'Review each code element; ensure code execution is restricted to Administrators and signatures are locked.'
			);
		} else {
			$checks[] = $this->check(
				'Bricks code elements in page data',
				'pass',
				10,
				10,
				'low',
				sprintf( 'No code elements detected across %d published items.', $scanned ),
				''
			);
		}

		$checks[] = $this->check(
			'Code-signature lock',
			defined( 'BRICKS_LOCK_CODE_SIGNATURES' ) && BRICKS_LOCK_CODE_SIGNATURES ? 'pass' : 'info',
			0,
			0,
			'info',
			defined( 'BRICKS_LOCK_CODE_SIGNATURES' ) && BRICKS_LOCK_CODE_SIGNATURES
				? 'BRICKS_LOCK_CODE_SIGNATURES is enabled — code signing is locked.'
				: 'BRICKS_LOCK_CODE_SIGNATURES is not set; Bricks code execution depends on per-role settings.',
			'Set define( \'BRICKS_LOCK_CODE_SIGNATURES\', true ) to prevent re-signing of code elements.'
		);

		return $this->category( 'Code Exposure', $checks );
	}

	/**
	 * WordPress core + PHP runtime currency.
	 *
	 * @return array
	 */
	private function cat_platform() {
		$checks    = array();
		$inventory = $this->build_inventory();

		// WP core update available?
		$core_update = $inventory['core']['update_available'];
		if ( $core_update ) {
			$checks[] = $this->check(
				'WordPress core up to date',
				'warn',
				5,
				15,
				'medium',
				sprintf( 'Core is %s; %s is available.', $inventory['core']['version'], $core_update ),
				'Update WordPress core to the latest release.'
			);
		} else {
			$checks[] = $this->check(
				'WordPress core up to date',
				'pass',
				15,
				15,
				'low',
				sprintf( 'Core %s — no pending core update reported.', $inventory['core']['version'] ),
				''
			);
		}

		// PHP EOL.
		$php_branch = implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
		$eol        = isset( self::$php_eol[ $php_branch ] ) ? self::$php_eol[ $php_branch ] : null;
		$is_eol     = $eol ? ( gmdate( 'Y-m-d' ) > $eol ) : false;
		if ( $is_eol ) {
			$checks[] = $this->check(
				'PHP version security-supported',
				'fail',
				0,
				15,
				'high',
				sprintf( 'PHP %s reached end-of-life (%s) — no security fixes.', PHP_VERSION, $eol ),
				'Upgrade to PHP 8.2 or later.',
				'https://www.php.net/supported-versions.php'
			);
		} else {
			$checks[] = $this->check(
				'PHP version security-supported',
				'pass',
				15,
				15,
				'low',
				sprintf( 'PHP %s is within its security-support window.', PHP_VERSION ),
				''
			);
		}

		// Outdated plugins.
		$outdated = array();
		foreach ( $inventory['plugins'] as $p ) {
			if ( $p['update_available'] ) {
				$outdated[] = $p['slug'] . ' (' . $p['version'] . '→' . $p['update_available'] . ')';
			}
		}
		if ( ! empty( $outdated ) ) {
			$checks[] = $this->check(
				'Plugins up to date',
				'warn',
				0,
				10,
				'medium',
				sprintf( '%d plugin(s) have updates: %s', count( $outdated ), implode( ', ', array_slice( $outdated, 0, 10 ) ) ),
				'Apply pending plugin updates; remove unused plugins.'
			);
		} else {
			$checks[] = $this->check(
				'Plugins up to date',
				'pass',
				10,
				10,
				'low',
				'No pending plugin updates reported.',
				''
			);
		}

		return $this->category( 'Platform Currency', $checks );
	}

	/**
	 * Configuration hygiene — debug flags, file-edit lock, salts, default admin.
	 *
	 * @return array
	 */
	private function cat_config_hygiene() {
		$checks = array();

		// WP_DEBUG_DISPLAY on a live site.
		$debug_display = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$checks[]      = $this->check(
			'Debug output disabled',
			$debug_display ? 'fail' : 'pass',
			$debug_display ? 0 : 10,
			10,
			'high',
			$debug_display
				? 'WP_DEBUG and WP_DEBUG_DISPLAY are both on — errors may leak paths/secrets to visitors.'
				: 'Debug output is not displayed to visitors.',
			$debug_display ? 'Set WP_DEBUG_DISPLAY to false (log to file instead).' : ''
		);

		// DISALLOW_FILE_EDIT.
		$file_edit_locked = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$checks[]         = $this->check(
			'Theme/plugin file editor disabled',
			$file_edit_locked ? 'pass' : 'warn',
			$file_edit_locked ? 10 : 3,
			10,
			'medium',
			$file_edit_locked
				? 'DISALLOW_FILE_EDIT is enabled — the built-in code editor is off.'
				: 'DISALLOW_FILE_EDIT is not set; an admin (or hijacked admin session) can edit PHP from wp-admin.',
			$file_edit_locked ? '' : "Add define( 'DISALLOW_FILE_EDIT', true ) to wp-config.php."
		);

		// Salts defined and not placeholders.
		$salt_ok = defined( 'AUTH_KEY' ) && AUTH_KEY && false === strpos( (string) AUTH_KEY, 'put your unique phrase here' );
		$checks[] = $this->check(
			'Security keys/salts set',
			$salt_ok ? 'pass' : 'fail',
			$salt_ok ? 10 : 0,
			10,
			'high',
			$salt_ok ? 'Authentication keys/salts are defined.' : 'AUTH_KEY is missing or still the default placeholder.',
			$salt_ok ? '' : 'Generate fresh salts at https://api.wordpress.org/secret-key/1.1/salt/ and update wp-config.php.'
		);

		// Default 'admin' username.
		$has_admin = get_user_by( 'login', 'admin' );
		$checks[]  = $this->check(
			'No default "admin" account',
			$has_admin ? 'warn' : 'pass',
			$has_admin ? 5 : 10,
			10,
			'medium',
			$has_admin ? 'A user named "admin" exists — a predictable target for brute force.' : 'No default "admin" username found.',
			$has_admin ? 'Rename the admin account or create a new admin and remove "admin".' : ''
		);

		return $this->category( 'Configuration Hygiene', $checks );
	}

	/**
	 * Access + transport — REST over TLS, admin-owned Application Passwords.
	 *
	 * @return array
	 */
	private function cat_access_transport() {
		$checks = array();

		// REST/site over HTTPS.
		$is_https = ( 0 === strpos( get_site_url(), 'https://' ) );
		$checks[] = $this->check(
			'Site served over HTTPS',
			$is_https ? 'pass' : 'fail',
			$is_https ? 15 : 0,
			15,
			'critical',
			$is_https
				? 'Site URL uses https — Application Password Basic auth is transport-encrypted.'
				: 'Site URL is http — Application Passwords (HTTP Basic) travel in cleartext.',
			$is_https ? '' : 'Serve the site over HTTPS before using the REST bridge with Application Passwords.'
		);

		// Admin-owned Application Passwords.
		$admin_app_pw = 0;
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID' ) ) );
			foreach ( $admins as $admin ) {
				$pws = WP_Application_Passwords::get_user_application_passwords( $admin->ID );
				if ( is_array( $pws ) ) {
					$admin_app_pw += count( $pws );
				}
			}
		}
		$checks[] = $this->check(
			'Application Password exposure',
			$admin_app_pw > 0 ? 'info' : 'pass',
			0,
			0,
			'info',
			$admin_app_pw > 0
				? sprintf( '%d Application Password(s) are owned by administrator accounts.', $admin_app_pw )
				: 'No administrator-owned Application Passwords found.',
			$admin_app_pw > 0 ? 'Prefer a dedicated least-privilege user for automation; revoke unused Application Passwords.' : ''
		);

		return $this->category( 'Access & Transport', $checks );
	}

	/**
	 * Hardening status — confirms the always-on bridge hardening is present
	 * (these are informational confirmations, not re-detections).
	 *
	 * @return array
	 */
	private function cat_hardening_status() {
		$checks = array();
		$active = class_exists( 'Bricks_API_Bridge_Security_Hardening' );

		$checks[] = $this->check(
			'Bridge hardening layer active',
			$active ? 'pass' : 'info',
			$active ? 5 : 0,
			5,
			'info',
			$active
				? 'Bridge hardening is loaded (user-enum block, author-archive block, xmlrpc off, version-string strip, security headers, login rate-limit).'
				: 'Bridge hardening class not detected.',
			''
		);

		$checks[] = $this->check(
			'XML-RPC disabled',
			'pass',
			0,
			0,
			'info',
			'xmlrpc is filtered off by the bridge hardening layer.',
			''
		);

		return $this->category( 'Hardening Status', $checks );
	}
}
