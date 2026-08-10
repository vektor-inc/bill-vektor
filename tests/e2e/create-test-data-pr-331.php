<?php
/**
 * PR #331 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-331.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める（他の create-test-data-*.php と同じ方式）。
 *
 * tests/e2e/pr-331-post-type-array-warning.spec.js が参照するデータを作成する。
 * issue #318 / PR #331 は「絞り込みの post_type・bill_client・start_date・end_date に
 * 配列を渡すと PHP の警告が出る」不具合の修正であり、この関数
 * （bill_custom_home_post_type() / inc/functions-pre-get-posts.php）は
 * ログイン前にも実行される経路のため、警告確認自体は既存のどのデータでも可能。
 * ただし「投稿タイプ・取引先・発行日の絞り込みが従来どおり効くこと」（デグレ確認）を
 * 他PRのテストデータに依存せず検証できるよう、この PR 専用の書類・取引先を用意する。
 *
 * 作成するデータ:
 * - 取引先 1件（PR331テスト取引先） … 絞り込みプルダウンに表示させるため client_hidden は設定しない
 * - 請求書（post） 2件 … 発行日絞り込み・取引先絞り込み・投稿タイプ絞り込みの検証用
 * - 見積書（estimate） 1件 … 投稿タイプ絞り込み（見積書のみ表示）の検証用
 *
 * 同じ件名の投稿があれば再利用し、二重作成を防ぐ（他の create-test-data-*.php と同じ方針）。
 * 件名は他PRのキーワード検索テスト（create-test-data-298.php の $conflict_keywords）と
 * 衝突しないよう「PR331」を含む固有の件名にしている。
 */

/**
 * 同じ件名・書類種別の投稿があれば再利用し、無ければ作成する
 *
 * @param string $title     件名。
 * @param string $post_type 書類種別（post / estimate / client）。
 * @param string $date      発行日（Y-m-d H:i:s）。空文字の場合は現在日時。
 * @return int 作成または再利用した投稿の ID。
 */
function bill_e2e_331_create_post( $title, $post_type, $date = '' ) {
	// 既に同じ件名の書類があれば再利用する（ゴミ箱にある投稿も既存として扱う）
	$existing = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => array( 'any', 'trash' ),
			'posts_per_page' => 1,
			'title'          => $title,
			'fields'         => 'ids',
		)
	);
	if ( $existing ) {
		echo 'Skipped (already exists): ' . $title . ' / ID: ' . $existing[0] . "\n";
		return (int) $existing[0];
	}

	$postarr = array(
		'post_title'  => $title,
		'post_type'   => $post_type,
		'post_status' => 'publish',
	);
	if ( $date ) {
		$postarr['post_date'] = $date;
	}

	$post_id = wp_insert_post( $postarr );

	// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( '投稿の作成に失敗しました（' . $title . '）: ' . $post_id->get_error_message() );
	}

	echo 'Created: ' . $title . ' / ID: ' . $post_id . ' / URL: ' . get_permalink( $post_id ) . "\n";

	return $post_id;
}

/*
  取引先
/*-------------------------------------------*/
$client_id = bill_e2e_331_create_post( 'PR331テスト取引先', 'client' );

/*
  請求書（post）
/*-------------------------------------------*/
// 発行日の絞り込み（2024-05-01〜2024-05-31）で2件ともヒットし、
// 取引先絞り込みでは「PR331契約書A」だけがヒットするようにする。
$invoice_a_id = bill_e2e_331_create_post( 'PR331契約書A', 'post', '2024-05-01 10:00:00' );
update_post_meta( $invoice_a_id, 'bill_client', $client_id );

$invoice_b_id = bill_e2e_331_create_post( 'PR331契約書B', 'post', '2024-06-01 10:00:00' );
// 取引先は設定しない（取引先絞り込みで除外されることの確認用）

/*
  見積書（estimate）
/*-------------------------------------------*/
// 投稿タイプ「見積書」だけに絞り込んだ際にこの1件だけが表示されることの確認用。
// 発行日は請求書2件のどちらとも被らない日付にして、期間絞り込みのテストでも
// 意図した件数だけがヒットするようにする。
$estimate_id = bill_e2e_331_create_post( 'PR331見積書A', 'estimate', '2024-05-15 10:00:00' );
update_post_meta( $estimate_id, 'bill_client', $client_id );

echo "\nDone.\n";
echo "CLIENT_ID={$client_id}\n";
