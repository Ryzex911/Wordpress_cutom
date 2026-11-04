<?php /* Template Name: Automodellen en prijzen */ get_header(); ?>
<main id="main"><section class="page-hero"><div class="container"><span class="eyebrow">Model database</span><h1>Modellen & prijzen</h1><p class="muted">Vergelijk modellen, prestaties en indicatieve vanafprijzen.</p></div></section><div class="container price-shortcode-wrap"><?php if(shortcode_exists('automotion_prices')){echo do_shortcode('[automotion_prices]');}else{ ?><div class="notice error">Activeer eerst de plugin “AutoMotion Car Prices”.</div><?php } ?></div></main>
<?php get_footer(); ?>
