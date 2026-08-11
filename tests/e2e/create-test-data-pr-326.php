<?php
/**
 * PR #326 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-326.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 *
 * tests/e2e/pr-326-invoice-client-column.spec.js が参照するデータを作成する。
 * 作るものは2種類ある。
 *
 * 1. 表示パターンの請求書（取引先カラムの表示と列幅の確認用）
 * 2. 不正値の書類（無関係な投稿のタイトルが漏れないことの確認用）
 *
 * spec は投稿IDではなく書類のタイトルで対象行を特定し、同じタイトルの行が
 * 1件であることを前提にしている。そのため同じタイトルの書類が既にある環境では
 * 作成せずスキップし、二重作成でテストが壊れないようにしている。
 * ゴミ箱にある投稿も既存として扱う。復元された時に同じタイトルが2件になり、
 * 原因の分かりにくいテスト失敗になるのを避けるため。
 *
 * 件名は tests/e2e/create-test-data-298.php の $conflict_keywords（数字・サイト・
 * 制作費・年度・更新）を避けている。同じDBで動く pr-298-keyword-search.spec.js が
 * 絞り込み結果の完全一致で検証しており、件名が引っかかると落ちるため。
 */

/**
 * 指定したタイトルの投稿IDを取得する
 *
 * @param string $title     投稿タイトル。
 * @param string $post_type 投稿タイプ。
 * @return int 見つかった投稿ID。無ければ 0。
 */
function bill_e2e_326_find_post_by_title( $title, $post_type ) {
	$post_ids = get_posts(
		array(
			'post_type'      => $post_type,
			'title'          => $title,
			// 探したい状態を明示する。
			// 'any' は「すべての状態」ではなく、exclude_from_search が true の状態
			// （コアでは trash と auto-draft の2つ）を除くという指定。
			// draft・pending・private・future は 'any' でも拾えるが、trash は拾えない。
			// ゴミ箱に残った投稿を見落として重複作成しないよう、状態を並べて明示している
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// suppress_filters は既定の true のままにする。
			// false にすると他プラグインの posts_where 等でフィクスチャ投稿が
			// 検索結果から隠され、重複作成してしまう恐れがあるため
		)
	);

	return $post_ids ? (int) $post_ids[0] : 0;
}

/*
 * 旧い件名で作成済みの請求書を削除する。
 * 「Webサイト制作請求（登録済取引先）」は create-test-data-298.php が
 * キーワード「サイト」の検証に使う件名と衝突し、
 * pr-298-keyword-search.spec.js を落とすため件名を変更した。
 * このブランチを先に取り込んだ環境には旧い件名の請求書が残っており、
 * 残したままでは件名を変えた意味が無くなるのでここで消す。
 */
$obsolete_title   = 'Webサイト制作請求（登録済取引先）';
$obsolete_post_id = bill_e2e_326_find_post_by_title( $obsolete_title, 'post' );
if ( $obsolete_post_id ) {
	wp_delete_post( $obsolete_post_id, true );
	echo "Deleted obsolete invoice: {$obsolete_title} (ID: {$obsolete_post_id})\n";
}

/**
 * 登録済取引先「株式会社ベクトル」を取得または作成する
 *
 * @return int 取引先の投稿ID。
 */
function bill_e2e_326_get_named_client() {
	static $client_id = null;

	// 1回目の呼び出しでのみ検索・作成する
	if ( null !== $client_id ) {
		return $client_id;
	}

	$title     = '株式会社ベクトル';
	$client_id = bill_e2e_326_find_post_by_title( $title, 'client' );

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
function bill_e2e_326_get_untitled_client() {
	static $client_id = null;

	// 1回目の呼び出しでのみ検索・作成する
	if ( null !== $client_id ) {
		return $client_id;
	}

	$marker_key = 'bill_e2e_326_untitled_client';

	$post_ids = get_posts(
		array(
			'post_type'      => 'client',
			// 探したい状態を明示する。
			// 'any' は「すべての状態」ではなく、exclude_from_search が true の状態
			// （コアでは trash と auto-draft の2つ）を除くという指定。
			// draft・pending・private・future は 'any' でも拾えるが、trash は拾えない。
			// ゴミ箱に残った投稿を見落として重複作成しないよう、状態を並べて明示している
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => $marker_key,
			'meta_value'     => '1',
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

/**
 * 請求書に付与するカテゴリーのターム ID を取得または作成する
 *
 * カテゴリーはターム ID の決め打ち（例: array( 1 )）を避け、名前から解決する。
 * そのIDのカテゴリーが無い環境では黙って無視され、カテゴリー列が空のまま
 * 幅を確認することになって前提が崩れるため。
 *
 * @param string $name カテゴリー名。
 * @return int カテゴリーのターム ID。
 */
function bill_e2e_326_get_category_id( $name ) {
	$category = term_exists( $name, 'category' );

	// 無ければ作成する（環境に依存せず必ずカテゴリーが付くようにするため）
	if ( ! $category ) {
		$category = wp_insert_term( $name, 'category' );
	}

	// wp_insert_term() は失敗時に WP_Error を返すため、作成に失敗していないか確認する
	if ( is_wp_error( $category ) ) {
		/*
		 * 名前は違うがスラッグが衝突する場合も term_exists エラーになる。
		 * このとき get_error_data() に既存のターム ID が入るため、
		 * 再実行が止まらないよう既存のIDを再利用する。
		 */
		if ( 'term_exists' === $category->get_error_code() && $category->get_error_data() ) {
			return (int) $category->get_error_data();
		}
		WP_CLI::error( 'カテゴリーの作成に失敗しました（' . $name . '）: ' . $category->get_error_message() );
	}

	return (int) $category['term_id'];
}

/*
 * ここから 1. 表示パターンの請求書。
 *
 * 請求書一覧（投稿タイプ post）に追加した取引先カラムの表示確認用データ。
 * 請求書一覧は見積書一覧より列が多く（作成者・カテゴリー・タグ・コメント）、
 * 取引先列の幅が破綻しないかを確認するため、カテゴリーとタグも付与する。
 *
 * client_ref は登録済取引先の参照方法で、named なら「株式会社ベクトル」、
 * untitled なら無題の取引先を bill_client にセットする。
 * 取引先を選択しない請求書では空文字にする。
 */
$invoices = array(
	array(
		// 登録済の取引先を選択 → 取引先の投稿タイトル
		'title'      => 'Web制作請求（登録済取引先）',
		'manual'     => '',
		'client_ref' => 'named',
	),
	array(
		// 手入力のみ → 手入力の値
		'title'      => '単発ロゴ制作請求（イレギュラー）',
		'manual'     => '個人事業主 山田太郎',
		'client_ref' => '',
	),
	array(
		// 手入力＋登録済 → 手入力を優先
		'title'      => '両方入力した請求書',
		'manual'     => '手入力を優先する取引先',
		'client_ref' => 'named',
	),
	array(
		// 日本語42文字の長い取引先名（折り返し確認用）
		'title'      => '新規オフィス移転に伴う社内ネットワーク再構築およびセキュリティ強化対応一式のご請求',
		'manual'     => '株式会社グローバルテクノロジーソリューションズジャパンホールディングス東日本第二営業部',
		'client_ref' => '',
	),
	array(
		// 英字1語の長い取引先名（はみ出し確認用）
		'title'      => '英字取引先テスト（請求書）',
		'manual'     => 'InternationalBusinessMachinesCorporationJapanBranchOffice',
		'client_ref' => '',
	),
	array(
		// どちらも未設定 → 「—」
		'title'      => '取引先未設定の請求書',
		'manual'     => '',
		'client_ref' => '',
	),
	array(
		// 無題の取引先を選択 → 「—」
		'title'      => '名称未設定の取引先を選んだ請求書',
		'manual'     => '',
		'client_ref' => 'untitled',
	),
);

foreach ( $invoices as $invoice ) {
	// 同じタイトルの請求書があれば作成しない
	$existing_id = bill_e2e_326_find_post_by_title( $invoice['title'], 'post' );
	if ( $existing_id ) {
		echo "Skipped invoice (already exists): {$invoice['title']} (ID: {$existing_id})\n";
		continue;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'  => $invoice['title'],
			'post_type'   => 'post',
			'post_status' => 'publish',
			'tags_input'  => array( '制作', '保守' ),
		)
	);

	// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( '請求書の作成に失敗しました（' . $invoice['title'] . '）: ' . $post_id->get_error_message() );
	}

	/*
	 * カテゴリーは名前から解決したターム ID で付与する。
	 * wp_set_post_terms() は階層化タクソノミー（category）に渡した値を intval() で
	 * IDに丸めるため、名前の文字列を渡しても 0 になって何も付与されない。
	 */
	$category_result = wp_set_post_terms( $post_id, array( bill_e2e_326_get_category_id( '請求書テスト' ) ), 'category' );

	// wp_set_post_terms() は失敗時に WP_Error を返すため、付与できたか確認する
	if ( is_wp_error( $category_result ) ) {
		WP_CLI::error( 'カテゴリーの付与に失敗しました（' . $invoice['title'] . '）: ' . $category_result->get_error_message() );
	}

	// 取引先（イレギュラー）の手入力値
	if ( '' !== $invoice['manual'] ) {
		add_post_meta( $post_id, 'bill_client_name_manual', $invoice['manual'] );
	}

	// 取引先（登録済）の選択値。取引先の投稿は必要になった時にだけ作成する
	if ( 'named' === $invoice['client_ref'] ) {
		add_post_meta( $post_id, 'bill_client', bill_e2e_326_get_named_client() );
	} elseif ( 'untitled' === $invoice['client_ref'] ) {
		add_post_meta( $post_id, 'bill_client', bill_e2e_326_get_untitled_client() );
	}

	echo "Created invoice: {$invoice['title']} (ID: {$post_id})\n";
	echo 'URL: ' . get_permalink( $post_id ) . "\n";
}

/*
 * ここから 2. 不正値の書類。
 *
 * 非公開の固定ページを1件作り、そのIDを不正な形（-ID / IDabc / ID そのもの）で
 * 請求書・見積書の取引先（登録済）カスタムフィールドに保存する。
 * 修正前はこの非公開ページのタイトルが取引先名として表示されていた。
 */

// 取引先名として漏れてはいけない非公開ページ
$secret_title = 'PR326 機密の非公開ページ';
$page_id      = bill_e2e_326_find_post_by_title( $secret_title, 'page' );

if ( ! $page_id ) {
	$page_id = wp_insert_post(
		array(
			'post_title'  => $secret_title,
			'post_type'   => 'page',
			'post_status' => 'private',
		)
	);

	if ( is_wp_error( $page_id ) ) {
		WP_CLI::error( '非公開ページの作成に失敗しました: ' . $page_id->get_error_message() );
	}
}

echo "SECRET_PAGE_ID={$page_id}\n";
echo "SECRET_PAGE_TITLE={$secret_title}\n";

/*
 * bill_client に入れる不正値のパターン。
 * minus  … absint() で正の数に均されていた値
 * suffix … 数字以外を含む値
 * plain  … 正しい整数だが取引先（client）以外の投稿を指す値
 */
$fixtures = array(
	array(
		'title' => 'PR326 不正値マイナス（請求書）',
		'type'  => 'post',
		'value' => '-' . $page_id,
	),
	array(
		'title' => 'PR326 不正値数字以外（請求書）',
		'type'  => 'post',
		'value' => $page_id . 'abc',
	),
	array(
		'title' => 'PR326 不正値ページID（請求書）',
		'type'  => 'post',
		'value' => (string) $page_id,
	),
	array(
		'title' => 'PR326 不正値マイナス（見積書）',
		'type'  => 'estimate',
		'value' => '-' . $page_id,
	),
	array(
		'title' => 'PR326 不正値数字以外（見積書）',
		'type'  => 'estimate',
		'value' => $page_id . 'abc',
	),
	array(
		'title' => 'PR326 不正値ページID（見積書）',
		'type'  => 'estimate',
		'value' => (string) $page_id,
	),
);

foreach ( $fixtures as $fixture ) {
	$post_id = bill_e2e_326_find_post_by_title( $fixture['title'], $fixture['type'] );

	if ( ! $post_id ) {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $fixture['title'],
				'post_type'   => $fixture['type'],
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( '書類の作成に失敗しました（' . $fixture['title'] . '）: ' . $post_id->get_error_message() );
		}
	}

	/*
	 * 不正値のフィクスチャは spec の C2 で正しい取引先に選び直されるため、
	 * 既存の投稿でも毎回不正値に戻す（何度実行しても同じ条件で確認できるようにする）。
	 * 取引先（イレギュラー）が残っていると不正値の経路を通らないため必ず消す。
	 */
	delete_post_meta( $post_id, 'bill_client_name_manual' );
	update_post_meta( $post_id, 'bill_client', $fixture['value'] );

	echo "{$fixture['title']}: ID={$post_id} bill_client=" . get_post_meta( $post_id, 'bill_client', true ) . "\n";
}

echo "\nDone.\n";
