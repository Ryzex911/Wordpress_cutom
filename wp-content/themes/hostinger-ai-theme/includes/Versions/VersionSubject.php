<?php

namespace Hostinger\AiTheme\Versions;

use Hostinger\AiTheme\Builder\Helper;
use Hostinger\AiTheme\Constants\GenerationConstant;

defined( 'ABSPATH' ) || exit;

class VersionSubject {
    /**
     * Post types carrying GenerationConstant::META_KEY.
     */
    public const TAGGED_POST_TYPES = GenerationConstant::GENERATED_POST_TYPES;

    /**
     * Untagged singletons that generation rewrites in place.
     */
    public const TEMPLATE_POST_TYPES = array( 'wp_template_part', 'wp_template' );

    /**
     * Tagged types that phase 2 trashes. Attachments are excluded: they are
     * force-deleted at commit time instead (see SnapshotRestorer).
     */
    public const TRASHABLE_POST_TYPES = array( 'page', 'post', 'product', 'wp_navigation' );

    public const OPTION_PREFIX = 'hostinger_ai_';

    public const KIT_OPTION = 'elementor_active_kit';

    /**
     * WordPress and plugin options generation writes that fall outside the prefix.
     */
    public const EXTRA_OPTIONS = array(
        'blogname',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'elementor_active_kit',
        'hostinger_elementor_typography_set',
        'hostinger_page_title_selector_set',
        'woocommerce_shop_page_id',
        'woocommerce_cart_page_id',
        'woocommerce_checkout_page_id',
        'woocommerce_myaccount_page_id',
        'woocommerce_coming_soon',
        'WPLANG',
    );

    /**
     * Never captured, and equally never touched by restore. Subtracted in both
     * directions: the prefix match would otherwise make restore delete the very
     * options this list exists to protect.
     */
    public const DENIED_OPTIONS = array(
        GenerationConstant::STATE_OPTION,
        Helper::HOSTINGER_AI_THEME_GENERATED_ONCE_OPTION,
        'hostinger_ai_litespeed_configured',
        VersionConstant::DB_VERSION_OPTION,
        VersionConstant::RESTORE_IN_PROGRESS_OPTION,
    );

    /**
     * wp_theme is load-bearing: without it a block theme cannot resolve a
     * restored template part at all.
     */
    public const TAXONOMIES = array(
        'product_cat',
        'product_type',
        'wp_template_part_area',
        'wp_theme',
    );

    public const PRODUCT_CATEGORY_OPTION = 'hostinger_ai_created_product_categories';

    /**
     * The plugins restore is allowed to act on. active_plugins is captured whole
     * for the audit trail, but reconciliation only touches this set.
     */
    public const KNOWN_PLUGINS = array(
        'elementor'   => 'elementor/elementor.php',
        'woocommerce' => 'woocommerce/woocommerce.php',
        'affiliate'   => 'hostinger-affiliate-plugin/hostinger-affiliate-plugin.php',
        'reach'       => 'hostinger-reach/hostinger-reach.php',
    );

    public function get_post_roles(): array {
        global $wpdb;

        $roles = array();

        $placeholders = implode( ', ', array_fill( 0, count( self::TAGGED_POST_TYPES ), '%s' ) );

        $tagged = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type IN ({$placeholders})",
                array_merge( array( GenerationConstant::META_KEY ), self::TAGGED_POST_TYPES )
            )
        );

        foreach ( $tagged as $post_id ) {
            $roles[ (int) $post_id ] = VersionConstant::ROLE_GENERATED;
        }

        $templates = $wpdb->get_results(
            "SELECT ID, post_type FROM {$wpdb->posts}
             WHERE post_type IN ('wp_template_part', 'wp_template')
             AND post_status != 'auto-draft'"
        );

        foreach ( $templates as $template ) {
            $roles[ (int) $template->ID ] = $template->post_type === 'wp_template_part'
                ? VersionConstant::ROLE_TEMPLATE_PART
                : VersionConstant::ROLE_TEMPLATE;
        }

        $kit_id = (int) get_option( self::KIT_OPTION, 0 );

        if ( $kit_id > 0 && get_post_status( $kit_id ) ) {
            $roles[ $kit_id ] = VersionConstant::ROLE_KIT;
        }

        return $roles;
    }

    public function get_option_names(): array {
        global $wpdb;

        $prefixed = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%'
            )
        );

        $names = array_merge( $prefixed, self::EXTRA_OPTIONS );

        return array_values( array_diff( array_unique( $names ), self::DENIED_OPTIONS ) );
    }

    public function is_option_denied( string $option_name ): bool {
        return in_array( $option_name, self::DENIED_OPTIONS, true );
    }

    public function is_option_removable( string $option_name ): bool {
        if ( $this->is_option_denied( $option_name ) ) {
            return false;
        }

        return str_starts_with( $option_name, self::OPTION_PREFIX ) || in_array( $option_name, self::EXTRA_OPTIONS, true );
    }

    public function get_theme_mods(): array {
        $mods = get_theme_mods();

        return is_array( $mods ) ? $mods : array();
    }

    public function get_taxonomies(): array {
        return self::TAXONOMIES;
    }

    public function get_known_plugins(): array {
        return array_values( self::KNOWN_PLUGINS );
    }

    public function get_active_plugins(): array {
        $active = get_option( 'active_plugins', array() );

        return is_array( $active ) ? $active : array();
    }

    public function get_trashable_post_ids(): array {
        global $wpdb;

        $placeholders = implode( ', ', array_fill( 0, count( self::TRASHABLE_POST_TYPES ), '%s' ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type IN ({$placeholders})
                 AND p.post_status != 'trash'",
                array_merge( array( GenerationConstant::META_KEY ), self::TRASHABLE_POST_TYPES )
            )
        );

        return array_map( 'intval', $ids );
    }

    public function get_generated_attachment_ids(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = 'attachment'",
                GenerationConstant::META_KEY
            )
        );

        return array_map( 'intval', $ids );
    }
}
