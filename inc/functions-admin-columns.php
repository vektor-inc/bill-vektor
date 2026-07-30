<?php
/*
---------------------------------------------
  管理画面の書類一覧に取引先カラムを追加する
---------------------------------------------
*/

/**
 * 取引先カラムのカラムキーを取得する
 *
 * 「取引先（イレギュラー）」「取引先（登録済）」のどちらから取得した名前も
 * 表示するため、カスタムフィールド名（bill_client）とは別のキーにしている。
 *
 * @return string カラムキー。
 */
function bill_get_client_column_key() {
	return 'bill_client_name';
}

/**
 * 取引先カラムを追加する書類の投稿タイプを取得する
 *
 * 請求書（post）・領収書（receipt）など他の書類種別にも表示する場合は
 * この配列に投稿タイプのスラッグを追加する。
 *
 * @return string[] 投稿タイプのスラッグの配列。
 */
function bill_get_client_column_post_types() {
	return array( 'estimate' );
}

/**
 * 取引先カラムの追加・出力用のフックを登録する
 *
 * 対象の投稿タイプごとに、カラムの追加フィルターとカラムの内容を出力する
 * アクションを登録する。
 *
 * @return void
 */
function bill_register_client_admin_column() {
	foreach ( bill_get_client_column_post_types() as $post_type ) {
		// カラム自体を追加する（投稿タイプ別のフィルター）
		add_filter( "manage_{$post_type}_posts_columns", 'bill_add_client_admin_column' );
		// 追加したカラムの中身を出力する（投稿タイプ別のアクション）
		add_action( "manage_{$post_type}_posts_custom_column", 'bill_render_client_admin_column', 10, 2 );
	}
}
add_action( 'admin_init', 'bill_register_client_admin_column' );

/**
 * 投稿一覧のカラムに取引先カラムを追加する
 *
 * どの取引先の書類かは件名と並べて確認したい情報のため、タイトル列の直後に挿入する。
 *
 * @param array $columns カラムキーをキー、カラム見出しを値とする配列。
 * @return array 取引先カラムを追加したカラムの配列。
 */
function bill_add_client_admin_column( $columns ) {
	$column_key  = bill_get_client_column_key();
	$new_columns = array();

	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;

		// タイトル列の直後に取引先列を挿入する
		if ( 'title' === $key ) {
			$new_columns[ $column_key ] = __( '取引先', 'bill-vektor' );
		}
	}

	// タイトル列が無効化されている場合は末尾に追加する
	if ( ! isset( $new_columns[ $column_key ] ) ) {
		$new_columns[ $column_key ] = __( '取引先', 'bill-vektor' );
	}

	return $new_columns;
}

/**
 * 取引先カラムの内容を出力する
 *
 * 取引先名の取得は bill_get_client_name_by_post() に委譲するため、
 * 書類本体・PDFタイトル・CSVエクスポートと同じ取引先名が表示される。
 * 取引先へのリンクは不要なため、名前のみをエスケープして出力する。
 *
 * @param string $column_name 出力対象のカラムキー。
 * @param int    $post_id     行の書類の投稿ID。
 * @return void
 */
function bill_render_client_admin_column( $column_name, $post_id ) {
	// 取引先カラム以外は何もしない
	if ( bill_get_client_column_key() !== $column_name ) {
		return;
	}

	$client_name = bill_get_client_name_by_post( $post_id );

	/*
	 * 表示できる取引先名が無い場合は、WordPress 管理画面の慣習に合わせて
	 * ダッシュと読み上げ用の代替テキストを表示する。
	 * 取引先（登録済）を選択していても取引先の投稿タイトルが空であれば
	 * ここに入るため、「未設定」ではなく「なし」という表現にしている。
	 */
	if ( '' === $client_name ) {
		echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( '取引先なし', 'bill-vektor' ) . '</span>';
		return;
	}

	echo esc_html( $client_name );
}
