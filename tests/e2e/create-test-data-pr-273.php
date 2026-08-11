<?php
/**
 * PR #273 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-273.php
 *   （$(basename "$PWD") はカレントディレクトリ名＝テーマのディレクトリ名。
 *     worktree ではディレクトリ名が bill-vektor 以外になるため決め打ちにしない）
 *
 * 作成する投稿:
 * 1. 品目テーブルに 行A / 行B / 行C の3行を持つ投稿
 *    投稿編集画面の「請求品目」メタボックスで、入力欄のクリック・タップ、
 *    行の追加・削除、ドラッグハンドルによる並び替えを確認するために使う。
 *    3行の品目名をすべて変えてあるのは、並び替えの前後で
 *    行の順番が変わったかどうかを品目名で見分けるためです。
 *
 * このスクリプトは冪等（何度実行しても結果が同じ）です。
 * 投稿は「[e2e-test] PR273 〜」の一意なタイトルで既存投稿を検索し、
 * 見つかればその投稿を再利用してメタだけを期待値どおりに上書きします。
 * 再実行のたびに同じ投稿が増えていくと、テストがどれを開けばよいか
 * 判別できなくなるためです。
 *
 * 作成・再利用した投稿IDとタイトルは tests/e2e/.test-data-273.json に書き出します。
 * 投稿IDは環境（既存の投稿数）によって変わるため、
 * tests/e2e/test-data-273.js がこのファイルを読んで編集画面のURLを組み立て、
 * tests/e2e/pr-273-flexible-table-touch.spec.js がそれを参照します。
 *
 * 書き出す形式:
 *   { "flexible_table": { "id": 12, "title": "[e2e-test] PR273 並び替え・タップ確認用" } }
 *
 * タイトルも書き出すのは、マニフェストがデータベースと紐付いていないためです。
 * wp-env clean や DB 入れ替えで投稿が消えてもマニフェストは残るため、
 * 古いIDの編集画面を開いても「フォーカスが当たる」「行数が増減する」のような
 * 別の投稿でも偶然通ってしまう検証は素通りします。
 * テスト側で「意図した投稿を開けているか」をタイトルで確認できるようにしています。
 */

// 投稿IDの書き出し先。spec 側と同じ tests/e2e/ 配下に置く
// （wp-env コンテナ内でもテーマごとマウントされるためホスト側にも反映される）
const BILL_E2E_273_MANIFEST_PATH = __DIR__ . '/.test-data-273.json';

/**
 * 指定したタイトルの投稿IDを取得する
 *
 * @param string $title 投稿タイトル。
 * @return int 見つかった投稿ID。無ければ 0。
 */
function bill_e2e_273_find_post_by_title( $title ) {
	$post_ids = get_posts(
		array(
			'post_type'      => 'post',
			// 'title' は post_title の完全一致検索（部分一致の 's' ではない）
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

/**
 * テスト用投稿を取得または作成し、品目テーブルのメタを期待値どおりに設定する
 *
 * 既存投稿があれば再利用し、下書き・ゴミ箱に落ちていた場合は公開状態に戻す。
 * メタは add ではなく update で上書きするため、再実行しても値が重複しない。
 *
 * @param string $key   マニフェストに書き出すキー。
 * @param string $title 投稿タイトル（既存投稿の検索キーを兼ねる）。
 * @param array  $items bill_items メタに設定する品目の配列。
 * @return int 作成または再利用した投稿ID。
 */
function bill_e2e_273_upsert_post( $key, $title, $items ) {
	$post_id = bill_e2e_273_find_post_by_title( $title );

	if ( $post_id ) {
		// 既存投稿を再利用する。下書き・ゴミ箱のままだと
		// テストが編集画面を開けない場合があるため公開状態に揃える
		if ( 'publish' !== get_post_status( $post_id ) ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				WP_CLI::error( '投稿の公開状態への変更に失敗しました（' . $key . '）: ' . $updated->get_error_message() );
			}
		}

		echo 'Reused post ID (' . $key . '): ' . $post_id . "\n";
	} else {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'post',
				'post_status' => 'publish',
			),
			true
		);

		// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( '投稿の作成に失敗しました（' . $key . '）: ' . $post_id->get_error_message() );
		}

		$post_id = (int) $post_id;
		echo 'Created post ID (' . $key . '): ' . $post_id . "\n";
	}

	// メタは add_post_meta ではなく update_post_meta で上書きする。
	// 再実行時に同じメタキーが複数ぶら下がり、行数が変わるのを防ぐため
	update_post_meta( $post_id, 'bill_items', $items );

	// get_edit_post_link() は編集権限が無いと null を返す。
	// wp eval-file を --user なしで実行すると権限が無い扱いになり
	// 空表示になってしまうため、権限に依存しない admin_url() で組み立てる
	echo 'Edit URL: ' . admin_url( 'post.php?post=' . $post_id . '&action=edit' ) . "\n";

	return $post_id;
}

// 作成するテスト投稿の定義。キーは spec 側が参照するマニフェストのキーと対応する
//
// 品目1行分の配列は inc/custom-field/custom-field-table.php の入力欄と対応しており、
// name（品目名）・count（数量）・unit（単位）・price（単価）・
// tax-rate（消費税率）・tax-type（税抜か税込か）を持つ。
$bill_e2e_273_fixtures = array(
	// 品目テーブルの操作確認用。行A / 行B / 行C の3行を持たせる
	'flexible_table' => array(
		'title' => '[e2e-test] PR273 並び替え・タップ確認用',
		'items' => array(
			// 1行目。並び替えのテストではこの行をドラッグして順番が変わるかを見る
			array(
				'name'     => '行A テスト品目',
				'count'    => '1',
				'unit'     => '式',
				'price'    => 10000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
			// 2行目。タップでフォーカスが当たるかの確認に使う
			array(
				'name'     => '行B テスト品目',
				'count'    => '2',
				'unit'     => '個',
				'price'    => 20000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
			// 3行目。並び替えの移動先、および数量欄のタップ確認に使う
			array(
				'name'     => '行C テスト品目',
				'count'    => '3',
				'unit'     => '人日',
				'price'    => 30000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
		),
	),
);

// 各テスト投稿を作成または再利用し、投稿IDとタイトルを集める
$bill_e2e_273_manifest = array();
foreach ( $bill_e2e_273_fixtures as $bill_e2e_273_key => $bill_e2e_273_fixture ) {
	$bill_e2e_273_manifest[ $bill_e2e_273_key ] = array(
		'id'    => bill_e2e_273_upsert_post(
			$bill_e2e_273_key,
			$bill_e2e_273_fixture['title'],
			$bill_e2e_273_fixture['items']
		),
		// テスト側が「意図した投稿を開けているか」を確認するために使う
		'title' => $bill_e2e_273_fixture['title'],
	);
}

// テスト側が読み取れるよう投稿IDとタイトルを JSON で書き出す
$bill_e2e_273_json = wp_json_encode( $bill_e2e_273_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
if ( false === $bill_e2e_273_json ) {
	WP_CLI::error( 'テストデータの JSON 変換に失敗しました。' );
}

// 書き込みに失敗したまま進むと spec 側が古いIDを読んで
// 原因の分かりにくい失敗になるため、ここで明確にエラーにする
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
if ( false === file_put_contents( BILL_E2E_273_MANIFEST_PATH, $bill_e2e_273_json . "\n" ) ) {
	WP_CLI::error( '投稿IDの書き出しに失敗しました: ' . BILL_E2E_273_MANIFEST_PATH );
}

echo 'Wrote test data manifest: ' . BILL_E2E_273_MANIFEST_PATH . "\n";
