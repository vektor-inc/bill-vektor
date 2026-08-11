<?php
/**
 * PR #319 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-319.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * tests/e2e/pr-319-view-auth.spec.js が参照する書類1件と、
 * 権限確認用ユーザー2名（購読者・寄稿者）を作成する。
 *
 * 作成する書類（post 投稿）:
 * 1. [e2e-test] PR319 購読者アクセス制限確認用
 *    購読者に403で遮断され、書類内容が案内ページへ漏れないことの確認に使う。
 *    合計金額（税込）は品目から一意に計算し、マニフェストへ書き出す。
 *
 * 作成するユーザー:
 * 1. billsub（購読者）… フロント側の閲覧制限（403）を確認する
 * 2. billcon（寄稿者）… 一覧・明細を閲覧できる回帰確認に使う
 *
 * 以前はこのユーザー2名を「依頼で用意済み」の前提で決め打ちしていたため、
 * 未整備の環境ではテストがすべて失敗していた。このスクリプトで冪等に
 * 作成することで、どの環境でも同じ手順で準備できるようにしている。
 * 既に同じログイン名のユーザーが存在する場合も、役割とパスワードを
 * このスクリプトの期待値へ揃える（手動作成されていた場合の食い違いを防ぐため）。
 *
 * このスクリプトは冪等（何度実行しても結果が同じ）です。
 * 書類は「[e2e-test] PR319 〜」の一意なタイトルで既存投稿を検索し、
 * 見つかればその投稿を再利用してメタだけを期待値どおりに上書きします。
 *
 * 作成・再利用した書類IDとタイトル・合計金額、および確認用アカウントの
 * ログイン名・パスワードは tests/e2e/.test-data-319.json に書き出します。
 * 投稿IDは環境（既存の投稿数）によって変わるため、
 * tests/e2e/test-data-319.js がこのファイルを読んで対象URLを組み立て、
 * tests/e2e/pr-319-view-auth.spec.js がそれを参照します。
 *
 * 書き出す形式:
 *   {
 *     "document": { "id": 12, "title": "[e2e-test] PR319 購読者アクセス制限確認用", "total": "13,200" },
 *     "users": {
 *       "subscriber":  { "login": "billsub", "password": "password" },
 *       "contributor": { "login": "billcon", "password": "password" }
 *     }
 *   }
 *
 * タイトルと合計金額も書き出すのは、マニフェストがデータベースと紐付いていないためです。
 * wp-env clean や DB 入れ替えで投稿が消えてもマニフェストは残るため、
 * 古いIDのページを開いても「書類の内容が漏れていないこと」のような
 * 否定形の検証は素通りしてしまいます（空振り PASS）。
 * テスト側で「意図した書類が表示されているか」をタイトルと合計金額で
 * 確認できるようにしています。
 */

// 投稿IDの書き出し先。spec 側と同じ tests/e2e/ 配下に置く
// （wp-env コンテナ内でもテーマごとマウントされるためホスト側にも反映される）
const BILL_E2E_319_MANIFEST_PATH = __DIR__ . '/.test-data-319.json';

/**
 * 指定したタイトルの投稿IDを取得する
 *
 * @param string $title 投稿タイトル。
 * @return int 見つかった投稿ID。無ければ 0。
 */
function bill_e2e_319_find_post_by_title( $title ) {
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
		)
	);

	return $post_ids ? (int) $post_ids[0] : 0;
}

/**
 * 権限確認用の書類を取得または作成し、品目メタを期待値どおりに設定する
 *
 * @return int 作成または再利用した投稿ID。
 */
function bill_e2e_319_upsert_document() {
	$title = '[e2e-test] PR319 購読者アクセス制限確認用';

	// 合計金額を暗算しやすいよう、税抜価格 × 1個 × 消費税10%のシンプルな1品目にする。
	// テスト対象は「金額の正しさ」ではなく「この文言・金額が権限のない画面へ漏れないこと」のため、
	// 計算過程を複雑にする必要はない。
	$items = array(
		array(
			'name'     => 'テスト品目',
			'count'    => '1',
			'unit'     => '式',
			'price'    => 12000,
			'tax-rate' => '10%',
			'tax-type' => 'tax_excluded',
		),
	);

	$post_id = bill_e2e_319_find_post_by_title( $title );

	if ( $post_id ) {
		// 既存投稿を再利用する。下書き・ゴミ箱のままだとテストが閲覧できないため公開状態に揃える
		if ( 'publish' !== get_post_status( $post_id ) ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				WP_CLI::error( '投稿の公開状態への変更に失敗しました: ' . $updated->get_error_message() );
			}
		}

		echo 'Reused post ID: ' . $post_id . "\n";
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
			WP_CLI::error( '投稿の作成に失敗しました: ' . $post_id->get_error_message() );
		}

		$post_id = (int) $post_id;
		echo 'Created post ID: ' . $post_id . "\n";
	}

	// メタは add_post_meta ではなく update_post_meta で上書きする。
	// 再実行時に同じメタキーが複数ぶら下がり、合計金額が変わるのを防ぐため
	update_post_meta( $post_id, 'bill_items', $items );
	// 端数処理は前回実行時の値が残らないよう明示的に削除する（この品目構成では丸め誤差が出ないため）
	delete_post_meta( $post_id, 'bill_tax_fraction' );
	// お支払期日が未設定だと単体ページの日付表示で PHP の警告が出るため、確認用の値を設定しておく
	update_post_meta( $post_id, 'bill_limit_date', '20260930' );

	echo 'URL: ' . get_permalink( $post_id ) . "\n";

	return $post_id;
}

/**
 * 権限確認用ユーザーを取得または作成し、役割・パスワードを期待値どおりに設定する
 *
 * 既に同じログイン名のユーザーがいる場合も、役割とパスワードをこのスクリプトの
 * 期待値へ上書きする。手動で作成されていたユーザーと役割・パスワードが食い違ったまま
 * だと、テストがログインできず原因の分かりにくい失敗になるため。
 *
 * @param string $login    ログイン名。
 * @param string $role     権限グループ（例: 'subscriber', 'contributor'）。
 * @param string $password パスワード。
 * @param string $email    メールアドレス（新規作成時のみ使用）。
 * @return int 作成または再利用したユーザーID。
 */
function bill_e2e_319_upsert_user( $login, $role, $password, $email ) {
	$user = get_user_by( 'login', $login );

	if ( $user ) {
		$updated = wp_update_user(
			array(
				'ID'        => $user->ID,
				'user_pass' => $password,
			)
		);

		if ( is_wp_error( $updated ) ) {
			WP_CLI::error( 'ユーザーの更新に失敗しました（' . $login . '）: ' . $updated->get_error_message() );
		}

		$user->set_role( $role );

		echo 'Reused user: ' . $login . ' (ID: ' . $user->ID . ', role: ' . $role . ")\n";

		return (int) $user->ID;
	}

	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => $password,
			'user_email' => $email,
			'role'       => $role,
		)
	);

	// wp_insert_user() は失敗時に WP_Error を返すため、ユーザー作成に失敗していないか確認する
	if ( is_wp_error( $user_id ) ) {
		WP_CLI::error( 'ユーザーの作成に失敗しました（' . $login . '）: ' . $user_id->get_error_message() );
	}

	echo 'Created user: ' . $login . ' (ID: ' . $user_id . ', role: ' . $role . ")\n";

	return (int) $user_id;
}

// 書類を作成・再利用し、テーマ自身の計算関数で合計金額（税込）を求める。
// 計算ロジックを本スクリプトで再実装せず theme 側の関数を直接呼ぶことで、
// 丸め処理の仕様が変わってもマニフェストの値が自動的に追随するようにしている。
$bill_e2e_319_document_id    = bill_e2e_319_upsert_document();
$bill_e2e_319_document_title = get_the_title( $bill_e2e_319_document_id );
$bill_e2e_319_document_post  = get_post( $bill_e2e_319_document_id );
$bill_e2e_319_total          = bill_vektor_invoice_total_tax( $bill_e2e_319_document_post );
$bill_e2e_319_total_formatted = number_format( $bill_e2e_319_total );

echo 'Total (tax included): ' . $bill_e2e_319_total_formatted . "\n";

// 権限確認用ユーザーを作成・再利用する
$bill_e2e_319_subscriber_id  = bill_e2e_319_upsert_user( 'billsub', 'subscriber', 'password', 'billsub@example.com' );
$bill_e2e_319_contributor_id = bill_e2e_319_upsert_user( 'billcon', 'contributor', 'password', 'billcon@example.com' );

// テスト側が読み取れるよう、書類の投稿ID・タイトル・合計金額とユーザーの認証情報を JSON で書き出す
$bill_e2e_319_manifest = array(
	'document' => array(
		'id'    => $bill_e2e_319_document_id,
		'title' => $bill_e2e_319_document_title,
		'total' => $bill_e2e_319_total_formatted,
	),
	'users'    => array(
		'subscriber'  => array(
			'login'    => 'billsub',
			'password' => 'password',
		),
		'contributor' => array(
			'login'    => 'billcon',
			'password' => 'password',
		),
	),
);

$bill_e2e_319_json = wp_json_encode( $bill_e2e_319_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
if ( false === $bill_e2e_319_json ) {
	WP_CLI::error( 'テストデータの JSON 変換に失敗しました。' );
}

// 書き込みに失敗したまま進むと spec 側が古い情報を読んで
// 原因の分かりにくい失敗になるため、ここで明確にエラーにする
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
if ( false === file_put_contents( BILL_E2E_319_MANIFEST_PATH, $bill_e2e_319_json . "\n" ) ) {
	WP_CLI::error( '投稿IDの書き出しに失敗しました: ' . BILL_E2E_319_MANIFEST_PATH );
}

echo 'Wrote test data manifest: ' . BILL_E2E_319_MANIFEST_PATH . "\n";
