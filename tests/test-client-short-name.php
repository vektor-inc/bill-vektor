<?php
/**
 * Class ClientShortNameTest
 *
 * 表示用の取引先名（省略名優先）を取得する bill_get_client_short_name() のテスト
 *
 * @package BillVektor
 */

/**
 * bill_get_client_short_name() のテスト
 *
 * 省略名の優先・省略名が無い場合のフォールバック・取引先未設定時の空文字・
 * 取引先IDが不正値の場合の型ガードを検証する。
 */
class ClientShortNameTest extends WP_UnitTestCase {

	/**
	 * テスト対象の請求書（post）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $bill_id;

	/**
	 * 書類の件名（取引先未設定時に件名が漏れないか確認するために保持する）
	 *
	 * @var string
	 */
	private $bill_title = 'テスト用請求書の件名';

	/**
	 * 省略名が登録されている取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $client_id;

	/**
	 * 省略名が登録されている取引先の投稿タイトル
	 *
	 * @var string
	 */
	private $client_title = '株式会社テスト取引先';

	/**
	 * 取引先に登録した省略名
	 *
	 * @var string
	 */
	private $client_short_name = 'テスト取引先';

	/**
	 * 省略名が登録されていない取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $no_short_name_client_id;

	/**
	 * 省略名が登録されていない取引先の投稿タイトル
	 *
	 * @var string
	 */
	private $no_short_name_client_title = '有限会社省略名なし';

	/**
	 * テスト前の共通セットアップ
	 *
	 * テスト用の書類と、省略名あり・省略名なしの取引先を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// 省略名が登録されている取引先を作成
		$this->client_id = wp_insert_post(
			array(
				'post_title'  => $this->client_title,
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		update_post_meta( $this->client_id, 'client_short_name', $this->client_short_name );

		// 省略名が登録されていない取引先を作成
		$this->no_short_name_client_id = wp_insert_post(
			array(
				'post_title'  => $this->no_short_name_client_title,
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		// テスト用の書類（請求書）を作成
		$this->bill_id = wp_insert_post(
			array(
				'post_title'  => $this->bill_title,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * 作成した投稿とグローバルの $post を元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		// 一覧画面・CSV エクスポートを模してセットしたグローバルの $post を破棄
		unset( $GLOBALS['post'] );

		wp_delete_post( $this->bill_id, true );
		wp_delete_post( $this->client_id, true );
		wp_delete_post( $this->no_short_name_client_id, true );

		parent::tear_down();
	}

	/**
	 * bill_get_client_short_name() のテスト
	 *
	 * 省略名が登録されていれば省略名を返し、無ければ通常の取引先名を返すこと、
	 * 取引先が未設定・不正値の場合に空文字（書類自身の件名を返さない）ことを検証する。
	 *
	 * @return void
	 */
	public function test_bill_get_client_short_name() {

		// テストの配列（bill_client の 'client_id' 等の文字列は set_up で作成した投稿IDに差し替える）
		$test_cases = array(
			array(
				'test_condition_name' => '省略名が登録されている取引先を選択している場合 => 省略名',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'client_id',
					),
				),
				'expected'            => $this->client_short_name,
			),
			array(
				'test_condition_name' => '省略名が登録されていない取引先を選択している場合 => 取引先の投稿タイトル',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'no_short_name_client_id',
					),
				),
				'expected'            => $this->no_short_name_client_title,
			),
			array(
				'test_condition_name' => '省略名が空文字で登録されている取引先を選択している場合 => 取引先の投稿タイトル',
				'conditions'          => array(
					'post_meta'        => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'no_short_name_client_id',
					),
					'client_post_meta' => array(
						'client_short_name' => '',
					),
				),
				'expected'            => $this->no_short_name_client_title,
			),
			array(
				'test_condition_name' => '省略名ありの取引先と取引先（イレギュラー）の両方が入力されている場合 => 省略名を優先',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '個人事業主テスト',
						'bill_client'             => 'client_id',
					),
				),
				'expected'            => $this->client_short_name,
			),
			array(
				'test_condition_name' => '取引先（登録済）が未設定で取引先（イレギュラー）のみ入力されている場合 => イレギュラーの入力値',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '個人事業主テスト',
						'bill_client'             => '',
					),
				),
				'expected'            => '個人事業主テスト',
			),
			array(
				'test_condition_name' => '取引先が両方とも未設定の場合 => 空文字（書類自身の件名を返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => '',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '取引先が未登録で bill_client が 0 の場合 => 空文字',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => '0',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '削除済の取引先IDが bill_client に残っている場合 => 空文字',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => '99999999',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'bill_client に配列が入っている場合 => 空文字（型ガード）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => array( 'invalid' ),
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'bill_client にオブジェクトが入っている場合 => 空文字（型ガード）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'invalid_object',
					),
				),
				'expected'            => '',
			),
			array(
				/*
				 * 数値以外の文字列は (int) キャストすると先頭の数字だけが残るため、
				 * 型ガードが無いと実在する取引先の省略名を返してしまう。
				 * （省略名ありの取引先IDに文字を付けた値で検証する）
				 */
				'test_condition_name' => 'bill_client に数値以外の文字列が入っている場合 => 空文字（型ガード・省略名を返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'client_id_with_suffix',
					),
				),
				'expected'            => '',
			),
			array(
				/*
				 * 省略名が無い取引先の場合は投稿タイトルへのフォールバック経路を通るため、
				 * そちらにも型ガードが効いていることを検証する。
				 */
				'test_condition_name' => 'bill_client に数値以外の文字列が入っている場合 => 空文字（型ガード・取引先の投稿タイトルも返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'no_short_name_client_id_with_suffix',
					),
				),
				'expected'            => '',
			),
			array(
				/*
				 * get_post_meta() は内部の absint() で符号を落とすため、
				 * 負数を素通しすると絶対値のIDの取引先の省略名が表示されてしまう。
				 */
				'test_condition_name' => 'bill_client に省略名ありの取引先IDの負数が入っている場合 => 空文字（別の取引先の省略名を返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'client_id_negative',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'bill_client に省略名ありの取引先IDの小数が入っている場合 => 空文字（別の取引先の省略名を返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'client_id_decimal',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'bill_client が不正値でも取引先（イレギュラー）が入力されている場合 => イレギュラーの入力値',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '個人事業主テスト',
						'bill_client'             => array( 'invalid' ),
					),
				),
				'expected'            => '個人事業主テスト',
			),
		);

		foreach ( $test_cases as $case ) {
			// 取引先側のカスタムフィールドを上書きするケース（省略名が空文字で保存されている場合など）
			if ( isset( $case['conditions']['client_post_meta'] ) ) {
				foreach ( $case['conditions']['client_post_meta'] as $meta_name => $meta_value ) {
					update_post_meta( $this->no_short_name_client_id, $meta_name, $meta_value );
				}
			}

			// 書類のカスタムフィールドを設定（bill_client は set_up で作成した投稿のIDや不正値に差し替える）
			foreach ( $case['conditions']['post_meta'] as $meta_name => $meta_value ) {
				if ( 'client_id' === $meta_value ) {
					$meta_value = $this->client_id;
				}
				if ( 'no_short_name_client_id' === $meta_value ) {
					$meta_value = $this->no_short_name_client_id;
				}
				if ( 'client_id_with_suffix' === $meta_value ) {
					// 実在する取引先IDに文字列を付けた数値以外の値（(int) キャストするとIDに戻る）
					$meta_value = $this->client_id . 'abc';
				}
				if ( 'no_short_name_client_id_with_suffix' === $meta_value ) {
					$meta_value = $this->no_short_name_client_id . 'abc';
				}
				if ( 'client_id_negative' === $meta_value ) {
					// 実在する取引先IDの負数（absint() を通すとIDに戻る）
					$meta_value = '-' . $this->client_id;
				}
				if ( 'client_id_decimal' === $meta_value ) {
					// 実在する取引先IDに小数部を付けた値（absint() を通すとIDに戻る）
					$meta_value = $this->client_id . '.9';
				}
				if ( 'invalid_object' === $meta_value ) {
					// 数値以外のオブジェクトが保存されている状態を再現する
					$meta_value          = new stdClass();
					$meta_value->invalid = true;
				}
				update_post_meta( $this->bill_id, $meta_name, $meta_value );
			}

			/*
			 * 書類一覧（index.php）や CSV エクスポートではグローバルの $post に
			 * ループ中の書類がセットされるため、同じ状態を再現する。
			 * これにより「取引先が未設定でも書類自身の件名を返さない」ことを検証できる。
			 */
			$GLOBALS['post'] = get_post( $this->bill_id );

			// テスト関数実行
			$actual = bill_get_client_short_name( $this->bill_id );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// 書類のカスタムフィールドを削除
			foreach ( array_keys( $case['conditions']['post_meta'] ) as $meta_name ) {
				delete_post_meta( $this->bill_id, $meta_name );
			}

			// 取引先側のカスタムフィールドを削除
			if ( isset( $case['conditions']['client_post_meta'] ) ) {
				foreach ( array_keys( $case['conditions']['client_post_meta'] ) as $meta_name ) {
					delete_post_meta( $this->no_short_name_client_id, $meta_name );
				}
			}

			// グローバルの $post を破棄
			unset( $GLOBALS['post'] );
		}

		// 存在しない投稿IDを渡した場合は空文字が返る
		$this->assertSame( '', bill_get_client_short_name( 99999999 ), '存在しない投稿IDの場合 => 空文字' );

		/*
		 * 投稿IDが 0 の場合はグローバルの $post を参照せず空文字を返す。
		 * （取引先が入力済の書類をグローバルにセットした状態で検証する）
		 */
		update_post_meta( $this->bill_id, 'bill_client', $this->client_id );
		$GLOBALS['post'] = get_post( $this->bill_id );
		$this->assertSame( '', bill_get_client_short_name( 0 ), '投稿IDが 0 の場合 => 空文字' );
		unset( $GLOBALS['post'] );

		// 投稿オブジェクトを渡しても省略名が取得できる
		$this->assertSame(
			$this->client_short_name,
			bill_get_client_short_name( get_post( $this->bill_id ) ),
			'WP_Post を渡した場合 => 省略名'
		);
		delete_post_meta( $this->bill_id, 'bill_client' );
	}

	/**
	 * bill_get_client_id() のテスト
	 *
	 * 取引先（登録済）の投稿IDが取得できること、未設定・不正値の場合に 0 が返ることを検証する。
	 * 書類一覧のテンプレート（index.php）はこの返り値でリンクの出し分けを行うため、
	 * 不正値でグローバルの $post のIDが返らないことを確認する。
	 *
	 * @return void
	 */
	public function test_bill_get_client_id() {

		// テストの配列（bill_client の値は set_up で作成した投稿IDや不正値に差し替える）
		$test_cases = array(
			array(
				'test_condition_name' => '取引先（登録済）が選択されている場合 => 取引先の投稿ID',
				'conditions'          => array(
					'bill_client' => 'client_id',
				),
				'expected'            => 'client_id',
			),
			array(
				'test_condition_name' => '取引先の投稿IDが数値文字列で保存されている場合 => 数値に変換した投稿ID',
				'conditions'          => array(
					'bill_client' => 'client_id_as_string',
				),
				'expected'            => 'client_id',
			),
			array(
				'test_condition_name' => '取引先（登録済）が未設定の場合 => 0',
				'conditions'          => array(
					'bill_client' => '',
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'bill_client に数値以外の文字列が入っている場合 => 0（型ガード）',
				'conditions'          => array(
					'bill_client' => 'client_id_with_suffix',
				),
				'expected'            => 0,
			),
			array(
				/*
				 * get_post_meta() は内部の absint() で符号を落とすため、
				 * 負数をそのまま返すと絶対値のIDの取引先を参照してしまう。
				 */
				'test_condition_name' => 'bill_client に負数が入っている場合 => 0（絶対値のIDの取引先を参照しない）',
				'conditions'          => array(
					'bill_client' => 'client_id_negative',
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'bill_client に小数が入っている場合 => 0（端数を切り捨てたIDの取引先を参照しない）',
				'conditions'          => array(
					'bill_client' => 'client_id_decimal',
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '削除済の取引先IDが bill_client に残っている場合 => 0',
				'conditions'          => array(
					'bill_client' => '99999999',
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'bill_client に配列が入っている場合 => 0（型ガード）',
				'conditions'          => array(
					'bill_client' => array( 'invalid' ),
				),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			// 書類のカスタムフィールドを設定
			$meta_value = $case['conditions']['bill_client'];
			if ( 'client_id' === $meta_value ) {
				$meta_value = $this->client_id;
			}
			if ( 'client_id_as_string' === $meta_value ) {
				$meta_value = (string) $this->client_id;
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
			update_post_meta( $this->bill_id, 'bill_client', $meta_value );

			// 期待値の 'client_id' は set_up で作成した取引先のIDに差し替える
			$expected = ( 'client_id' === $case['expected'] ) ? $this->client_id : $case['expected'];

			// 書類一覧と同じくグローバルの $post がセットされた状態を再現
			$GLOBALS['post'] = get_post( $this->bill_id );

			// テスト関数実行
			$actual = bill_get_client_id( $this->bill_id );

			// 期待値テスト
			$this->assertSame( $expected, $actual, $case['test_condition_name'] );

			// カスタムフィールドとグローバルの $post を破棄
			delete_post_meta( $this->bill_id, 'bill_client' );
			unset( $GLOBALS['post'] );
		}

		// 存在しない投稿IDを渡した場合は 0 が返る
		$this->assertSame( 0, bill_get_client_id( 99999999 ), '存在しない投稿IDの場合 => 0' );

		/*
		 * 投稿IDが 0 の場合はグローバルの $post を参照せず 0 を返す。
		 * （取引先が入力済の書類をグローバルにセットした状態で検証する）
		 */
		update_post_meta( $this->bill_id, 'bill_client', $this->client_id );
		$GLOBALS['post'] = get_post( $this->bill_id );
		$this->assertSame( 0, bill_get_client_id( 0 ), '投稿IDが 0 の場合 => 0' );
		unset( $GLOBALS['post'] );

		// 投稿オブジェクトを渡しても取引先の投稿IDが取得できる
		$this->assertSame(
			$this->client_id,
			bill_get_client_id( get_post( $this->bill_id ) ),
			'WP_Post を渡した場合 => 取引先の投稿ID'
		);
		delete_post_meta( $this->bill_id, 'bill_client' );
	}
}
