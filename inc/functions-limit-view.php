<?php
/*-------------------------------------------*/
/*  No login redirect
/*-------------------------------------------*/
/*  wp_head _ add noindex, nofollow
/*-------------------------------------------*/


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
/*  wp_head _ add noindex, nofollow
/*-------------------------------------------*/
function bill_add_nofollow(){
  echo '<meta name="robots" content="noindex, nofollow">';
}
add_action( 'wp_head', 'bill_add_nofollow' );