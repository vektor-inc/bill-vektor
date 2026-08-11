<?php
/**
 * Class ClientHonorificTest
 *
 * 書類の敬称（御中など）を取得する bill_get_client_honorific() のテスト
 *
 * @package BillVektor
 */

/**
 * bill_get_client_honorific() のテスト
 *
 * 取引先（登録済）に敬称が登録されていればその敬称を返し、未登録・取引先が
 * 未設定/不正値の場合は既定の敬称「御中」を返すこと、取引先（イレギュラー）が
 * 入力されている場合は敬称を出さない（空文字を返す）ことを検証する。
 *
 * また、この関数は値を画面へ直接出力せず戻り値として返すこと
 * （呼び出し側の template-parts/doc/frame-bill.php・frame-estimate.php や
 * functions.php の bill_title_custom() が esc_html() でエスケープしてから
 * 使うため、ここで直接出力すると二重出力やエスケープ漏れの原因になる）も検証する。
 */
class ClientHonorificTest extends WP_UnitTestCase {

	/**
	 * テスト対象の請求書（post）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $bill_id;

	/**
	 * 敬称「様」が登録されている取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $client_id;

	/**
	 * 取引先に登録した敬称
	 *
	 * @var string
	 */
	private $client_honorific = '様';

	/**
	 * 敬称が登録されていない取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $no_honorific_client_id;

	/**
	 * 取引先ではない投稿（固定ページ）の投稿IDを保持する
	 *
	 * bill_client に取引先以外の投稿IDが保存されている状態を再現するために使う。
	 *
	 * @var int
	 */
	private $non_client_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * テスト用の請求書と、敬称あり・敬称なしの取引先、
	 * および取引先ではない投稿を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// 敬称「様」が登録されている取引先を作成
		$this->client_id = wp_insert_post(
			array(
				'post_title'  => '株式会社テスト取引先',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		update_post_meta( $this->client_id, 'client_honorific', $this->client_honorific );

		// 敬称が登録されていない取引先を作成
		$this->no_honorific_client_id = wp_insert_post(
			array(
				'post_title'  => '有限会社敬称なし',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		/*
		 * 取引先ではない投稿（非公開の固定ページ）を作成する。
		 * bill_client に取引先以外の投稿IDが保存されている状態を再現するために使う。
		 */
		$this->non_client_id = wp_insert_post(
			array(
				'post_title'  => '取引先ではない非公開ページ',
				'post_status' => 'private',
				'post_type'   => 'page',
			)
		);

		// テスト用の書類（請求書）を作成
		$this->bill_id = wp_insert_post(
			array(
				'post_title'  => 'テスト用請求書の件名',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * 作成した投稿を削除する。
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_delete_post( $this->bill_id, true );
		wp_delete_post( $this->client_id, true );
		wp_delete_post( $this->no_honorific_client_id, true );
		wp_delete_post( $this->non_client_id, true );

		parent::tear_down();
	}

	/**
	 * bill_get_client_honorific() のテスト
	 *
	 * 取引先に敬称が登録されていればその敬称を返し、未登録・取引先が未設定/
	 * 不正値の場合に既定の敬称「御中」を返すこと、取引先（イレギュラー）が
	 * 入力されている場合に敬称を出さない（空文字を返す）ことを検証する。
	 *
	 * @return void
	 */
	public function test_bill_get_client_honorific() {

		// テストの配列（bill_client の 'client_id' 等の文字列は set_up で作成した投稿IDに差し替える）
		$test_cases = array(
			array(
				'test_condition_name' => '取引先に敬称が登録されている場合 => 登録された敬称',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'client_id',
				),
				'expected'            => $this->client_honorific,
			),
			array(
				'test_condition_name' => '取引先が選択されているが敬称が未登録の場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'no_honorific_client_id',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => '取引先が両方とも未設定の場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => '',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => 'bill_client が 0 の場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => '0',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => '削除済の取引先IDが bill_client に残っている場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => '99999999',
				),
				'expected'            => '御中',
			),
			array(
				/*
				 * 実在する投稿IDでも取引先（client）以外を指している場合に、
				 * その投稿のメタ値（無関係な値）を敬称として読まないことを確認する。
				 */
				'test_condition_name' => 'bill_client が実在する取引先以外の投稿（固定ページ）を指している場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'non_client_id',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => 'bill_client に配列が入っている場合 => 既定の敬称「御中」（型ガード）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => array( 'invalid' ),
				),
				'expected'            => '御中',
			),
			array(
				/*
				 * 数値以外の文字列は (int) キャストすると先頭の数字だけが残るため、
				 * 型ガードが無いと実在する取引先の敬称を返してしまう。
				 */
				'test_condition_name' => 'bill_client に数値以外の文字列が入っている場合 => 既定の敬称「御中」（型ガード・敬称を返さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'client_id_with_suffix',
				),
				'expected'            => '御中',
			),
			array(
				/*
				 * get_post_meta() は内部の absint() で符号を落とすため、
				 * 型ガードが無いと負数を素通しした場合に絶対値のIDの取引先
				 * （＝別の取引先）の敬称が表示されてしまう。
				 * 修正前のコードはこのケースで実際に「様」（別の取引先の敬称）を
				 * 返してしまい、このテストが red になることを確認済み。
				 */
				'test_condition_name' => 'bill_client に敬称ありの取引先IDの負数が入っている場合 => 既定の敬称「御中」（別の取引先の敬称を返さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'client_id_negative',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => 'bill_client に敬称ありの取引先IDの小数が入っている場合 => 既定の敬称「御中」（別の取引先の敬称を返さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'client_id_decimal',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => 空文字（敬称を出さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '個人事業主テスト',
					'bill_client'              => 'client_id',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されていて bill_client が不正値の場合 => 空文字（敬称を出さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '個人事業主テスト',
					'bill_client'              => array( 'invalid' ),
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// 書類のカスタムフィールドを設定（bill_client は set_up で作成した投稿のIDや不正値に差し替える）
			foreach ( $case['conditions'] as $meta_name => $meta_value ) {
				if ( 'client_id' === $meta_value ) {
					$meta_value = $this->client_id;
				}
				if ( 'no_honorific_client_id' === $meta_value ) {
					$meta_value = $this->no_honorific_client_id;
				}
				if ( 'non_client_id' === $meta_value ) {
					$meta_value = $this->non_client_id;
				}
				if ( 'client_id_with_suffix' === $meta_value ) {
					// 実在する取引先IDに文字列を付けた数値以外の値（(int) キャストするとIDに戻る）
					$meta_value = $this->client_id . 'abc';
				}
				if ( 'client_id_negative' === $meta_value ) {
					// 実在する取引先IDの負数（absint() を通すとIDに戻る）
					$meta_value = '-' . $this->client_id;
				}
				if ( 'client_id_decimal' === $meta_value ) {
					// 実在する取引先IDに小数部を付けた値（absint() を通すとIDに戻る）
					$meta_value = $this->client_id . '.9';
				}
				update_post_meta( $this->bill_id, $meta_name, $meta_value );
			}

			// テスト対象の投稿オブジェクトを取得
			$post = get_post( $this->bill_id );

			/*
			 * 画面へ直接出力していないことを確認するため、出力バッファで囲んで実行する。
			 * 直接出力していると、呼び出し側の esc_html( bill_get_client_honorific( $post ) )
			 * がエスケープ前の生値をそのまま出力してしまい、エスケープ漏れになる。
			 */
			ob_start();
			$actual = bill_get_client_honorific( $post );
			$echoed  = ob_get_clean();

			// 期待値テスト（戻り値）
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// 画面へ直接出力していないことのテスト
			$this->assertSame( '', $echoed, $case['test_condition_name'] . '（戻り値ではなく画面へ直接出力していないこと）' );

			// 書類のカスタムフィールドを削除
			foreach ( array_keys( $case['conditions'] ) as $meta_name ) {
				delete_post_meta( $this->bill_id, $meta_name );
			}
		}
	}
}
