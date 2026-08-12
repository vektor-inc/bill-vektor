<?php
/**
 * PR #311 e2e テスト用データ作成スクリプト。
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 * npx wp-env run cli wp eval-file "wp-content/themes/$(basename "$PWD")/tests/e2e/create-test-data-pr-311.php"
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * $(basename "$PWD") でカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * すべての投稿に専用メタを付け、cleanup-test-data-pr-311.php で個別削除できるようにする。
 *
 * issue #322: 投稿日時を指定しないと全件が同一日時（秒単位）で作成され、
 * 一覧の並び順が不安定になる。MySQL は ORDER BY の対象値が同じ行の順序を
 * 保証しないため、ページ送りのたびに同じ投稿が重複して現れたり、
 * どのページにも現れなかったりする（create-test-data-pr-314.php で先に
 * 対応済みの問題と同じ）。作成順に1分ずつ古くなる投稿日時を明示して防ぐ。
 */

define( 'BILL_E2E_PR311_MARKER_KEY', 'bill_e2e_pr311' );
$GLOBALS['bill_e2e_pr311_created_post_ids'] = array();

// 作成順に1分ずつ古い投稿日時を割り当てて、一覧の並び順を安定させる（issue #322）。
$GLOBALS['bill_e2e_pr311_base_time']   = time();
$GLOBALS['bill_e2e_pr311_date_offset'] = 0;

/**
 * PR #311 用の投稿を作成する。
 *
 * @param string $post_type 投稿タイプ。
 * @param string $title     投稿タイトル。
 * @param array  $meta      保存する投稿メタ。
 * @param string $post_name 投稿スラッグ（空の場合は WordPress の既定に任せる）。
 * @return int 投稿 ID。
 */
function bill_e2e_pr311_create_post( $post_type, $title, $meta = array(), $post_name = '' ) {
	// 作成順に1分ずつ古い投稿日時を割り当てて、一覧の並び順を安定させる（issue #322）。
	$timestamp = $GLOBALS['bill_e2e_pr311_base_time'] - ( $GLOBALS['bill_e2e_pr311_date_offset'] * MINUTE_IN_SECONDS );
	++$GLOBALS['bill_e2e_pr311_date_offset'];

	$postarr = array(
		'post_title'    => $title,
		'post_type'     => $post_type,
		'post_status'   => 'publish',
		'post_date'     => gmdate( 'Y-m-d H:i:s', $timestamp ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
		'meta_input'    => $meta,
	);

	// 無題の取引先はスラッグが決まらずリンクを特定できないため、明示的に指定できるようにする（issue #322）。
	if ( '' !== $post_name ) {
		$postarr['post_name'] = $post_name;
	}

	$post_id = wp_insert_post( $postarr, true );

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}

	// テーマ側の保存処理に影響されないよう、作成後に専用マーカーを明示して付ける。
	update_post_meta( $post_id, BILL_E2E_PR311_MARKER_KEY, '1' );
	$GLOBALS['bill_e2e_pr311_created_post_ids'][] = (int) $post_id;

	echo "Created {$post_type}: {$title} (ID: {$post_id})\n";
	return (int) $post_id;
}

// 前回中断時のデータがあれば、重複作成を避けるため先に個別削除する。
$existing_ids = get_posts(
	array(
		'post_type'      => array( 'estimate', 'client' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => BILL_E2E_PR311_MARKER_KEY,
		'meta_value'     => '1',
	)
);
foreach ( $existing_ids as $existing_id ) {
	wp_delete_post( $existing_id, true );
}

// 登録済み取引先。フロント一覧では省略名が優先されることも確認する。
$named_client_id = bill_e2e_pr311_create_post(
	'client',
	'PR311 株式会社テスト取引先',
	array( 'client_short_name' => 'PR311 テスト社' )
);

// 取引先一覧の空アンカー回帰を確認するため、無題の取引先も作成する。
// タイトルが空の取引先は他スペック（pr-297 / pr-314 / pr-326 等）も作成するため、
// 読み上げテキストによる全体件数の検証は他スペックのデータと衝突する（issue #322）。
// スラッグを固定し、spec 側からこの取引先だけを href で一意に特定できるようにする。
$untitled_client_id = bill_e2e_pr311_create_post( 'client', '', array(), 'pr311-untitled-client' );

// 削除済み ID が残るケース用に一度取引先を作成し、その ID だけ控えて削除する。
$deleted_client_id = bill_e2e_pr311_create_post( 'client', 'PR311 削除予定の取引先' );
wp_delete_post( $deleted_client_id, true );

bill_e2e_pr311_create_post( 'estimate', 'PR311 未設定（メタなし）の見積' );
bill_e2e_pr311_create_post(
	'estimate',
	'PR311 未設定（空文字）の見積',
	array(
		'bill_client_name_manual' => '',
		'bill_client'             => '',
	)
);
bill_e2e_pr311_create_post(
	'estimate',
	'PR311 登録済み取引先の見積',
	array(
		'bill_client_name_manual' => '',
		'bill_client'             => $named_client_id,
	)
);
bill_e2e_pr311_create_post(
	'estimate',
	'PR311 手入力取引先の見積',
	array(
		'bill_client_name_manual' => 'PR311 手入力の取引先',
		'bill_client'             => '',
	)
);
bill_e2e_pr311_create_post(
	'estimate',
	'PR311 配列値の見積',
	array(
		'bill_client_name_manual' => '',
		'bill_client'             => array( 1 ),
	)
);
bill_e2e_pr311_create_post(
	'estimate',
	'PR311 削除済み取引先の見積',
	array(
		'bill_client_name_manual' => '',
		'bill_client'             => $deleted_client_id,
	)
);

echo "Named client ID: {$named_client_id}\n";
echo "Untitled client ID: {$untitled_client_id}\n";
echo "Deleted client ID: {$deleted_client_id}\n";

// 後片付けでは、この実行で作成した ID だけを個別削除する。
update_option( 'bill_e2e_pr311_post_ids', $GLOBALS['bill_e2e_pr311_created_post_ids'], false );
