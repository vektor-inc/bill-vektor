<?php 

require_once( 'inc/setting-page/setting-page.php' );


function bill_theme_scripts(){

  // 静的HTMLで読み込んでいたCSSを読み込む
  wp_enqueue_style( 'bill-css-bootstrap', get_template_directory_uri() . '/assets/css/bootstrap.min.css', array(), '3.3.6' );
  wp_enqueue_style( 'bill_css', get_template_directory_uri() . '/assets/css/style.css', array('bill-css-bootstrap'), '4' );

  // テーマディレクトリ直下にある style.css を出力
  wp_enqueue_style( 'bill-theme-style', get_stylesheet_uri(), array( 'bill_css' ),'20160710' );

	// テーマ用のjsを読み込む
	wp_enqueue_script( 'bill-js-bootstrap', get_template_directory_uri() . '/assets/js/bootstrap.min.js', array( 'jquery' ), '20160710', true );

}
add_action( 'wp_enqueue_scripts', 'bill_theme_scripts' );

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
