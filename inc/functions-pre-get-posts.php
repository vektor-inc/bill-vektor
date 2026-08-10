<?php
/**
 * 書類一覧のメインクエリに絞り込みフォームの条件を反映する
 *
 * トップページ（書類一覧）の絞り込みフォーム由来のクエリー文字列
 * （post_type・bill_client・start_date・end_date・bill_keyword）をメインクエリ
 * （WP_Query）へ反映する。
 *
 * 【重要】pre_get_posts は WP::main() の中で wp フックより前に実行されるため、
 * bill_no_login_redirect()（inc/functions-limit-view.php）の認証ゲートより先に走る。
 * つまりこの関数は未ログインの第三者にも到達できる経路であり、$_GET の値を型チェック
 * せずに文字列処理（esc_attr()・文字列連結など）へ渡すと、未認証のリクエストだけで
 * PHP の警告（Array to string conversion 等）をログに記録させられてしまう（issue #318）。
 * そのため各パラメーターは is_string() で型を確認してから使う。
 *
 * post_type は WordPress コアの public_query_vars に含まれ、この関数より前に走る
 * WP::parse_request() によって $_GET['post_type'] がそのまま query_vars に入っている。
 * トップページ（is_front_page()）以外では、この関数は post_type を上書きしないため、
 * post_type[]=xxx のような配列指定を含めて WordPress 標準の挙動がそのまま有効になる
 * （このテーマは元々アーカイブ・タクソノミー・日付アーカイブの投稿タイプを制限していない）。
 * トップページでは post_type が文字列の場合のみ受け付け、未指定または配列の場合は
 * 既定値 array( 'post', 'estimate' ) にフォールバックする。
 *
 * @param WP_Query $query pre_get_posts から渡されるメインクエリ。
 * @return void
 */
function bill_custom_home_post_type( $query ) {
	if ( ! is_admin() && ! is_singular() && $query->is_main_query() ) {
		// bill_client[]=1 のように配列で渡された場合、esc_attr() は文字列前提のため
		// 配列を渡すと PHP の警告（Array to string conversion）が出る。文字列の場合のみ
		// 受け付け、配列の場合は未指定と同じ扱い（絞り込みなし）にする
		$client_id = ( isset( $_GET['bill_client'] ) && is_string( $_GET['bill_client'] ) && $_GET['bill_client'] ) ? esc_attr( $_GET['bill_client'] ) : '';

		if ( isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ) {
			// 入力値をクエリー変数へ渡すだけなので、出力エスケープの esc_attr() ではなく
			// 入力のサニタイズ関数である sanitize_key() を使う（$_GET のスラッシュは
			// wp_unslash() で除去してから渡す）
			$query->set( 'post_type', sanitize_key( wp_unslash( $_GET['post_type'] ) ) );
		} else if ( is_front_page() ) {
			$query->set( 'post_type',  array( 'post', 'estimate' ) );
		}

		if ( $client_id ) {
			$meta_query[] = array(
				'key'   => 'bill_client',
				'value' => $client_id,
			);
			$query->set( 'meta_query', $meta_query );
		}

		/*
		  期間の絞り込み
		/*-------------------------------------------*/
		// start_date[]=xxx のように配列で渡された場合の警告を避けるため文字列の場合のみ
		// 受け付ける。end_date は下の文字列連結（. ' 23:59:59'）でも同じ警告が出るため同様
		$start_date = ( isset( $_GET['start_date'] ) && is_string( $_GET['start_date'] ) && $_GET['start_date'] ) ? $_GET['start_date'] : '';
		$end_date   = ( isset( $_GET['end_date'] ) && is_string( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'] . ' 23:59:59' : '';
		// if ( $start_date && $end_date ){
			// $start_date = $start_date.' 00:00:00';
			// $end_date = $end_date.' 23:59:59';
			$date_query = array(
				array(
					'compare' => 'BETWEEN',
					// 'inclusive'=>ture,
					'after'   => $start_date,
					'before'  => $end_date,
				),
			);
			$query->set( 'date_query', $date_query );
			// }

		/*
		  キーワードの絞り込み
		/*-------------------------------------------*/
		$bill_keyword = bill_get_search_keyword();
		// キーワードが「0」の1文字でも絞り込みが効くよう、truthy 判定ではなく空文字と比較する
		if ( '' !== $bill_keyword ) {
			// WordPress 標準のキーワード検索（s）にそのまま渡す。
			// pre_get_posts は WP_Query::parse_query() の後に実行されるため、
			// ここで s をセットしても is_search() は true にならず、
			// index.php の一覧表示の分岐（is_front_page() / is_archive() / is_tax()）は維持される。
			// WP_Query::parse_search() 内で stripslashes() されるため wp_slash() で付け直しておく。
			$query->set( 's', wp_slash( $bill_keyword ) );
			// 検索対象を書類の件名だけに限定する（本文がヒットするのを防ぐ）
			$query->set( 'search_columns', bill_get_search_columns() );
			// 並び順を発行日順に固定する。
			// WP_Query はキーワード検索時、orderby が未指定だと「フレーズ一致 → 全語一致」で
			// 優劣を付ける並べ替えを ORDER BY の先頭に入れるため、検索語が2語以上のときに
			// 発行日順が崩れる（1語のときは全件同じ値になるため実質発行日順のまま）。
			// 書類一覧は発行日の列を持つ発行日順の一覧であり、キーワードで絞り込んだ瞬間に
			// 並び順が変わるのはユーザーにとって想定外の挙動になるため、関連度順ではなく
			// 発行日順を維持する。CSV エクスポート（get_posts の既定が date）と行の順序を
			// 揃える意味もある。指定値は WordPress の既定と同じなので他への影響はない。
			$query->set( 'orderby', 'date' );
		}

			return;
	}
}
add_action( 'pre_get_posts', 'bill_custom_home_post_type' );
