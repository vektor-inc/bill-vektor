<?php
/**
 * issue #296 動作確認用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-296.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 *
 * 請求書一覧（投稿タイプ post）に追加した取引先カラムの表示確認用データを作成する。
 * 請求書一覧は見積書一覧より列が多く（作成者・カテゴリー・タグ・コメント）、
 * 取引先列の幅（26%）が破綻しないかを確認するため、カテゴリーとタグも付与する。
 *
 * 作成する取引先（client 投稿）:
 * 1. 株式会社ベクトル       … 「取引先（登録済）」で選択する通常の取引先
 * 2. （タイトルなし）       … 無題で保存された取引先。取引先を選択済でも表示できる
 *                             名前が無いため、一覧では「—」になることの確認用
 *
 * 作成する請求書（post 投稿）:
 * 1. Webサイト制作請求（登録済取引先）      … 登録済の取引先を選択 → 取引先の投稿タイトル
 * 2. 単発ロゴ制作請求（イレギュラー）        … 手入力のみ → 手入力の値
 * 3. 両方入力した請求書                    … 手入力＋登録済 → 手入力を優先
 * 4. 新規オフィス移転に伴う〜のご請求        … 日本語42文字の長い取引先名（折り返し確認用）
 * 5. 英字取引先テスト（請求書）              … 英字1語の長い取引先名（はみ出し確認用）
 * 6. 取引先未設定の請求書                  … どちらも未設定 → 「—」
 * 7. 名称未設定の取引先を選んだ請求書        … 無題の取引先を選択 → 「—」
 *
 * 同じタイトルの請求書が既にある環境では作成せずスキップし、二重作成にならないようにしている。
 * ゴミ箱にある投稿も既存として扱う。復元された時に同じタイトルが2件になり、
 * 原因の分かりにくい確認ミスになるのを避けるため。
 */

/**
 * 指定したタイトルの投稿IDを取得する
 *
 * @param string $title     投稿タイトル。
 * @param string $post_type 投稿タイプ。
 * @return int 見つかった投稿ID。無ければ 0。
 */
function bill_e2e_296_find_post_by_title( $title, $post_type ) {
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
function bill_e2e_296_get_named_client() {
	static $client_id = null;

	// 1回目の呼び出しでのみ検索・作成する
	if ( null !== $client_id ) {
		return $client_id;
	}

	$title     = '株式会社ベクトル';
	$client_id = bill_e2e_296_find_post_by_title( $title, 'client' );

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
function bill_e2e_296_get_untitled_client() {
	static $client_id = null;

	// 1回目の呼び出しでのみ検索・作成する
	if ( null !== $client_id ) {
		return $client_id;
	}

	$marker_key = 'bill_e2e_296_untitled_client';

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
 * 作成する請求書の定義。
 * client_ref は登録済取引先の参照方法で、named なら「株式会社ベクトル」、
 * untitled なら無題の取引先を bill_client にセットする。
 * 取引先を選択しない請求書では空文字にする。
 */
$invoices = array(
	array(
		'title'      => 'Webサイト制作請求（登録済取引先）',
		'manual'     => '',
		'client_ref' => 'named',
	),
	array(
		'title'      => '単発ロゴ制作請求（イレギュラー）',
		'manual'     => '個人事業主 山田太郎',
		'client_ref' => '',
	),
	array(
		'title'      => '両方入力した請求書',
		'manual'     => '手入力を優先する取引先',
		'client_ref' => 'named',
	),
	array(
		'title'      => '新規オフィス移転に伴う社内ネットワーク再構築およびセキュリティ強化対応一式のご請求',
		'manual'     => '株式会社グローバルテクノロジーソリューションズジャパンホールディングス東日本第二営業部',
		'client_ref' => '',
	),
	array(
		'title'      => '英字取引先テスト（請求書）',
		'manual'     => 'InternationalBusinessMachinesCorporationJapanBranchOffice',
		'client_ref' => '',
	),
	array(
		'title'      => '取引先未設定の請求書',
		'manual'     => '',
		'client_ref' => '',
	),
	array(
		'title'      => '名称未設定の取引先を選んだ請求書',
		'manual'     => '',
		'client_ref' => 'untitled',
	),
);

foreach ( $invoices as $invoice ) {
	// 同じタイトルの請求書があれば作成しない
	$existing_id = bill_e2e_296_find_post_by_title( $invoice['title'], 'post' );
	if ( $existing_id ) {
		echo "Skipped invoice (already exists): {$invoice['title']} (ID: {$existing_id})\n";
		continue;
	}

	/*
	 * 請求書一覧にはカテゴリー・タグの列があり、その列に文字が入っているかどうかで
	 * 各列の幅の見え方が変わる。実際の運用に近い状態で幅を確認するため、
	 * すべての請求書に同じカテゴリーとタグを付与する。
	 */
	$post_id = wp_insert_post(
		array(
			'post_title'    => $invoice['title'],
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_category' => array( 1 ),
			'tags_input'    => array( '制作費', '保守費' ),
		)
	);

	// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( '請求書の作成に失敗しました（' . $invoice['title'] . '）: ' . $post_id->get_error_message() );
	}

	// 取引先（イレギュラー）の手入力値
	if ( '' !== $invoice['manual'] ) {
		add_post_meta( $post_id, 'bill_client_name_manual', $invoice['manual'] );
	}

	// 取引先（登録済）の選択値。取引先の投稿は必要になった時にだけ作成する
	if ( 'named' === $invoice['client_ref'] ) {
		add_post_meta( $post_id, 'bill_client', bill_e2e_296_get_named_client() );
	} elseif ( 'untitled' === $invoice['client_ref'] ) {
		add_post_meta( $post_id, 'bill_client', bill_e2e_296_get_untitled_client() );
	}

	echo "Created invoice: {$invoice['title']} (ID: {$post_id})\n";
	echo 'URL: ' . get_permalink( $post_id ) . "\n";
}
