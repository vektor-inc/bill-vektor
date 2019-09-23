<?php
/**
 * Class taxTest
 *
 * @package BillVektor
 */

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
class taxTest extends WP_UnitTestCase {
	/**
	 * 消費税の計算が正しいかどうか？
	 */
	function test_taxRate() {

		$test_array = array(
			// 請求書で発行日が20190930より前の場合
			// 消費税率指定がなければ8%で指定
			array(
				'bill_tax_rate' => null,
				'post_date'     => '2019-09-30 00:00:01',
				'correct'       => 0.08,
			),
			// 請求書で発行日が20191001より後の場合
			// 消費税率指定がなければ10%で指定
			array(
				'bill_tax_rate' => null,
				'post_date'     => '2019-10-01 00:00:00',
				'correct'       => 0.1,
			),
			// 見積書で発行が 9月以前
			// 10%指定のある場合
			array(
				'bill_tax_rate' => '10',
				'post_date'     => '2019-09-30 00:00:00',
				'correct'       => 0.1,
			),

		);

		print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'bill_tax_rate' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;

		foreach ( $test_array as $key => $test_value ) {
			$num     = $key + 1;
			$post    = array(
				'post_title'   => 'test post :' . $num,
				'post_content' => 'test content',
				'post_date'    => $test_value['post_date'],
			);
			$post_id = wp_insert_post( $post );
			if ( $test_value['bill_tax_rate'] ) {
				update_post_meta( $post_id, 'bill_tax_rate', $test_value['bill_tax_rate'] );
			}

			$post = get_post( $post_id );

			// 価格を取得
			$return = bill_tax_rate( $post_id );
			$this->assertEquals( $test_value['correct'], $return );

			print PHP_EOL;
			print 'return :' . $return . PHP_EOL;
			print 'correct:' . $test_value['correct'] . PHP_EOL;
			wp_delete_post( $post_id, true );
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
				'tax_rate'    => 0.08,
				'tax_correct' => 7,
			),
			// 消費税の端数が 0.5 以上だった場合
			array(
				'price'       => 98,
				'tax_rate'    => 0.08,
				'tax_correct' => 7,
			),
		);

		foreach ( $test_array as $key => $test_value ) {

			// 価格を取得
			$tax = bill_tax( $test_value['price'], $test_value['tax_rate'] );
						$this->assertEquals( $test_value['tax_correct'], $tax );

			print PHP_EOL;
			print 'price:' . $tax . PHP_EOL;
			print 'tax_correct:' . $test_value['tax_correct'] . PHP_EOL;
		} // foreach ( $test_array as $key => $test_value) {
	} // function test_price() {

}
