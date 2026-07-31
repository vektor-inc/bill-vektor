<?php
/*
---------------------------------------------
  No login redirect
---------------------------------------------
  書類の閲覧権限
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
/**
 * フロント側の閲覧を制限する
 *
 * 未ログインのリクエストはログインページへ誘導し、ログイン済みでも書類の閲覧権限が
 * 無いユーザーには 403 のページを表示する。
 *
 * wp フックに登録しているため、通常のページだけでなくフィード・検索結果・
 * サイトマップ・oEmbed の HTML・「?p=ID」の直アクセスもまとめて対象になる。
 * これらの出力は wp フックより後の template_redirect 以降で行われるため、
 * ここで止めれば書類の情報が出力されることはない。
 *
 * @param WP|null $content wp アクションから渡される WP オブジェクト（未使用）。既存の引数名を維持している。
 * @return void
 */
function bill_no_login_redirect( $content = null ) {
	// 管理画面は WordPress 本体の権限（auth_redirect() と各画面の権限チェック）で
	// 保護されているため対象外とする。
	// なお wp アクションは wp-blog-header.php からしか発火せず、管理画面や
	// admin-ajax.php では実行されないため、この判定自体は保険として残している。
	//
	// ここで is_admin() を使ってはいけない。is_admin() は $GLOBALS['current_screen'] が
	// あればそちらを優先して見るため、フロント側で set_current_screen() を呼ぶプラグインが
	// 同居すると WP_Screen::get() が in_admin = 'site' を立てて is_admin() が true になる。
	// そうなるとこのガードが丸ごと素通りし、未ログインの第三者にサイト全体が開いてしまう。
	// WP_ADMIN は wp-admin/admin.php が読み込まれた時点で定義される定数で、
	// プラグインから後付けで立てられる current_screen と違って乗っ取られない。
	if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
		return;
	}

	// 未ログインのリクエストはログインページへ誘導する（従来どおりの挙動）
	if ( ! is_user_logged_in() ) {
		/*
		auth_redirect() の場合、アドレスバーにURLをhttps無しで直入力された時に、ログイン先は http でも実際にはhttpsなので認証が通らなくて無限ループになる
		*/
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url         = wp_login_url( $request_uri );
		wp_safe_redirect( $url );
		exit;
	}

	// ログイン済みでも書類の閲覧権限が無い場合は 403 のページを表示する。
	// ここでログインページへリダイレクトしてはいけない。wp-login.php は POST が無くても
	// wp_signon() を実行し、その中の wp_authenticate_cookie() がログイン Cookie から
	// ユーザーを復元して redirect_to へ戻すため、フロントと wp-login.php を
	// 往復し続ける無限リダイレクトになる。
	if ( ! bill_can_view_documents() ) {
		bill_render_forbidden_page();
	}
}//end bill_no_login_redirect()
add_action( 'wp', 'bill_no_login_redirect' );

/*
---------------------------------------------
  書類の閲覧権限
---------------------------------------------
*/
/**
 * 書類を閲覧できるユーザーかどうかを判定する
 *
 * BillVektor のフロント側は投稿者を問わず全ての請求書・見積書の一覧と明細
 * （金額・取引先・自社の住所や振込口座）を表示するため、「ログインしているだけ」の
 * ユーザーに見せてよい画面ではない。
 *
 * 既定の条件は投稿の編集権限（edit_posts）とする。寄稿者・投稿者も edit_posts を
 * 持つため、これらのロールで書類を運用している既存サイトの動作は変わらず、
 * 締め出されるのは購読者のような閲覧専用のロールだけになる。
 *
 * リダイレクトや exit を含まない判定だけの関数にしているのは、PHPUnit から直接
 * 呼び出して権限ごとの結果を検証できるようにするため（CsvExport::can_export() と同じ方針）。
 *
 * @param WP_User|int|null $user 判定対象のユーザー。WP_User かユーザーIDを受け取る。
 *                               null の場合はログイン中のユーザーを判定する。
 * @return bool 閲覧できる場合は true、未ログインまたは権限が無い場合は false。
 */
function bill_can_view_documents( $user = null ) {
	if ( ! $user instanceof WP_User ) {
		// user_can() がユーザーIDを受け取るため、拡張側がIDを渡すのは自然な使い方になる。
		// WP_User だけを受け付けて他を切り捨てると、ID を渡されたときに黙って
		// ログイン中のユーザーを判定してしまい、権限のある閲覧者として通してしまう。
		if ( is_numeric( $user ) ) {
			$user = get_userdata( (int) $user );
		} else {
			// 引数が省略された場合はログイン中のユーザーを対象にする
			$user = wp_get_current_user();
		}
	}

	// 存在しないユーザーIDを渡された場合は get_userdata() が false を返す。閉じる側に倒す
	if ( ! $user instanceof WP_User ) {
		return false;
	}

	// 未ログイン（ID が 0）はここで確定させ、フィルターにも通さない。
	// フィルターより先に閉じておかないと、拡張側が true を返した瞬間に匿名の第三者へ
	// 全公開されてしまい、CSVエクスポート・REST API で塞いだ未ログインの漏洩が復活する。
	if ( empty( $user->ID ) ) {
		return false;
	}

	// 既定の閲覧条件は投稿の編集権限
	$can_view = user_can( $user, 'edit_posts' );

	/**
	 * 書類の閲覧権限の判定結果を変更する
	 *
	 * 閲覧専用のアカウントにも書類を見せたい場合や、逆に編集者以上へ限定したい場合に使う。
	 *
	 * 例）編集者以上に限定する:
	 *     add_filter(
	 *         'bill_vektor_can_view_documents',
	 *         function( $can_view, $user ) {
	 *             return user_can( $user, 'edit_others_posts' );
	 *         },
	 *         10,
	 *         2
	 *     );
	 *
	 * 未ログインのリクエストはこのフィルターに到達しないため、true を返しても
	 * 匿名の第三者へ公開されることはない。
	 *
	 * @param bool    $can_view 閲覧を許可する場合は true。
	 * @param WP_User $user     判定対象のユーザー。必ずログイン済みのユーザーが渡される。
	 */
	return (bool) apply_filters( 'bill_vektor_can_view_documents', $can_view, $user );
}

/**
 * ユーザーのロール名を表示用の文字列で取得する
 *
 * 403 のページで「どのアカウントでログインしているせいで見られないのか」を示すために使う。
 * 権限不足の原因はほとんどが「別のアカウントでログインしたままだった」ことのため、
 * ユーザー名とあわせてロール名を明示しないとユーザーが復帰できない。
 *
 * @param WP_User|null $user 対象のユーザー。null の場合はログイン中のユーザーを使う。
 * @return string 翻訳済みのロール名。複数ある場合は区切り文字で連結する。ロールが無い場合は空文字。
 */
function bill_get_user_role_label( $user = null ) {
	// 引数が省略された場合はログイン中のユーザーを対象にする
	if ( ! $user instanceof WP_User ) {
		$user = wp_get_current_user();
	}

	// 未ログイン、またはロールが割り当てられていないユーザーは表示できる名前が無い
	if ( empty( $user->ID ) || empty( $user->roles ) ) {
		return '';
	}

	$wp_roles   = wp_roles();
	$role_names = array();

	foreach ( $user->roles as $role ) {
		if ( isset( $wp_roles->role_names[ $role ] ) ) {
			// 登録済みのロールは翻訳済みの表示名（「購読者」など）にする
			$role_names[] = translate_user_role( $wp_roles->role_names[ $role ] );
		} else {
			// プラグイン削除後の残骸などで登録が無いロールはスラッグのまま表示する
			$role_names[] = $role;
		}
	}

	// 複数のロールを持つユーザーに備えて連結する
	return implode( _x( '、', 'ユーザーロール名の区切り文字', 'bill-vektor' ), $role_names );
}

/**
 * 403 のページの <title> を返す
 *
 * wp_get_document_title() は pre_get_document_title が空文字以外を返した時点で
 * その値を採用するため、このフィルターを最後に掛けてタイトルを確定させる。
 *
 * @param string $title 先行するフィルターが返したタイトル（未使用）。
 * @return string 403 のページ用のタイトル。
 */
function bill_get_forbidden_document_title( $title ) {
	return __( 'この画面を表示する権限がありません', 'bill-vektor' ) . ' - ' . get_bloginfo( 'name', 'display' );
}

/**
 * 書類の閲覧権限が無いログインユーザー向けに 403 のページを出力して処理を終了する
 *
 * ログイン済みのユーザーをログインページへリダイレクトすると無限ループになるため、
 * リダイレクトではなく 403 のページを表示する（詳細は bill_no_login_redirect() のコメント参照）。
 *
 * サイドバー（カテゴリー名を出力する）とパンくず（書類の件名を出力する）は、
 * 閲覧を塞いだはずの情報が漏れてしまうため、このページでは読み込まない。
 *
 * @return void
 */
function bill_render_forbidden_page() {
	// 権限のあるユーザーが同じ URL を開いたときに 403 のページが再利用されないよう、
	// CDN・プロキシ・ブラウザにキャッシュさせない
	nocache_headers();
	status_header( 403 );

	// フィードのリクエストでは WP::send_headers() が wp フックより前に
	// application/rss+xml を送出済みで、そのままだと HTML を RSS として返してしまう。
	// ここで HTML に上書きする
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

	// 書類の情報を参照しているグローバルを空にしてから描画する
	bill_reset_query_for_forbidden_page();

	// 空のクエリではサイト名だけの <title> になるため、状況が分かる文言に差し替える
	add_filter( 'pre_get_document_title', 'bill_get_forbidden_document_title', 99 );

	get_header();
	get_template_part( 'template-parts/view-forbidden' );
	get_footer();

	exit;
}

/**
 * 403 のページを描画する前に、書類の情報を参照しているグローバルを空にする
 *
 * メインクエリを引数なしの WP_Query に差し替える。引数なしの WP_Query はクエリを実行せず
 * is_singular() などの条件分岐タグが全て false になるため、wp_head() が出力する
 * <title>・canonical・oEmbed の discovery リンク・フィードリンク・前後の記事リンクから
 * 書類の件名やスラッグが漏れるのを、出力元を1つずつ潰さずにまとめて防げる。
 *
 * $wp_the_query と $post も同時に空にする。$wp_query だけ差し替えても、
 * 管理バーの編集リンク（wp_admin_bar_edit_menu()）は $wp_the_query を、
 * SEO 系・構造化データ系の拡張は wp_head / wp_footer で global $post を直接読むため、
 * これらが書類を参照し続けてしまう。
 *
 * 呼び出し元は直後に exit するため、差し替えによる副作用は残らない。
 *
 * @return void
 */
function bill_reset_query_for_forbidden_page() {
	global $wp_query;

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 403 のページから書類の情報が漏れないよう、意図的にメインクエリを空にする
	$wp_query = new WP_Query();

	// 通常のリクエストと同様に、メインクエリと同じインスタンスを指させる
	$GLOBALS['wp_the_query'] = $wp_query;

	// global $post を直接読む拡張が書類を出力しないよう空にする
	$GLOBALS['post'] = null;
}

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
 * Application Passwords などの Cookie 以外の認証方式も、determine_current_user の時点で
 * ユーザーが確定するため影響を受けない。
 *
 * @param mixed $result 先行するフィルターが返した認証結果。WP_Error / true / 未判定の null。
 * @return mixed 未ログインの場合は 401 の WP_Error。それ以外は $result をそのまま返す。
 */
function bill_rest_require_login( $result ) {
	// 既に認証結果が入っている場合は、他のプラグインが入れたエラー（WP_Error）や
	// 認証済みの明示（true）を潰さないよう、そのまま返す。
	// 判定はコア（rest_cookie_check_errors() 等）と同じ ! empty() に揃える。
	// null 以外かどうかで判定すると、契約（WP_Error / true / null）から外れた false などの
	// falsy な値を返すフィルターが同居していた場合に、その値をそのまま返してこのガードが
	// 丸ごと無効化されてしまう（コア側も ! empty() 判定のため未判定として素通しし、
	// 未ログインのまま 200 でディスパッチされる）。契約外の入力でも閉じる側に倒す
	if ( ! empty( $result ) ) {
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

	// ログイン済みの場合は判定せず、後続のフィルター（コアの Cookie 認証チェック等）に委ねる。
	// ここで true を返すと rest_cookie_check_errors()（優先度100）が即 return してしまい、
	// Cookie 認証の REST リクエストに対する nonce 検証（CSRF対策）が失われるため $result を返す
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
