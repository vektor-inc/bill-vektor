<?php
/**
 * Class TemplateTagsTest
 *
 * inc/template-tags.php のテンプレートタグのテスト
 *
 * @package BillVektor
 */

/**
 * 取引先名を取得する共通関数のテスト
 *
 * 取引先（イレギュラー）優先・登録済取引先のタイトル取得に加え、
 * 取引先が未設定のときに書類自身の件名を返さないこと、
 * サニタイズされていない値（配列など）が保存されていても
 * 別の投稿のタイトルを返さないことを検証する。
 */
class TemplateTagsTest extends WP_UnitTestCase {

	/**
	 * テスト対象の見積書の投稿IDを保持する
	 *
	 * @var int
	 */
	private $estimate_id;

	/**
	 * 見積書の件名（取引先未設定時に件名が漏れないか確認するために保持する）
	 *
	 * @var string
	 */
	private $estimate_title = 'テスト用見積書の件名';

	/**
	 * 登録済取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $client_id;

	/**
	 * 登録済取引先の名前
	 *
	 * @var string
	 */
	private $client_title = '株式会社テスト取引先';

	/**
	 * 投稿タイトルが空の登録済取引先（無題で保存された取引先）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $untitled_client_id;

	/**
	 * 取引先ではない投稿（固定ページ）の投稿IDを保持する
	 *
	 * bill_client に取引先以外の投稿IDが保存されている状態を再現するために使う。
	 *
	 * @var int
	 */
	private $non_client_id;

	/**
	 * 取引先ではない投稿のタイトル
	 *
	 * このタイトルが取引先名として漏れないことを検証するため保持する。
	 *
	 * @var string
	 */
	private $non_client_title = '取引先ではない非公開ページ';

	/**
	 * テスト前の共通セットアップ
	 *
	 * テスト用の見積書と登録済取引先（通常・無題）、および取引先ではない投稿を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// テスト用の登録済取引先（client 投稿）を作成
		$this->client_id = wp_insert_post(
			array(
				'post_title'  => $this->client_title,
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		// 無題で保存された登録済取引先を作成（client は title のみサポートのため空でも保存できる）
		$this->untitled_client_id = wp_insert_post(
			array(
				'post_title'  => '',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		/*
		 * 無題の取引先が作成できていないと「取引先を選択済だが名前が空」の検証が
		 * 「取引先が未設定」と同じ条件に退化してしまうため、作成できたことを確認する。
		 */
		$this->assertGreaterThan( 0, $this->untitled_client_id, '無題の登録済取引先が作成できている' );

		/*
		 * 取引先ではない投稿（非公開の固定ページ）を作成する。
		 * bill_client に取引先以外の投稿IDが保存されていると、その投稿のタイトルが
		 * 取引先名として表示されてしまうため、その状態を再現するために使う。
		 */
		$this->non_client_id = wp_insert_post(
			array(
				'post_title'  => $this->non_client_title,
				'post_status' => 'private',
				'post_type'   => 'page',
			)
		);

		/*
		 * 取引先ではない投稿のタイトルが空だと、修正前でも空文字が返って
		 * 「他の投稿のタイトルが漏れる」不具合を検出できないため、
		 * タイトルを取得できることを確認する。
		 */
		$this->assertNotSame( '', get_the_title( $this->non_client_id ), '取引先ではない投稿にタイトルがある' );

		// テスト用の見積書を作成
		$this->estimate_id = wp_insert_post(
			array(
				'post_title'  => $this->estimate_title,
				'post_status' => 'publish',
				'post_type'   => 'estimate',
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
		// 書類の表示中を模してセットしたグローバルの $post を破棄
		unset( $GLOBALS['post'] );

		wp_delete_post( $this->estimate_id, true );
		wp_delete_post( $this->client_id, true );
		wp_delete_post( $this->untitled_client_id, true );
		wp_delete_post( $this->non_client_id, true );

		parent::tear_down();
	}

	/**
	 * bill_get_client_name() のテスト
	 *
	 * 取引先（イレギュラー）が優先されること、登録済取引先の投稿タイトルが
	 * 取得できること、取引先が未設定・不正な値の場合に空文字が返ることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_get_client_name() {

		/*
		 * 取引先（登録済）の値は保存時にサニタイズされておらず、
		 * bill_client[]=1 のような送信で配列がそのまま保存され得る。
		 * 配列は (int) 変換で 1 になるため、投稿ID 1 のタイトルが
		 * 取引先名として返る状態を再現できるように投稿ID 1 を用意する。
		 */
		$injected_post_id = $this->prepare_injected_post();

		/*
		 * 配列がメタに保存され、取得時も配列のまま返ることを実データで確認する。
		 * ここが配列で返らないなら、以降の配列ケースは実状と乖離したテストになる。
		 */
		update_post_meta( $this->estimate_id, 'bill_client', array( $injected_post_id ) );
		$this->assertIsArray(
			get_post_meta( $this->estimate_id, 'bill_client', true ),
			'bill_client には配列がそのまま保存され、取得時も配列で返る'
		);
		delete_post_meta( $this->estimate_id, 'bill_client' );

		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => '取引先（イレギュラー）のみ入力されている場合 => イレギュラーの入力値',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '個人事業主テスト',
						'bill_client'             => '',
					),
				),
				'expected'            => '個人事業主テスト',
			),
			array(
				'test_condition_name' => '取引先（登録済）のみ選択されている場合 => 登録済取引先の投稿タイトル',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'client_id',
					),
				),
				'expected'            => $this->client_title,
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）と（登録済）の両方が入力されている場合 => イレギュラーを優先',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '個人事業主テスト',
						'bill_client'             => 'client_id',
					),
				),
				'expected'            => '個人事業主テスト',
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）に "0" が入力されている場合 => "0"（登録済取引先へフォールバックしない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '0',
						'bill_client'             => 'client_id',
					),
				),
				'expected'            => '0',
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
				'test_condition_name' => '取引先（登録済）のカスタムフィールドが未保存の場合 => 空文字（書類自身の件名を返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
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
				'test_condition_name' => 'bill_client に配列が保存されている場合 => 空文字（配列を1と評価して他の投稿のタイトルを返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'injected_array',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'bill_client にオブジェクトが保存されている場合 => 空文字（他の投稿のタイトルを返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'injected_object',
					),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'bill_client_name_manual に配列が保存されている場合 => 空文字（配列をそのまま返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => 'injected_array',
						'bill_client'             => '',
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
				'test_condition_name' => '登録済取引先を選択しているが取引先が無題で保存されている場合 => 空文字（書類自身の件名を返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'untitled_client_id',
					),
				),
				'expected'            => '',
			),
			array(
				/*
				 * absint() は符号を落とすため、-123 をそのまま通すと
				 * 投稿ID 123 のタイトルを取引先名として返してしまう。
				 */
				'test_condition_name' => 'bill_client に負数が入っている場合 => 空文字（絶対値のIDの投稿のタイトルを返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'non_client_id_negative',
					),
				),
				'expected'            => '',
			),
			array(
				/*
				 * (int) キャストは末尾の非数値を捨てるため、'123abc' をそのまま通すと
				 * 投稿ID 123 のタイトルを取引先名として返してしまう。
				 */
				'test_condition_name' => 'bill_client に数字以外を含む値が入っている場合 => 空文字（数値部分のIDの投稿のタイトルを返さない）',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'non_client_id_with_suffix',
					),
				),
				'expected'            => '',
			),
			array(
				/*
				 * 実在する投稿IDでも取引先（client）以外を指している場合は取引先なしとして扱う。
				 * 非公開ページのIDが保存されていると、そのタイトルが取引先名として
				 * 書類や書類一覧に表示されてしまうため。
				 */
				'test_condition_name' => 'bill_client が実在する取引先以外の投稿（固定ページ）を指している場合 => 空文字',
				'conditions'          => array(
					'post_meta' => array(
						'bill_client_name_manual' => '',
						'bill_client'             => 'non_client_id',
					),
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// カスタムフィールドを設定（プレースホルダーは実際に作成した投稿のIDに差し替える）
			foreach ( $case['conditions']['post_meta'] as $meta_name => $meta_value ) {
				if ( 'client_id' === $meta_value ) {
					$meta_value = $this->client_id;
				}
				if ( 'untitled_client_id' === $meta_value ) {
					$meta_value = $this->untitled_client_id;
				}
				if ( 'non_client_id' === $meta_value ) {
					$meta_value = $this->non_client_id;
				}
				if ( 'non_client_id_negative' === $meta_value ) {
					// 取引先ではない投稿IDの負数（absint() を通すとそのIDに戻る）
					$meta_value = '-' . $this->non_client_id;
				}
				if ( 'non_client_id_with_suffix' === $meta_value ) {
					// 取引先ではない投稿IDに文字列を付けた値（(int) キャストするとそのIDに戻る）
					$meta_value = $this->non_client_id . 'abc';
				}
				if ( 'injected_array' === $meta_value ) {
					// bill_client[]=1 のような送信で保存され得る配列を再現する
					$meta_value = array( $injected_post_id );
				}
				if ( 'injected_object' === $meta_value ) {
					/*
					 * メタにオブジェクトが保存されている場合を再現する。
					 * post_title を持たせないと WP_Post の既定値 '' が返り、
					 * 修正前でも空文字になってテストが赤くならないため、
					 * 注入される値を明示的に持たせる。
					 */
					$meta_value = (object) array(
						'ID'         => $injected_post_id,
						'post_title' => '注入されたタイトル',
					);
				}
				update_post_meta( $this->estimate_id, $meta_name, $meta_value );
			}

			/*
			 * 書類の表示中・管理画面の一覧などグローバルの $post がセットされている状態を再現する。
			 * get_the_title() は引数が空だとグローバルの $post を参照するため、
			 * この状態でないと「書類自身の件名を返してしまう」不具合を検出できない。
			 */
			$GLOBALS['post'] = get_post( $this->estimate_id );

			// テスト関数実行
			$actual = bill_get_client_name( get_post( $this->estimate_id ) );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// カスタムフィールドを削除
			foreach ( array_keys( $case['conditions']['post_meta'] ) as $meta_name ) {
				delete_post_meta( $this->estimate_id, $meta_name );
			}

			// グローバルの $post を破棄
			unset( $GLOBALS['post'] );
		}
	}

	/**
	 * 配列が (int) 変換されたときに参照される投稿ID 1 を用意する
	 *
	 * 配列は整数変換すると 1 になるため、投稿ID 1 にタイトルのある投稿が
	 * 存在していないと「配列を渡すと他の投稿のタイトルが返る」不具合を再現できない。
	 * テスト環境に投稿ID 1 が無い場合は作成する。
	 *
	 * @return int 投稿ID 1。
	 */
	private function prepare_injected_post() {
		// 投稿ID 1 が存在しない場合はIDを指定して作成する
		if ( ! get_post( 1 ) ) {
			wp_insert_post(
				array(
					'import_id'   => 1,
					'post_title'  => '配列注入で参照される投稿',
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);
		}

		/*
		 * 投稿ID 1 のタイトルが空だと、配列ケースが修正前でも空文字を返してしまい
		 * 不具合を検出できない（テストが素通りする）ため、タイトルがあることを確認する。
		 */
		$this->assertNotSame( '', get_the_title( 1 ), '配列注入で参照される投稿ID 1 にタイトルがある' );

		return 1;
	}
}
