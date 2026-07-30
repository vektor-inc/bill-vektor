<?php
/**
 * Class AdminColumnsTest
 *
 * 管理画面の書類一覧に追加した取引先カラムのテスト
 *
 * @package BillVektor
 */

/**
 * 取引先カラムのテスト
 *
 * 取引先名の取得（イレギュラー優先・未設定時の空文字）、カラムの挿入位置、
 * カラムの出力内容（エスケープ・未設定時のダッシュ）を検証する。
 */
class AdminColumnsTest extends WP_UnitTestCase {

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
	 * テスト前の共通セットアップ
	 *
	 * テスト用の見積書と登録済取引先を作成する。
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
		// 一覧画面を模してセットしたグローバルの $post を破棄
		unset( $GLOBALS['post'] );

		wp_delete_post( $this->estimate_id, true );
		wp_delete_post( $this->client_id, true );

		parent::tear_down();
	}

	/**
	 * bill_get_client_name_by_post_id() のテスト
	 *
	 * 取引先（イレギュラー）が優先されること、登録済取引先の投稿タイトルが
	 * 取得できること、未設定時に空文字が返ることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_get_client_name_by_post_id() {

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
		);

		foreach ( $test_cases as $case ) {
			// カスタムフィールドを設定（bill_client は set_up で作成した取引先のIDに差し替える）
			foreach ( $case['conditions']['post_meta'] as $meta_name => $meta_value ) {
				if ( 'client_id' === $meta_value ) {
					$meta_value = $this->client_id;
				}
				update_post_meta( $this->estimate_id, $meta_name, $meta_value );
			}

			/*
			 * 管理画面の投稿一覧では WP_Posts_List_Table::single_row() が
			 * グローバルの $post に行の投稿をセットするため、同じ状態を再現する。
			 * これにより「取引先が未設定でも書類自身の件名を返さない」ことを検証できる。
			 */
			$GLOBALS['post'] = get_post( $this->estimate_id );

			// テスト関数実行
			$actual = bill_get_client_name_by_post_id( $this->estimate_id );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// カスタムフィールドを削除
			foreach ( array_keys( $case['conditions']['post_meta'] ) as $meta_name ) {
				delete_post_meta( $this->estimate_id, $meta_name );
			}

			// グローバルの $post を破棄
			unset( $GLOBALS['post'] );
		}

		// 存在しない投稿IDを渡した場合は空文字が返る
		$this->assertSame( '', bill_get_client_name_by_post_id( 99999999 ), '存在しない投稿IDの場合 => 空文字' );

		/*
		 * 投稿IDが 0 の場合はグローバルの $post を参照せず空文字を返す。
		 * （取引先が入力済の書類をグローバルにセットした状態で検証する）
		 */
		update_post_meta( $this->estimate_id, 'bill_client_name_manual', 'グローバル参照テスト' );
		$GLOBALS['post'] = get_post( $this->estimate_id );
		$this->assertSame( '', bill_get_client_name_by_post_id( 0 ), '投稿IDが 0 の場合 => 空文字' );
		unset( $GLOBALS['post'] );
		delete_post_meta( $this->estimate_id, 'bill_client_name_manual' );

		// 投稿オブジェクトを渡しても取引先名が取得できる
		update_post_meta( $this->estimate_id, 'bill_client_name_manual', '投稿オブジェクト渡しテスト' );
		$this->assertSame(
			'投稿オブジェクト渡しテスト',
			bill_get_client_name_by_post_id( get_post( $this->estimate_id ) ),
			'WP_Post を渡した場合 => 取引先名'
		);
		delete_post_meta( $this->estimate_id, 'bill_client_name_manual' );
	}

	/**
	 * bill_add_client_admin_column() のテスト
	 *
	 * 取引先カラムがタイトル列の直後に挿入されること、タイトル列が無い場合は
	 * 末尾に追加されることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_add_client_admin_column() {

		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => '見積書一覧の標準的なカラム構成の場合 => タイトルの直後に取引先を挿入',
				'conditions'          => array(
					'columns' => array(
						'cb'    => '<input type="checkbox" />',
						'title' => 'タイトル',
						'date'  => '日付',
					),
				),
				'expected'            => array( 'cb', 'title', BILL_CLIENT_ADMIN_COLUMN_KEY, 'date' ),
			),
			array(
				'test_condition_name' => 'カテゴリー列がある場合 => タイトルの直後（カテゴリーより前）に取引先を挿入',
				'conditions'          => array(
					'columns' => array(
						'cb'                    => '<input type="checkbox" />',
						'title'                 => 'タイトル',
						'taxonomy-estimate-cat' => '見積書カテゴリー',
						'date'                  => '日付',
					),
				),
				'expected'            => array( 'cb', 'title', BILL_CLIENT_ADMIN_COLUMN_KEY, 'taxonomy-estimate-cat', 'date' ),
			),
			array(
				'test_condition_name' => 'タイトル列が無い場合 => 末尾に取引先を追加',
				'conditions'          => array(
					'columns' => array(
						'cb'   => '<input type="checkbox" />',
						'date' => '日付',
					),
				),
				'expected'            => array( 'cb', 'date', BILL_CLIENT_ADMIN_COLUMN_KEY ),
			),
			array(
				'test_condition_name' => 'カラムが空配列の場合 => 取引先のみ',
				'conditions'          => array(
					'columns' => array(),
				),
				'expected'            => array( BILL_CLIENT_ADMIN_COLUMN_KEY ),
			),
		);

		foreach ( $test_cases as $case ) {
			// テスト関数実行
			$actual = bill_add_client_admin_column( $case['conditions']['columns'] );

			// カラムの並び順を検証
			$this->assertSame( $case['expected'], array_keys( $actual ), $case['test_condition_name'] );

			// 追加したカラムの見出しを検証
			$this->assertSame( '取引先', $actual[ BILL_CLIENT_ADMIN_COLUMN_KEY ], $case['test_condition_name'] . '（カラム見出し）' );
		}
	}

	/**
	 * bill_render_client_admin_column() のテスト
	 *
	 * 取引先名がエスケープされて出力されること、未設定時にダッシュが出力されること、
	 * 対象外のカラムでは何も出力しないことを検証する。
	 *
	 * @return void
	 */
	public function test_bill_render_client_admin_column() {

		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => 取引先名を出力',
				'conditions'          => array(
					'column_name' => BILL_CLIENT_ADMIN_COLUMN_KEY,
					'post_meta'   => array(
						'bill_client_name_manual' => '個人事業主テスト',
					),
				),
				'expected'            => '個人事業主テスト',
			),
			array(
				'test_condition_name' => '取引先名にHTMLタグが含まれる場合 => エスケープして出力',
				'conditions'          => array(
					'column_name' => BILL_CLIENT_ADMIN_COLUMN_KEY,
					'post_meta'   => array(
						'bill_client_name_manual' => '<script>alert(1)</script>',
					),
				),
				'expected'            => '&lt;script&gt;alert(1)&lt;/script&gt;',
			),
			array(
				'test_condition_name' => '取引先が未設定の場合 => ダッシュと代替テキストを出力',
				'conditions'          => array(
					'column_name' => BILL_CLIENT_ADMIN_COLUMN_KEY,
					'post_meta'   => array(
						'bill_client_name_manual' => '',
					),
				),
				'expected'            => '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">取引先未設定</span>',
			),
			array(
				'test_condition_name' => '対象外のカラムが渡された場合 => 何も出力しない',
				'conditions'          => array(
					'column_name' => 'date',
					'post_meta'   => array(
						'bill_client_name_manual' => '個人事業主テスト',
					),
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// カスタムフィールドを設定
			foreach ( $case['conditions']['post_meta'] as $meta_name => $meta_value ) {
				update_post_meta( $this->estimate_id, $meta_name, $meta_value );
			}

			// 管理画面の投稿一覧と同じくグローバルの $post がセットされた状態を再現
			$GLOBALS['post'] = get_post( $this->estimate_id );

			// テスト関数実行（出力を取得）
			ob_start();
			bill_render_client_admin_column( $case['conditions']['column_name'], $this->estimate_id );
			$actual = ob_get_clean();

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
	 * bill_register_client_admin_column() のテスト
	 *
	 * 対象の投稿タイプに対してカラム追加・出力のフックが登録されることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_register_client_admin_column() {

		// フックを登録
		bill_register_client_admin_column();

		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => '見積書（estimate）の場合 => カラム追加フィルターが登録されている',
				'conditions'          => array(
					'hook_name'     => 'manage_estimate_posts_columns',
					'callback_name' => 'bill_add_client_admin_column',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '見積書（estimate）の場合 => カラム出力アクションが登録されている',
				'conditions'          => array(
					'hook_name'     => 'manage_estimate_posts_custom_column',
					'callback_name' => 'bill_render_client_admin_column',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '請求書（post）の場合 => 今回はスコープ外なので登録されていない',
				'conditions'          => array(
					'hook_name'     => 'manage_post_posts_columns',
					'callback_name' => 'bill_add_client_admin_column',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// テスト関数実行（フックの登録有無を真偽値で取得）
			$actual = false !== has_filter( $case['conditions']['hook_name'], $case['conditions']['callback_name'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
