<?php
/**
 * issue #295 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
 *
 * 実行コマンド（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-295.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * --env-cwd はカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 *
 * このファイル名は PR 番号が決まるまでの暫定で issue 番号ベース（295）にしている
 * （tests/e2e/issue-299-csv-export-auth.spec.js と同じ命名の前例に合わせた）。
 * PR 番号が決まり次第、司の指示で create-test-data-pr-XXX.php へリネームする。
 *
 * 作成するデータ:
 * - 取引先 1件（株式会社イーツーイーサーチ商事）: 登録済取引先名での検索・並び替えの検証用
 * - 請求書 3件:
 *   - 取引先（登録済）だけを設定した書類（登録済取引先名で検索できることの検証用）
 *   - 取引先（イレギュラー）の手入力名だけを設定した書類（手入力名で検索できることの検証用）
 *   - 手入力・登録済の両方を設定した書類（並び替えで手入力が優先されることの検証用）
 * - 見積書 1件（取引先未設定。並び替えで一覧から消えないことの検証用）
 *
 * 件名・取引先名は issue-295-client-name-search.spec.js の期待値と対になっている。
 * 変更すると spec が落ちるため、片方だけを直さないこと。
 */

/**
 * 同じ件名・書類種別の投稿があれば再利用し、下書き・ゴミ箱に落ちていた場合は
 * 公開状態に戻す。無ければ作成する
 *
 * このスクリプトを2回実行しても同じ件名の書類が重複しないよう、
 * 実行を繰り返しても結果が変わらないようにしている（create-test-data-298.php と同じ方針）。
 *
 * @param string $title     件名。
 * @param string $post_type 書類種別（post / estimate / client）。
 * @return int 作成または再利用した投稿の ID。
 */
function bill_e2e_295_create_post( $title, $post_type ) {
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

	$post_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => '',
			'post_type'    => $post_type,
			'post_status'  => 'publish',
		)
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( '投稿の作成に失敗しました（' . $title . '）: ' . $post_id->get_error_message() );
	}

	echo 'Created: ' . $title . ' / ID: ' . $post_id . "\n";

	return $post_id;
}

/*
  取引先
/*-------------------------------------------*/
// 絞り込みフォームの取引先プルダウンに表示させるため client_hidden は設定しない
$client_id = bill_e2e_295_create_post( '株式会社イーツーイーサーチ商事', 'client' );

/*
  請求書
/*-------------------------------------------*/
// 取引先（登録済）だけを設定。「イーツーイーサーチ」で検索できることの検証用
$bill_registered_id = bill_e2e_295_create_post( 'issue295登録済取引先の請求書', 'post' );
update_post_meta( $bill_registered_id, 'bill_client', $client_id );
delete_post_meta( $bill_registered_id, 'bill_client_name_manual' );

// 取引先（イレギュラー）の手入力名だけを設定。「issue295手入力太郎」で検索できることの検証用
$bill_manual_id = bill_e2e_295_create_post( 'issue295手入力取引先の請求書', 'post' );
update_post_meta( $bill_manual_id, 'bill_client_name_manual', 'issue295手入力太郎' );
delete_post_meta( $bill_manual_id, 'bill_client' );

// 手入力・登録済の両方を設定。並び替えで手入力名「issue295並び替え確認商店」が
// 優先されること、および「イーツーイーサーチ」でも独立に検索できることの検証用
$bill_both_id = bill_e2e_295_create_post( 'issue295両方設定の請求書', 'post' );
update_post_meta( $bill_both_id, 'bill_client', $client_id );
update_post_meta( $bill_both_id, 'bill_client_name_manual', 'issue295並び替え確認商店' );

/*
  見積書
/*-------------------------------------------*/
// 取引先を一切設定しない。取引先名で並び替えても一覧から消えないことの検証用
$estimate_unset_id = bill_e2e_295_create_post( 'issue295取引先未設定の見積書', 'estimate' );
delete_post_meta( $estimate_unset_id, 'bill_client' );
delete_post_meta( $estimate_unset_id, 'bill_client_name_manual' );

echo "\nDone.\n";
