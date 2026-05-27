<?php
/**
 * Quirks Coercion — auto-fix and warn on common Bricks element settings traps.
 *
 * Centralises five known footguns that previously required client-side
 * workarounds and were documented as feedback memories. Called once from
 * pages-controller's update/patch entry points so the fixes apply uniformly
 * regardless of how the data arrived (full PUT, partial patch, append).
 *
 * Behaviour split:
 *   • Coercions are applied silently (data is fixed in place).
 *   • Detections are surfaced as response warnings (data is left alone) —
 *     callers can decide whether to act. We don't auto-rewrite anything that
 *     could mask author intent.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_Quirks_Coercion {

	/**
	 * Walk an element tree, fix coercible quirks, and collect warnings about
	 * the rest. Mutates `$elements` in place.
	 *
	 * @param array $elements Bricks element array (top-level, by reference).
	 * @return array { warnings: string[], image_alts: array<int,string> } —
	 *               image_alts maps attachment_id → alt_text for the caller
	 *               to write to media-library postmeta after the bricks save.
	 */
	public static function process( &$elements ) {
		$warnings   = array();
		$image_alts = array();

		if ( ! is_array( $elements ) ) {
			return array( 'warnings' => $warnings, 'image_alts' => $image_alts );
		}

		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) || empty( $el['name'] ) ) {
				continue;
			}
			$id       = isset( $el['id'] ) ? $el['id'] : '?';
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : null;
			if ( null === $settings ) {
				continue;
			}

			// (1) link.postId must be a JSON string for Bricks' internal-link
			// resolver to emit the href. Integers render anchor with no href.
			// Coerce silently for both link.postId and _link.postId.
			foreach ( array( 'link', '_link' ) as $link_key ) {
				if ( isset( $settings[ $link_key ]['postId'] ) && is_int( $settings[ $link_key ]['postId'] ) ) {
					$settings[ $link_key ]['postId'] = (string) $settings[ $link_key ]['postId'];
				}
			}

			// (2) _background.color.raw rejects var() — Bricks compiler doesn't
			// resolve CSS variables here and outputs transparent. Warn so the
			// caller can switch to hex or move into _cssCustom. Don't auto-fix —
			// resolving the var would require theme-styles introspection that
			// can drift between sites, and silently changing the colour is
			// worse than transparent (you'd see *something* the author didn't
			// pick).
			if ( isset( $settings['_background']['color']['raw'] ) && is_string( $settings['_background']['color']['raw'] ) ) {
				if ( false !== strpos( $settings['_background']['color']['raw'], 'var(' ) ) {
					$warnings[] = sprintf(
						'%s: _background.color.raw contains var() — Bricks renders transparent. Use hex (#abc123) or move to _cssCustom %%root%% { background: var(...) !important; }.',
						$id
					);
				}
			}

			// (3) Containers' `_html` field is silently dropped by Bricks'
			// sanitiser. Authors expect raw HTML there; the only working
			// path is text-basic.text. Warn rather than auto-move because
			// reshaping the tree (adding/removing elements) is beyond the
			// scope of a sanitisation pass.
			if ( 'container' === $el['name'] && isset( $settings['_html'] ) && '' !== trim( (string) $settings['_html'] ) ) {
				$warnings[] = sprintf(
					'%s: container._html will not render — Bricks strips it. Use a text-basic child with the HTML in `text`.',
					$id
				);
			}

			// (4) image element: harvest alt for media-library write-through.
			// Bricks reads alt from the attachment, not from settings.image.alt.
			// We collect (id → alt) here; the caller writes after save so a
			// failing alt-write doesn't roll back the page save.
			if ( 'image' === $el['name'] && isset( $settings['image'] ) && is_array( $settings['image'] ) ) {
				$img_id  = isset( $settings['image']['id'] ) ? (int) $settings['image']['id'] : 0;
				$img_alt = isset( $settings['image']['alt'] ) ? (string) $settings['image']['alt'] : '';
				if ( $img_id > 0 && '' !== $img_alt ) {
					$image_alts[ $img_id ] = $img_alt;
				}
			}

			$el['settings'] = $settings;
		}
		unset( $el );

		return array( 'warnings' => $warnings, 'image_alts' => $image_alts );
	}

	/**
	 * Write collected image alts to the WP media library. Called after the
	 * page save so partial failures here can't undo the bricks_data write.
	 *
	 * @param array<int,string> $image_alts attachment_id → alt_text
	 * @return int Number of attachments updated.
	 */
	public static function write_image_alts( $image_alts ) {
		$count = 0;
		foreach ( $image_alts as $attachment_id => $alt ) {
			if ( $attachment_id <= 0 ) {
				continue;
			}
			// Only write if attachment exists and is an image, and only if the
			// new alt actually differs — avoids touching unchanged rows.
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			$current = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $current === $alt ) {
				continue;
			}
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			$count++;
		}
		return $count;
	}
}
