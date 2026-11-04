<?php

namespace Hostinger\AiTheme\Builder;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter: envelope blog stubs -> WordPress posts.
 *
 * Reuses the legacy blog create primitive (a `post` + featured image + SEO
 * title/description via {@see Seo}); only the input mapping is new. Legacy
 * *generated* posts by calling ai-services — the envelope already carries them,
 * so this only maps each post stub onto the primitive: the body ships as a
 * Gutenberg block tree (`serialize_blocks`) for both editors, the cover becomes
 * the featured image from the sideloaded attachment, and — the one extension
 * beyond legacy — the envelope's category terms are created and assigned (the
 * `category` taxonomy), since legacy left posts uncategorized.
 */
class EnvelopePosts {

    private MediaSideloader $media;
    private Seo $seo;

    /**
     * @var array<int, int>
     */
    private array $created_category_ids = array();

    /**
     * @var int|null Resolved once per run by {@see self::resolve_post_author()}.
     */
    private ?int $post_author = null;

    public function __construct( ?MediaSideloader $media = null, ?Seo $seo = null ) {
        $this->media = $media ?? new MediaSideloader();
        $this->seo   = $seo ?? new Seo();
    }

    /**
     * Create posts from the envelope's blog block.
     *
     * @param array<string, array{id: int, url: string}> $media_map
     * @return array{post_ids: array<int, int>, category_ids: array<int, int>}
     */
    public function create( array $blog, array $media_map ): array {
        $posts = $blog['posts'] ?? array();

        if ( empty( $posts ) || ! is_array( $posts ) ) {
            return array(
                'post_ids'     => array(),
                'category_ids' => array(),
            );
        }

        $term_id_by_name = $this->ensure_categories( $blog, $posts );
        $created_ids     = array();

        foreach ( $posts as $post ) {
            if ( empty( $post['title'] ) ) {
                continue;
            }

            $post_id = $this->create_post( $post, $media_map, $term_id_by_name );

            if ( $post_id > 0 ) {
                $created_ids[] = $post_id;
            }
        }

        return array(
            'post_ids'     => $created_ids,
            'category_ids' => array_values( array_unique( $this->created_category_ids ) ),
        );
    }

    /**
     * Ensure every referenced blog category term exists and return a name ->
     * term id map. Created ids are tracked for teardown.
     *
     * @param array $blog
     * @param array $posts
     * @return array<string, int>
     */
    private function ensure_categories( array $blog, array $posts ): array {
        $names = array();

        foreach ( $blog['categories'] ?? array() as $category ) {
            if ( ! empty( $category['name'] ) ) {
                $names[] = (string) $category['name'];
            }
        }

        foreach ( $posts as $post ) {
            foreach ( $post['categories'] ?? array() as $category ) {
                if ( ! empty( $category['name'] ) ) {
                    $names[] = (string) $category['name'];
                }
            }
        }

        $names = array_values( array_unique( $names ) );
        $map   = array();

        foreach ( $names as $name ) {
            $term = get_term_by( 'name', $name, 'category' );

            if ( ! $term instanceof WP_Term ) {
                $result = wp_insert_term( $name, 'category' );
                if ( is_wp_error( $result ) || empty( $result['term_id'] ) ) {
                    continue;
                }

                $term_id                        = (int) $result['term_id'];
                $this->created_category_ids[]   = $term_id;
                $map[ $name ]                   = $term_id;
                continue;
            }

            $map[ $name ] = (int) $term->term_id;
        }

        return $map;
    }

    /**
     * @param array                                      $post
     * @param array<string, array{id: int, url: string}> $media_map
     * @param array<string, int>                         $term_id_by_name
     */
    private function create_post( array $post, array $media_map, array $term_id_by_name ): int {
        $blocks  = is_array( $post['content'] ?? null ) ? $post['content'] : array();
        $blocks  = $this->media->rewrite_gutenberg( $blocks, $media_map );
        $content = serialize_blocks( $blocks );

        $post_data = array(
            'post_title'   => sanitize_text_field( (string) $post['title'] ),
            'post_content' => $content,
            'post_excerpt' => wp_kses_post( (string) ( $post['excerpt'] ?? '' ) ),
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_author'  => $this->resolve_post_author(),
        );

        if ( ! empty( $post['slug'] ) ) {
            $post_data['post_name'] = sanitize_title( (string) $post['slug'] );
        }

        $post_id = Helper::insert_trusted_post( $post_data );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return 0;
        }

        $post_id = (int) $post_id;

        $this->attach_featured_image( $post_id, $post, $media_map );
        $this->assign_categories( $post_id, $post, $term_id_by_name );
        $this->apply_seo( $post_id, $post );

        return $post_id;
    }

    /**
     * Author for generated posts.
     *
     * The applier also runs under WP-CLI, where there is no current user: an
     * unset `post_author` would leave every post authored by user 0, breaking
     * bylines and author archives. Fall back to the oldest administrator.
     */
    private function resolve_post_author(): int {
        if ( $this->post_author !== null ) {
            return $this->post_author;
        }

        $author = get_current_user_id();

        if ( $author < 1 ) {
            $author = Helper::oldest_administrator_id();
        }

        $this->post_author = (int) $author;

        return $this->post_author;
    }

    /**
     * @param int                                         $post_id
     * @param array                                       $post
     * @param array<string, array{id: int, url: string}> $media_map
     */
    private function attach_featured_image( int $post_id, array $post, array $media_map ): void {
        $featured = $post['featured_image'] ?? null;
        if ( ! is_array( $featured ) ) {
            return;
        }

        $src = $featured['src'] ?? '';
        if ( is_string( $src ) && isset( $media_map[ $src ] ) ) {
            set_post_thumbnail( $post_id, (int) $media_map[ $src ]['id'] );
        }
    }

    /**
     * @param int                $post_id
     * @param array              $post
     * @param array<string, int> $term_id_by_name
     */
    private function assign_categories( int $post_id, array $post, array $term_id_by_name ): void {
        $term_ids = array();

        foreach ( $post['categories'] ?? array() as $category ) {
            $name = $category['name'] ?? '';
            if ( is_string( $name ) && isset( $term_id_by_name[ $name ] ) ) {
                $term_ids[] = $term_id_by_name[ $name ];
            }
        }

        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $post_id, array_values( array_unique( $term_ids ) ), 'category', false );
        }
    }

    /**
     * @param int   $post_id
     * @param array $post
     */
    private function apply_seo( int $post_id, array $post ): void {
        $this->seo->load_seo_title( $post_id, (string) $post['title'] );

        if ( ! empty( $post['excerpt'] ) ) {
            $this->seo->load_seo_description( $post_id, (string) $post['excerpt'] );
        }

        $this->seo->add_seo_meta_tags( $post_id );
    }
}
