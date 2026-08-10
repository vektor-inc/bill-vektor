<?php
function bill_custom_home_post_type( $query ) {
	if ( ! is_admin() && ! is_singular() && $query->is_main_query() ) {
		$client_id = ( isset( $_GET['bill_client'] ) && $_GET['bill_client'] ) ? esc_attr( $_GET['bill_client'] ) : '';

		global $wp_query;
		// post_type[]=xxx のように配列で渡された場合、esc_attr() は文字列前提のため
		// 配列を渡すと PHP の警告（Array to string conversion）が出る。
		// 配列での投稿タイプ指定はサポートしないため、文字列の場合のみ受け付け、
		// 配列で渡された場合は「指定なし」として扱う（else if の既定値にフォールバックする）
		if ( isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ){
			$query->set( 'post_type',  esc_attr( $_GET['post_type'] ) );
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
		$start_date = ( isset( $_GET['start_date'] ) && $_GET['start_date'] ) ? $_GET['start_date'] : '';
		$end_date   = ( isset( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'] . ' 23:59:59' : '';
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
