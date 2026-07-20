<?php
/**
 * PR #266 e2e テスト用データ作成スクリプト
 * wp-env run cli で実行する
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
 */

// 1. 税込6000円（四捨五入）+ 消費税デフォルト（四捨五入）
$post_id_tax_round = wp_insert_post( array(
	'post_title'  => '[e2e-test] 税込6000円（四捨五入）デフォルト',
	'post_type'   => 'post',
	'post_status' => 'publish',
) );

// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
if ( is_wp_error( $post_id_tax_round ) ) {
	WP_CLI::error( '投稿の作成に失敗しました（tax_round_default）: ' . $post_id_tax_round->get_error_message() );
}

add_post_meta( $post_id_tax_round, 'bill_items', array(
	array(
		'name'     => 'テスト品目',
		'count'    => '1',
		'unit'     => '個',
		'price'    => 6000,
		'tax-rate' => '10%',
		'tax-type' => 'tax_included',
	),
) );

echo "Created post ID (tax_round_default): " . $post_id_tax_round . "\n";
echo "URL: " . get_permalink( $post_id_tax_round ) . "\n";

// 2. 税込6000円（四捨五入）+ 消費税切り上げ
$post_id_tax_ceil = wp_insert_post( array(
	'post_title'  => '[e2e-test] 税込6000円（四捨五入）消費税切り上げ',
	'post_type'   => 'post',
	'post_status' => 'publish',
) );

// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
if ( is_wp_error( $post_id_tax_ceil ) ) {
	WP_CLI::error( '投稿の作成に失敗しました（tax_round_ceil）: ' . $post_id_tax_ceil->get_error_message() );
}

add_post_meta( $post_id_tax_ceil, 'bill_items', array(
	array(
		'name'     => 'テスト品目',
		'count'    => '1',
		'unit'     => '個',
		'price'    => 6000,
		'tax-rate' => '10%',
		'tax-type' => 'tax_included',
	),
) );
add_post_meta( $post_id_tax_ceil, 'bill_tax_fraction', 'ceil' );

echo "Created post ID (tax_round_ceil): " . $post_id_tax_ceil . "\n";
echo "URL: " . get_permalink( $post_id_tax_ceil ) . "\n";

// 3. 税抜10000円（デグレ確認）
$post_id_excluded = wp_insert_post( array(
	'post_title'  => '[e2e-test] 税抜10000円（デグレ確認）',
	'post_type'   => 'post',
	'post_status' => 'publish',
) );

// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
if ( is_wp_error( $post_id_excluded ) ) {
	WP_CLI::error( '投稿の作成に失敗しました（tax_excluded）: ' . $post_id_excluded->get_error_message() );
}

add_post_meta( $post_id_excluded, 'bill_items', array(
	array(
		'name'     => 'テスト品目',
		'count'    => '1',
		'unit'     => '個',
		'price'    => 10000,
		'tax-rate' => '10%',
		'tax-type' => 'tax_excluded',
	),
) );
add_post_meta( $post_id_excluded, 'bill_tax_fraction', 'floor' );

echo "Created post ID (tax_excluded): " . $post_id_excluded . "\n";
echo "URL: " . get_permalink( $post_id_excluded ) . "\n";

// 4. 税抜3333円×3個 + 消費税切り捨て（デグレ確認）
$post_id_excluded_3333 = wp_insert_post( array(
	'post_title'  => '[e2e-test] 税抜3333円×3個 消費税切り捨て（デグレ確認）',
	'post_type'   => 'post',
	'post_status' => 'publish',
) );

// wp_insert_post() は失敗時に WP_Error を返すため、投稿作成に失敗していないか確認する
if ( is_wp_error( $post_id_excluded_3333 ) ) {
	WP_CLI::error( '投稿の作成に失敗しました（tax_excluded_3333）: ' . $post_id_excluded_3333->get_error_message() );
}

add_post_meta( $post_id_excluded_3333, 'bill_items', array(
	array(
		'name'     => 'テスト品目',
		'count'    => '3',
		'unit'     => '個',
		'price'    => 3333,
		'tax-rate' => '10%',
		'tax-type' => 'tax_excluded',
	),
) );
add_post_meta( $post_id_excluded_3333, 'bill_tax_fraction', 'floor' );

echo "Created post ID (tax_excluded_3333): " . $post_id_excluded_3333 . "\n";
echo "URL: " . get_permalink( $post_id_excluded_3333 ) . "\n";
