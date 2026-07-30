<?php
/*
---------------------------------------------
  No login redirect
---------------------------------------------
  REST API _ require login
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
  REST API _ require login
---------------------------------------------
*/
/**
 * 未ログインの REST API リクエストを拒否する
 *
 * bill_no_login_redirect() は wp フックで動くため、wp フックが実行されない REST API の
 * リクエストでは発火しない。そのため、このガードが無いと未ログインの第三者が
 * /wp-json/wp/v2/posts などから請求書の件名・発行日・パーマリンク・投稿者を取得できてしまう。
 * BillVektor はサイト全体がログイン必須の業務ツールで、匿名で REST API を利用する
 * 正当な利用者が存在しないため、ルート単位の許可リストは設けず一律で拒否する。
 * ログイン済みのリクエストは素通しするため、管理画面やブロックエディターには影響しない。
 *
 * @param WP_Error|true|null $result 先行するフィルターが返した認証結果。未判定の場合は null。
 * @return WP_Error|true|null 未ログインの場合は 401 の WP_Error。それ以外は $result をそのまま返す。
 */
function bill_rest_require_login( $result ) {
	// null 以外は「認証結果が既に確定している」というのが rest_authentication_errors の契約。
	// 他のプラグインが入れたエラー（WP_Error）や認証済みの明示（true）を潰さないよう、そのまま返す
	if ( null !== $result ) {
		return $result;
	}

	// 未ログインのリクエストは 401（認証が必要）で拒否する
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'bill_rest_not_logged_in',
			__( 'このサイトのREST APIを利用するにはログインが必要です。', 'bill-vektor' ),
			array( 'status' => 401 )
		);
	}

	// ログイン済みの場合は判定せず、後続のフィルター（コアの Cookie 認証チェック等）に委ねる
	return $result;
}
add_filter( 'rest_authentication_errors', 'bill_rest_require_login' );

/*
---------------------------------------------
	wp_head _ add noindex, nofollow
---------------------------------------------
*/
function bill_add_nofollow() {
	echo '<meta name="robots" content="noindex, nofollow">';
}
add_action( 'wp_head', 'bill_add_nofollow' );
