<?php
/**
 * Plugin Name: AutoMotion Car Prices
 * Description: Beheer automodellen, specificaties en indicatieve prijzen voor de AutoMotion-website.
 * Version: 1.0.0
 * Author: Tawfik Alabed
 * Requires PHP: 8.0
 * Text Domain: automotion-prices
 */

if (!defined('ABSPATH')) { exit; }

/** Registreert het automodel als apart inhoudstype in WordPress. */
function amp_register_car_type(): void {
    register_post_type('car_model', array(
        'labels'=>array('name'=>'Automodellen','singular_name'=>'Automodel','add_new_item'=>'Nieuw automodel','edit_item'=>'Automodel bewerken'),
        'public'=>true,'show_in_rest'=>true,'menu_icon'=>'dashicons-car','has_archive'=>true,'rewrite'=>array('slug'=>'automodellen'),
        'supports'=>array('title','editor','thumbnail'),'taxonomies'=>array('car_brand')
    ));
    register_taxonomy('car_brand','car_model',array('labels'=>array('name'=>'Merken','singular_name'=>'Merk'),'public'=>true,'show_in_rest'=>true,'hierarchical'=>true));
}
add_action('init','amp_register_car_type');

/** Velden voor prijs, vermogen, acceleratie en externe fallbackfoto. */
function amp_add_meta_box(): void {
    add_meta_box('amp_specs','Modelgegevens','amp_meta_box_html','car_model','normal','high');
}
add_action('add_meta_boxes','amp_add_meta_box');

function amp_meta_box_html(WP_Post $post): void {
    wp_nonce_field('amp_save_specs','amp_specs_nonce');
    $fields=array('price'=>'Vanafprijs (€)','power'=>'Vermogen','acceleration'=>'0–100 km/u','image'=>'Afbeelding URL');
    foreach($fields as $key=>$label){$value=get_post_meta($post->ID,'_amp_'.$key,true);echo '<p><label for="amp_'.$key.'"><strong>'.esc_html($label).'</strong></label><br><input style="width:100%;max-width:650px" id="amp_'.$key.'" name="amp_'.$key.'" value="'.esc_attr($value).'"></p>';}
}

function amp_save_specs(int $post_id): void {
    if(!isset($_POST['amp_specs_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amp_specs_nonce'])),'amp_save_specs')||defined('DOING_AUTOSAVE')){return;}
    if(!current_user_can('edit_post',$post_id)){return;}
    foreach(array('price','power','acceleration','image') as $key){if(isset($_POST['amp_'.$key])){$raw=wp_unslash($_POST['amp_'.$key]);$value=$key==='image'?esc_url_raw($raw):sanitize_text_field($raw);update_post_meta($post_id,'_amp_'.$key,$value);}}
}
add_action('save_post_car_model','amp_save_specs');

/** Shortcode [automotion_prices] voor de openbare prijzenpagina. */
function amp_prices_shortcode(): string {
    $cars=new WP_Query(array('post_type'=>'car_model','posts_per_page'=>-1,'orderby'=>'menu_order title','order'=>'ASC'));
    ob_start();
    if($cars->have_posts()){echo '<div class="price-grid">';while($cars->have_posts()){$cars->the_post();$id=get_the_ID();$image=get_the_post_thumbnail_url($id,'large')?:get_post_meta($id,'_amp_image',true);$price=get_post_meta($id,'_amp_price',true);$power=get_post_meta($id,'_amp_power',true);$accel=get_post_meta($id,'_amp_acceleration',true);echo '<article class="price-card">';if($image){echo '<img src="'.esc_url($image).'" alt="'.esc_attr(get_the_title()).'">';}echo '<div class="price-card-body"><span class="eyebrow">Audi model</span><h3>'.esc_html(get_the_title()).'</h3><p class="muted">'.esc_html(wp_trim_words(get_the_excerpt(),18)).'</p><span class="car-price">Vanaf € '.esc_html($price).'</span><div class="car-specs"><span>'.esc_html($power).'</span><span>0–100: '.esc_html($accel).'</span></div></div></article>';}echo '</div>';wp_reset_postdata();}else{echo '<p>Er zijn nog geen automodellen toegevoegd.</p>';}
    return (string) ob_get_clean();
}
add_shortcode('automotion_prices','amp_prices_shortcode');

/** Plaatst drie bewerkbare voorbeeldmodellen bij de eerste activatie. */
function amp_activate(): void {
    amp_register_car_type();
    if(!get_option('amp_seeded')){
        $cars=array(
            array('Audi R8 V10 Performance','Een atmosferische V10-supercar met quattro en een messcherpe respons.','245.000','620 pk','3,1 sec','https://images.unsplash.com/photo-1614699207812-8ec8e6967144?auto=format&fit=crop&w=1200&q=85'),
            array('Audi RS6 Avant','Dagelijkse bruikbaarheid gecombineerd met het vermogen van een echte supercar.','169.900','630 pk','3,4 sec','https://images.unsplash.com/photo-1658216144552-93c120e63a98?auto=format&fit=crop&w=1200&q=85'),
            array('Audi RS e-tron GT','Elektrische prestaties, quattro-aandrijving en een uitgesproken Gran Turismo-design.','149.990','646 pk','3,3 sec','https://images.unsplash.com/photo-1612468008274-9445bd56161e?auto=format&fit=crop&w=1200&q=85')
        );
        foreach($cars as $car){$id=wp_insert_post(array('post_type'=>'car_model','post_status'=>'publish','post_title'=>$car[0],'post_content'=>$car[1],'post_excerpt'=>$car[1]));if(!is_wp_error($id)){update_post_meta($id,'_amp_price',$car[2]);update_post_meta($id,'_amp_power',$car[3]);update_post_meta($id,'_amp_acceleration',$car[4]);update_post_meta($id,'_amp_image',$car[5]);}}
        update_option('amp_seeded',1);
    }
    flush_rewrite_rules();
}
register_activation_hook(__FILE__,'amp_activate');
register_deactivation_hook(__FILE__,'flush_rewrite_rules');
