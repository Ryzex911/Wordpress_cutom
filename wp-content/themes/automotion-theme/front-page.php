<?php get_header(); ?>
<main id="main">
<section class="hero"><div class="container hero-grid"><div class="hero-copy reveal">
    <span class="eyebrow">Driven by passion</span><h1>Voel elke <span>beweging.</span></h1>
    <p>Ontdek autonieuws, diepgaande reviews, unieke tuningprojecten en de grootste autoshows. Eén platform voor iedereen met benzine in het bloed.</p>
    <div class="hero-actions"><a class="btn" href="#latest">Ontdek het laatste →</a><a class="btn btn-ghost" href="<?php echo esc_url(home_url('/contact/')); ?>">Neem contact op</a></div>
    <div class="hero-stats"><div class="stat"><strong><?php echo esc_html(wp_count_posts()->publish); ?>+</strong><span>Artikelen</span></div><div class="stat"><strong>4</strong><span>Categorieën</span></div><div class="stat"><strong>100%</strong><span>Autopassie</span></div></div>
</div><div class="car-stage reveal" aria-label="Gestileerde interactieve sportauto"><div class="car-glow"></div><div class="road-line"></div><div class="car-3d" id="car-3d"><div class="car-roof"></div><div class="car-window"></div><div class="car-body"></div><div class="car-light"></div><div class="wheel wheel-left"></div><div class="wheel wheel-right"></div></div></div></div><div class="scroll-hint">Scroll</div></section>

<section class="topics"><div class="container topic-row"><a class="topic-pill" href="<?php echo esc_url(home_url('/')); ?>">Alles</a><?php foreach (get_categories(array('hide_empty'=>false)) as $cat) { ?><a class="topic-pill" href="<?php echo esc_url(get_category_link($cat)); ?>"><?php echo esc_html($cat->name); ?></a><?php } ?></div></section>

<section class="section" id="latest"><div class="container"><header class="section-head reveal"><div><span class="eyebrow">Vers van de redactie</span><h2>Laatste verhalen</h2></div><a class="read-more" href="<?php echo esc_url(get_permalink(get_option('page_for_posts')) ?: home_url('/blog/')); ?>">Bekijk alle berichten</a></header>
<div class="posts-grid"><?php $latest=new WP_Query(array('posts_per_page'=>6)); if($latest->have_posts()): while($latest->have_posts()):$latest->the_post(); get_template_part('template-parts/card'); endwhile; wp_reset_postdata(); else: ?><p class="muted">Voeg je eerste berichten toe via WordPress → Posts.</p><?php endif; ?></div></div></section>

<section class="section"><div class="container"><div class="feature-panel reveal"><div><span class="eyebrow">Meer dan nieuws</span><h2>Techniek die je kunt voelen.</h2><p class="muted">Van pk’s en acceleratie tot design en rijbeleving: AutoMotion maakt autotechniek begrijpelijk, zonder de passie eruit te halen.</p><a class="btn" href="<?php echo esc_url(home_url('/category/reviews/')); ?>">Lees onze reviews</a></div><div class="speed-gauge"><div><strong>320</strong><span>KM / UUR</span></div></div></div></div></section>
<section class="cta-panel"><div class="container reveal"><h2>Jouw verhaal op AutoMotion?</h2><p>Heb je een bijzondere auto, evenement of tuningproject? Laat van je horen.</p><a class="btn" href="<?php echo esc_url(home_url('/contact/')); ?>">Stuur ons een bericht</a></div></section>
</main><?php get_footer(); ?>
