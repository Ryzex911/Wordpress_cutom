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

function automotion_assets(): void {
    wp_enqueue_style('automotion-style', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
    wp_enqueue_script('automotion-main', get_template_directory_uri().'/assets/js/main.js', array(), wp_get_theme()->get('Version'), true);
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

function automotion_fallback_menu(): void {
    echo '<ul class="primary-menu"><li><a href="'.esc_url(home_url('/')).'">Home</a></li><li><a href="'.esc_url(get_post_type_archive_link('post') ?: home_url('/blog/')).'">Blog</a></li></ul>';
}

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
