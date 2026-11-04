<?php
/**
 * Template voor een individueel blogbericht.
 *
 * @package AutoMotion
 */

get_header();
?>

<main id="main">
    <div class="container content-layout" style="padding-top: 150px;">
        <article class="single-article">

            <?php while (have_posts()) : the_post(); ?>

                <header class="article-header">
                    <div class="post-meta">
                        <span><?php echo esc_html(get_the_date()); ?></span>

                        <span>
                            <?php the_category(', '); ?>
                        </span>
                    </div>

                    <h1><?php the_title(); ?></h1>

                    <p class="muted">
                        Door <?php the_author(); ?>
                        ·
                        <?php
                        $words = str_word_count(
                            wp_strip_all_tags(get_the_content())
                        );

                        echo esc_html(ceil($words / 220));
                        ?>
                        min leestijd
                    </p>
                </header>

                <?php if (has_post_thumbnail()) : ?>
                    <figure class="article-image">
                        <?php the_post_thumbnail('full'); ?>
                    </figure>
                <?php endif; ?>

                <div class="entry-content">
                    <?php
                    the_content();
                    wp_link_pages();
                    ?>
                </div>

                <?php
                // Toont bestaande reacties en het reactieformulier.
                if (comments_open() || get_comments_number()) {
                    comments_template();
                }
                ?>

            <?php endwhile; ?>

        </article>

        <?php get_sidebar(); ?>
    </div>
</main>

<?php get_footer(); ?>