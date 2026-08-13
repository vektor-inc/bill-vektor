<?php
/**
 * PR #346 e2e テスト用データ作成スクリプト。
 * wp-env run cli で実行する
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-346.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める（他の create-test-data-*.php と同じ方式）。
 *
 * tests/e2e/pr-346-csv-export-escaping.spec.js が参照するデータを作成する。
 *
 * issue #313 / PR #346 は、CSV エクスポートで書き出す値のエスケープ処理を
 * 共通処理 CsvExport::format_csv_cell() に統一した修正。PHPUnit 側は
 * format_csv_cell() 単体のテストまでしか担保できず（export_csv() は最後に
 * die() するため PHPUnit から呼べない）、出力箇所の置き換え漏れを検知できない。
 * このスクリプトが作る書類を実際に CSV エクスポートし、共通処理を通った
 * 出力になっているかを e2e 側で確認する。
 *
 * 取引先は取引先（登録済）の投稿を別途作らず、書類側の取引先（イレギュラー）欄
 * （bill_client_name_manual）に直接、検証したい文字列を入れる。
 * bill_get_client_name() は取引先（イレギュラー）を優先して返すため、
 * 取引先マスタを増やさずに任意の取引先名で CSV を出力させられる
 * （create-test-data-pr-314.php の「請求書D_イレギュラー取引先」と同じ手法）。
 *
 * 作成する書類（すべて件名に "PR346" を含め、他スペックのキーワード検索・
 * 部分一致ロケータと衝突しないようにする）:
 * - PR346請求書A_数式取引先        … 取引先名 "=1+1"（数式として実行されうる先頭文字）
 * - PR346請求書B_ダブルクォート取引先 … 取引先名 'テスト"商事'（" を含む）
 * - PR346請求書C_アンパサンド取引先  … 取引先名 "A&B商事"（& を含む）
 * - PR346請求書D_通常取引先        … 取引先名 "株式会社PR346通常取引先"（デグレ確認用）
 *
 * 品目はすべて同じ内容にして金額を "11,000" に揃え、金額欄に ' が付かないこと
 * （純粋な数値・カンマ区切りは無害化の対象外）を共通の観点として確認できるようにする。
 *
 * 同じ件名の投稿があれば再利用し、二重作成を防ぐ（他の create-test-data-*.php と同じ方針）。
 *
 * 投稿日時を指定しないと全件が同一日時（秒単位）で作成され、MySQL は ORDER BY の
 * 対象値が同じ行の順序を保証しないため、絞り込み無しの一覧をページ送りで走査する
 * テストが増えたときに順序が不安定になりうる（07321c2 / issue #322 で
 * create-test-data-pr-311.php / -pr-314.php に入れたのと同じ対応）。
 * 作成順に1分ずつ古くなる投稿日時を明示して防ぐ。
 */

// 作成順に1分ずつ古い投稿日時を割り当てて、一覧の並び順を安定させる（issue #322 と同じ対応）。
$GLOBALS['bill_e2e_pr346_base_time']   = time();
$GLOBALS['bill_e2e_pr346_date_offset'] = 0;

/**
 * 品目・支払期限のメタを組み立てる。
 *
 * CSV エクスポートは品目から税率ごとの金額を算出して行を出力するため、
 * 品目が1件も無い書類は CSV に現れない。検証用の書類には必ず品目を持たせる。
 * 全書類で同じ品目にし、金額欄が "11,000"（10,000円 + 消費税10%）に揃うようにする。
 *
 * @param string $client_name_manual 取引先（イレギュラー）欄に入れる値。
 * @return array 投稿メタの配列。
 */
function bill_e2e_pr346_doc_meta( $client_name_manual ) {
	return array(
		'bill_client_name_manual' => $client_name_manual,
		'bill_client'             => '',
		'bill_limit_date'         => gmdate( 'Ymd', strtotime( '+1 month' ) ),
		'bill_items'              => array(
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
}

/**
 * 同じ件名の書類があれば再利用し、無ければ作成する。
 *
 * @param string $title 件名。
 * @param array  $meta  保存する投稿メタ。
 * @return int 作成または再利用した投稿の ID。
 */
function bill_e2e_pr346_create_post( $title, $meta ) {
	// 探したい状態を明示する。'any' は「すべての状態」ではなく、
	// exclude_from_search が true の状態（コアでは trash と auto-draft の2つ）を
	// 除くという指定。ゴミ箱に残った投稿を見落として重複作成しないよう、
	// 状態を並べて明示している（他の create-test-data-*.php と同じ方針）。
	$existing = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page' => 1,
			'title'          => $title,
			'fields'         => 'ids',
		)
	);
	if ( $existing ) {
		echo 'Skipped (already exists): ' . $title . ' / ID: ' . $existing[0] . "\n";
		return (int) $existing[0];
	}

	// 作成順に1分ずつ古い投稿日時を割り当てて、一覧の並び順を安定させる（issue #322 と同じ対応）。
	$timestamp = $GLOBALS['bill_e2e_pr346_base_time'] - ( $GLOBALS['bill_e2e_pr346_date_offset'] * MINUTE_IN_SECONDS );
	++$GLOBALS['bill_e2e_pr346_date_offset'];

	// post_date はサイトのローカル時刻を入れる欄で、UTC のままだと wp-env の既定（UTC）
	// 以外のタイムゾーン設定（日本時間など）では実際の作成時刻より過去にずれ、
	// 日時を指定していない他スペックのデータより古く並んでしまう
	// （create-test-data-pr-311.php と同じ対応）。get_date_from_gmt() で
	// UTC → サイトのタイムゾーンへ変換してから設定する。
	$post_id = wp_insert_post(
		array(
			'post_title'    => $title,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'meta_input'    => $meta,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( '書類の作成に失敗しました（' . $title . '）: ' . $post_id->get_error_message() );
	}

	echo 'Created: ' . $title . ' / ID: ' . $post_id . "\n";

	return (int) $post_id;
}

/*
  書類
/*-------------------------------------------*/
bill_e2e_pr346_create_post( 'PR346請求書A_数式取引先', bill_e2e_pr346_doc_meta( '=1+1' ) );
bill_e2e_pr346_create_post( 'PR346請求書B_ダブルクォート取引先', bill_e2e_pr346_doc_meta( 'テスト"商事' ) );
bill_e2e_pr346_create_post( 'PR346請求書C_アンパサンド取引先', bill_e2e_pr346_doc_meta( 'A&B商事' ) );
bill_e2e_pr346_create_post( 'PR346請求書D_通常取引先', bill_e2e_pr346_doc_meta( '株式会社PR346通常取引先' ) );

echo "\nDone.\n";
