<?php
/**
 * PR #298 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行コマンド（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-298.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * 作成するデータ:
 * - 取引先 1件（株式会社テスト商事）
 * - 請求書 7件（キーワード検索・ページ送り・発行日絞り込み・CSV エクスポートの検証用）
 * - 見積書 1件（書類種別 + 取引先 + キーワードの併用検証用）
 *
 * 件名と発行日は pr-298-keyword-search.spec.js の期待値と対になっている。
 * 変更すると spec が落ちるため、片方だけを直さないこと。
 *
 * 発行日の時刻を 10:00:00 にしているのは、発行日の絞り込みが
 * WP_Date_Query の after（既定で範囲の開始を含まない）で組まれているため。
 * 00:00:00 で作成すると開始日を指定した絞り込みから外れてしまう。
 *
 * pr-298-keyword-search.spec.js は、件名の完全一致を確認するテストで
 * 取引先「株式会社テスト商事」を併用する（issue #304）。この取引先を使う
 * テストデータは本スクリプトが作るものだけのため、他の create-test-data 系
 * スクリプトのデータや実データが同じ DB に同居していても、それらの件名に
 * 数字や「サイト」「更新」などの語句が含まれていても spec には影響しない。
 */

/**
 * 同じ件名・書類種別の投稿があれば再利用し、下書き・ゴミ箱に落ちていた場合は
 * 公開状態に戻す。無ければ作成する
 *
 * このスクリプトを2回実行すると同じ件名の書類が重複し、
 * 「1件だけヒットすること」を確認している spec が落ちるため、
 * 実行を繰り返しても結果が変わらないようにしている。
 *
 * @param string $title     件名。
 * @param string $post_type 書類種別（post / estimate / client）。
 * @param string $date      発行日（Y-m-d H:i:s）。空文字の場合は現在日時。
 * @param string $content   本文。
 * @return int 作成または再利用した投稿の ID。
 */
function bill_e2e_298_create_post( $title, $post_type, $date = '', $content = '' ) {
	// 既に同じ件名の書類があるか確認する。
	// 探したい状態を明示する。'any' は「すべての状態」ではなく、
	// exclude_from_search が true の状態（コアでは trash と auto-draft の2つ）を
	// 除くという指定。draft・pending・private・future は 'any' でも拾えるが、
	// trash は拾えない。ゴミ箱に残った投稿を見落として重複作成しないよう、
	// 状態を並べて明示している
	$existing = get_posts(
		array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page'         => 1,
			'title'                  => $title,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	if ( $existing ) {
		$post_id = $existing[0]->ID;

		// 既存投稿を再利用する。ゴミ箱（trash）まで拾うようにしたことで
		// ゴミ箱の投稿をそのまま返してしまうと一覧に表示されず spec が落ちるため、
		// 下書き・ゴミ箱のままだった場合は公開状態に揃える
		if ( 'publish' !== get_post_status( $post_id ) ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				WP_CLI::error( '投稿の公開状態への変更に失敗しました（' . $title . '）: ' . $updated->get_error_message() );
			}
		}

		echo 'Skipped (already exists): ' . $title . ' / ID: ' . $post_id . "\n";
		return $post_id;
	}

	$postarr = array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_type'    => $post_type,
		'post_status'  => 'publish',
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

/**
 * 書類に品目と支払期限を設定する
 *
 * CSV エクスポートは品目（bill_items）から金額を組み立てるため、
 * 品目が無い書類は CSV に1行も出力されない。
 * spec が CSV 内の件名を照合しているので、全書類に品目を設定しておく。
 *
 * @param int $post_id    対象の投稿 ID。
 * @param int $client_id  取引先の投稿 ID。
 * @param int $price      品目の単価（税抜）。
 * @param string $limit_date 支払期限（8桁の数字）。
 * @return void
 */
function bill_e2e_298_set_doc_meta( $post_id, $client_id, $price, $limit_date ) {
	update_post_meta(
		$post_id,
		'bill_items',
		array(
			array(
				'name'     => 'テスト品目',
				'count'    => '1',
				'unit'     => '式',
				'price'    => $price,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
		)
	);
	update_post_meta( $post_id, 'bill_client', $client_id );
	update_post_meta( $post_id, 'bill_limit_date', $limit_date );
}

/*
  取引先
/*-------------------------------------------*/
// 絞り込みフォームの取引先プルダウンに表示させるため client_hidden は設定しない
$client_id = bill_e2e_298_create_post( '株式会社テスト商事', 'client' );

/*
  請求書
/*-------------------------------------------*/
// 件名と検証内容の対応:
// - ロゴ制作費 / サイト制作費 : キーワード「制作費」で2件ヒットし、ページ送りで
//   1ページ目に新しい サイト制作費、2ページ目に ロゴ制作費 が出ることの検証用。
//   キーワード「サイト」では サイト制作費 だけがヒットする。
// - 型番0123の部品代 : キーワード「0」の1文字でも絞り込めることの検証用。
//   spec 側は取引先「株式会社テスト商事」で絞り込んだ上で件名を照合するため、
//   他の請求書の件名に数字が含まれていても衝突しない（issue #304）。
// - 保守費用（月額） : CSV エクスポートにキーワードの絞り込みが効いていること
//   （キーワード「サイト」の CSV に含まれないこと）の検証用。
// - 年度 更新プラン / 更新プランの年度切替 : 複数語のキーワードでも
//   発行日の新しい順が保たれることの検証用。発行日が古い 年度 更新プラン だけが
//   「年度 更新」をフレーズとして含むため、関連度順になっていると順序が入れ替わる。
// - 本文テスト書類 : 検索対象が件名に限定されていること（本文だけに含まれる語句が
//   ヒットしないこと）の検証用。
$bills = array(
	array(
		'title' => 'ロゴ制作費',
		'date'  => '2024-01-15 10:00:00',
		'price' => 50000,
		'limit' => '20240229',
	),
	array(
		'title' => 'サイト制作費',
		'date'  => '2024-02-15 10:00:00',
		'price' => 300000,
		'limit' => '20240331',
	),
	array(
		'title' => '型番0123の部品代',
		'date'  => '2024-03-15 10:00:00',
		'price' => 12000,
		'limit' => '20240430',
	),
	array(
		'title' => '保守費用（月額）',
		'date'  => '2024-04-15 10:00:00',
		'price' => 20000,
		'limit' => '20240531',
	),
	array(
		'title' => '年度 更新プラン',
		'date'  => '2023-01-01 10:00:00',
		'price' => 80000,
		'limit' => '20230228',
	),
	array(
		'title' => '更新プランの年度切替',
		'date'  => '2023-08-01 10:00:00',
		'price' => 90000,
		'limit' => '20230930',
	),
);
foreach ( $bills as $bill ) {
	$bill_id = bill_e2e_298_create_post( $bill['title'], 'post', $bill['date'] );
	bill_e2e_298_set_doc_meta( $bill_id, $client_id, $bill['price'], $bill['limit'] );
}

// 本文にだけ検索用の語句を持つ請求書（件名には含めない）
$content_only_id = bill_e2e_298_create_post( '本文テスト書類', 'post', '2024-05-15 10:00:00', '本文限定キーワード' );
bill_e2e_298_set_doc_meta( $content_only_id, $client_id, 10000, '20240630' );

/*
  見積書
/*-------------------------------------------*/
// 書類種別（見積書）+ 取引先 + キーワードの3条件を併用した絞り込みの検証用
$estimate_id = bill_e2e_298_create_post( 'サイトリニューアル見積', 'estimate', '2024-02-20 10:00:00' );
bill_e2e_298_set_doc_meta( $estimate_id, $client_id, 500000, '20240331' );

/*
  同じ取引先に紐づく書類との干渉チェック
/*-------------------------------------------*/
// pr-298-keyword-search.spec.js は、件名の完全一致を確認するテストで
// 取引先「株式会社テスト商事」（$client_id）を併用する（issue #304）。
// これにより、他の create-test-data 系スクリプトが作る書類（取引先が異なる）と
// 同じ DB に同居しても spec には影響しない。
//
// ここで検知したいのは、その前提が崩れるケース、つまり
// 「$client_id に紐づく書類の中に、本スクリプトが作った件名以外に
// キーワード検索と衝突しうる件名が紛れ込んでいる」場合。
// 本スクリプトだけがこの取引先を使う想定のため通常は起きないが、
// 手動でのデータ投入やスクリプトの将来的な変更で紛れ込んだ場合に気づけるようにする。
$conflict_keywords = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'サイト', '制作費', '年度', '更新' );
$created_titles    = array(
	'ロゴ制作費',
	'サイト制作費',
	'型番0123の部品代',
	'保守費用（月額）',
	'年度 更新プラン',
	'更新プランの年度切替',
	'本文テスト書類',
	'サイトリニューアル見積',
);
$conflicts         = array();
// e2e はログイン済み（管理者）でフロント一覧を確認するため、'private' の書類も一覧に
// 表示される。'publish' だけを対象にすると、'private' の書類に衝突する件名があっても
// 検知できないため、実際に一覧へ出てくるステータスに揃える。
$client_bills      = get_posts(
	array(
		'post_type'      => array( 'post', 'estimate' ),
		'post_status'    => array( 'publish', 'private' ),
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => 'bill_client',
				'value' => $client_id,
			),
		),
	)
);
foreach ( $client_bills as $bill_post ) {
	// このスクリプトが作成した書類は対象外
	if ( in_array( $bill_post->post_title, $created_titles, true ) ) {
		continue;
	}
	foreach ( $conflict_keywords as $conflict_keyword ) {
		if ( false !== strpos( $bill_post->post_title, $conflict_keyword ) ) {
			$conflicts[] = $bill_post->post_title;
			break;
		}
	}
}
if ( $conflicts ) {
	WP_CLI::warning(
		"取引先「株式会社テスト商事」に紐づく書類の中に、テストのキーワードと衝突する件名があります。pr-298-keyword-search.spec.js が落ちる可能性があります:\n  - "
		. implode( "\n  - ", $conflicts )
	);
}

echo "\nDone.\n";
