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
	 * テスト用寄稿者ユーザーIDを保持する
	 *
	 * @var int
	 */
	private $contributor_user_id;

	/**
	 * 寄稿者が作成した複製元投稿のIDを保持する
	 *
	 * @var int
	 */
	private $contributor_post_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * テスト用投稿・管理者ユーザー・購読者ユーザー・寄稿者ユーザーを作成する。
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

		// テスト用寄稿者ユーザーを作成（edit_posts はあるが edit_others_posts・edit_pages は無い）
		$this->contributor_user_id = wp_create_user( 'test_contributor', 'password', 'contributor@example.com' );
		$contributor_user          = new WP_User( $this->contributor_user_id );
		$contributor_user->set_role( 'contributor' );

		// 寄稿者が自分で作成した複製元投稿。draft のままにするのは、
		// 公開済み投稿だと寄稿者の edit_posts 権限だけでは edit_post 権限チェックが
		// 通らない（公開済み投稿の編集には edit_published_posts が必要）ため
		$this->contributor_post_id = wp_insert_post(
			array(
				'post_title'   => 'テスト用書類（寄稿者作成）',
				'post_content' => '',
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_author'  => $this->contributor_user_id,
			)
		);
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
		if ( $this->contributor_post_id ) {
			wp_delete_post( $this->contributor_post_id, true );
		}

		// 作成したユーザーを削除
		if ( $this->admin_user_id ) {
			wp_delete_user( $this->admin_user_id );
		}
		if ( $this->subscriber_user_id ) {
			wp_delete_user( $this->subscriber_user_id );
		}
		if ( $this->contributor_user_id ) {
			wp_delete_user( $this->contributor_user_id );
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
	 * bill_copy_redirect() の複製先投稿タイプに対する作成権限チェックのテスト
	 *
	 * master_id の書類自体を編集できるユーザーでも、URL の post_type を差し替えれば
	 * 作成権限のない投稿タイプの下書きを作れてしまう不具合への対応を検証する。
	 * 寄稿者ロール（edit_posts はあるが edit_others_posts・edit_pages は無い）で、
	 * 作成権限のある投稿タイプ（post・estimate・client）は従来どおり複製でき、
	 * 作成権限のない投稿タイプ（page）は権限エラーで止まることを確認する。
	 * あわせて、未登録・不正な post_type（存在しない投稿タイプ名）を指定した場合も
	 * 権限エラーで止まり、bill_copy_post() 側の既定値（'post'）にフォールバックして
	 * 作成されてしまわないことを、権限の強い管理者ユーザーでも確認する。
	 *
	 * @return void
	 */
	public function test_bill_copy_redirect__post_type_capability() {

		$test_cases = array(
			// --- 正常系：寄稿者が作成権限のある投稿タイプ（post）へ複製する場合 ---
			array(
				'test_condition_name' => '寄稿者が作成権限のある投稿タイプ（post）へ複製する場合 => 従来どおり複製できる',
				'user_id_property'    => 'contributor_user_id',
				'master_id_property'  => 'contributor_post_id',
				'post_type'           => 'post',
				'expected_exception'  => false,
			),
			// --- 正常系：寄稿者が作成権限のある投稿タイプ（estimate・見積書）へ複製する場合 ---
			array(
				'test_condition_name' => '寄稿者が作成権限のある投稿タイプ（estimate）へ複製する場合 => 従来どおり複製できる',
				'user_id_property'    => 'contributor_user_id',
				'master_id_property'  => 'contributor_post_id',
				'post_type'           => 'estimate',
				'expected_exception'  => false,
			),
			// --- 正常系：寄稿者が作成権限のある投稿タイプ（client・取引先）へ複製する場合 ---
			array(
				'test_condition_name' => '寄稿者が作成権限のある投稿タイプ（client）へ複製する場合 => 従来どおり複製できる',
				'user_id_property'    => 'contributor_user_id',
				'master_id_property'  => 'contributor_post_id',
				'post_type'           => 'client',
				'expected_exception'  => false,
			),
			// --- 異常系：寄稿者が作成権限のない投稿タイプ（page）を指定した場合 ---
			array(
				'test_condition_name' => '寄稿者が作成権限のない投稿タイプ（page）を指定した場合 => 権限エラーで複製されない',
				'user_id_property'    => 'contributor_user_id',
				'master_id_property'  => 'contributor_post_id',
				'post_type'           => 'page',
				'expected_exception'  => true,
			),
			// --- 境界値：管理者でも、未登録・不正な post_type を指定した場合は複製されない ---
			array(
				'test_condition_name' => '管理者が未登録・不正な post_type（not-a-real-type）を指定した場合 => 権限エラーで複製されない',
				'user_id_property'    => 'admin_user_id',
				'master_id_property'  => 'post_id',
				'post_type'           => 'not-a-real-type',
				'expected_exception'  => true,
			),
		);

		// 対象投稿タイプごとの投稿件数を確認するため $wpdb を使う
		// （'not-a-real-type' のような未登録タイプは get_post_types() や wp_count_posts() の
		// 対象にならないため、posts テーブルを直接数えて「作られていないこと」を確認する）
		global $wpdb;

		foreach ( $test_cases as $case ) {
			$user_id   = $this->{ $case['user_id_property'] };
			$master_id = $this->{ $case['master_id_property'] };

			// 異常系（権限エラーで止まるはず）のケースだけ、実行前の対象投稿タイプの件数を控えておく
			$post_count_before = null;
			if ( $case['expected_exception'] ) {
				$post_count_before = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $case['post_type'] )
				);
			}

			// ログインし、有効な nonce を付与したリクエストを組み立てる
			wp_set_current_user( $user_id );
			$nonce = wp_create_nonce( 'bill_copy_' . $master_id );
			$_GET  = array(
				'master_id'       => $master_id,
				'post_type'       => $case['post_type'],
				'table_copy_type' => 'all',
				'duplicate_type'  => 'full',
				'_wpnonce'        => $nonce,
			);
			// check_admin_referer() は $_REQUEST['_wpnonce'] を参照するため合わせて設定する
			$_REQUEST['_wpnonce'] = $nonce;

			$exception_thrown = false;
			$redirect_called  = false;
			$redirect_location = '';

			// クロージャを変数に保存し、フレームワーク側のフィルターを除去しないよう個別に外す
			$die_handler = function () {
				return function ( $message ) {
					throw new \Exception( 'wp_die called: ' . ( is_string( $message ) ? $message : '' ) );
				};
			};
			add_filter( 'wp_die_handler', $die_handler );

			// wp_safe_redirect はヘッダー送信を試みるため、テスト環境では例外化して処理を止める
			$redirect_handler = function ( $location ) use ( &$redirect_called, &$redirect_location ) {
				$redirect_called   = true;
				$redirect_location = $location;
				throw new \Exception( 'wp_redirect called: ' . $location );
			};
			add_filter( 'wp_redirect', $redirect_handler );

			try {
				bill_copy_redirect();
			} catch ( \Exception $e ) {
				$message          = $e->getMessage();
				$exception_thrown = strpos( $message, 'wp_die called:' ) === 0;
			} finally {
				remove_filter( 'wp_die_handler', $die_handler );
				remove_filter( 'wp_redirect', $redirect_handler );
				$_GET     = array();
				$_REQUEST = array();
			}

			if ( $case['expected_exception'] ) {
				$this->assertTrue( $exception_thrown, $case['test_condition_name'] );

				// wp_die が呼ばれたことだけでなく、対象投稿タイプの投稿が
				// 実際に増えていない（複製されていない）ことも確認する。
				// wp_die の呼び出し位置だけを見るテストだと、チェックの位置が
				// 動いても素通りしてしまうため
				$post_count_after = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $case['post_type'] )
				);
				$this->assertSame(
					$post_count_before,
					$post_count_after,
					$case['test_condition_name'] . '（投稿が作成されていないこと）'
				);
			} else {
				// 正常系：wp_die が呼ばれず、かつ wp_redirect に到達したことを確認する
				$this->assertFalse( $exception_thrown, $case['test_condition_name'] );
				$this->assertTrue( $redirect_called, $case['test_condition_name'] . ' (wp_redirect が呼ばれること)' );

				// 複製された投稿を後始末する（リダイレクト先URLの post= から ID を取り出す）
				if ( preg_match( '/post=(\d+)/', $redirect_location, $matches ) ) {
					wp_delete_post( (int) $matches[1], true );
				}
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

	/**
	 * bill_copy_post() の「取引先（イレギュラー）」引き継ぎテスト
	 *
	 * 見積書に入力した bill_client_name_manual（取引先（イレギュラー）欄の保存先）が、
	 * 請求書発行時（duplicate_type を付けない経路）にも複製先へ引き継がれることを検証する（issue #347）。
	 * あわせて、既に全項目コピーされている duplicate_type=full の経路（見積書・請求書の複製）で
	 * bill_client_name_manual が二重に add_post_meta されず1件のままであることも確認する
	 * （個別コピー対象への追加と全項目コピー分岐が重複する回帰を防ぐため）。
	 *
	 * @return void
	 */
	public function test_bill_copy_post() {

		// テスト用の見積書投稿を作成し、取引先（イレギュラー）の値を保存しておく
		$estimate_post_id = wp_insert_post(
			array(
				'post_title'   => 'テスト用見積書（取引先イレギュラー）',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'estimate',
			)
		);
		update_post_meta( $estimate_post_id, 'bill_client_name_manual', '株式会社イレギュラー商事' );

		$test_cases = array(
			// --- 正常系：「この内容で請求書を発行」ボタン相当（table_copy_type=all, duplicate_type未指定） ---
			array(
				'test_condition_name' => '「この内容で請求書を発行」相当（table_copy_type=all, duplicate_type=""）の場合 => 取引先（イレギュラー）が請求書側に引き継がれる',
				'table_copy_type'     => 'all',
				'duplicate_type'      => '',
				'expected_value'      => '株式会社イレギュラー商事',
				'expected_count'      => 1,
			),
			// --- 正常系：「件名を品目一式にして請求書を発行」ボタン相当（table_copy_type=total, duplicate_type未指定） ---
			array(
				'test_condition_name' => '「件名を品目一式にして請求書を発行」相当（table_copy_type=total, duplicate_type=""）の場合 => 取引先（イレギュラー）が請求書側に引き継がれる',
				'table_copy_type'     => 'total',
				'duplicate_type'      => '',
				'expected_value'      => '株式会社イレギュラー商事',
				'expected_count'      => 1,
			),
			// --- 境界値：duplicate_type=full（見積書・請求書の複製ボタン相当）の場合、全項目コピー分岐で
			//     既に引き継がれているため、個別コピー分岐と二重登録されず1件のままであること ---
			array(
				'test_condition_name' => 'duplicate_type=full（複製ボタン相当）の場合 => 取引先（イレギュラー）が二重登録されず1件だけ引き継がれる',
				'table_copy_type'     => 'all',
				'duplicate_type'      => 'full',
				'expected_value'      => '株式会社イレギュラー商事',
				'expected_count'      => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			// 複製を実行
			$new_post_id = bill_copy_post( $estimate_post_id, 'post', $case['table_copy_type'], $case['duplicate_type'] );

			// 複製に成功していること（false・null どちらも失敗として弾く）
			$this->assertIsInt( $new_post_id, $case['test_condition_name'] . '（複製成功）' );
			$this->assertGreaterThan( 0, $new_post_id, $case['test_condition_name'] . '（複製成功）' );

			// 引き継がれた値が期待通りであること
			$actual_value = get_post_meta( $new_post_id, 'bill_client_name_manual', true );
			$this->assertSame( $case['expected_value'], $actual_value, $case['test_condition_name'] . '（値）' );

			// add_post_meta が二重に呼ばれて値が複数登録されていないこと
			$actual_all = get_post_meta( $new_post_id, 'bill_client_name_manual' );
			$this->assertCount( $case['expected_count'], $actual_all, $case['test_condition_name'] . '（登録件数）' );

			// 作成した複製投稿を削除
			wp_delete_post( $new_post_id, true );
		}

		// 作成した見積書投稿を削除
		wp_delete_post( $estimate_post_id, true );
	}
}
