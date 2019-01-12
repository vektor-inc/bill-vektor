<?php
/**
 * Class PriceTest
 *
 * @package BillVektor
 */
/*
$ cd $(wp theme path --dir bill-vektor)
$ bash bin/install-wp-tests.sh wordpress_test root 'WordPress' localhost latest
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

		foreach ( $test_array as $key => $test_value ) {

			// 価格を取得
			$number = bill_item_number( $test_value['number_input'] );
						$this->assertEquals( $test_value['number_correct'], $number );

			print PHP_EOL;
			print 'number         :' . $number . PHP_EOL;
			print 'number_correct :' . $test_value['number_correct'] . PHP_EOL;
		}
	}

	/**
	 * 価格テスト.
	 */
	function test_price() {

		$test_array = array(
			array(
				'item_count'         => 2,
				'item_price'         => 400,
				'item_price_correct' => 800,
			),
			// 価格の四捨五入
			array(
				'item_count'         => '2.７５',
				'item_price'         => '５',
				'item_price_correct' => 14,
			),
		);

		foreach ( $test_array as $key => $test_value ) {

			// 価格を取得
			$item_price_total = bill_item_price_total( bill_item_number( $test_value['item_count'] ), bill_item_number( $test_value['item_price'] ) );
						$this->assertEquals( $test_value['item_price_correct'], $item_price_total );

			print PHP_EOL;
			print 'item_price            :' . $item_price_total . PHP_EOL;
			print 'item_price_correct    :' . $test_value['item_price_correct'] . PHP_EOL;
		} // foreach ( $test_array as $key => $test_value) {
	} // function test_price() {


	/**
	 * 消費税の計算が正しいかどうか？
	 */
	function test_tax() {

		$test_array = array(
			// 消費税の端数が 0.4 以下だった場合
			array(
				'price'       => 92,
				'tax_correct' => 7,
			),
			// 消費税の端数が 0.5 以上だった場合
			array(
				'price'       => 98,
				'tax_correct' => 7,
			),
		);

		foreach ( $test_array as $key => $test_value ) {

			// 価格を取得
			$tax = bill_tax( $test_value['price'] );
						$this->assertEquals( $test_value['tax_correct'], $tax );

			print PHP_EOL;
			print 'price:' . $tax . PHP_EOL;
			print 'tax_correct:' . $test_value['tax_correct'] . PHP_EOL;
		} // foreach ( $test_array as $key => $test_value) {
	} // function test_price() {


	// 消費税が正しく計算されるかどうか
	// function test_bill_total_add_tax() {
	// $test_array = array(
	// 消費税を計算した時の端数が0.4以下のとき
	// array(
	//
	// ),
	// 消費税を計算した時の端数が0.5以下のとき
	// array(
	//
	// ),
	// );
	// }
}
