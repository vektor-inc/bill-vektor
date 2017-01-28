<?php 

/*-------------------------------------------*/
/*  Load Module
/*-------------------------------------------*/
/*  Theme setup
/*-------------------------------------------*/
/*  Load Theme CSS & JS
/*-------------------------------------------*/
/*  Load Admin CSS & JS
/*-------------------------------------------*/
/*  WidgetArea initiate
/*-------------------------------------------*/
/*  Add Post Type Client
/*-------------------------------------------*/
/*  Remove_post_editor_support
/*-------------------------------------------*/
/*  No login redirect
/*-------------------------------------------*/
/*  Replace Post Label
/*-------------------------------------------*/


$theme_opt = wp_get_theme(get_template());
define('BILLVEKTOR_THEME_VERSION', $theme_opt->Version);

/*-------------------------------------------*/
/*  Load Module
/*-------------------------------------------*/
require_once( 'inc/custom-field-builder-config.php' );
require_once( 'inc/setting-page/setting-page.php' );
require_once( 'inc/custom-field-bill/custom-field-bill.php' );
require_once( 'inc/custom-field-estimate/custom-field-estimate.php' );
require_once( 'inc/custom-field-client/custom-field-client.php' );
require_once( 'inc/duplicate-doc/duplicate-doc.php' );

get_template_part('inc/template-tags');

/*-------------------------------------------*/
/*  Theme setup
/*-------------------------------------------*/
function bill_theme_title() {
    // title tag
    add_theme_support( 'title-tag' );
    // custom menu
    register_nav_menus( array( 'Header Navigation' => 'Header Navigation', ) );
}
add_action( 'after_setup_theme', 'bill_theme_title' );

/*-------------------------------------------*/
/*  Load Theme CSS & JS
/*-------------------------------------------*/
function bill_theme_scripts(){

  // 静的HTMLで読み込んでいたCSSを読み込む
  wp_enqueue_style( 'bill-css-bootstrap', get_template_directory_uri() . '/assets/css/bootstrap.min.css', array(), '3.3.6' );
  wp_enqueue_style( 'bill-css', get_template_directory_uri() . '/assets/css/style.css', array('bill-css-bootstrap'), BILLVEKTOR_THEME_VERSION );

  // テーマディレクトリ直下にある style.css を出力
  wp_enqueue_style( 'bill-theme-style', get_stylesheet_uri(), array( 'bill-css' ),BILLVEKTOR_THEME_VERSION );

	// テーマ用のjsを読み込む
	wp_enqueue_script( 'bill-js-bootstrap', get_template_directory_uri() . '/assets/js/bootstrap.min.js', array( 'jquery' ), BILLVEKTOR_THEME_VERSION, true );

}
add_action( 'wp_enqueue_scripts', 'bill_theme_scripts' );

/*-------------------------------------------*/
/*  Load Admin CSS & JS
/*-------------------------------------------*/
function bill_admin_scripts(){
  // 管理画面用のcss
  wp_enqueue_style( 'bill-admin-css', get_template_directory_uri() . '/assets/css/admin-style.css', BILLVEKTOR_THEME_VERSION, null );
  // 管理画面用のjs
  wp_enqueue_script( 'bill-js-bootstrap', get_template_directory_uri() . '/assets/js/admin.js', array( 'jquery','jquery-ui-sortable' ), BILLVEKTOR_THEME_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'bill_admin_scripts' );

/*-------------------------------------------*/
/*  WidgetArea initiate
/*-------------------------------------------*/
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


/*-------------------------------------------*/
/*  Add Post Type Client
/*-------------------------------------------*/
add_action( 'init', 'bill_add_post_type_client', 0 );
function bill_add_post_type_client() {
    register_post_type( 'client', /* カスタム投稿タイプのスラッグ */
        array(
            'labels' => array(
                'name' => '取引先',
            ),
        'public'             => false,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'has_archive'        => false,
        'supports'           => array('title'),
        'menu_icon'          => 'dashicons-building',
        'menu_position'      => 3,
        )
    );
}
/*-------------------------------------------*/
/*  Add Post Type Estimate
/*-------------------------------------------*/
add_action( 'init', 'bill_add_post_type_estimate', 0 );
function bill_add_post_type_estimate() {
    register_post_type( 'estimate',
        array(
            'labels' => array(
                'name' => '見積書',
            ),
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'has_archive'        => true,
        'supports'           => array('title'),
        'menu_icon'          => 'dashicons-media-spreadsheet',
        'menu_position'      => 5,
        )
    );
    register_taxonomy(
      'estimate-cat', 
      'estimate',
      array(
        'hierarchical' => true,
        'update_count_callback' => '_update_post_term_count',
        'label' => '見積書カテゴリー',
        'singular_label' => '見積書カテゴリー',
        'public' => true,
        'show_ui' => true,
      )
    );
}

/*-------------------------------------------*/
/*  Remove_post_editor_support
/*-------------------------------------------*/
function bill_remove_post_editor_support() {
 remove_post_type_support( 'post', 'editor' );
}
add_action( 'init' , 'bill_remove_post_editor_support' );

/*-------------------------------------------*/
/*  No login redirect
/*-------------------------------------------*/
function bill_no_login_redirect( $content ) {
  global $pagenow;
  if( !is_user_logged_in() && !is_admin() && ( $pagenow != 'wp-login.php' ) && php_sapi_name() !== 'cli' ){
    auth_redirect();
  }
}//bill_no_login_redirect
add_action( 'init', 'bill_no_login_redirect' );

/*-------------------------------------------*/
/*  Replace Post Label
/*-------------------------------------------*/
function bill_change_post_type_args_post($args){
  if ( isset( $args['rest_base'] ) && $args['rest_base'] == 'posts' ) {
    $args['labels']['name_admin_bar'] = '請求書';
    $args['labels']['name'] = '請求書';
  }
  return $args;
}
add_filter( 'register_post_type_args', 'bill_change_post_type_args_post' );


// function bill_custom_home_post_type($query){
//     if ( $query->is_front_page() && $query->is_main_query() ) {
//         $query->set( 'post_type', array( 'post', 'estimate' ) );
//     }
// }
// add_action( 'pre_get_posts', 'bill_custom_home_post_type' );