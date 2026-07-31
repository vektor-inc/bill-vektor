<?php
/**
 * PR #311 e2e テストデータを個別に完全削除する。
 *
 * 実行方法:
 * npx wp-env run cli wp eval-file wp-content/themes/bill-vektor/tests/e2e/cleanup-test-data-pr-311.php
 */

$post_ids = get_option( 'bill_e2e_pr311_post_ids', array() );

foreach ( $post_ids as $post_id ) {
	if ( get_post( $post_id ) ) {
		wp_delete_post( $post_id, true );
		echo "Deleted post ID: {$post_id}\n";
	}
}

delete_option( 'bill_e2e_pr311_post_ids' );
