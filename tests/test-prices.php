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
class PriceTest extends WP_UnitTestCase {

	/**
	 * 価格テスト.
	 */
	function test_number() {

		$test_array = array(

			array(
				'number_input'   => 2,
				'number_correct' => 2,
			),
			// 価格の四捨五入
			array(
				'number_input'   => '2,000',
				'number_correct' => 2000,
			),
			// 小数点はそのまま
			array(
				'number_input'   => '2.75',
				'number_correct' => '2.75',
			),
			// 全角を半角に
			array(
				'number_input'   => '２．７５',
				'number_correct' => '2.75',
			),
			// 全角を半角に
			array(
				'number_input'   => '２，０００',
				'number_correct' => 2000,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Item Number' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $key => $test_value ) {

			// 価格を取得
			$number = bill_item_number( $test_value['number_input'] );
			$this->assertEquals( $test_value['number_correct'], $number );

			// print PHP_EOL;
			// print 'number         :' . $number . PHP_EOL;
			// print 'number_correct :' . $test_value['number_correct'] . PHP_EOL;
		}
	}

	/**
	 * 単価テスト.
	 */
	function test_bill_vektor_invoice_unit_plice() {

		$test_array = array(
			array(
				'price'    => 1080,
				'tax_rate' => 0.08,
				'tax_type' => 'tax_included',
				'correct'  => 1000,
			),
			array(
				'price'    => 1100,
				'tax_rate' => 0.1,
				'tax_type' => 'tax_included',
				'correct'  => 1000,
			),
			array(
				'price'    => 1000,
				'tax_rate' => 0.08,
				'tax_type' => 'tax_excluded',
				'correct'  => 1000,
			),
			array(
				'price'    => 1000,
				'tax_rate' => 0.10,
				'tax_type' => 'tax_excluded',
				'correct'  => 1000,
			),
			array(
				'price'    => 6000,
				'tax_rate' => 0.10,
				'tax_type' => 'tax_included',
				'correct'  => 5455,
			),
			array(
				'price'    => 6000,
				'tax_rate' => 0.10,
				'tax_type' => 'tax_included_ceil',
				'correct'  => 5455,
			),
			array(
				'price'    => 6000,
				'tax_rate' => 0.10,
				'tax_type' => 'tax_included_floor',
				'correct'  => 5454,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Unit Plice' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_invoice_unit_plice( $test_value['price'], $test_value['tax_rate'], $test_value['tax_type'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			// print PHP_EOL;
			// print 'return  :' . $return . PHP_EOL;
			// print 'correct :' . $correct . PHP_EOL;
		}
	}

	/**
	 * 税抜金額テスト.
	 */
	function test_bill_vektor_invoice_total_plice() {

		$test_array = array(
			array(
				'unit_price' => 1000,
				'count'      => 10,
				'correct'    => 10000,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Total Plice' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_invoice_total_plice( $test_value['unit_price'], $test_value['count'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			// print PHP_EOL;
			// print 'return  :' . $return . PHP_EOL;
			// print 'correct :' . $correct . PHP_EOL;
		}
	}

	/**
	 * 消費税額テスト.
	 */
	function test_bill_vektor_invoice_tax_plice() {

		$test_array = array(
			array(
				'total_price' => 10000,
				'tax_rate'    => 0.08,
				'correct'     => 800,
			),
			array(
				'total_price' => 10000,
				'tax_rate'    => 0.1,
				'correct'     => 1000,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Tax Plice' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_invoice_tax_plice( $test_value['total_price'], $test_value['tax_rate'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			// print PHP_EOL;
			// print 'return  :' . $return . PHP_EOL;
			// print 'correct :' . $correct . PHP_EOL;
		}
	}

	/**
	 * 品目1件分の消費税額計算テスト（共通ヘルパー）.
	 */
	function test_bill_vektor_invoice_item_tax() {

		$test_array = array(
			// 税抜入力・端数なし
			array(
				'test_condition_name' => '税抜入力（10%）で端数が出ない場合 => 税抜合計 × 税率',
				'tax_type'            => 'tax_excluded',
				'original_price'      => 1000,
				'count'               => 1,
				'total_price'         => 1000,
				'tax_rate'            => 0.1,
				'correct'             => 100,
			),
			// 税抜入力・端数あり（丸め処理はこの関数では行わず小数のまま返す）
			array(
				'test_condition_name' => '税抜入力（10%）で端数が出る場合 => 丸めずに小数のまま返す',
				'tax_type'            => 'tax_excluded',
				'original_price'      => 333,
				'count'               => 1,
				'total_price'         => 333,
				'tax_rate'            => 0.1,
				'correct'             => 33.3,
			),
			// 税込入力（四捨五入）・端数なし
			array(
				'test_condition_name' => '税込入力（tax_included, 10%）で端数が出ない場合 => 元の税込合計 - 税抜合計',
				'tax_type'            => 'tax_included',
				'original_price'      => 1100,
				'count'               => 1,
				'total_price'         => 1000,
				'tax_rate'            => 0.1,
				'correct'             => 100,
			),
			// 税込入力（四捨五入）・端数あり（PR #266 で修正された 6000円/10%のケース）
			array(
				'test_condition_name' => '税込入力（tax_included, 10%）で税抜変換に端数が出る場合 => 税込-税抜方式で1円ずれない',
				'tax_type'            => 'tax_included',
				'original_price'      => 6000,
				'count'               => 1,
				'total_price'         => 5455, // round(6000 / 1.1)
				'tax_rate'            => 0.1,
				'correct'             => 545, // 6000 - 5455
			),
			// 税込入力（切り上げ）・端数あり
			array(
				'test_condition_name' => '税込入力（tax_included_ceil, 10%）で税抜変換に端数が出る場合 => 税込-税抜方式で1円ずれない',
				'tax_type'            => 'tax_included_ceil',
				'original_price'      => 6000,
				'count'               => 1,
				'total_price'         => 5455, // ceil(6000 / 1.1)
				'tax_rate'            => 0.1,
				'correct'             => 545, // 6000 - 5455
			),
			// 税込入力（切り捨て）・端数あり
			array(
				'test_condition_name' => '税込入力（tax_included_floor, 10%）で税抜変換に端数が出る場合 => 税込-税抜方式で1円ずれない',
				'tax_type'            => 'tax_included_floor',
				'original_price'      => 6000,
				'count'               => 1,
				'total_price'         => 5454, // floor(6000 / 1.1)
				'tax_rate'            => 0.1,
				'correct'             => 546, // 6000 - 5454
			),
			// 個数が複数の場合（税込入力）
			array(
				'test_condition_name' => '税込入力（tax_included, 8%）で個数が複数の場合 => 元の税込合計 - 税抜合計',
				'tax_type'            => 'tax_included',
				'original_price'      => 1080,
				'count'               => 3,
				'total_price'         => 3000,
				'tax_rate'            => 0.08,
				'correct'             => 240, // (1080 * 3) - 3000
			),
			// 非課税（0%）
			array(
				'test_condition_name' => '税抜入力で税率が 0% の場合 => 消費税額は 0',
				'tax_type'            => 'tax_excluded',
				'original_price'      => 6000,
				'count'               => 1,
				'total_price'         => 6000,
				'tax_rate'            => 0,
				'correct'             => 0,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Item Tax' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 消費税額を取得
			$return  = bill_vektor_invoice_item_tax( $test_value['tax_type'], $test_value['original_price'], $test_value['count'], $test_value['total_price'], $test_value['tax_rate'] );
			$correct = $test_value['correct'];
			// 浮動小数点演算の誤差を許容するため assertEqualsWithDelta を使用（丸め処理はこの関数の責務ではないため小数のまま比較する）
			$this->assertEqualsWithDelta( $correct, $return, 0.0001, $test_value['test_condition_name'] );
		}
	}

	/**
	 * 税込金額テスト.
	 */
	function test_bill_vektor_invoice_full_plice() {

		$test_array = array(
			array(
				'total_price' => 10000,
				'tax_price'   => 800,
				'correct'     => 10800,
			),
			array(
				'total_price' => 10000,
				'tax_price'   => 1000,
				'correct'     => 11000,
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Full Plice' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_invoice_full_plice( $test_value['total_price'], $test_value['tax_price'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			// print PHP_EOL;
			// print 'return  :' . $return . PHP_EOL;
			// print 'correct :' . $correct . PHP_EOL;
		}
	}

	/**
	 * 税率修正テスト.
	 */
	function test_bill_vektor_fix_tax_rate() {

		$test_array = array(
			array(
				'old_tax_rate' => 10,
				'post_date'    => '2019-10-01 00:00:00',
				'correct'      => '10%',
			),
			array(
				'old_tax_rate' => 8,
				'post_date'    => '2019-09-30 23:59:59',
				'correct'      => '8%',
			),
			// 日付に関係なく税率指定がある場合 8%
			array(
				'old_tax_rate' => 8,
				'post_date'    => '2020-10-01 00:00:00',
				'correct'      => '8%',
			),
			// 日付に関係なく税率指定がある場合 10%
			array(
				'old_tax_rate' => 10,
				'post_date'    => '2019-09-30 23:59:59',
				'correct'      => '10%',
			),
			// 古い指定がない場合 10%
			array(
				'old_tax_rate' => null,
				'post_date'    => '2019-10-01 00:00:00',
				'correct'      => '10%',
			),
			// 古い指定がない場合 8%
			array(
				'old_tax_rate' => null,
				'post_date'    => '2019-09-30 23:59:59',
				'correct'      => '8%',
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Fix Tax Rate' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_fix_tax_rate( $test_value['old_tax_rate'], $test_value['post_date'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			// print PHP_EOL;
			// print 'return  :' . $return . PHP_EOL;
			// print 'correct :' . $correct . PHP_EOL;
		}
	}

	/**
	 * 税抜・税込修正テスト.
	 */
	function test_bill_vektor_fix_tax_type() {

		$test_array = array(
			array(
				'old_tax_type' => 'tax_not_auto',
				'correct'      => 'tax_included',
			),
			array(
				'old_tax_type' => 'tax_auto',
				'correct'      => 'tax_excluded',
			),
			array(
				'old_tax_type' => null,
				'correct'      => 'tax_excluded',
			),
		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Fix Tax Type' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_fix_tax_type( $test_value['old_tax_type'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			// print PHP_EOL;
			// print 'return  :' . $return . PHP_EOL;
			// print 'correct :' . $correct . PHP_EOL;
		}
	}
}
