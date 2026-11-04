<?php

namespace Hostinger\AiTheme\Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Contract + option keys for the `POST /wordpress/generate-website` envelope.
 *
 * Must stay in lock-step with the producer in ai-services
 * (endpoints/wordpress_generate_website). The envelope is the only coupling
 * point between the two repos. This class holds the shape validation and the
 * option keys the applier reads/writes; the runtime theme.json merge is gated
 * on {@see self::is_active()}.
 */
class BundleSchema {
    /**
     * Value of the applier-mode option when an envelope has been applied.
     */
    public const MODE_ENVELOPE = 'envelope';

    /**
     * Major contract version this applier understands. The producer does not
     * currently stamp a version, so a missing version is treated as supported;
     * a *present* version with a different major is rejected.
     */
    public const SUPPORTED_MAJOR = 1;

    /**
     * Editors the applier can route (matches the producer's `editor` field).
     */
    public const SUPPORTED_EDITORS = array( 'gutenberg', 'elementor' );

    public const OPTION_MODE       = 'hostinger_ai_applier_mode';
    public const OPTION_THEME_JSON = 'hostinger_ai_envelope_theme_json';

    // Tracked created artifacts (teardown keys for clean-before-apply).
    public const OPTION_PAGES          = 'hostinger_ai_envelope_pages';
    public const OPTION_MENU           = 'hostinger_ai_envelope_menu_id';
    public const OPTION_BLOG_POSTS     = 'hostinger_ai_created_blog_posts';
    public const OPTION_BLOG_CATS      = 'hostinger_ai_created_blog_categories';
    public const OPTION_PRODUCTS       = 'hostinger_ai_created_products';
    public const OPTION_PRODUCT_CATS   = 'hostinger_ai_created_product_categories';

    /**
     * @param mixed $version
     */
    public static function is_supported_version( $version ): bool {
        if ( $version === null || $version === '' ) {
            // The producer omits a version today; treat that as the current
            // major so real envelopes apply while future majors can be gated.
            return true;
        }

        if ( ! is_string( $version ) && ! is_int( $version ) && ! is_float( $version ) ) {
            return false;
        }

        $major = (int) explode( '.', (string) $version )[0];

        return $major === self::SUPPORTED_MAJOR;
    }

    /**
     * Shape-only + version-gated validation. The producer owns deep block-tree
     * correctness; this guards the envelope is the shape the applier can run.
     *
     * @param array $envelope
     */
    public static function validate( array $envelope ): bool {
        $version = $envelope['schema_version'] ?? ( $envelope['version'] ?? '' );
        if ( ! self::is_supported_version( $version ) ) {
            return false;
        }

        $editor = $envelope['editor'] ?? '';
        if ( ! is_string( $editor ) || ! in_array( $editor, self::SUPPORTED_EDITORS, true ) ) {
            return false;
        }

        if ( empty( $envelope['pages'] ) || ! is_array( $envelope['pages'] ) ) {
            return false;
        }

        if ( empty( $envelope['theme'] ) || ! is_array( $envelope['theme'] ) ) {
            return false;
        }

        return true;
    }

    public static function is_active(): bool {
        return get_option( self::OPTION_MODE ) === self::MODE_ENVELOPE;
    }
}
