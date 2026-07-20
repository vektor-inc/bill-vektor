<?php

require_once( 'dupricate-doc-functions.php' );

/*
  記事リスト _ 複製して編集へのリンクを追加
/*-------------------------------------------*/
function bill_post_list_add_filter() {
	add_filter( 'post_row_actions', 'bill_row_actions_add_duplicate_link', 10, 2 );
	add_filter( 'estimate_row_actions', 'bill_row_actions_add_duplicate_link', 10, 2 );
}
add_action( 'admin_init', 'bill_post_list_add_filter' );


/*
  請求書発行のボタン追加
/*-------------------------------------------*/
/**
 * 編集画面のサブミットボックスに複製・請求書発行ボタンを追加する関数
 *
 * post_submitbox_start フックで呼ばれ、投稿タイプに応じて複製・発行ボタンを表示する。
 * - 見積書（estimate）: 「見積書を複製」「この内容で請求書を発行」
 *   「件名を品目一式にして請求書を発行」の3つのボタンを表示する。
 * - 請求書（post）: 「請求書を複製」の1つのボタンを表示する。
 * 各リンクには CSRF 対策の nonce を付与する。
 *
 * @return void
 */
add_action( 'post_submitbox_start', 'bill_duplicate' );
function bill_duplicate() {
	global $post;
	// CSRF 対策として nonce を URL パラメーターに付与する（各リンク共通で使い回す）
	$nonce = wp_create_nonce( 'bill_copy_' . $post->ID );
	$links = admin_url() . 'post-new.php?master_id=' . $post->ID . '&_wpnonce=' . $nonce;
	if ( get_post_type() == 'estimate' ) { ?>

	<div class="duplicate-section">

	<a href="<?php echo esc_url( $links ) . '&post_type=estimate&table_copy_type=all&duplicate_type=full'; ?>" class="button button-default button-block">見積書を複製</a>

	<a href="<?php echo esc_url( $links ) . '&post_type=post&table_copy_type=all'; ?>" class="button button-default button-block">この内容で請求書を発行</a>

	<a href="<?php echo esc_url( $links ) . '&post_type=post&table_copy_type=total'; ?>" class="button button-default button-block">件名を品目一式にして請求書を発行</a>

	</div><!-- [ / #duplicate-section ] -->
	<?php } elseif ( get_post_type() == 'post' ) { ?>

	<div class="duplicate-section">

	<a href="<?php echo esc_url( $links ) . '&post_type=post&table_copy_type=all&duplicate_type=full'; ?>" class="button button-default button-block">請求書を複製</a>

	</div><!-- [ / #duplicate-section ] -->
	<?php } ?>
	<?php
}
