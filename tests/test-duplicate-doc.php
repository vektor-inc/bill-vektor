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

	/**
	 * bill_duplicate() のリンク生成に対する nonce 付与テスト
	 *
	 * サブミットボックスに出力される複製・発行リンクに、有効な nonce（_wpnonce）が
	 * 付与されていることを検証する。検証対象は以下の投稿タイプ。
	 *
	 * - 見積書（estimate）: 「見積書を複製」「この内容で請求書を発行」
	 *   「件名を品目一式にして請求書を発行」の3リンクが出力される。
	 * - 請求書（post）: 「請求書を複製」の1リンクが出力される（issue #283）。
	 * - 上記以外（page 等）: 複製・発行リンクが出力されない。
	 *
	 * PR #279 で修正した不具合（リンク生成側で nonce の付け忘れが発生し、
	 * check_admin_referer() 側だけ nonce 検証を追加したためエラーになっていた）の
	 * 再発防止も兼ねる回帰テスト。
	 *
	 * @return void
	 */
	public function test_bill_duplicate() {

		// テスト用の見積書投稿を2件作成（post ID ごとに nonce が変わることを確認するため）
		$estimate_post_id_1 = wp_insert_post(
			array(
				'post_title'   => 'テスト用見積書1',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'estimate',
			)
		);
		$estimate_post_id_2 = wp_insert_post(
			array(
				'post_title'   => 'テスト用見積書2',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'estimate',
			)
		);
		// テスト用の固定ページ投稿を作成（複製対象外の投稿タイプの負のケース用）
		$page_post_id = wp_insert_post(
			array(
				'post_title'   => 'テスト用固定ページ',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		$test_cases = array(
			// --- 正常系：見積書投稿の場合、3リンクすべてに有効な nonce が付与される ---
			array(
				'test_condition_name' => '見積書投稿の場合 => 3リンクすべてに投稿IDに対して有効な nonce が付与される',
				'post_id'             => $estimate_post_id_1,
				'expect_links'        => true,
				'expected_link_count' => 3,
			),
			// --- 正常系：別の見積書投稿でも、その投稿ID用の nonce が付与される ---
			array(
				'test_condition_name' => '別の見積書投稿の場合 => その投稿ID用の nonce が付与される（post ID ごとに nonce が異なる）',
				'post_id'             => $estimate_post_id_2,
				'expect_links'        => true,
				'expected_link_count' => 3,
			),
			// --- 正常系：請求書（post）投稿の場合、「請求書を複製」の1リンクに有効な nonce が付与される（issue #283） ---
			array(
				'test_condition_name' => '請求書（post）投稿の場合 => 「請求書を複製」リンク1つに投稿IDに対して有効な nonce が付与される',
				'post_id'             => $this->post_id,
				'expect_links'        => true,
				'expected_link_count' => 1,
			),
			// --- 異常系・境界値：複製対象外の投稿タイプ（page）の場合、複製・発行リンクが出力されない ---
			array(
				'test_condition_name' => '複製対象外の投稿タイプ（固定ページ）の場合 => 複製・発行リンクが出力されない',
				'post_id'             => $page_post_id,
				'expect_links'        => false,
				'expected_link_count' => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			// global $post と get_post_type() の参照先を切り替える
			global $post;
			$post = get_post( $case['post_id'] );
			setup_postdata( $post );

			// bill_duplicate() は post_submitbox_start フックで直接 HTML を echo する関数のため
			// 出力バッファリングで結果を取得する
			ob_start();
			bill_duplicate();
			$output = ob_get_clean();

			if ( $case['expect_links'] ) {
				// href 属性内の _wpnonce パラメーター値を全て抽出する
				preg_match_all( '/_wpnonce=([a-f0-9]+)/', $output, $matches );

				// 投稿タイプごとに期待されるリンク数（nonce の数）が出力されていること
				$this->assertCount( $case['expected_link_count'], $matches[1], $case['test_condition_name'] . '（リンク数）' );

				foreach ( $matches[1] as $nonce ) {
					// 各 nonce が対象投稿の post ID に対して有効であること
					$this->assertNotFalse(
						wp_verify_nonce( $nonce, 'bill_copy_' . $case['post_id'] ),
						$case['test_condition_name'] . '（nonce 検証）'
					);
				}
			} else {
				// 複製対象外の投稿タイプでは duplicate-section 自体が出力されないこと
				$this->assertStringNotContainsString( 'duplicate-section', $output, $case['test_condition_name'] );
			}

			wp_reset_postdata();
		}

		// 作成した投稿を削除
		wp_delete_post( $estimate_post_id_1, true );
		wp_delete_post( $estimate_post_id_2, true );
		wp_delete_post( $page_post_id, true );

		// wp_reset_postdata() はメインクエリの The Loop 内でしか効果がなく、
		// このテストのように setup_postdata() を直接呼ぶだけのケースでは no-op になるため、
		// global $post の汚染（削除済み投稿を指したまま残る）を防ぐため明示的にリセットする
		$GLOBALS['post'] = null;
		wp_reset_postdata();
	}
}
