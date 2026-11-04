<?php

namespace Hostinger\AiTheme\Builder;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the envelope's baked (short-lived, signed) image URLs into the media
 * library and rewrites the block / `_elementor_data` trees to point at the
 * local attachments.
 *
 * The producer bakes signed GCS URLs directly into content (Gutenberg
 * `<img src>` / `core/cover` url, Elementor image-widget + container-background
 * url) and into product / blog / logo image fields. Those URLs expire, so this
 * helper sideloads every unique remote URL exactly once, then swaps each
 * occurrence for the local attachment URL (setting attachment ids where the
 * block supports them so responsive `srcset` and the media library work).
 *
 * Sideloading is fail-soft per image: one retry, then the remote URL is left in
 * place and a warning is surfaced (the page still renders; the image may break
 * once the signed URL expires and can be re-imported later).
 */
class MediaSideloader {

    /**
     * Sideload a deduplicated set of remote image URLs.
     *
     * @param array<int, string> $urls
     * @return array{map: array<string, array{id: int, url: string}>, warnings: array<int, string>}
     */
    public function sideload( array $urls ): array {
        $map      = array();
        $warnings = array();

        $urls = array_values(
            array_unique(
                array_filter( $urls, fn( $url ): bool => is_string( $url ) && $this->is_remote( $url ) )
            )
        );

        if ( empty( $urls ) ) {
            return array( 'map' => $map, 'warnings' => $warnings );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ( $urls as $url ) {
            $attachment_id = $this->sideload_one( $url );

            if ( $attachment_id > 0 ) {
                $local_url = wp_get_attachment_url( $attachment_id );

                if ( is_string( $local_url ) && $local_url !== '' ) {
                    $map[ $url ] = array(
                        'id'  => $attachment_id,
                        'url' => $local_url,
                    );
                    continue;
                }
            }

            $warnings[] = sprintf( 'Image import failed: %s', $this->describe_url( $url ) );
        }

        return array(
            'map'      => $map,
            'warnings' => $warnings,
        );
    }

    /**
     * Every unique remote image URL referenced by a Gutenberg block tree.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, string>
     */
    public function collect_gutenberg_urls( array $blocks ): array {
        $urls = array();
        $this->walk_gutenberg_urls( $blocks, $urls );

        return array_values( array_unique( $urls ) );
    }

    /**
     * Every unique remote image URL referenced by an `_elementor_data` tree.
     *
     * @param array<int, mixed> $elements
     * @return array<int, string>
     */
    public function collect_elementor_urls( array $elements ): array {
        $urls = array();
        $this->walk_elementor_urls( $elements, $urls );

        return array_values( array_unique( $urls ) );
    }

    /**
     * Rewrite a Gutenberg parsed-block tree, swapping remote image URLs for the
     * sideloaded local URLs and stamping attachment ids on image blocks.
     *
     * @param array<int, mixed>                              $blocks
     * @param array<string, array{id: int, url: string}>     $map
     * @return array<int, mixed>
     */
    public function rewrite_gutenberg( array $blocks, array $map ): array {
        return array_map(
            fn( $block ) => is_array( $block ) ? $this->rewrite_gutenberg_block( $block, $map ) : $block,
            $blocks
        );
    }

    /**
     * Rewrite an `_elementor_data` element tree in place.
     *
     * @param array<int, mixed>                          $elements
     * @param array<string, array{id: int, url: string}> $map
     * @return array<int, mixed>
     */
    public function rewrite_elementor( array $elements, array $map ): array {
        return array_map(
            fn( $node ) => is_array( $node ) ? $this->rewrite_elementor_node( $node, $map ) : $node,
            $elements
        );
    }

    // ------------------------------------------------------------------
    // Gutenberg internals
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $blocks
     * @param array<int, string> $urls
     */
    private function walk_gutenberg_urls( array $blocks, array &$urls ): void {
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $name = $block['blockName'] ?? '';

            if ( $name === 'core/cover' && ! empty( $block['attrs']['url'] ) && is_string( $block['attrs']['url'] ) ) {
                $this->maybe_add_url( $block['attrs']['url'], $urls );
            }

            if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
                foreach ( $this->img_srcs( $block['innerHTML'] ) as $src ) {
                    $this->maybe_add_url( $src, $urls );
                }
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $this->walk_gutenberg_urls( $block['innerBlocks'], $urls );
            }
        }
    }

    /**
     * @param array<string, mixed>                       $block
     * @param array<string, array{id: int, url: string}> $map
     * @return array<string, mixed>
     */
    private function rewrite_gutenberg_block( array $block, array $map ): array {
        $name     = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
        $image_id = 0;

        if ( $name === 'core/cover' && isset( $block['attrs']['url'] ) && is_string( $block['attrs']['url'] ) ) {
            $mapped = $map[ $block['attrs']['url'] ] ?? null;
            if ( $mapped !== null ) {
                $block['attrs']['url'] = $mapped['url'];
                $block['attrs']['id']  = (int) $mapped['id'];
                // core/cover's save() renders `wp-image-{id}` on the background
                // <img> whenever `id` is set; the class must be added in lockstep
                // or the block fails validation and the editor recovers it by
                // splitting the background image into a standalone image block.
                $image_id = (int) $mapped['id'];
            }
        }

        if ( $name === 'core/image' && isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
            $src = $this->first_img_src( $block['innerHTML'] );
            if ( $src !== '' && isset( $map[ $src ] ) ) {
                $image_id             = (int) $map[ $src ]['id'];
                $block['attrs']['id'] = $image_id;
            }
        }

        if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
            $block['innerHTML'] = $this->rewrite_img_html( $block['innerHTML'], $map, $image_id );
        }

        if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
            $block['innerContent'] = array_map(
                fn( $chunk ) => is_string( $chunk ) ? $this->rewrite_img_html( $chunk, $map, $image_id ) : $chunk,
                $block['innerContent']
            );
        }

        if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
            $block['innerBlocks'] = array_map(
                fn( $inner ) => is_array( $inner ) ? $this->rewrite_gutenberg_block( $inner, $map ) : $inner,
                $block['innerBlocks']
            );
        }

        return $block;
    }

    /**
     * Swap image `src`s in an HTML fragment and, when an attachment id is known,
     * add the `wp-image-{id}` class core uses to wire up responsive images.
     *
     * @param string                                      $html
     * @param array<string, array{id: int, url: string}> $map
     * @param int                                         $image_id
     */
    private function rewrite_img_html( string $html, array $map, int $image_id ): string {
        if ( $html === '' || ! class_exists( WP_HTML_Tag_Processor::class ) ) {
            return $html;
        }

        $processor = new WP_HTML_Tag_Processor( $html );
        $changed   = false;

        while ( $processor->next_tag( 'img' ) ) {
            $src = $processor->get_attribute( 'src' );

            if ( is_string( $src ) && isset( $map[ $src ] ) ) {
                $processor->set_attribute( 'src', $map[ $src ]['url'] );
                if ( $image_id > 0 ) {
                    $processor->add_class( 'wp-image-' . $image_id );
                }
                $changed = true;
            }
        }

        return $changed ? $processor->get_updated_html() : $html;
    }

    /**
     * @param string $html
     * @return array<int, string>
     */
    private function img_srcs( string $html ): array {
        if ( $html === '' || ! class_exists( WP_HTML_Tag_Processor::class ) ) {
            return array();
        }

        $processor = new WP_HTML_Tag_Processor( $html );
        $srcs      = array();

        while ( $processor->next_tag( 'img' ) ) {
            $src = $processor->get_attribute( 'src' );
            if ( is_string( $src ) && $src !== '' ) {
                $srcs[] = $src;
            }
        }

        return $srcs;
    }

    private function first_img_src( string $html ): string {
        $srcs = $this->img_srcs( $html );

        return $srcs[0] ?? '';
    }

    // ------------------------------------------------------------------
    // Elementor internals
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed>  $elements
     * @param array<int, string> $urls
     */
    private function walk_elementor_urls( array $elements, array &$urls ): void {
        foreach ( $elements as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $settings = $node['settings'] ?? array();
            if ( is_array( $settings ) ) {
                foreach ( array( 'image', 'background_image' ) as $key ) {
                    if ( ! empty( $settings[ $key ]['url'] ) && is_string( $settings[ $key ]['url'] ) ) {
                        $this->maybe_add_url( $settings[ $key ]['url'], $urls );
                    }
                }
            }

            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->walk_elementor_urls( $node['elements'], $urls );
            }
        }
    }

    /**
     * @param array<string, mixed>                       $node
     * @param array<string, array{id: int, url: string}> $map
     * @return array<string, mixed>
     */
    private function rewrite_elementor_node( array $node, array $map ): array {
        if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
            foreach ( array( 'image', 'background_image' ) as $key ) {
                if ( ! isset( $node['settings'][ $key ]['url'] ) || ! is_string( $node['settings'][ $key ]['url'] ) ) {
                    continue;
                }

                $mapped = $map[ $node['settings'][ $key ]['url'] ] ?? null;
                if ( $mapped !== null ) {
                    $node['settings'][ $key ]['url'] = $mapped['url'];
                    $node['settings'][ $key ]['id']  = $mapped['id'];
                }
            }
        }

        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            $node['elements'] = array_map(
                fn( $child ) => is_array( $child ) ? $this->rewrite_elementor_node( $child, $map ) : $child,
                $node['elements']
            );
        }

        return $node;
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * @param string             $url
     * @param array<int, string> $urls
     */
    private function maybe_add_url( string $url, array &$urls ): void {
        if ( $this->is_remote( $url ) ) {
            $urls[] = $url;
        }
    }

    private function is_remote( string $url ): bool {
        return str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' );
    }

    private function sideload_one( string $url ): int {
        foreach ( array( 0, 1 ) as $attempt ) {
            $attachment_id = media_sideload_image( $url, 0, null, 'id' );

            if ( ! is_wp_error( $attachment_id ) && is_int( $attachment_id ) && $attachment_id > 0 ) {
                return $attachment_id;
            }
        }

        return 0;
    }

    /**
     * Describe a URL for a warning without leaking its signed query string.
     *
     * @param string $url
     */
    private function describe_url( string $url ): string {
        $query_position = strpos( $url, '?' );

        return $query_position === false ? $url : substr( $url, 0, $query_position );
    }
}
