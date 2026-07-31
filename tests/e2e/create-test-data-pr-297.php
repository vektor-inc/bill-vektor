<?php
/**
 * PR #297 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法（テーマディレクトリで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/${PWD##*/}" wp eval-file tests/e2e/create-test-data-pr-297.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 *
 * tests/e2e/pr-297-estimate-client-column.spec.js が参照する見積書と取引先を作成する。
 *
 * 作成する取引先（client 投稿）:
 * 1. 株式会社ベクトル       … 「取引先（登録済）」で選択する通常の取引先
 * 2. （タイトルなし）       … 無題で保存された取引先。取引先を選択済でも表示できる
 *                             名前が無いため、一覧では「—」になることの確認用
 *
 * 作成する見積書（estimate 投稿）:
 * 1. Webサイト制作見積（登録済取引先）      … 登録済の取引先を選択 → 取引先の投稿タイトル
 * 2. 単発ロゴ制作見積（イレギュラー）        … 手入力のみ → 手入力の値
 * 3. 両方入力した見積                      … 手入力＋登録済 → 手入力を優先
 * 4. 新規オフィス移転に伴う〜お見積          … 日本語42文字の長い取引先名（折り返し確認用）
 * 5. 英字取引先テスト                      … 英字1語の長い取引先名（はみ出し確認用）
 * 6. 取引先未設定の見積                    … どちらも未設定 → 「—」
 * 7. 名称未設定の取引先を選んだ見積          … 無題の取引先を選択 → 「—」
 *
 * spec は投稿IDではなく見積書のタイトルで対象行を特定し、同じタイトルの行が
 * 1件であることを前提にしている。そのため同じタイトルの見積書が既にある環境では
 * 作成せずスキップし、二重作成でテストが壊れないようにしている。
 * ゴミ箱にある投稿も既存として扱う。復元された時に同じタイトルが2件になり、
 * 原因の分かりにくいテスト失敗になるのを避けるため。
 */

/**
 * 指定したタイトルの投稿IDを取得する
 *
 * @param string $title     投稿タイトル。
 * @param string $post_type 投稿タイプ。
 * @return int 見つかった投稿ID。無ければ 0。
 */
function bill_e2e_pr297_find_post_by_title( $title, $post_type ) {
	$post_ids = get_posts(
		array(
			'post_type'        => $post_type,
			'title'            => $title,
			// 'any' はゴミ箱を含まないため trash を明示して重複作成を防ぐ
			'post_status'      => array( 'any', 'trash' ),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	return $post_ids ? (int) $post_ids[0] : 0;
}

/**
 * 登録済取引先「株式会社ベクトル」を取得または作成する
 *
 * @return int 取引先の投稿ID。
 */
function bill_e2e_pr297_get_named_client() {
	static $client_id = null;

	// 1回目の呼び出しでのみ検索・作成する
	if ( null !== $client_id ) {
		return $client_id;
	}

	$title     = '株式会社ベクトル';
	$client_id = bill_e2e_pr297_find_post_by_title( $title, 'client' );

	if ( $client_id ) {
		echo "Skipped client (already exists): {$title} (ID: {$client_id})\n";
		return $client_id;
	}

	$client_id = wp_insert_post(
		array(
			'post_title'  => $title,
			'post_type'   => 'client',
			'post_status' => 'publish',
		)
	);

	// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
	if ( is_wp_error( $client_id ) ) {
		WP_CLI::error( '取引先の作成に失敗しました（named_client）: ' . $client_id->get_error_message() );
	}

	echo "Created client: {$title} (ID: {$client_id})\n";

	return $client_id;
}

/**
 * 無題で保存された登録済取引先を取得または作成する
 *
 * タイトルが空のため、タイトルでは検索できない。このスクリプトが作成したものだと
 * 判別できるようカスタムフィールドを目印として付け、それを使って再利用する。
 *
 * @return int 取引先の投稿ID。
 */
function bill_e2e_pr297_get_untitled_client() {
	static $client_id = null;

	// 1回目の呼び出しでのみ検索・作成する
	if ( null !== $client_id ) {
		return $client_id;
	}

	$marker_key = 'bill_e2e_pr297_untitled_client';

	$post_ids = get_posts(
		array(
			'post_type'        => 'client',
			// 'any' はゴミ箱を含まないため trash を明示して重複作成を防ぐ
			'post_status'      => array( 'any', 'trash' ),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_key'         => $marker_key,
			'meta_value'       => '1',
		)
	);

	if ( $post_ids ) {
		$client_id = (int) $post_ids[0];
		echo "Skipped client (already exists): （タイトルなし） (ID: {$client_id})\n";
		return $client_id;
	}

	$client_id = wp_insert_post(
		array(
			'post_title'  => '',
			'post_type'   => 'client',
			'post_status' => 'publish',
		)
	);

	// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
	if ( is_wp_error( $client_id ) ) {
		WP_CLI::error( '取引先の作成に失敗しました（untitled_client）: ' . $client_id->get_error_message() );
	}

	// タイトルが空でも再実行時に見つけられるよう目印を付ける
	add_post_meta( $client_id, $marker_key, '1' );

	echo "Created client: （タイトルなし） (ID: {$client_id})\n";

	return $client_id;
}

/*
 * 作成する見積書の定義。
 * client_ref は登録済取引先の参照方法で、named なら「株式会社ベクトル」、
 * untitled なら無題の取引先を bill_client にセットする。
 * 取引先を選択しない見積書では空文字にする。
 */
$estimates = array(
	array(
		'title'      => 'Webサイト制作見積（登録済取引先）',
		'manual'     => '',
		'client_ref' => 'named',
	),
	array(
		'title'      => '単発ロゴ制作見積（イレギュラー）',
		'manual'     => '個人事業主 山田太郎',
		'client_ref' => '',
	),
	array(
		'title'      => '両方入力した見積',
		'manual'     => '手入力を優先する取引先',
		'client_ref' => 'named',
	),
	array(
		'title'      => '新規オフィス移転に伴う社内ネットワーク再構築およびセキュリティ強化対応一式のお見積',
		'manual'     => '株式会社グローバルテクノロジーソリューションズジャパンホールディングス東日本第二営業部',
		'client_ref' => '',
	),
	array(
		'title'      => '英字取引先テスト',
		'manual'     => 'InternationalBusinessMachinesCorporationJapanBranchOffice',
		'client_ref' => '',
	),
	array(
		'title'      => '取引先未設定の見積',
		'manual'     => '',
		'client_ref' => '',
	),
	array(
		'title'      => '名称未設定の取引先を選んだ見積',
		'manual'     => '',
		'client_ref' => 'untitled',
	),
);

foreach ( $estimates as $estimate ) {
	// 同じタイトルの見積書があれば作成しない（spec が同タイトル1件を前提にしているため）
	$existing_id = bill_e2e_pr297_find_post_by_title( $estimate['title'], 'estimate' );
	if ( $existing_id ) {
		echo "Skipped estimate (already exists): {$estimate['title']} (ID: {$existing_id})\n";
		continue;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'  => $estimate['title'],
			'post_type'   => 'estimate',
			'post_status' => 'publish',
		)
	);

	// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( '見積書の作成に失敗しました（' . $estimate['title'] . '）: ' . $post_id->get_error_message() );
	}

	// 取引先（イレギュラー）の手入力値
	if ( '' !== $estimate['manual'] ) {
		add_post_meta( $post_id, 'bill_client_name_manual', $estimate['manual'] );
	}

	// 取引先（登録済）の選択値。取引先の投稿は必要になった時にだけ作成する
	if ( 'named' === $estimate['client_ref'] ) {
		add_post_meta( $post_id, 'bill_client', bill_e2e_pr297_get_named_client() );
	} elseif ( 'untitled' === $estimate['client_ref'] ) {
		add_post_meta( $post_id, 'bill_client', bill_e2e_pr297_get_untitled_client() );
	}

	echo "Created estimate: {$estimate['title']} (ID: {$post_id})\n";
	echo "URL: " . get_permalink( $post_id ) . "\n";
}
