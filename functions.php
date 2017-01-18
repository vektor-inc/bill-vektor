<?php 

require_once( 'inc/custom_fields_builder.php' );
require_once( 'inc/setting-page/setting-page.php' );
require_once( 'inc/bill-custom-fields/bill-custom-fields.php' );
get_template_part('inc/template-tags');

function bill_theme_scripts(){

  // 静的HTMLで読み込んでいたCSSを読み込む
  wp_enqueue_style( 'bill-css-bootstrap', get_template_directory_uri() . '/assets/css/bootstrap.min.css', array(), '3.3.6' );
  wp_enqueue_style( 'bill-css', get_template_directory_uri() . '/assets/css/style.css', array('bill-css-bootstrap'), '4' );

  // テーマディレクトリ直下にある style.css を出力
  wp_enqueue_style( 'bill-theme-style', get_stylesheet_uri(), array( 'bill-css' ),'20160710' );

	// テーマ用のjsを読み込む
	wp_enqueue_script( 'bill-js-bootstrap', get_template_directory_uri() . '/assets/js/bootstrap.min.js', array( 'jquery' ), '20160710', true );

}
add_action( 'wp_enqueue_scripts', 'bill_theme_scripts' );

function bill_admin_scripts(){
  // 管理画面用のcss
  wp_enqueue_style( 'bill-admin-css', get_template_directory_uri() . '/assets/css/admin-style.css', array(), null );
  // 管理画面用のjs
  wp_enqueue_script( 'bill-js-bootstrap', get_template_directory_uri() . '/assets/js/admin.js', array( 'jquery','jquery-ui-sortable' ), null, true );
}
add_action( 'admin_enqueue_scripts', 'bill_admin_scripts' );

function bill_theme_title() {
    add_theme_support( 'title-tag' );
}
add_action( 'after_setup_theme', 'bill_theme_title' );

function bill_widgets_init() {
  register_sidebar( array(
    'name' => 'Sidebar',
    'id' => 'sidebar-widget-area',
    'before_widget' => '<aside class="sub-section section %2$s" id="%1$s">',
    'after_widget' => '</aside>',
    'before_title' => '<h4 class="sub-section-title">',
    'after_title' => '</h4>',
  ) );
}
add_action( 'widgets_init', 'bill_widgets_init' );

function bill_theme_custom_menu() {
    register_nav_menus( array( 'Header Navigation' => 'Header Navigation', ) );
}
add_action( 'after_setup_theme', 'bill_theme_custom_menu' );





function bill_options_default(){
      $default = array(
      'own-name'    => '株式会社ベクトル',
      'own-address'   => '〒460-0008
名古屋市中区栄1-22-16
ミナミ栄ビル 302号
TEL : 000-000-0000',
      'own-logo'    => '',
      'own-seal'    => '',
      'own-payee'   => '三菱東京UFJ銀行
尾張新川支店 普通 0040364
株式会社ベクトル',
      'remarks'     => '恐れ入りますが、お振込手数料は御社でご負担いただけますようお願い申し上げます。',
      );
  return $default;
}
