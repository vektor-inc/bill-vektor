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

			print PHP_EOL;
			print 'number         :' . $number . PHP_EOL;
			print 'number_correct :' . $test_value['number_correct'] . PHP_EOL;
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
				'correct'  => 1000
			),
			array(
				'price'    => 1100,
				'tax_rate' => 0.1,
				'tax_type' => 'tax_included',
				'correct'  => 1000
			),
			array(
				'price'    => 1000,
				'tax_rate' => 0.08,
				'tax_type' => 'tax_excluded',
				'correct'  => 1000
			),
			array(
				'price'    => 1000,
				'tax_rate' => 0.10,
				'tax_type' => 'tax_excluded',
				'correct'  => 1000
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

			print PHP_EOL;
			print 'return  :' . $return . PHP_EOL;
			print 'correct :' . $correct . PHP_EOL;
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
				'correct'    => 10000
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

			print PHP_EOL;
			print 'return  :' . $return . PHP_EOL;
			print 'correct :' . $correct . PHP_EOL;
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
				'correct'     => 800
			),
			array(
				'total_price' => 10000,
				'tax_rate'    => 0.1,
				'correct'     => 1000
			),
		);

        print PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print 'Test Bill Vektor Invoice Tax Plice' . PHP_EOL;
		print '------------------------------------' . PHP_EOL;
		print PHP_EOL;

		foreach ( $test_array as $test_value ) {

			// 価格を取得
			$return  = bill_vektor_invoice_total_plice( $test_value['total_price'], $test_value['tax_rate'] );
			$correct = $test_value['correct'];
			$this->assertEquals( $correct, $return );

			print PHP_EOL;
			print 'return  :' . $return . PHP_EOL;
			print 'correct :' . $correct . PHP_EOL;
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
				'correct'     => 10800
			),
			array(
				'total_price' => 10000,
				'tax_price'   => 1000,
				'correct'     => 11000
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

			print PHP_EOL;
			print 'return  :' . $return . PHP_EOL;
			print 'correct :' . $correct . PHP_EOL;
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
				'correct'      => '10%'
			),
			array(
				'old_tax_rate' => 8,
				'post_date'    => '2019-09-30 23:59:59',
				'correct'      => '8%'
			),
			// 日付に関係なく税率指定がある場合 8%
			array(
				'old_tax_rate' => 8,
				'post_date'    => '2020-10-01 00:00:00',
				'correct'      => '8%'
			),
			// 日付に関係なく税率指定がある場合 10%
			array(
				'old_tax_rate' => 10,
				'post_date'    => '2019-09-30 23:59:59',
				'correct'      => '10%'
			),
			// 古い指定がない場合 10%
			array(
				'old_tax_rate' => null,
				'post_date'    => '2019-10-01 00:00:00',
				'correct'      => '10%'
			),
			// 古い指定がない場合 8%
			array(
				'old_tax_rate' => null,
				'post_date'    => '2019-09-30 23:59:59',
				'correct'      => '8%'
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

			print PHP_EOL;
			print 'return  :' . $return . PHP_EOL;
			print 'correct :' . $correct . PHP_EOL;
		}
	}

	/**
	 * 税抜・税込修正テスト.
	 */
	function test_bill_vektor_fix_tax_type() {

		$test_array = array(
			array(
				'old_tax_type' => 'tax_not_auto',
				'correct'      => 'tax_included'
			),
			array(
				'old_tax_type' => 'tax_auto',
				'correct'      => 'tax_excluded'
			),
			array(
				'old_tax_type' => null,
				'correct'      => 'tax_excluded'
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

			print PHP_EOL;
			print 'return  :' . $return . PHP_EOL;
			print 'correct :' . $correct . PHP_EOL;
		}
	}

}
