<?php
/**
 * Class PriceTest
 *
 * @package BillVektor
 */
/*
$ cd $(wp theme path --dir bill-vektor)
$ bash bin/install-wp-tests.sh wordpress_test root 'WordPress' localhost latest

/*
cd /app
bash setup-phpunit.sh
source ~/.bashrc
cd $(wp theme path --dir bill-vektor)
phpunit
*/

/**
 * BillVektor test case.
 */
class InvoiceTest extends WP_UnitTestCase {

	public static function setup_data() {

		$posts = array();

		register_post_type(
			'estimate',
			array(
				'labels'             => array(
					'name'         => '見積書',
					'edit_item'    => '見積書の編集',
					'add_new_item' => '見積書の作成',
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'has_archive'        => true,
				'supports'           => array( 'title' ),
				'menu_icon'          => 'dashicons-media-spreadsheet',
				'menu_position'      => 5,
			)
		);
		register_taxonomy(
			'estimate-cat',
			'estimate',
			array(
				'hierarchical'          => true,
				'update_count_callback' => '_update_post_term_count',
				'label'                 => '見積書カテゴリー',
				'singular_label'        => '見積書カテゴリー',
				'public'                => true,
				'show_ui'               => true,
			)
		);

		// 古いカスタムフィールドを使っていた場合の品目リスト（税抜）
		$old_bill_item_tax_exclude = array(
			array(
				'name'  => 'item-001',
				'count' => '1',
				'unit'  => '個',
				'price' => 1000,
			),
			array(
				'name'  => 'item-002',
				'count' => '2',
				'unit'  => '個',
				'price' => 2000,
			),
			array(
				'name'  => 'item-003',
				'count' => '3',
				'unit'  => '個',
				'price' => 3000,
			),
		);

		// 古いカスタムフィールドを使っていた場合の品目リスト（8%税込み）
		$old_bill_item_tax8_include = array(
			array(
				'name'  => 'item-001',
				'count' => '1',
				'unit'  => '個',
				'price' => 1080,
			),
			array(
				'name'  => 'item-002',
				'count' => '2',
				'unit'  => '個',
				'price' => 2160,
			),
			array(
				'name'  => 'item-003',
				'count' => '3',
				'unit'  => '個',
				'price' => 3240,
			),
		);

		// 古いカスタムフィールドを使っていた場合の品目リスト（10%税込み）
		$old_bill_item_tax10_include = array(
			array(
				'name'  => 'item-001',
				'count' => '1',
				'unit'  => '個',
				'price' => 1100,
			),
			array(
				'name'  => 'item-002',
				'count' => '2',
				'unit'  => '個',
				'price' => 2200,
			),
			array(
				'name'  => 'item-003',
				'count' => '3',
				'unit'  => '個',
				'price' => 3300,
			),
		);

		// インボイスのごちゃまぜ仕様
		$bill_item_invoice = array(
			array(
				'name'     => 'item-001',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 1000,
				'tax-rate' => '8%',
				'tax-type' => 'tax_excluded',
			),
			array(
				'name'     => 'item-002',
				'count'    => '2',
				'unit'     => '個',
				'price'    => 2000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_excluded',
			),
			array(
				'name'     => 'item-003',
				'count'    => '3',
				'unit'     => '個',
				'price'    => 3240,
				'tax-rate' => '8%',
				'tax-type' => 'tax_included',
			),
			array(
				'name'     => 'item-004',
				'count'    => '4',
				'unit'     => '個',
				'price'    => 4400,
				'tax-rate' => '10%',
				'tax-type' => 'tax_included',
			),
		);

		// 消費税率と税込・税抜が空の場合（10%前 / 2019-10-01 より前 ）
		$posts['empty-10-before'] = wp_insert_post(
			array(
				'post_title'   => 'empty-10-before',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
				'post_date'    => '2019-09-30 23:59:59',
			)
		);
		add_post_meta( $posts['empty-10-before'], 'bill_items', $old_bill_item_tax_exclude );

		// 消費税率と税込・税抜が空の場合（10%後 / 2019-10-01 以降 )
		$posts['empty-10-after'] = wp_insert_post(
			array(
				'post_title'   => 'empty-10-after',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
				'post_date'    => '2019-10-01 00:00:00',
			)
		);
		add_post_meta( $posts['empty-10-after'], 'bill_items', $old_bill_item_tax_exclude );

		// 古い消費税率（8%）と消費税（最後にまとめて自動計算する）を選んでいた場合
		$posts['old-8tax-exclude'] = wp_insert_post(
			array(
				'post_title'   => 'Old 8% Tax Exclude',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['old-8tax-exclude'], 'bill_items', $old_bill_item_tax_exclude );
		add_post_meta( $posts['old-8tax-exclude'], 'bill_tax_rate', 8 );
		add_post_meta( $posts['old-8tax-exclude'], 'bill_tax_type', 'tax_auto' );

		// 古い消費税率（10%）と消費税（最後にまとめて自動計算する）を選んでいた場合
		$posts['old-10tax-exclude'] = wp_insert_post(
			array(
				'post_title'   => 'Old 10% Tax Exclude',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['old-10tax-exclude'], 'bill_items', $old_bill_item_tax_exclude );
		add_post_meta( $posts['old-10tax-exclude'], 'bill_tax_rate', 10 );
		add_post_meta( $posts['old-10tax-exclude'], 'bill_tax_type', 'tax_auto' );

		// 古い消費税率（8%）と消費税（品目毎に予め消費税込の金額で入力する）を選んでいた場合
		$posts['old-8tax-include'] = wp_insert_post(
			array(
				'post_title'   => 'Old 8% Tax Exclude',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['old-8tax-include'], 'bill_items', $old_bill_item_tax8_include );
		add_post_meta( $posts['old-8tax-include'], 'bill_tax_rate', 8 );
		add_post_meta( $posts['old-8tax-include'], 'bill_tax_type', 'tax_not_auto' );

		// 古い消費税率（8%）と消費税（品目毎に予め消費税込の金額で入力する）を選んでいた場合
		$posts['old-10tax-include'] = wp_insert_post(
			array(
				'post_title'   => 'Old 10% Tax Exclude',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['old-10tax-include'], 'bill_items', $old_bill_item_tax10_include );
		add_post_meta( $posts['old-10tax-include'], 'bill_tax_rate', 10 );
		add_post_meta( $posts['old-10tax-include'], 'bill_tax_type', 'tax_not_auto' );

		// インボイス仕様ごちゃ混ぜ
		$posts['invoice'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['invoice'], 'bill_items', $bill_item_invoice );

		// 税込 6000 円（単価：四捨五入）の処理共通項目
		$bill_item_tax_round = array(
			array(
				'name'     => 'item-001',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 6000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_included',
			),
		);

		// 税込 6000 円の処理（単価：四捨五入・消費税：デフォルト）
		$posts['tax_round_default'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_round_default'], 'bill_items', $bill_item_tax_round );

		// 税込 6000 円の処理（単価：四捨五入・消費税：四捨五入）
		$posts['tax_round_round'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_round_round'], 'bill_items', $bill_item_tax_round );
		add_post_meta( $posts['tax_round_round'], 'bill_tax_fraction', 'round' );

		// 税込 6000 円の処理（単価：四捨五入・消費税：切り上げ）
		$posts['tax_round_ceil'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_round_ceil'], 'bill_items', $bill_item_tax_round );
		add_post_meta( $posts['tax_round_ceil'], 'bill_tax_fraction', 'ceil' );

		// 税込 6000 円の処理（単価：四捨五入・消費税：切り捨て）
		$posts['tax_round_floor'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_round_floor'], 'bill_items', $bill_item_tax_round );
		add_post_meta( $posts['tax_round_floor'], 'bill_tax_fraction', 'floor' );

		// 税込 6000 円単価：切り上げ）の処理共通項目
		$bill_item_tax_ceil = array(
			array(
				'name'     => 'item-001',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 6000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_included_ceil',
			),
		);

		// 税込 6000 円の処理（単価：切り上げ・消費税：デフォルト）
		$posts['tax_ceil_default'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_ceil_default'], 'bill_items', $bill_item_tax_ceil );

		// 税込 6000 円の処理（単価：切り上げ・消費税：四捨五入）
		$posts['tax_ceil_round'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_ceil_round'], 'bill_items', $bill_item_tax_ceil );
		add_post_meta( $posts['tax_ceil_round'], 'bill_tax_fraction', 'round' );

		// 税込 6000 円の処理（単価：切り上げ・消費税：切り上げ）
		$posts['tax_ceil_ceil'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_ceil_ceil'], 'bill_items', $bill_item_tax_ceil );
		add_post_meta( $posts['tax_ceil_ceil'], 'bill_tax_fraction', 'ceil' );

		// 税込 6000 円の処理（単価：切り上げ・消費税：切り捨て）
		$posts['tax_ceil_floor'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_ceil_floor'], 'bill_items', $bill_item_tax_ceil );
		add_post_meta( $posts['tax_ceil_floor'], 'bill_tax_fraction', 'floor' );

		// 税込 6000 円の処理共通項目
		$bill_item_tax_floor = array(
			array(
				'name'     => 'item-001',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 6000,
				'tax-rate' => '10%',
				'tax-type' => 'tax_included_floor',
			),
		);

		// 税込 6000 円の処理（単価：切り捨て・消費税：デフォルト）
		$posts['tax_floor_default'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_floor_default'], 'bill_items', $bill_item_tax_floor );

		// 税込 6000 円の処理（単価：切り捨て・消費税：四捨五入）
		$posts['tax_floor_round'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_floor_round'], 'bill_items', $bill_item_tax_floor );
		add_post_meta( $posts['tax_floor_round'], 'bill_tax_fraction', 'round' );

		// 税込 6000 円の処理（単価：切り捨て・消費税：切り上げ）
		$posts['tax_floor_ceil'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_floor_ceil'], 'bill_items', $bill_item_tax_floor );
		add_post_meta( $posts['tax_floor_ceil'], 'bill_tax_fraction', 'ceil' );

		// 税込 6000 円の処理（単価：切り捨て・消費税：切り捨て）
		$posts['tax_floor_floor'] = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_floor_floor'], 'bill_items', $bill_item_tax_floor );
		add_post_meta( $posts['tax_floor_floor'], 'bill_tax_fraction', 'floor' );

		// 非課税
		$bill_item_tax_none = array(
			array(
				'name'     => 'item-001',
				'count'    => '1',
				'unit'     => '個',
				'price'    => 6000,
				'tax-rate' => '0%',
				'tax-type' => 'tax_included_ceil',
			),
		);
		$posts['tax_none']  = wp_insert_post(
			array(
				'post_title'   => 'New Type',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $posts['tax_none'], 'bill_items', $bill_item_tax_none );
		add_post_meta( $posts['tax_none'], 'bill_tax_fraction', 'floor' );

		return $posts;
	}

	public function test_bill_vektor_invoice_each_tax() {

		$data = self::setup_data();

		$test_array = array(
			array(
				'post_id'  => $data['empty-10-before'],
				'cortrect' => array(
					'8%' => array(
						'rate'  => '8%対象',
						'price' => 14000,
						'tax'   => 1120,
						'total' => 15120,
					),
				),
			),
			array(
				'post_id'  => $data['empty-10-after'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 14000,
						'tax'   => 1400,
						'total' => 15400,
					),
				),
			),
			array(
				'post_id'  => $data['old-8tax-exclude'],
				'cortrect' => array(
					'8%' => array(
						'rate'  => '8%対象',
						'price' => 14000,
						'tax'   => 1120,
						'total' => 15120,
					),
				),
			),
			array(
				'post_id'  => $data['old-10tax-exclude'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 14000,
						'tax'   => 1400,
						'total' => 15400,
					),
				),
			),
			array(
				'post_id'  => $data['old-8tax-include'],
				'cortrect' => array(
					'8%' => array(
						'rate'  => '8%対象',
						'price' => 14000,
						'tax'   => 1120,
						'total' => 15120,
					),
				),
			),
			array(
				'post_id'  => $data['old-10tax-include'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 14000,
						'tax'   => 1400,
						'total' => 15400,
					),
				),
			),
			array(
				'post_id'  => $data['invoice'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 20000,
						'tax'   => 2000,
						'total' => 22000,
					),
					'8%'  => array(
						'rate'  => '8%対象',
						'price' => 10000,
						'tax'   => 800,
						'total' => 10800,
					),
				),
			),
			array(
				'post_id'  => $data['tax_round_default'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 546,
						'total' => 6001,
					),
				),
			),
			array(
				'post_id'  => $data['tax_round_round'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 546,
						'total' => 6001,
					),
				),
			),
			array(
				'post_id'  => $data['tax_round_ceil'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 546,
						'total' => 6001,
					),
				),
			),
			array(
				'post_id'  => $data['tax_round_floor'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 545,
						'total' => 6000,
					),
				),
			),
			array(
				'post_id'  => $data['tax_ceil_default'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 546,
						'total' => 6001,
					),
				),
			),
			array(
				'post_id'  => $data['tax_ceil_round'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 546,
						'total' => 6001,
					),
				),
			),
			array(
				'post_id'  => $data['tax_ceil_ceil'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 546,
						'total' => 6001,
					),
				),
			),
			array(
				'post_id'  => $data['tax_ceil_floor'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5455,
						'tax'   => 545,
						'total' => 6000,
					),
				),
			),
			array(
				'post_id'  => $data['tax_floor_default'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5454,
						'tax'   => 545,
						'total' => 5999,
					),
				),
			),
			array(
				'post_id'  => $data['tax_floor_round'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5454,
						'tax'   => 545,
						'total' => 5999,
					),
				),
			),
			array(
				'post_id'  => $data['tax_floor_ceil'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5454,
						'tax'   => 546,
						'total' => 6000,
					),
				),
			),
			array(
				'post_id'  => $data['tax_floor_floor'],
				'cortrect' => array(
					'10%' => array(
						'rate'  => '10%対象',
						'price' => 5454,
						'tax'   => 545,
						'total' => 5999,
					),
				),
			),
			array(
				'post_id'  => $data['tax_none'],
				'cortrect' => array(
					'0%' => array(
						'rate'  => '0%対象',
						'price' => 6000,
						'tax'   => 0,
						'total' => 6000,
					),
				),
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Each Tax' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test ) {
			$post    = get_post( $test['post_id'] );
			$return  = bill_vektor_invoice_each_tax( $post );
			$correct = $test['cortrect'];
			// print 'return------------------------------------' . PHP_EOL;
			// var_dump( $return ) . PHP_EOL;
			// print 'correct------------------------------------' . PHP_EOL;
			// var_dump( $correct ) . PHP_EOL;
			$this->assertEquals( $correct, $return );
		}
	}

	public function test_bill_vektor_invoice_total_tax() {

		$data = self::setup_data();

		$test_array = array(
			array(
				'post_id'  => $data['empty-10-before'],
				'cortrect' => 15120,
			),
			array(
				'post_id'  => $data['empty-10-after'],
				'cortrect' => 15400,
			),
			array(
				'post_id'  => $data['old-8tax-exclude'],
				'cortrect' => 15120,
			),
			array(
				'post_id'  => $data['old-10tax-exclude'],
				'cortrect' => 15400,
			),
			array(
				'post_id'  => $data['old-8tax-include'],
				'cortrect' => 15120,
			),
			array(
				'post_id'  => $data['old-10tax-include'],
				'cortrect' => 15400,
			),
			array(
				'post_id'  => $data['invoice'],
				'cortrect' => 32800,
			),
			array(
				'post_id'  => $data['tax_round_default'],
				'cortrect' => 6001,
			),
			array(
				'post_id'  => $data['tax_round_round'],
				'cortrect' => 6001,
			),
			array(
				'post_id'  => $data['tax_round_ceil'],
				'cortrect' => 6001,
			),
			array(
				'post_id'  => $data['tax_round_floor'],
				'cortrect' => 6000,
			),
			array(
				'post_id'  => $data['tax_ceil_default'],
				'cortrect' => 6001,
			),
			array(
				'post_id'  => $data['tax_ceil_round'],
				'cortrect' => 6001,
			),
			array(
				'post_id'  => $data['tax_ceil_ceil'],
				'cortrect' => 6001,
			),
			array(
				'post_id'  => $data['tax_ceil_floor'],
				'cortrect' => 6000,
			),
			array(
				'post_id'  => $data['tax_floor_default'],
				'cortrect' => 5999,
			),
			array(
				'post_id'  => $data['tax_floor_round'],
				'cortrect' => 5999,
			),
			array(
				'post_id'  => $data['tax_floor_ceil'],
				'cortrect' => 6000,
			),
			array(
				'post_id'  => $data['tax_floor_floor'],
				'cortrect' => 5999,
			),
			array(
				'post_id'  => $data['tax_none'],
				'cortrect' => 6000,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Total Tax' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test ) {
			$post    = get_post( $test['post_id'] );
			$return  = bill_vektor_invoice_total_tax( $post );
			$correct = $test['cortrect'];
			// print 'return: ' . $return . PHP_EOL;
			// print 'correct: ' . $correct . PHP_EOL;
			$this->assertEquals( $correct, $return );
		}
	}

	/**
	 * 合計金額テスト
	 */
	public function test_bill_vektor_invoice_total_tax__unit() {

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'test_bill_vektor_invoice_total_tax__unit' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		$post_data = array(
			'post_title'   => 'test',
			'post_content' => 'test',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		$test_array = array(
			array(
				'test_name'     => 'とりあえず動くか確認',
				'custom_fields' => array(
					'bill_items'        => array(
						array(
							'name'     => 'test',
							'count'    => 1,
							'unit'     => '円',
							'price'    => 10000,
							'tax-rate' => '10%',
							'tax-type' => 'tax_excluded',
						),
					),
					'bill_tax_rate'     => 10, // old_tax_rate
					'bill_tax_type'     => 'tax_included',  // old_tax_type
					'bill_tax_fraction' => 'floor',
				),
				'expected'      => 11000,
			),
			array(
				'test_name'     => '単位が空の場合',
				'custom_fields' => array(
					'bill_items'        => array(
						array(
							'name'     => 'test',
							'count'    => 1,
							'unit'     => '',
							'price'    => 10000,
							'tax-rate' => '10%',
							'tax-type' => 'tax_excluded',
						),
					),
					'bill_tax_fraction' => 'floor',
				),
				'expected'      => 11000,
			),
			array(
				'test_name'     => '旧設定で税抜きだが 個別項目で税込み -> 税込みで計算される',
				'custom_fields' => array(
					'bill_items'        => array(
						array(
							'name'     => 'test',
							'count'    => 1,
							'unit'     => '',
							'price'    => 11000,
							'tax-rate' => '10%',
							'tax-type' => 'tax_included',
						),
					),
					'bill_tax_rate'     => 10, // old_tax_rate
					'bill_tax_type'     => 'tax_excluded',  // old_tax_type
					'bill_tax_fraction' => 'floor',
				),
				'expected'      => 11000,
			),
			array(
				'test_name'     => '10%税込 + 8%税込',
				'custom_fields' => array(
					'bill_items'        => array(
						array(
							'name'     => 'test',
							'count'    => 1,
							'unit'     => '',
							'price'    => 11000,
							'tax-rate' => '10%',
							'tax-type' => 'tax_included',
						),
						array(
							'name'     => 'test',
							'count'    => 1,
							'unit'     => '',
							'price'    => 10800,
							'tax-rate' => '8%',
							'tax-type' => 'tax_included',
						),
					),
					'bill_tax_fraction' => 'floor',
				),
				'expected'      => 21800,
			),

		);

		foreach ( $test_array as $test_item ) {
			$post_id = wp_insert_post( $post_data );
			foreach ( $test_item['custom_fields'] as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
			$post   = get_post( $post_id );
			$actual = bill_vektor_invoice_total_tax( $post );
			print PHP_EOL;
			print 'test_name  :' . $actual . PHP_EOL;
			print 'actual  :' . $actual . PHP_EOL;
			print 'expected :' . $test_item['expected'] . PHP_EOL;
			$this->assertEquals( $test_item['expected'], $actual );
			wp_delete_post( $post_id, true );
		}
	}

}
