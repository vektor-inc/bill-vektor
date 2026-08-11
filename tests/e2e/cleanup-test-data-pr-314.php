<?php
/**
 * PR #314 e2e テストデータを個別に完全削除する。
 *
 * 実行方法（テーマのディレクトリ＝このリポジトリのルートで実行する）:
 * npx wp-env run cli wp eval-file wp-content/themes/$(basename "$PWD")/tests/e2e/cleanup-test-data-pr-314.php
 *
 * テーマのディレクトリ名は git worktree などで bill-vektor 以外になることがあるため、
 * $(basename "$PWD") でカレントディレクトリ名から求める。
 * package.json の phpunit スクリプトはシェルのパラメータ展開で同じ値を求めているが、
 * その記法はブロックコメントの終端と同じ文字並びを含みコメント内に書けないため、
 * ここでは同じ結果になる basename を使う。
 */

$post_ids = get_option( 'bill_e2e_pr314_post_ids', array() );

foreach ( $post_ids as $post_id ) {
	if ( get_post( $post_id ) ) {
		wp_delete_post( $post_id, true );
		echo "Deleted post ID: {$post_id}\n";
	}
}

delete_option( 'bill_e2e_pr314_post_ids' );
