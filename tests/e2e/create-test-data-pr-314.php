<?php
/**
 * PR #314 e2e テスト用データ作成スクリプト。
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 * npx wp-env run cli wp eval-file "wp-content/themes/$(basename "$PWD")/tests/e2e/create-test-data-pr-314.php"
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * $(basename "$PWD") でカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * すべての投稿に専用メタを付け、cleanup-test-data-pr-314.php で個別削除できるようにする。
 */

define( 'BILL_E2E_PR314_MARKER_KEY', 'bill_e2e_pr314' );
$GLOBALS['bill_e2e_pr314_created_post_ids'] = array();

/*
 * 投稿日時が同一だと一覧の並び順が一定にならず、ページ送りで同じ投稿が
 * 重複したり抜け落ちたりする。作成順に1分ずつ古くなる日時を明示して並びを固定する。
 */
$GLOBALS['bill_e2e_pr314_base_time']   = time();
$GLOBALS['bill_e2e_pr314_date_offset'] = 0;

/**
 * PR #314 用の投稿を作成する。
 *
 * @param string $post_type 投稿タイプ。
 * @param string $title     投稿タイトル。
 * @param array  $meta      保存する投稿メタ。
 * @param string $post_name 投稿スラッグ（空の場合はWordPressの既定に任せる）。
 * @return int 投稿 ID。
 */
function bill_e2e_pr314_create_post( $post_type, $title, $meta = array(), $post_name = '' ) {
	// 作成順に1分ずつ古い投稿日時を割り当てて、一覧の並び順を安定させる
	$timestamp = $GLOBALS['bill_e2e_pr314_base_time'] - ( $GLOBALS['bill_e2e_pr314_date_offset'] * MINUTE_IN_SECONDS );
	++$GLOBALS['bill_e2e_pr314_date_offset'];

	// post_date はサイトのローカル時刻を入れる欄で、UTC のままだと wp-env の既定（UTC）
	// 以外のタイムゾーン設定（日本時間など）では実際の作成時刻より過去にずれ、
	// 日時を指定していない他スペックのデータより古く並んでしまう（issue #322 コードレビュー指摘）。
	// get_date_from_gmt() で UTC → サイトのタイムゾーンへ変換してから設定する。
	$postarr = array(
		'post_title'    => $title,
		'post_type'     => $post_type,
		'post_status'   => 'publish',
		'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
		'meta_input'    => $meta,
	);

	// 無題の取引先はスラッグが決まらずリンクを特定できないため、明示的に指定できるようにする。
	if ( '' !== $post_name ) {
		$postarr['post_name'] = $post_name;
	}

	$post_id = wp_insert_post( $postarr, true );

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}

	// テーマ側の保存処理に影響されないよう、作成後に専用マーカーを明示して付ける。
	update_post_meta( $post_id, BILL_E2E_PR314_MARKER_KEY, '1' );
	$GLOBALS['bill_e2e_pr314_created_post_ids'][] = (int) $post_id;

	echo "Created {$post_type}: {$title} (ID: {$post_id})\n";
	return (int) $post_id;
}

/**
 * 書類の品目・支払期限のメタを組み立てる。
 *
 * CSVエクスポートは品目から税率ごとの金額を算出して行を出力するため、
 * 品目が1件も無い書類はCSVに現れない。CSVの取引先名を検証できるよう、
 * 検証用の書類には必ず品目を持たせる。
 *
 * @param array $extra 書類ごとに追加する取引先のメタ。
 * @return array 投稿メタの配列。
 */
function bill_e2e_pr314_doc_meta( $extra = array() ) {
	$base = array(
		'bill_limit_date' => gmdate( 'Ymd', strtotime( '+1 month' ) ),
		'bill_items'      => array(
			array(
				'name'     => '作業費',
				'count'    => '1',
				'unit'     => '式',
				'price'    => '10000',
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
		),
	);

	return array_merge( $base, $extra );
}

// 前回中断時のデータがあれば、重複作成を避けるため先に個別削除する。
$existing_ids = get_posts(
	array(
		'post_type'      => array( 'post', 'client' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => BILL_E2E_PR314_MARKER_KEY,
		'meta_value'     => '1',
	)
);
foreach ( $existing_ids as $existing_id ) {
	wp_delete_post( $existing_id, true );
}

// 省略名が登録されている取引先（一覧・CSVで省略名が優先されることの確認用）。
//
// コードレビュー指摘: 取引先名を PR番号なしの一般的な社名にしていたため、
// 他スペック（例: pr-311 の「PR311 株式会社テスト取引先」）の取引先名の
// 部分文字列となり、部分一致ロケータで誤って複数行にヒットしていた。
// スペック固有のプレフィックスを付けて他スペックのデータと衝突しないようにする。
$short_name_client_id = bill_e2e_pr314_create_post(
	'client',
	'PR314 株式会社テスト取引先',
	array( 'client_short_name' => 'テスト取引先' )
);

// 取引先一覧でフルネームが表示されること（省略名にならないこと）の確認用。
bill_e2e_pr314_create_post(
	'client',
	'PR314 有限会社サンプル商会',
	array( 'client_short_name' => 'サンプル商会' )
);

// 省略名が登録されていない取引先（投稿タイトルへのフォールバック確認用）。
$no_short_name_client_id = bill_e2e_pr314_create_post( 'client', 'PR314 合同会社ショートネームなし' );

// 無題の取引先（空アンカーの回帰確認用。リンクを特定できるようスラッグを固定する）。
// コードレビュー指摘（issue #322）: 素の 'untitled-client' のままだと、他スペックが
// 同じ語を含むスラッグを使った場合にまた衝突しうる。「フィクスチャはスペック固有にする」
// という本 issue の原則に合わせ、pr-311 と同様にスペック名を含める。
bill_e2e_pr314_create_post( 'client', '', array(), 'pr314-untitled-client' );

// 取引先（登録済）＋省略名あり。
bill_e2e_pr314_create_post(
	'post',
	'請求書A取引先あり',
	bill_e2e_pr314_doc_meta(
		array(
			'bill_client_name_manual' => '',
			'bill_client'             => $short_name_client_id,
		)
	)
);

// 取引先が未設定（ダッシュ表示と、書類自身の件名が漏れないことの確認用）。
bill_e2e_pr314_create_post( 'post', '請求書B取引先なし', bill_e2e_pr314_doc_meta() );

// 取引先（登録済）＋省略名なし（投稿タイトルへのフォールバック確認用）。
bill_e2e_pr314_create_post(
	'post',
	'請求書C_省略名なし取引先',
	bill_e2e_pr314_doc_meta(
		array(
			'bill_client_name_manual' => '',
			'bill_client'             => $no_short_name_client_id,
		)
	)
);

// 取引先（イレギュラー）のみ（リンクにせずテキスト表示になることの確認用）。
bill_e2e_pr314_create_post(
	'post',
	'請求書D_イレギュラー取引先',
	bill_e2e_pr314_doc_meta(
		array(
			'bill_client_name_manual' => 'イレギュラー商店',
			'bill_client'             => '',
		)
	)
);

/*
 * ページ送りの見出し（.screen-reader-text）を検証するには一覧が複数ページに
 * 分かれている必要があるため、表示件数の設定は変えずに書類を水増しする。
 * 上で作った検証用の書類より古い日時になるので、1ページ目の並びは崩れない。
 */
for ( $i = 1; $i <= 8; $i++ ) {
	bill_e2e_pr314_create_post( 'post', sprintf( 'ページ送り確認用の請求書%02d', $i ) );
}

// 後片付けでは、この実行で作成した ID だけを個別削除する。
update_option( 'bill_e2e_pr314_post_ids', $GLOBALS['bill_e2e_pr314_created_post_ids'], false );
