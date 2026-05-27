<?php
/**
 * Dynamic Data Tags for CSS variables.
 *
 * Registers {bab_var:name} tags that resolve to values from
 * the bricks_global_variables option, making CSS variables
 * available as Bricks dynamic data.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Dynamic_Tags
 */
class Bricks_API_Bridge_Dynamic_Tags {

	/**
	 * Register dynamic tag hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'bricks/dynamic_tags_list', array( $this, 'add_tags' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 10, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 10, 3 );
	}

	/**
	 * Add CSS variable tags to the Bricks dynamic tags list.
	 *
	 * @param array $tags Existing dynamic tags.
	 * @return array Modified tags list.
	 */
	public function add_tags( $tags ) {
		$variables = $this->get_variables();

		if ( empty( $variables ) ) {
			return $tags;
		}

		foreach ( $variables as $var ) {
			$name = '';
			if ( isset( $var['name'] ) ) {
				$name = $var['name'];
			} elseif ( isset( $var['id'] ) ) {
				$name = $var['id'];
			}

			if ( empty( $name ) ) {
				continue;
			}

			// Clean the name for use as a tag key (strip -- prefix if present).
			$clean_name = ltrim( $name, '-' );

			$tags[] = array(
				'name'  => '{bab_var:' . $clean_name . '}',
				'label' => 'BAB Var: ' . $clean_name,
				'group' => 'BAB CSS Variables',
			);
		}

		return $tags;
	}

	/**
	 * Render a single dynamic tag.
	 *
	 * @param string $tag     The tag string.
	 * @param mixed  $post    The current post.
	 * @param string $context The rendering context.
	 * @return string The rendered value or original tag.
	 */
	public function render_tag( $tag, $post, $context ) {
		if ( strpos( $tag, '{bab_var:' ) !== 0 ) {
			return $tag;
		}

		$var_name = str_replace( array( '{bab_var:', '}' ), '', $tag );

		return $this->resolve_variable( $var_name );
	}

	/**
	 * Render all {bab_var:...} tags in a content string.
	 *
	 * @param string $content The content string.
	 * @param mixed  $post    The current post.
	 * @param string $context The rendering context.
	 * @return string Content with tags replaced.
	 */
	public function render_content( $content, $post, $context ) {
		if ( false === strpos( $content, '{bab_var:' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/\{bab_var:([^}]+)\}/',
			function ( $matches ) {
				return $this->resolve_variable( $matches[1] );
			},
			$content
		);
	}

	/**
	 * Resolve a variable name to its value.
	 *
	 * Searches global variables by name (with and without -- prefix).
	 *
	 * @param string $var_name The variable name to look up.
	 * @return string The variable value or empty string.
	 */
	private function resolve_variable( $var_name ) {
		$variables = $this->get_variables();

		if ( empty( $variables ) ) {
			return '';
		}

		foreach ( $variables as $var ) {
			$name = '';
			if ( isset( $var['name'] ) ) {
				$name = $var['name'];
			} elseif ( isset( $var['id'] ) ) {
				$name = $var['id'];
			}

			$clean_name = ltrim( $name, '-' );

			if ( $clean_name === $var_name || $name === $var_name ) {
				return isset( $var['value'] ) ? $var['value'] : '';
			}
		}

		return '';
	}

	/**
	 * Get CSS variables from the WordPress option.
	 *
	 * Tries multiple option keys for compatibility.
	 *
	 * @return array
	 */
	private function get_variables() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$option_keys = array( 'bricks_global_variables', 'bricks_css_variables', 'bricks_variables' );

		foreach ( $option_keys as $key ) {
			$val = get_option( $key, null );
			if ( ! empty( $val ) && is_array( $val ) ) {
				$cached = $val;
				return $cached;
			}
		}

		$cached = array();
		return $cached;
	}
}
