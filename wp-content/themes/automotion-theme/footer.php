<?php /** Footer. @package AutoMotion */ ?>
<footer class="site-footer"><div class="container"><div class="footer-grid">
    <div><a class="brand" href="<?php echo esc_url(home_url('/')); ?>"><i class="brand-mark"></i><b>Auto<span>Motion</span></b></a><p class="muted">Dagelijks autonieuws, eerlijke reviews, tuning en de beste autoshows.</p></div>
    <div><div class="footer-title">Navigatie</div><?php wp_nav_menu(array('theme_location'=>'footer','container'=>false,'menu_class'=>'footer-menu','fallback_cb'=>false)); ?></div>
    <div><div class="footer-title">Onderwerpen</div><ul class="footer-menu"><?php wp_list_categories(array('title_li'=>'','number'=>5)); ?></ul></div>
</div><div class="footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> AutoMotion</span><span>Custom WordPress theme door Tawfik Alabed</span></div></div></footer>
<?php wp_footer(); ?></body></html>
