<?php

namespace Hostinger\AiTheme\Builder;

use Throwable;
use WC_Product_Simple;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter: envelope WooCommerce stubs -> WooCommerce products.
 *
 * Products are created through the WooCommerce CRUD API
 * ({@see WC_Product_Simple}), so WooCommerce owns the price / SKU meta, the
 * `simple` `product_type` term and the `wc_product_meta_lookup` row that the
 * shop, search and sorting queries read from. On top of that the adapter adds a
 * featured image and {@see ProductCategoryManager} category terms; only the
 * input mapping is new, because the envelope's product shape
 * (`name/slug/sku/regular_price/description/short_description/categories/images`)
 * differs from the legacy per-section shape. Images are the already-sideloaded
 * attachments (resolved through the shared media map), not keyword lookups.
 */
class EnvelopeProducts {

    private ProductCategoryManager $categories;

    public function __construct( ?ProductCategoryManager $categories = null ) {
        $this->categories = $categories ?? new ProductCategoryManager();
    }

    /**
     * Whether WooCommerce is active enough to create products.
     */
    public function is_available(): bool {
        return class_exists( 'WooCommerce' )
            && class_exists( 'WC_Product_Simple' )
            && function_exists( 'wc_get_product' )
            && taxonomy_exists( 'product_cat' );
    }

    /**
     * Create products from the envelope's WooCommerce block.
     *
     * @param array                                      $woocommerce Envelope `woocommerce` block.
     * @param array<string, array{id: int, url: string}> $media_map   source URL -> sideloaded attachment.
     * @return array<int, int> Created product post ids.
     */
    public function create( array $woocommerce, array $media_map ): array {
        $products = $woocommerce['products'] ?? array();

        if ( empty( $products ) || ! is_array( $products ) ) {
            return array();
        }

        $term_id_by_name = $this->ensure_category_terms( $woocommerce, $products );
        $created_ids     = array();

        foreach ( $products as $product ) {
            if ( empty( $product['name'] ) ) {
                continue;
            }

            $product_id = $this->create_product( $product, $term_id_by_name, $media_map );

            if ( $product_id > 0 ) {
                $created_ids[] = $product_id;
            }
        }

        return $created_ids;
    }

    /**
     * Ensure every referenced category term exists (creating + tracking via the
     * reused manager) and return a name -> term id map for assignment.
     *
     * @param array $woocommerce
     * @param array $products
     * @return array<string, int>
     */
    private function ensure_category_terms( array $woocommerce, array $products ): array {
        $names = array();

        foreach ( $woocommerce['categories'] ?? array() as $category ) {
            if ( ! empty( $category['name'] ) ) {
                $names[] = (string) $category['name'];
            }
        }

        foreach ( $products as $product ) {
            foreach ( $product['categories'] ?? array() as $category ) {
                if ( ! empty( $category['name'] ) ) {
                    $names[] = (string) $category['name'];
                }
            }
        }

        $names = array_values( array_unique( $names ) );

        if ( empty( $names ) ) {
            return array();
        }

        // Creates missing terms and records their ids for teardown.
        $this->categories->ensure_category_ids( $names );

        $map = array();
        foreach ( $names as $name ) {
            $term = get_term_by( 'name', $name, 'product_cat' );
            if ( $term instanceof WP_Term ) {
                $map[ $name ] = (int) $term->term_id;
            }
        }

        return $map;
    }

    /**
     * @param array               $product
     * @param array<string, int>  $term_id_by_name
     * @param array<string, array{id: int, url: string}> $media_map
     */
    private function create_product( array $product, array $term_id_by_name, array $media_map ): int {
        // Go through the CRUD API rather than wp_insert_post + raw meta: only
        // save() writes the wc_product_meta_lookup row that the shop loop,
        // price filters and sorting read, and it owns the `simple`
        // `product_type` term for us.
        $wc_product = new WC_Product_Simple();

        $wc_product->set_name( sanitize_text_field( (string) $product['name'] ) );
        $wc_product->set_description( wp_kses_post( (string) ( $product['description'] ?? '' ) ) );
        $wc_product->set_short_description( wp_kses_post( (string) ( $product['short_description'] ?? '' ) ) );
        $wc_product->set_status( 'publish' );

        if ( ! empty( $product['slug'] ) ) {
            $wc_product->set_slug( sanitize_title( (string) $product['slug'] ) );
        }

        $price = isset( $product['regular_price'] ) ? (string) $product['regular_price'] : '';
        if ( $price !== '' ) {
            $wc_product->set_regular_price( $price );
            $wc_product->set_price( $price );
        }

        if ( ! empty( $product['sku'] ) ) {
            try {
                $wc_product->set_sku( sanitize_text_field( (string) $product['sku'] ) );
            } catch ( Throwable ) {
                // A duplicate or invalid SKU must not cost us the product.
            }
        }

        try {
            $product_id = (int) $wc_product->save();
        } catch ( Throwable ) {
            return 0;
        }

        if ( $product_id < 1 ) {
            return 0;
        }

        $this->attach_featured_image( $product_id, $product, $media_map );
        $this->assign_categories( $product_id, $product, $term_id_by_name );

        return $product_id;
    }

    /**
     * @param int                                         $product_id
     * @param array                                       $product
     * @param array<string, array{id: int, url: string}> $media_map
     */
    private function attach_featured_image( int $product_id, array $product, array $media_map ): void {
        $images = $product['images'] ?? array();
        if ( empty( $images ) || ! is_array( $images ) ) {
            return;
        }

        $src = $images[0]['src'] ?? '';
        if ( is_string( $src ) && isset( $media_map[ $src ] ) ) {
            set_post_thumbnail( $product_id, (int) $media_map[ $src ]['id'] );
        }
    }

    /**
     * @param int                $product_id
     * @param array              $product
     * @param array<string, int> $term_id_by_name
     */
    private function assign_categories( int $product_id, array $product, array $term_id_by_name ): void {
        $term_ids = array();

        foreach ( $product['categories'] ?? array() as $category ) {
            $name = $category['name'] ?? '';
            if ( is_string( $name ) && isset( $term_id_by_name[ $name ] ) ) {
                $term_ids[] = $term_id_by_name[ $name ];
            }
        }

        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $product_id, array_values( array_unique( $term_ids ) ), 'product_cat', false );
        }
    }
}
