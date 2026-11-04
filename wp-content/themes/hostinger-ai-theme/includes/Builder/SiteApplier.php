<?php

namespace Hostinger\AiTheme\Builder;

use Hostinger\AiTheme\Builder\Elementor\KitManager;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a `POST /wordpress/generate-website` envelope to a live WordPress
 * install.
 *
 * The envelope (produced by ai-services) is one synchronous JSON document:
 * theme globals, pages as editor-native block / widget trees, a menu, and
 * optional WooCommerce / blog stubs. This service is a pure applier — it never
 * calls ai-services; it clean-before-applies the document so re-runs never
 * accumulate duplicates, sideloads the baked signed image URLs into the media
 * library, and creates native, fully-editable pages / posts / products.
 *
 * The runtime `theme.json` merge (Gutenberg globals) is gated behind the
 * `hostinger_ai_applier_mode` option, so this stays inert on legacy sites — the
 * strangler path beside the untouched legacy per-step builder.
 */
class SiteApplier {

    /**
     * Register the runtime filter. Cheap: the callback early-returns unless an
     * envelope is active, so this is inert on legacy sites.
     */
    public function init(): void {
        add_filter( 'wp_theme_json_data_theme', array( $this, 'merge_theme_json' ), 1000 );
    }

    /**
     * Merge the applied Gutenberg `theme.json` delta at priority 1000 —
     * deliberately after the legacy filter's 999 so the envelope's authoritative
     * globals win on envelope-applied sites.
     *
     * @param \WP_Theme_JSON_Data $theme_json
     * @return \WP_Theme_JSON_Data
     */
    public function merge_theme_json( $theme_json ) {
        if ( ! BundleSchema::is_active() ) {
            return $theme_json;
        }

        $delta = get_option( BundleSchema::OPTION_THEME_JSON, array() );

        if ( empty( $delta ) || ! is_array( $delta ) ) {
            return $theme_json;
        }

        return $theme_json->update_with( $delta );
    }

    // ------------------------------------------------------------------
    // Apply pipeline
    // ------------------------------------------------------------------

    /**
     * Apply a full website envelope. Returns a summary for the REST response.
     *
     * @param array $envelope
     * @return array
     * @throws InvalidArgumentException When the envelope is unsupported/malformed (HTTP 422).
     * @throws RuntimeException         When every page fails to apply (HTTP 500).
     */
    public function apply( array $envelope ): array {
        if ( ! BundleSchema::validate( $envelope ) ) {
            throw new InvalidArgumentException( 'Unsupported or malformed website envelope.' );
        }

        $editor = (string) $envelope['editor'];
        $this->guard_editor( $editor );

        $warnings = array();

        $this->clean_before_apply();

        $this->set_mode( $editor );
        $this->apply_language( $envelope['language'] ?? array() );
        $this->apply_single_page_chrome( ! empty( $envelope['single_page'] ) );
        $this->apply_theme_globals( is_array( $envelope['theme'] ?? null ) ? $envelope['theme'] : array(), $editor );

        $media     = new MediaSideloader();
        $sideloaded = $media->sideload( $this->collect_all_urls( $envelope, $editor, $media ) );
        $media_map  = $sideloaded['map'];
        $warnings   = array_merge( $warnings, $sideloaded['warnings'] );

        $pages    = $this->create_pages( $envelope, $editor, $media, $media_map );
        $warnings = array_merge( $warnings, $pages['warnings'] );

        if ( empty( $pages['post_ids'] ) ) {
            throw new RuntimeException( 'Every page failed to apply.' );
        }

        // Persist the created page ids as soon as they exist: the clean before
        // apply is destructive, so anything failing past this point must still
        // leave the next run able to find and remove what this one created.
        update_option( BundleSchema::OPTION_PAGES, $pages['post_ids'] );

        try {
            $menu_id = $this->build_menu( $envelope['menu'] ?? array(), $pages['id_map'], $pages['post_ids'] );
        } catch ( Throwable ) {
            $menu_id    = 0;
            $warnings[] = 'Navigation menu failed to apply.';
        }

        $this->apply_brand( is_array( $envelope['brand'] ?? null ) ? $envelope['brand'] : array(), $media_map );

        $products_created = $this->apply_woocommerce( $envelope['woocommerce'] ?? null, $media_map, $warnings );
        $posts_created    = $this->apply_blog( $envelope['blog'] ?? null, $media, $media_map );

        if ( $menu_id > 0 ) {
            update_option( BundleSchema::OPTION_MENU, $menu_id );
        }

        $this->ensure_pretty_permalinks();
        $this->bust_caches( $editor, $pages['post_ids'] );

        return array(
            'pages_created'     => count( $pages['post_ids'] ),
            'front_page_id'     => $pages['front_id'],
            'menu_id'           => $menu_id,
            'products_created'  => $products_created,
            'posts_created'     => $posts_created,
            'assets_sideloaded' => count( $media_map ),
            'warnings'          => array_values( $warnings ),
        );
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    private function guard_editor( string $editor ): void {
        if ( $editor === 'elementor' && ! $this->is_elementor_active() ) {
            throw new InvalidArgumentException( 'Elementor is required for this website but is not active.' );
        }
    }

    private function is_elementor_active(): bool {
        return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
    }

    // ------------------------------------------------------------------
    // Clean-before-apply (idempotency)
    // ------------------------------------------------------------------

    /**
     * Permanently delete prior generated artifacts so re-running never
     * accumulates duplicates. Deletion bypasses the trash, so user edits to a
     * previously generated page are not recoverable. Sideloaded media from a
     * prior run is intentionally left in place.
     */
    private function clean_before_apply(): void {
        $pages = get_posts(
            array(
                'post_type'   => 'page',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => '_hostinger_ai_generated',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => '1',
            )
        );

        foreach ( $pages as $page_id ) {
            wp_delete_post( (int) $page_id, true );
        }

        $menu_id = (int) get_option( BundleSchema::OPTION_MENU, 0 );
        if ( $menu_id > 0 ) {
            wp_delete_post( $menu_id, true );
        }

        foreach ( (array) get_option( BundleSchema::OPTION_PRODUCTS, array() ) as $product_id ) {
            wp_delete_post( (int) $product_id, true );
        }
        ( new ProductCategoryManager() )->clear_created_categories();

        foreach ( (array) get_option( BundleSchema::OPTION_BLOG_POSTS, array() ) as $post_id ) {
            wp_delete_post( (int) $post_id, true );
        }
        foreach ( (array) get_option( BundleSchema::OPTION_BLOG_CATS, array() ) as $term_id ) {
            wp_delete_term( (int) $term_id, 'category' );
        }

        remove_theme_mod( 'custom_logo' );

        delete_option( BundleSchema::OPTION_PAGES );
        delete_option( BundleSchema::OPTION_MENU );
        delete_option( BundleSchema::OPTION_PRODUCTS );
        delete_option( BundleSchema::OPTION_BLOG_POSTS );
        delete_option( BundleSchema::OPTION_BLOG_CATS );

        $this->reset_user_global_styles();
    }

    /**
     * Clear the user Global Styles record.
     *
     * WordPress merges theme.json in three layers (core -> theme -> user), and
     * the user Global Styles post wins. A previous generation (legacy builder
     * or an earlier envelope) can leave a stale palette there that then
     * overrides the palette this applier writes into the theme layer via
     * {@see self::merge_theme_json()} — the site keeps rendering the old
     * colors no matter what envelope is applied. Resetting the record to empty
     * user styles hands authority back to the envelope's theme-layer delta.
     */
    private function reset_user_global_styles(): void {
        if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
            return;
        }

        $post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if ( $post_id <= 0 ) {
            return;
        }

        $empty = wp_json_encode(
            array(
                'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
                'isGlobalStylesUserThemeJSON' => true,
            )
        );

        wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => wp_slash( (string) $empty ),
            )
        );

        \WP_Theme_JSON_Resolver::clean_cached_data();
    }

    // ------------------------------------------------------------------
    // Mode / editor / language / chrome
    // ------------------------------------------------------------------

    private function set_mode( string $editor ): void {
        update_option( BundleSchema::OPTION_MODE, BundleSchema::MODE_ENVELOPE );
        update_option( 'hostinger_ai_builder_type', $editor );
    }

    /**
     * @param mixed $language
     */
    private function apply_language( $language ): void {
        if ( ! is_array( $language ) ) {
            return;
        }

        $code = $language['code'] ?? '';
        if ( is_string( $code ) && $code !== '' ) {
            update_option( 'hostinger_ai_selected_language', sanitize_text_field( $code ) );
        }
    }

    /**
     * Single-page sites hide the header nav and use the `footer-landing` part,
     * mirroring the legacy landing-page handling via the display + type options
     * the theme's chrome already keys off.
     *
     * @param bool $single_page
     */
    private function apply_single_page_chrome( bool $single_page ): void {
        $options = get_option( 'hostinger_ai_theme_display_options', array() );
        if ( ! is_array( $options ) ) {
            $options = array();
        }

        if ( $single_page ) {
            $options['hide_header'] = 1;
            update_option( 'hostinger_ai_theme_display_options', $options );
            update_option( 'hostinger_ai_website_type', array( 'landing page' ) );
            return;
        }

        unset( $options['hide_header'] );
        update_option( 'hostinger_ai_theme_display_options', $options );
        update_option( 'hostinger_ai_website_type', array() );
    }

    // ------------------------------------------------------------------
    // Theme globals
    // ------------------------------------------------------------------

    private function apply_theme_globals( array $theme, string $editor ): void {
        $gutenberg = $theme['gutenberg'] ?? array();
        update_option( BundleSchema::OPTION_THEME_JSON, is_array( $gutenberg ) ? $gutenberg : array() );

        if ( $editor !== 'elementor' ) {
            return;
        }

        $elementor = $theme['elementor'] ?? array();
        if ( ! is_array( $elementor ) || empty( $elementor ) ) {
            return;
        }

        ( new KitManager() )->apply_globals(
            is_array( $elementor['custom_colors'] ?? null ) ? $elementor['custom_colors'] : array(),
            is_array( $elementor['custom_typography'] ?? null ) ? $elementor['custom_typography'] : array()
        );
    }

    // ------------------------------------------------------------------
    // Media
    // ------------------------------------------------------------------

    /**
     * Collect every unique remote image URL the envelope references so they are
     * sideloaded exactly once.
     *
     * @param array           $envelope
     * @param string          $editor
     * @param MediaSideloader $media
     * @return array<int, string>
     */
    private function collect_all_urls( array $envelope, string $editor, MediaSideloader $media ): array {
        $urls = array();

        foreach ( $envelope['pages'] as $page ) {
            $content = $page['content'] ?? array();
            if ( ! is_array( $content ) ) {
                continue;
            }
            $urls = array_merge(
                $urls,
                $editor === 'elementor'
                    ? $media->collect_elementor_urls( $content )
                    : $media->collect_gutenberg_urls( $content )
            );
        }

        $logo = $envelope['brand']['logo']['signed_url'] ?? '';
        if ( is_string( $logo ) && $logo !== '' ) {
            $urls[] = $logo;
        }

        foreach ( $envelope['woocommerce']['products'] ?? array() as $product ) {
            foreach ( $product['images'] ?? array() as $image ) {
                if ( ! empty( $image['src'] ) && is_string( $image['src'] ) ) {
                    $urls[] = $image['src'];
                }
            }
        }

        foreach ( $envelope['blog']['posts'] ?? array() as $post ) {
            $featured = $post['featured_image']['src'] ?? '';
            if ( is_string( $featured ) && $featured !== '' ) {
                $urls[] = $featured;
            }
            if ( is_array( $post['content'] ?? null ) ) {
                $urls = array_merge( $urls, $media->collect_gutenberg_urls( $post['content'] ) );
            }
        }

        return array_values( array_unique( $urls ) );
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /**
     * @param array                                       $envelope
     * @param string                                      $editor
     * @param MediaSideloader                             $media
     * @param array<string, array{id: int, url: string}> $media_map
     * @return array{post_ids: array<int, int>, id_map: array<string, int>, front_id: int, warnings: array<int, string>}
     */
    private function create_pages( array $envelope, string $editor, MediaSideloader $media, array $media_map ): array {
        $post_ids     = array();
        $id_map       = array();
        $warnings     = array();
        $home_page_id = (string) ( $envelope['home_page_id'] ?? '' );

        $explicit_front = 0;
        $home_candidate = 0;

        foreach ( $envelope['pages'] as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }

            try {
                $post_id = $this->create_page( $page, $editor, $media, $media_map );
            } catch ( Throwable $e ) {
                $post_id = 0;
            }

            if ( $post_id <= 0 ) {
                $warnings[] = sprintf(
                    'Page failed to apply: %s',
                    (string) ( $page['title'] ?? ( $page['id'] ?? 'unknown' ) )
                );
                continue;
            }

            $post_ids[]  = $post_id;
            $envelope_id = (string) ( $page['id'] ?? '' );
            if ( $envelope_id !== '' ) {
                $id_map[ $envelope_id ] = $post_id;
                if ( $envelope_id === $home_page_id ) {
                    $explicit_front = $post_id;
                }
            }
            if ( ! empty( $page['is_home'] ) && $home_candidate === 0 ) {
                $home_candidate = $post_id;
            }
        }

        $front_id = $explicit_front;
        if ( $front_id === 0 ) {
            $front_id = $home_candidate !== 0 ? $home_candidate : ( $post_ids[0] ?? 0 );
        }

        if ( $front_id > 0 ) {
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front', $front_id );
        }

        return array(
            'post_ids' => $post_ids,
            'id_map'   => $id_map,
            'front_id' => $front_id,
            'warnings' => $warnings,
        );
    }

    /**
     * Create one published page from an envelope entry (Gutenberg block tree or
     * Elementor `_elementor_data`), rewritten with the sideloaded media.
     *
     * @param array                                       $page
     * @param string                                      $editor
     * @param MediaSideloader                             $media
     * @param array<string, array{id: int, url: string}> $media_map
     */
    private function create_page( array $page, string $editor, MediaSideloader $media, array $media_map ): int {
        $title   = (string) ( $page['title'] ?? '' );
        $slug    = (string) ( $page['slug'] ?? '' );
        $content = is_array( $page['content'] ?? null ) ? $page['content'] : array();

        $postarr = array(
            'post_title'   => sanitize_text_field( $title !== '' ? $title : 'Page' ),
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '',
        );
        if ( $slug !== '' ) {
            $postarr['post_name'] = sanitize_title( $slug );
        }

        if ( $editor === 'elementor' ) {
            $rewritten      = $media->rewrite_elementor( $content, $media_map );
            $elementor_json = wp_slash(
                (string) wp_json_encode( $rewritten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            );

            $page_id = Helper::insert_trusted_post( $postarr );
            if ( is_wp_error( $page_id ) || ! $page_id ) {
                return 0;
            }
            $page_id = (int) $page_id;

            update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
            update_post_meta( $page_id, '_elementor_version', Helper::get_elementor_version() );
            update_post_meta( $page_id, '_elementor_data', $elementor_json );
            update_post_meta( $page_id, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
        } else {
            $rewritten                = $media->rewrite_gutenberg( $content, $media_map );
            $postarr['post_content']  = serialize_blocks( $rewritten );

            $page_id = Helper::insert_trusted_post( $postarr );
            if ( is_wp_error( $page_id ) || ! $page_id ) {
                return 0;
            }
            $page_id = (int) $page_id;
        }

        update_post_meta( $page_id, '_wp_page_template', 'no-title' );
        update_post_meta( $page_id, '_hostinger_ai_generated', '1' );

        return $page_id;
    }

    // ------------------------------------------------------------------
    // Menu
    // ------------------------------------------------------------------

    /**
     * @param array              $menu    Envelope `menu` tree (flat MVP).
     * @param array<string, int> $id_map  Envelope page id -> created post id.
     * @param array<int, int>    $post_ids
     */
    private function build_menu( array $menu, array $id_map, array $post_ids ): int {
        $pages = array();

        foreach ( $menu as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $envelope_id = (string) ( $item['page_id'] ?? '' );
            if ( $envelope_id === '' || ! isset( $id_map[ $envelope_id ] ) ) {
                continue;
            }
            $post_id = $id_map[ $envelope_id ];
            $pages[] = array(
                'title'   => (string) ( $item['title'] ?? get_the_title( $post_id ) ),
                'page_id' => $post_id,
            );
        }

        if ( empty( $pages ) ) {
            foreach ( $post_ids as $post_id ) {
                $pages[] = array(
                    'title'   => get_the_title( $post_id ),
                    'page_id' => $post_id,
                );
            }
        }

        if ( empty( $pages ) ) {
            return 0;
        }

        return ( new NavigationBuilder( $pages ) )->updateMenus();
    }

    // ------------------------------------------------------------------
    // Brand / logo
    // ------------------------------------------------------------------

    /**
     * @param array                                       $brand
     * @param array<string, array{id: int, url: string}> $media_map
     */
    private function apply_brand( array $brand, array $media_map ): void {
        $name = $brand['name'] ?? '';
        if ( is_string( $name ) && $name !== '' ) {
            update_option( 'blogname', sanitize_text_field( $name ) );
        }

        $tagline = $brand['tagline'] ?? null;
        if ( is_string( $tagline ) ) {
            update_option( 'blogdescription', sanitize_text_field( $tagline ) );
        }

        $logo_src = $brand['logo']['signed_url'] ?? '';
        if ( is_string( $logo_src ) && isset( $media_map[ $logo_src ] ) ) {
            set_theme_mod( 'custom_logo', (int) $media_map[ $logo_src ]['id'] );
        }
    }

    // ------------------------------------------------------------------
    // WooCommerce / blog
    // ------------------------------------------------------------------

    /**
     * @param mixed                                       $woocommerce
     * @param array<string, array{id: int, url: string}> $media_map
     * @param array<int, string>                         $warnings
     */
    private function apply_woocommerce( $woocommerce, array $media_map, array &$warnings ): int {
        if ( empty( $woocommerce ) || ! is_array( $woocommerce ) ) {
            return 0;
        }

        $products = new EnvelopeProducts();
        if ( ! $products->is_available() ) {
            $warnings[] = 'WooCommerce is not active — products and categories were skipped.';
            return 0;
        }

        $product_ids = $products->create( $woocommerce, $media_map );
        update_option( BundleSchema::OPTION_PRODUCTS, $product_ids );

        return count( $product_ids );
    }

    /**
     * @param mixed                                       $blog
     * @param MediaSideloader                             $media
     * @param array<string, array{id: int, url: string}> $media_map
     */
    private function apply_blog( $blog, MediaSideloader $media, array $media_map ): int {
        if ( empty( $blog ) || ! is_array( $blog ) ) {
            return 0;
        }

        $result = ( new EnvelopePosts( $media ) )->create( $blog, $media_map );

        update_option( BundleSchema::OPTION_BLOG_POSTS, $result['post_ids'] );
        update_option( BundleSchema::OPTION_BLOG_CATS, $result['category_ids'] );

        return count( $result['post_ids'] );
    }

    // ------------------------------------------------------------------
    // Finalization
    // ------------------------------------------------------------------

    private function ensure_pretty_permalinks(): void {
        if ( get_option( 'permalink_structure' ) ) {
            return;
        }

        global $wp_rewrite;

        update_option( 'permalink_structure', '/%postname%/' );

        if ( $wp_rewrite instanceof \WP_Rewrite ) {
            $wp_rewrite->set_permalink_structure( '/%postname%/' );
            $wp_rewrite->flush_rules( false );
        }
    }

    /**
     * @param string          $editor
     * @param array<int, int> $page_ids
     */
    private function bust_caches( string $editor, array $page_ids ): void {
        if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
            wp_clean_theme_json_cache();
        }

        $this->bust_object_and_page_caches();

        if ( $editor === 'elementor' ) {
            $this->regenerate_elementor_css( $page_ids );
        }
    }

    /**
     * Purge LiteSpeed page + object caches after an envelope apply.
     *
     * The envelope palette only reaches the front end through
     * {@see self::merge_theme_json()}, which calls get_option() at render
     * time. LiteSpeed's persistent object cache can keep serving the
     * pre-apply option value on the front end (wp-admin reads fresh), so the
     * Site Editor shows the applied palette while the live site renders the
     * theme's static one — dark-palette sites shipped with unreadable header
     * text. Purge both LiteSpeed cache layers without changing administrator
     * configuration.
     */
    private function bust_object_and_page_caches(): void {
        if ( has_action( 'litespeed_purge_all' ) ) {
            do_action( 'litespeed_purge_all' );
        }

        if ( has_action( 'litespeed_purge_all_object' ) ) {
            do_action( 'litespeed_purge_all_object' );
        }

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }

    /**
     * @param array<int, int> $page_ids
     */
    private function regenerate_elementor_css( array $page_ids ): void {
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $elementor = \Elementor\Plugin::instance();
            if ( ! empty( $elementor->files_manager ) && method_exists( $elementor->files_manager, 'clear_cache' ) ) {
                $elementor->files_manager->clear_cache();
            }
        }

        if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            return;
        }

        foreach ( $page_ids as $page_id ) {
            $css_file = \Elementor\Core\Files\CSS\Post::create( (int) $page_id );
            $css_file->update();
        }

        if ( class_exists( '\Elementor\Core\Files\CSS\Global_CSS' ) ) {
            $global_css = \Elementor\Core\Files\CSS\Global_CSS::create( 'global.css' );
            $global_css->update();
        }
    }
}
