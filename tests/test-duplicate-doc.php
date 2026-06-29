<?php
/**
 * Class DuplicateDocTest
 *
 * bill_copy_redirect() のセキュリティ検証テスト
 *
 * @package BillVektor
 */

/**
 * 書類複製機能のセキュリティテスト
 *
 * nonce 検証・権限チェックが正しく機能することを検証する。
 */
class DuplicateDocTest extends WP_UnitTestCase {

	/**
	 * テスト対象の投稿IDを保持する
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * テスト用管理者ユーザーIDを保持する
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * テスト用購読者ユーザーIDを保持する
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * テスト用投稿・管理者ユーザー・購読者ユーザーを作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// テスト用投稿を作成
		$this->post_id = wp_insert_post(
			array(
				'post_title'   => 'テスト用書類',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		// テスト用管理者ユーザーを作成（edit_post 権限あり）
		$this->admin_user_id = wp_create_user( 'test_admin', 'password', 'admin@example.com' );
		$admin_user          = new WP_User( $this->admin_user_id );
		$admin_user->set_role( 'administrator' );

		// テスト用購読者ユーザーを作成（edit_post 権限なし）
		$this->subscriber_user_id = wp_create_user( 'test_subscriber', 'password', 'subscriber@example.com' );
		$subscriber_user          = new WP_User( $this->subscriber_user_id );
		$subscriber_user->set_role( 'subscriber' );
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * 作成したデータを削除する。
	 *
	 * @return void
	 */
	public function tear_down() {
		// $_GET・$_REQUEST をリセット
		$_GET     = array();
		$_REQUEST = array();

		// 作成した投稿を削除
		if ( $this->post_id ) {
			wp_delete_post( $this->post_id, true );
		}

		// 作成したユーザーを削除
		if ( $this->admin_user_id ) {
			wp_delete_user( $this->admin_user_id );
		}
		if ( $this->subscriber_user_id ) {
			wp_delete_user( $this->subscriber_user_id );
		}

		parent::tear_down();
	}

	/**
	 * bill_copy_redirect() の nonce 検証テスト
	 *
	 * nonce が欠落・不正な場合に wp_die が呼ばれることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_copy_redirect() {

		$test_cases = array(
			// --- 異常系：nonce なしでアクセスした場合 ---
			array(
				'test_condition_name' => 'nonce なしでアクセスした場合 => wp_die が発生すること',
				'setup'               => function () {
					// 管理者としてログイン
					wp_set_current_user( $this->admin_user_id );
					// nonce なしで master_id のみ設定
					$_GET = array(
						'master_id'       => $this->post_id,
						'post_type'       => 'post',
						'table_copy_type' => 'all',
						'duplicate_type'  => 'full',
					);
				},
				'expected_exception' => true,
			),
			// --- 異常系：不正な nonce でアクセスした場合 ---
			array(
				'test_condition_name' => '不正な nonce でアクセスした場合 => wp_die が発生すること',
				'setup'               => function () {
					// 管理者としてログイン
					wp_set_current_user( $this->admin_user_id );
					// 不正な nonce を設定
					$_GET = array(
						'master_id'       => $this->post_id,
						'post_type'       => 'post',
						'table_copy_type' => 'all',
						'duplicate_type'  => 'full',
						'_wpnonce'        => 'invalid_nonce_string',
					);
				},
				'expected_exception' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// セットアップ処理を実行
			( $case['setup'] )();

			if ( $case['expected_exception'] ) {
				// wp_die が呼ばれることを期待（bootstrap.php の fail_if_died が例外を throw するが
				// テスト実行中はフィルターが外れているため、check_admin_referer が wp_die 経由で
				// スクリプト終了を試みる動作を wp_die フィルターで捕捉する）
				$exception_thrown = false;

				// テスト用に wp_die_handler をオーバーライドして例外をキャッチする
				// クロージャを変数に保存し、フレームワーク側のフィルターを除去しないよう個別に外す
				$die_handler = function () {
					return function ( $message ) {
						throw new \Exception( 'wp_die called: ' . ( is_string( $message ) ? $message : '' ) );
					};
				};
				add_filter( 'wp_die_handler', $die_handler );

				try {
					bill_copy_redirect();
				} catch ( \Exception $e ) {
					$exception_thrown = true;
				} finally {
					// フィルターを個別に削除してフレームワーク側のフィルターを保持する
					remove_filter( 'wp_die_handler', $die_handler );
					$_GET     = array();
					$_REQUEST = array();
				}

				$this->assertTrue( $exception_thrown, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 権限のないユーザーが複製できないことのテスト
	 *
	 * edit_post 権限のない購読者ユーザーで複製を試みた場合に
	 * wp_die が呼ばれることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_copy_redirect__capability() {

		$test_cases = array(
			// --- 異常系：edit_post 権限のないユーザーが nonce 付きでアクセスした場合 ---
			array(
				'test_condition_name' => 'edit_post 権限のない購読者ユーザーが有効な nonce でアクセスした場合 => wp_die が発生すること',
				'setup'               => function () {
					// 購読者としてログイン（edit_post 権限なし）
					wp_set_current_user( $this->subscriber_user_id );
					// 有効な nonce を生成して設定
					$nonce = wp_create_nonce( 'bill_copy_' . $this->post_id );
					$_GET  = array(
						'master_id'       => $this->post_id,
						'post_type'       => 'post',
						'table_copy_type' => 'all',
						'duplicate_type'  => 'full',
						'_wpnonce'        => $nonce,
					);
					// check_admin_referer() は $_REQUEST['_wpnonce'] を参照するため合わせて設定する
					$_REQUEST['_wpnonce'] = $nonce;
				},
				'expected_exception' => true,
			),
			// --- 正常系（WP_HTTP_Redirect の確認）：管理者が有効な nonce でアクセスした場合 ---
			// リダイレクトが発生するため、wp_die は呼ばれないことを確認する
			array(
				'test_condition_name' => '管理者ユーザーが有効な nonce でアクセスした場合 => wp_redirect が呼ばれること',
				'setup'               => function () {
					// 管理者としてログイン
					wp_set_current_user( $this->admin_user_id );
					// 有効な nonce を生成して設定
					$nonce = wp_create_nonce( 'bill_copy_' . $this->post_id );
					$_GET  = array(
						'master_id'       => $this->post_id,
						'post_type'       => 'post',
						'table_copy_type' => 'all',
						'duplicate_type'  => 'full',
						'_wpnonce'        => $nonce,
					);
					// check_admin_referer() は $_REQUEST['_wpnonce'] を参照するため合わせて設定する
					$_REQUEST['_wpnonce'] = $nonce;
				},
				'expected_exception' => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// セットアップ処理を実行
			( $case['setup'] )();

			// テスト用に wp_die_handler と wp_redirect フィルターを設定
			$exception_thrown = false;
			$redirect_called  = false;

			// クロージャを変数に保存し、フレームワーク側のフィルターを除去しないよう個別に外す
			$die_handler = function () {
				return function ( $message ) {
					throw new \Exception( 'wp_die called: ' . ( is_string( $message ) ? $message : '' ) );
				};
			};
			add_filter( 'wp_die_handler', $die_handler );

			// wp_safe_redirect はヘッダー送信を試みるため、テスト環境では例外化して処理を止める
			// $redirect_called フラグで「リダイレクトに到達したこと」を正常系で確認できるようにする
			$redirect_handler = function ( $location ) use ( &$redirect_called ) {
				$redirect_called = true;
				throw new \Exception( 'wp_redirect called: ' . $location );
			};
			add_filter( 'wp_redirect', $redirect_handler );

			try {
				bill_copy_redirect();
			} catch ( \Exception $e ) {
				$message          = $e->getMessage();
				// wp_die 由来の例外か wp_redirect 由来の例外かを区別する
				// wp_die 呼び出しの場合は "wp_die called:" プレフィックスが付く
				// wp_redirect 呼び出しの場合は "wp_redirect called:" プレフィックスが付く
				$exception_thrown = strpos( $message, 'wp_die called:' ) === 0;
			} finally {
				// フィルターを個別に削除してフレームワーク側のフィルターを保持する
				remove_filter( 'wp_die_handler', $die_handler );
				remove_filter( 'wp_redirect', $redirect_handler );
				$_GET     = array();
				$_REQUEST = array();
			}

			if ( $case['expected_exception'] ) {
				$this->assertTrue( $exception_thrown, $case['test_condition_name'] );
			} else {
				// 正常系：wp_die が呼ばれず、かつ wp_redirect に到達したことを確認する
				// （何もせず return した場合の偽陽性を防ぐ）
				$this->assertFalse( $exception_thrown, $case['test_condition_name'] );
				$this->assertTrue( $redirect_called, $case['test_condition_name'] . ' (wp_redirect が呼ばれること)' );
			}
		}
	}
}
