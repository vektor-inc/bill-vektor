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

		/*
		 * この投稿にも client_honorific と同名のメタ値を登録しておく。
		 * ここに何も登録しないと、取引先IDの検証（post_type が client かどうか）を
		 * していない実装でも get_post_meta() が空文字を返すため既定の「御中」と
		 * 一致してしまい、「無関係な投稿のメタ値を敬称として読んでいないか」を
		 * このテストで検出できない（=空振りするテストになる）。
		 * 既定値の「御中」とは異なる値を登録しておくことで、検証漏れがあれば
		 * このメタ値がそのまま返ってきて red になるようにする。
		 */
		update_post_meta( $this->non_client_id, 'client_honorific', '殿' );

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
					'bill_client'             => 'client_id',
				),
				'expected'            => $this->client_honorific,
			),
			array(
				'test_condition_name' => '取引先が選択されているが敬称が未登録の場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => 'no_honorific_client_id',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => '取引先が両方とも未設定の場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => '',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => 'bill_client が 0 の場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => '0',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => '削除済の取引先IDが bill_client に残っている場合 => 既定の敬称「御中」',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => '99999999',
				),
				'expected'            => '御中',
			),
			array(
				/*
				 * 実在する投稿IDでも取引先（client）以外を指している場合に、
				 * その投稿に登録した client_honorific メタ値（set_up で登録した「殿」。
				 * 無関係な値）を敬称として読まないことを確認する。
				 * post_type の検証をしていない実装だと、ここで「殿」が返ってしまう。
				 */
				'test_condition_name' => 'bill_client が実在する取引先以外の投稿（固定ページ）を指している場合 => 既定の敬称「御中」（その投稿の client_honorific メタ値を読まない）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => 'non_client_id',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => 'bill_client に配列が入っている場合 => 既定の敬称「御中」（型ガード）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => array( 'invalid' ),
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
					'bill_client'             => 'client_id_with_suffix',
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
					'bill_client'             => 'client_id_negative',
				),
				'expected'            => '御中',
			),
			array(
				'test_condition_name' => 'bill_client に敬称ありの取引先IDの小数が入っている場合 => 既定の敬称「御中」（別の取引先の敬称を返さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => 'client_id_decimal',
				),
				'expected'            => '御中',
			),
			array(
				/*
				 * 先頭ゼロも absint() を通すと元の文字列と一致しなくなる
				 * （例: '0' . $client_id を absint() すると先頭ゼロが落ちて
				 * 別の数値表現になる）。型ガードにより不正値として扱われ、
				 * 別の取引先の敬称を返さないことを確認する。
				 */
				'test_condition_name' => 'bill_client に敬称ありの取引先IDの先頭にゼロを付けた値が入っている場合 => 既定の敬称「御中」（別の取引先の敬称を返さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => 'client_id_leading_zero',
				),
				'expected'            => '御中',
			),
			array(
				/*
				 * client_honorific も無加工の $_POST が保存されるため配列などが入り得る。
				 * bill_get_client_short_name() の省略名と同じ型ガード
				 * （! is_scalar( $client_honorific )）が効いていることを確認する。
				 */
				'test_condition_name' => '取引先が選択されているが敬称に配列が登録されている場合 => 既定の敬称「御中」（型ガード）',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'             => 'no_honorific_client_id',
				),
				'client_honorific_meta' => array( 'invalid' ),
				'expected'              => '御中',
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => 空文字（敬称を出さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '個人事業主テスト',
					'bill_client'             => 'client_id',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されていて bill_client が不正値の場合 => 空文字（敬称を出さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '個人事業主テスト',
					'bill_client'             => array( 'invalid' ),
				),
				'expected'            => '',
			),
			array(
				/*
				 * bill_get_client_name() は '0' を「イレギュラーの入力あり」として
				 * 扱う（is_scalar() && '' !== (string) ... の判定）。敬称側もこの
				 * 判定式に揃えたため、「0」という取引先名なのに登録済取引先の敬称が
				 * 付いてしまう（「0 様」のような表示になる）ことがないかを確認する。
				 */
				'test_condition_name' => '取引先（イレギュラー）が文字列「0」の場合 => 空文字（敬称を出さない）',
				'conditions'          => array(
					'bill_client_name_manual' => '0',
					'bill_client'             => 'client_id',
				),
				'expected'            => '',
			),
			array(
				/*
				 * 配列は is_scalar() が false になるため「イレギュラーの入力なし」
				 * として扱われ、登録済取引先の敬称にフォールバックする
				 * （bill_get_client_name() が同じ条件で登録済取引先名にフォールバック
				 * するのと挙動を揃える）。
				 */
				'test_condition_name' => 'bill_client_name_manual に配列が入っている場合 => 登録済取引先の敬称にフォールバック',
				'conditions'          => array(
					'bill_client_name_manual' => array( 'invalid' ),
					'bill_client'             => 'client_id',
				),
				'expected'            => $this->client_honorific,
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
				if ( 'client_id_leading_zero' === $meta_value ) {
					// 実在する取引先IDの先頭にゼロを付けた値（absint() を通すと先頭ゼロが落ちる）
					$meta_value = '0' . $this->client_id;
				}
				update_post_meta( $this->bill_id, $meta_name, $meta_value );
			}

			// 取引先側の敬称メタ値を上書きするケース（配列などが登録されている状態を再現する）
			if ( isset( $case['client_honorific_meta'] ) ) {
				update_post_meta( $this->no_honorific_client_id, 'client_honorific', $case['client_honorific_meta'] );
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
			$echoed = ob_get_clean();

			// 期待値テスト（戻り値）
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// 画面へ直接出力していないことのテスト
			$this->assertSame( '', $echoed, $case['test_condition_name'] . '（戻り値ではなく画面へ直接出力していないこと）' );

			// 書類のカスタムフィールドを削除
			foreach ( array_keys( $case['conditions'] ) as $meta_name ) {
				delete_post_meta( $this->bill_id, $meta_name );
			}

			// 取引先側の敬称メタ値の上書きを元（未登録）に戻す
			if ( isset( $case['client_honorific_meta'] ) ) {
				delete_post_meta( $this->no_honorific_client_id, 'client_honorific' );
			}
		}

		/*
		 * 存在しない投稿IDを渡した場合は、取引先が未設定のときの既定「御中」ではなく
		 * 空文字を返す（bill_get_client_short_name() が空文字を返すのと揃える）。
		 */
		$this->assertSame( '', bill_get_client_honorific( 99999999 ), '存在しない投稿IDの場合 => 空文字' );

		// 投稿IDが 0 の場合はグローバルの $post を参照せず空文字を返す
		update_post_meta( $this->bill_id, 'bill_client', $this->client_id );
		$GLOBALS['post'] = get_post( $this->bill_id );
		$this->assertSame( '', bill_get_client_honorific( 0 ), '投稿IDが 0 の場合 => 空文字（グローバルの $post を参照しない）' );
		unset( $GLOBALS['post'] );
		delete_post_meta( $this->bill_id, 'bill_client' );

		// 投稿オブジェクトだけでなく投稿IDを渡しても敬称が取得できる
		update_post_meta( $this->bill_id, 'bill_client', $this->client_id );
		$this->assertSame(
			$this->client_honorific,
			bill_get_client_honorific( $this->bill_id ),
			'投稿ID（int）を渡した場合 => 敬称'
		);
		delete_post_meta( $this->bill_id, 'bill_client' );
	}
}
