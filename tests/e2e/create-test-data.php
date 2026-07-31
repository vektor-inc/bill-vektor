<?php
/**
 * PR #266 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法:
 *   npx wp-env run cli --env-cwd='wp-content/themes/bill-vektor' wp eval-file tests/e2e/create-test-data.php
 *
 * 作成する投稿:
 * 1. 税込6000円（四捨五入）+ 消費税デフォルト（四捨五入）
 *    → 修正前: 6001円 / 修正後: 6000円
 * 2. 税込6000円（四捨五入）+ 消費税切り上げ
 *    → 修正前: 6001円 / 修正後: 6000円
 * 3. 税抜10000円（デグレ確認）
 *    → 常に: 11000円
 * 4. 税抜3333円×3個 + 消費税切り捨て（デグレ確認）
 *    → 常に: 10998円
 *
 * このスクリプトは冪等（何度実行しても結果が同じ）です。
 * 各投稿は「[e2e-test] 〜」の一意なタイトルで既存投稿を検索し、
 * 見つかればその投稿を再利用してメタだけを期待値どおりに上書きします。
 * 再実行のたびに同じ投稿が増えていくと、テストがどれを開けばよいか
 * 判別できなくなるためです。
 *
 * 作成・再利用した投稿IDとタイトルは tests/e2e/.test-data-266.json に書き出します。
 * 投稿IDは環境（既存の投稿数）によって変わるため、
 * tests/e2e/test-data-266.js がこのファイルを読んで対象URLを組み立て、
 * tests/e2e/pr-266-tax-calculation.spec.js がそれを参照します。
 *
 * 書き出す形式:
 *   { "tax_round_default": { "id": 12, "title": "[e2e-test] 税込6000円（四捨五入）デフォルト" }, ... }
 *
 * タイトルも書き出すのは、マニフェストがデータベースと紐付いていないためです。
 * wp-env clean や DB 入れ替えで投稿が消えてもマニフェストは残るため、
 * 古いIDのページを開いても「6,001が無いこと」のような否定形の検証は素通りしてしまいます。
 * テスト側で「意図した投稿が表示されているか」をタイトルで確認できるようにしています。
 */

// 投稿IDの書き出し先。spec 側と同じ tests/e2e/ 配下に置く
// （wp-env コンテナ内でもテーマごとマウントされるためホスト側にも反映される）
const BILL_E2E_266_MANIFEST_PATH = __DIR__ . '/.test-data-266.json';

/**
 * 指定したタイトルの投稿IDを取得する
 *
 * @param string $title 投稿タイトル。
 * @return int 見つかった投稿ID。無ければ 0。
 */
function bill_e2e_266_find_post_by_title( $title ) {
	$post_ids = get_posts(
		array(
			'post_type'      => 'post',
			// 'title' は post_title の完全一致検索（部分一致の 's' ではない）
			'title'          => $title,
			// 'any' はゴミ箱を含まないため trash を明示し、
			// ゴミ箱に残った投稿を見落として重複作成するのを防ぐ
			'post_status'    => array( 'any', 'trash' ),
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
 * テスト用投稿を取得または作成し、メタを期待値どおりに設定する
 *
 * 既存投稿があれば再利用し、下書き・ゴミ箱に落ちていた場合は公開状態に戻す。
 * メタは add ではなく update で上書きするため、再実行しても値が重複しない。
 *
 * @param string      $key          マニフェストに書き出すキー。
 * @param string      $title        投稿タイトル（既存投稿の検索キーを兼ねる）。
 * @param array       $items        bill_items メタに設定する品目の配列。
 * @param string|null $tax_fraction bill_tax_fraction メタに設定する値。未設定にする場合は null。
 * @return int 作成または再利用した投稿ID。
 */
function bill_e2e_266_upsert_post( $key, $title, $items, $tax_fraction = null ) {
	$post_id = bill_e2e_266_find_post_by_title( $title );

	if ( $post_id ) {
		// 既存投稿を再利用する。下書き・ゴミ箱のままだと
		// テストがページを開けないため公開状態に揃える
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
	// 再実行時に同じメタキーが複数ぶら下がり、計算結果が変わるのを防ぐため
	update_post_meta( $post_id, 'bill_items', $items );

	if ( null === $tax_fraction ) {
		// 端数処理を指定しないケースは、前回実行時の値が残らないよう明示的に削除する
		delete_post_meta( $post_id, 'bill_tax_fraction' );
	} else {
		update_post_meta( $post_id, 'bill_tax_fraction', $tax_fraction );
	}

	echo 'URL: ' . get_permalink( $post_id ) . "\n";

	return $post_id;
}

// 作成するテスト投稿の定義。キーは spec 側が参照するマニフェストのキーと対応する
//
// 注意: 件名に " ' -- ... や Wordpress などを含めないこと。
// WordPress の表示用フィルタ（wptexturize / capital_P_dangit 等）でページタイトルが
// 変換され、テスト側の件名照合が一致しなくなるため。
$bill_e2e_266_fixtures = array(
	// 1. 税込6000円（四捨五入）+ 消費税デフォルト（四捨五入）
	'tax_round_default' => array(
		'title'        => '[e2e-test] 税込6000円（四捨五入）デフォルト',
		'items'        => array(
			array(
				'name'     => 'テスト品目',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 6000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_included',
			),
		),
		'tax_fraction' => null,
	),
	// 2. 税込6000円（四捨五入）+ 消費税切り上げ
	'tax_round_ceil'    => array(
		'title'        => '[e2e-test] 税込6000円（四捨五入）消費税切り上げ',
		'items'        => array(
			array(
				'name'     => 'テスト品目',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 6000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_included',
			),
		),
		'tax_fraction' => 'ceil',
	),
	// 3. 税抜10000円（デグレ確認）
	'tax_excluded'      => array(
		'title'        => '[e2e-test] 税抜10000円（デグレ確認）',
		'items'        => array(
			array(
				'name'     => 'テスト品目',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 10000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
		),
		'tax_fraction' => 'floor',
	),
	// 4. 税抜3333円×3個 + 消費税切り捨て（デグレ確認）
	'tax_excluded_3333' => array(
		'title'        => '[e2e-test] 税抜3333円×3個 消費税切り捨て（デグレ確認）',
		'items'        => array(
			array(
				'name'     => 'テスト品目',
				'count'    => '3',
				'unit'     => '個',
				'price'    => 3333,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
		),
		'tax_fraction' => 'floor',
	),
);

// 各テスト投稿を作成または再利用し、投稿IDとタイトルを集める
$bill_e2e_266_manifest = array();
foreach ( $bill_e2e_266_fixtures as $bill_e2e_266_key => $bill_e2e_266_fixture ) {
	$bill_e2e_266_manifest[ $bill_e2e_266_key ] = array(
		'id'    => bill_e2e_266_upsert_post(
			$bill_e2e_266_key,
			$bill_e2e_266_fixture['title'],
			$bill_e2e_266_fixture['items'],
			$bill_e2e_266_fixture['tax_fraction']
		),
		// テスト側が「意図した投稿を開けているか」を確認するために使う
		'title' => $bill_e2e_266_fixture['title'],
	);
}

// テスト側が読み取れるよう投稿IDとタイトルを JSON で書き出す
$bill_e2e_266_json = wp_json_encode( $bill_e2e_266_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
if ( false === $bill_e2e_266_json ) {
	WP_CLI::error( 'テストデータの JSON 変換に失敗しました。' );
}

// 書き込みに失敗したまま進むと spec 側が古いIDを読んで
// 原因の分かりにくい失敗になるため、ここで明確にエラーにする
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
if ( false === file_put_contents( BILL_E2E_266_MANIFEST_PATH, $bill_e2e_266_json . "\n" ) ) {
	WP_CLI::error( '投稿IDの書き出しに失敗しました: ' . BILL_E2E_266_MANIFEST_PATH );
}

echo 'Wrote test data manifest: ' . BILL_E2E_266_MANIFEST_PATH . "\n";
