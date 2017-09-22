<?php
/**
 * Class PriceTest
 *
 * @package BillVektor
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
				'number_input' => 2,
				'number_correct' => 2,
			),
			// 価格の四捨五入
			array(
				'number_input' => '2,000',
				'number_correct' => 2000,
			),
			// 小数点はそのまま
			array(
				'number_input' => '2.75',
				'number_correct' => '2.75',
			),
			// 全角を半角に
			array(
				'number_input' => '２．７５',
				'number_correct' => '2.75',
			),
			// 全角を半角に
			array(
				'number_input' => '２，０００',
				'number_correct' => 2000,
			),
			);

		foreach ( $test_array as $key => $test_value) {

			// 価格を取得
			$number = bill_item_number( $test_value['number_input'] );

			// 
			$this->assertEquals( $test_value['number_correct'], $number );

			print PHP_EOL;
			print 'number         :'.$number.PHP_EOL;
			print 'number_correct :'.$test_value['number_correct'].PHP_EOL;
		}
	}

	/**
	 * 価格テスト.
	 */
	function test_price() {

		$test_array = array(
			// 
			array(
				'item_count' => 2,
				'item_price' => 400,
				'item_price_correct' => 800,
			),
			// 価格の四捨五入
			array(
				'item_count' => 2.75,
				'item_price' => 5,
				'item_price_correct' => 14,
			),
			);

		foreach ( $test_array as $key => $test_value) {

			// 価格を取得
			$item_price_total = bill_item_price_total( $test_value['item_count'], $test_value['item_price'] );

			// 
			$this->assertEquals( $test_value['item_price_correct'], $item_price_total );

			print PHP_EOL;
			print 'item_price            :'.$item_price_total.PHP_EOL;
			print 'item_price_correct    :'.$test_value['item_price_correct'].PHP_EOL;
		}
	}
}
