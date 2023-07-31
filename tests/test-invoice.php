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
                'price' => 1000
            ),
            array(
                'name'  => 'item-002',
                'count' => '2',
                'unit'  => '個',
                'price' => 2000
            ),
            array(
                'name'  => 'item-003',
                'count' => '3',
                'unit'  => '個',
                'price' => 3000
            ),
        );

        // 古いカスタムフィールドを使っていた場合の品目リスト（8%税込み）
        $old_bill_item_tax8_include = array(
            array(
                'name'  => 'item-001',
                'count' => '1',
                'unit'  => '個',
                'price' => 1080
            ),
            array(
                'name'  => 'item-002',
                'count' => '2',
                'unit'  => '個',
                'price' => 2160
            ),
            array(
                'name'  => 'item-003',
                'count' => '3',
                'unit'  => '個',
                'price' => 3240
            ),
        );

        // 古いカスタムフィールドを使っていた場合の品目リスト（10%税込み）
        $old_bill_item_tax10_include = array(
            array(
                'name'  => 'item-001',
                'count' => '1',
                'unit'  => '個',
                'price' => 1100
            ),
            array(
                'name'  => 'item-002',
                'count' => '2',
                'unit'  => '個',
                'price' => 2200
            ),
            array(
                'name'  => 'item-003',
                'count' => '3',
                'unit'  => '個',
                'price' => 3300
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

        // 古い消費税率（8%）と消費税（最後にまとめて自動計算する）を選んでいた場合
        $posts['empty'] = wp_insert_post(
            array(
                'post_title'   => 'Old 8% Tax Exclude',
                'post_content' => '',
                'post_type'    => 'estimate',
                'post_status'  => 'publish'
            )
        );
        add_post_meta( $posts['empty'], 'bill_items', $old_bill_item_tax_exclude );

        // 古い消費税率（8%）と消費税（最後にまとめて自動計算する）を選んでいた場合
        $posts['old-8tax-exclude'] = wp_insert_post(
            array(
                'post_title'   => 'Old 8% Tax Exclude',
                'post_content' => '',
                'post_type'    => 'estimate',
                'post_status'  => 'publish'
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
                'post_status'  => 'publish'
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
                'post_status'  => 'publish'
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
                'post_status'  => 'publish'
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
                'post_status'  => 'publish'
            )
        );
        add_post_meta( $posts['invoice'], 'bill_items', $bill_item_invoice );

        return $posts;

    }

    public function test_bill_vektor_invoice_each_tax() {

        $data = self::setup_data();

        $test_array = array(
            array(
                'post_id'  => $data['empty'],
                'cortrect' => array(
                    '10%' => array(
                        'rate'  => '10%対象',
                        'price' => 14000,
                        'tax'   => 1400,
                        'total' => 15400,
                    )
                )
            ),
            array(
                'post_id'  => $data['old-8tax-exclude'],
                'cortrect' => array(
                    '8%' => array(
                        'rate'  => '8%対象',
                        'price' => 14000,
                        'tax'   => 1120,
                        'total' => 15120,
                    )
                )
            ),
            array(
                'post_id'  => $data['old-10tax-exclude'],
                'cortrect' => array(
                    '10%' => array(
                        'rate'  => '10%対象',
                        'price' => 14000,
                        'tax'   => 1400,
                        'total' => 15400,
                    )
                )
            ),
            array(
                'post_id'  => $data['old-8tax-include'],
                'cortrect' => array(
                    '8%' => array(
                        'rate'  => '8%対象',
                        'price' => 14000,
                        'tax'   => 1120,
                        'total' => 15120,
                    )
                )
            ),
            array(
                'post_id'  => $data['old-10tax-include'],
                'cortrect' => array(
                    '10%' => array(
                        'rate'  => '10%対象',
                        'price' => 14000,
                        'tax'   => 1400,
                        'total' => 15400,
                    )
                )
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
                    '8%' => array(
                        'rate'  => '8%対象',
                        'price' => 10000,
                        'tax'   => 800,
                        'total' => 10800,
                    )
                )
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
            print 'return------------------------------------' . PHP_EOL;
			var_dump( $return ) . PHP_EOL;
			print 'correct------------------------------------' . PHP_EOL;
			var_dump( $correct ) . PHP_EOL;
            $this->assertEquals( $correct, $return );
        }
    }

    public function test_bill_vektor_invoice_total_tax() {

        $data = self::setup_data();

        $test_array = array(
            array(
                'post_id'  => $data['empty'],
                'cortrect' => 15400
            ),
            array(
                'post_id'  => $data['old-8tax-exclude'],
                'cortrect' => 15120
            ),
            array(
                'post_id'  => $data['old-10tax-exclude'],
                'cortrect' => 15400
            ),
            array(
                'post_id'  => $data['old-8tax-include'],
                'cortrect' => 15120
            ),
            array(
                'post_id'  => $data['old-10tax-include'],
                'cortrect' => 15400
            ),
            array(
                'post_id'  => $data['invoice'],
                'cortrect' => 32800
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
            print 'return: ' . $return . PHP_EOL;
			print 'correct: ' . $correct . PHP_EOL;
            $this->assertEquals( $correct, $return );
        }
    }
}