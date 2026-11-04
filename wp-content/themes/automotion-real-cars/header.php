<?php /** Header. @package AutoMotion */ ?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?><a class="skip-link screen-reader-text" href="#main">Ga naar inhoud</a>
<header class="site-header" id="site-header"><div class="container nav-wrap">
    <a class="brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="AutoMotion home">
        <?php if (has_custom_logo()) { the_custom_logo(); } else { ?><i class="brand-mark" aria-hidden="true"></i><b>Auto<span>Motion</span></b><?php } ?>
    </a>
    <nav aria-label="Hoofdnavigatie"><?php wp_nav_menu(array('theme_location'=>'primary','container'=>false,'menu_class'=>'primary-menu','fallback_cb'=>'automotion_fallback_menu')); ?></nav>
    <a class="nav-cta" href="<?php echo esc_url(home_url('/contact/')); ?>">Contact</a>
    <button class="menu-toggle" aria-expanded="false" aria-label="Menu openen">☰</button>
</div></header>
