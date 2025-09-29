<?php
/*
---------------------------------------------
  No login redirect
---------------------------------------------
  wp_head _ add noindex, nofollow
---------------------------------------------
 */


 /*
 ---------------------------------------------
   No login redirect
 ---------------------------------------------
 */
function bill_no_login_redirect( $content ) {
	global $pagenow;
	if ( ! is_user_logged_in() && ! is_admin() ) {
		// ログインページへリダイレクト
		$url = wp_login_url( $_SERVER['REQUEST_URI'] );
		wp_safe_redirect( $url );
		exit;

		/*
		auth_redirect() の場合、アドレスバーにURLをhttps無しで直入力された時に、ログイン先は http でも実際にはhttpsなので認証が通らなくて無限ループになる
		*/

	}
}//end bill_no_login_redirect()
add_action( 'wp', 'bill_no_login_redirect' );

/*
---------------------------------------------
	wp_head _ add noindex, nofollow
---------------------------------------------
*/
function bill_add_nofollow() {
	echo '<meta name="robots" content="noindex, nofollow">';
}
add_action( 'wp_head', 'bill_add_nofollow' );
