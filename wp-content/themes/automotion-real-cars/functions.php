<?php
/** Themafuncties voor AutoMotion. @package AutoMotion */
if (!defined('ABSPATH')) { exit; }

function automotion_setup(): void {
    load_theme_textdomain('automotion', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array('search-form','comment-form','comment-list','gallery','caption','style','script'));
    add_theme_support('custom-logo', array('height'=>80,'width'=>260,'flex-height'=>true,'flex-width'=>true));
    add_theme_support('responsive-embeds');
    register_nav_menus(array('primary'=>'Hoofdmenu','footer'=>'Footermenu'));
}
add_action('after_setup_theme','automotion_setup');

function automotion_assets(): void
{
    $css_file = get_stylesheet_directory() . '/style.css';
    $js_file  = get_template_directory() . '/assets/js/main.js';

    wp_enqueue_style(
        'automotion-style',
        get_stylesheet_uri(),
        array(),
        file_exists($css_file) ? filemtime($css_file) : wp_get_theme()->get('Version')
    );

    wp_enqueue_script(
        'automotion-main',
        get_template_directory_uri() . '/assets/js/main.js',
        array(),
        file_exists($js_file) ? filemtime($js_file) : wp_get_theme()->get('Version'),
        true
    );
}
add_action('wp_enqueue_scripts','automotion_assets');

function automotion_widgets(): void {
    register_sidebar(array(
        'name'=>'Blog sidebar','id'=>'blog-sidebar','description'=>'Widgets naast het berichtenoverzicht en losse berichten.',
        'before_widget'=>'<section id="%1$s" class="widget %2$s">','after_widget'=>'</section>',
        'before_title'=>'<h2 class="widget-title">','after_title'=>'</h2>'
    ));
}
add_action('widgets_init','automotion_widgets');

function automotion_excerpt_length(): int { return 20; }
add_filter('excerpt_length','automotion_excerpt_length');

/** Echte Audi-fallbackfoto voor berichten zonder uitgelichte afbeelding. */
function automotion_default_car_image(?int $post_id = null): string {
    $images = array(
        'https://images.unsplash.com/photo-1614699207812-8ec8e6967144?auto=format&fit=crop&w=1200&q=85',
        'https://images.unsplash.com/photo-1612468008274-9445bd56161e?auto=format&fit=crop&w=1200&q=85',
        'https://images.unsplash.com/photo-1658216144552-93c120e63a98?auto=format&fit=crop&w=1200&q=85',
    );
    $id = $post_id ?: (int) get_the_ID();
    return $images[$id % count($images)];
}

function automotion_fallback_menu(): void {
    echo '<ul class="primary-menu"><li><a href="'.esc_url(home_url('/')).'">Home</a></li><li><a href="'.esc_url(home_url('/over-ons/')).'">Over ons</a></li><li><a href="'.esc_url(home_url('/blog/')).'">Blogs</a></li><li><a href="'.esc_url(home_url('/prijzen/')).'">Prijzen</a></li></ul>';
}

/**
 * Richt de demo bij thema-activatie automatisch in.
 * Bestaande pagina's worden hergebruikt en nooit verwijderd.
 */
function automotion_create_demo_setup(): void {
    $definitions = array(
        'home'     => array('title'=>'Home', 'template'=>'default'),
        'over-ons' => array('title'=>'Over ons', 'template'=>'page-about.php'),
        'blog'     => array('title'=>'Blogs', 'template'=>'default'),
        'prijzen'  => array('title'=>'Prijzen', 'template'=>'page-prijzen.php'),
        'contact'  => array('title'=>'Contact', 'template'=>'page-contact.php'),
    );
    $page_ids = array();
    foreach ($definitions as $slug => $data) {
        $page = get_page_by_path($slug);
        if (!$page) {
            $id = wp_insert_post(array('post_title'=>$data['title'],'post_name'=>$slug,'post_type'=>'page','post_status'=>'publish'));
        } else {
            $id = $page->ID;
        }
        if (!is_wp_error($id) && $id) {
            $page_ids[$slug] = (int) $id;
            if ($data['template'] !== 'default') { update_post_meta($id, '_wp_page_template', $data['template']); }
        }
    }
    if (isset($page_ids['home'])) { update_option('show_on_front','page'); update_option('page_on_front',$page_ids['home']); }
    if (isset($page_ids['blog'])) { update_option('page_for_posts',$page_ids['blog']); }

    $menu_name = 'AutoMotion hoofdmenu';
    $menu = wp_get_nav_menu_object($menu_name);
    $menu_id = $menu ? $menu->term_id : wp_create_nav_menu($menu_name);
    if (!is_wp_error($menu_id)) {
        $existing = wp_get_nav_menu_items($menu_id) ?: array();
        $linked = array_map(static fn($item)=>(int)$item->object_id, $existing);
        foreach (array('home','over-ons','blog','prijzen') as $slug) {
            if (isset($page_ids[$slug]) && !in_array($page_ids[$slug],$linked,true)) {
                wp_update_nav_menu_item($menu_id,0,array('menu-item-title'=>$definitions[$slug]['title'],'menu-item-object'=>'page','menu-item-object-id'=>$page_ids[$slug],'menu-item-type'=>'post_type','menu-item-status'=>'publish'));
            }
        }
        $locations = get_theme_mod('nav_menu_locations',array());
        $locations['primary'] = $menu_id; $locations['footer'] = $menu_id;
        set_theme_mod('nav_menu_locations',$locations);
    }
    flush_rewrite_rules();
}
add_action('after_switch_theme','automotion_create_demo_setup');

function automotion_contact_submit(): void {
    if (!isset($_POST['automotion_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['automotion_nonce'])), 'automotion_contact')) {
        wp_die('Beveiligingscontrole mislukt. Probeer het opnieuw.');
    }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
    $target = wp_get_referer() ?: home_url('/');
    if (!$name || !is_email($email) || !$message) {
        wp_safe_redirect(add_query_arg('contact','error',$target)); exit;
    }
    $subject = sprintf('Nieuw AutoMotion-bericht van %s', $name);
    $headers = array('Reply-To: '.$name.' <'.$email.'>');
    $sent = wp_mail(get_option('admin_email'), $subject, $message, $headers);
    wp_safe_redirect(add_query_arg('contact', $sent ? 'success' : 'error', $target)); exit;
}
add_action('admin_post_nopriv_automotion_contact','automotion_contact_submit');
add_action('admin_post_automotion_contact','automotion_contact_submit');
