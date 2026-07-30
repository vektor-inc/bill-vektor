<?php
/**
 * Class RestApiAuthTest
 *
 * bill_rest_require_login() のセキュリティ検証テスト
 *
 * @package BillVektor
 */

/**
 * REST API の未ログインアクセス制限のテスト
 *
 * 未ログインの REST リクエストが 401 で拒否され、請求書の件名などが匿名の第三者に
 * 取得されないことを検証する。あわせてログイン済みのリクエストは従来どおり通ることを確認する。
 */
class RestApiAuthTest extends WP_UnitTestCase {

	/**
	 * テスト用の請求書（投稿タイプ post）の件名
	 *
	 * REST のレスポンスに件名が露出しているかどうかの判定に使う。
	 *
	 * @var string
	 */
	const TEST_INVOICE_TITLE = 'テスト請求書の件名';

	/**
	 * テスト用管理者ユーザーID
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * テスト用購読者ユーザーID
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * テスト用の請求書（投稿タイプ post）のID
	 *
	 * @var int
	 */
	private $invoice_post_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * REST サーバーを初期化し、テスト用ユーザーと請求書を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// REST サーバーを初期化する（rest_api_init が実行され、コアのルートが登録される）
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();

		// 管理者ユーザーを作成
		$this->admin_user_id = wp_create_user( 'test_rest_admin', 'password', 'rest_admin@example.com' );
		$admin_user          = new WP_User( $this->admin_user_id );
		$admin_user->set_role( 'administrator' );

		// 購読者ユーザーを作成
		$this->subscriber_user_id = wp_create_user( 'test_rest_subscriber', 'password', 'rest_subscriber@example.com' );
		$subscriber_user          = new WP_User( $this->subscriber_user_id );
		$subscriber_user->set_role( 'subscriber' );

		// テスト用の請求書を作成する（BillVektor の請求書は組み込み投稿タイプ post を流用している）
		$this->invoice_post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => self::TEST_INVOICE_TITLE,
				'post_status' => 'publish',
				'post_author' => $this->admin_user_id,
			)
		);
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * ログイン状態・REST サーバー・作成したデータを元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		// ログイン状態をリセット
		wp_set_current_user( 0 );

		// 作成した請求書を削除
		if ( $this->invoice_post_id ) {
			wp_delete_post( $this->invoice_post_id, true );
		}

		// 作成したユーザーを削除
		if ( $this->admin_user_id ) {
			wp_delete_user( $this->admin_user_id );
		}
		if ( $this->subscriber_user_id ) {
			wp_delete_user( $this->subscriber_user_id );
		}

		// REST サーバーを破棄して次のテストに持ち越さない
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * テスト条件からログインユーザーを設定する
	 *
	 * @param string $user_type 'anonymous' / 'admin' / 'subscriber' のいずれか。
	 * @return void
	 */
	private function set_current_user_by_type( $user_type ) {
		if ( 'admin' === $user_type ) {
			wp_set_current_user( $this->admin_user_id );
		} elseif ( 'subscriber' === $user_type ) {
			wp_set_current_user( $this->subscriber_user_id );
		} else {
			// 未ログイン状態
			wp_set_current_user( 0 );
		}
	}

	/**
	 * 実際の REST リクエストと同じ手順でルートへアクセスする
	 *
	 * WP_REST_Server::serve_request() と同じく、まず check_authentication()（＝
	 * rest_authentication_errors フィルター）で認証を判定し、エラーであればその時点で
	 * ステータスを返す。認証を通過した場合のみルートへディスパッチする。
	 *
	 * @param string      $route        アクセスするルート（例: '/wp/v2/posts'）。
	 * @param array       $params       リクエストパラメーター。
	 * @param string|null $prior_result 先行フィルターの結果。'error_403' / 'authenticated' / null。
	 * @return array 'status'（HTTPステータスコード）と 'exposed'（露出した件名・ユーザー名の配列）。
	 */
	private function request_rest_route( $route, $params, $prior_result ) {
		// 他のプラグインが先に認証結果を返しているケースを再現する（優先度5で本体より先に実行）
		$prior_filter = null;
		if ( 'error_403' === $prior_result ) {
			// 既に別のエラーが入っているケース
			$prior_filter = function () {
				return new WP_Error( 'prior_rest_error', 'prior error', array( 'status' => 403 ) );
			};
		} elseif ( 'authenticated' === $prior_result ) {
			// 別の認証方式で認証済みと明示されているケース
			$prior_filter = function () {
				return true;
			};
		}
		if ( null !== $prior_filter ) {
			add_filter( 'rest_authentication_errors', $prior_filter, 5 );
		}

		$server = rest_get_server();

		// 認証の判定（rest_authentication_errors フィルターの結果）
		$auth_result = $server->check_authentication();

		if ( is_wp_error( $auth_result ) ) {
			// 認証エラーの場合はルートへ到達せずエラーを返す
			$error_data = $auth_result->get_error_data();
			$status     = isset( $error_data['status'] ) ? $error_data['status'] : 500;
			$actual     = array(
				'status'  => $status,
				'exposed' => array(),
			);
		} else {
			// 認証を通過した場合はルートへディスパッチする
			$request = new WP_REST_Request( 'GET', $route );
			foreach ( $params as $param_key => $param_value ) {
				$request->set_param( $param_key, $param_value );
			}
			$response = rest_do_request( $request );

			// レスポンスから露出した情報（投稿の件名 / ユーザー名）を取り出す
			$exposed = array();
			$data    = $response->get_data();
			if ( is_array( $data ) ) {
				foreach ( $data as $item ) {
					if ( isset( $item['title']['rendered'] ) ) {
						// 投稿（請求書）の件名
						$exposed[] = $item['title']['rendered'];
					} elseif ( isset( $item['name'] ) ) {
						// ユーザーの表示名
						$exposed[] = $item['name'];
					}
				}
			}

			$actual = array(
				'status'  => $response->get_status(),
				'exposed' => $exposed,
			);
		}

		// 先行フィルターを外す
		if ( null !== $prior_filter ) {
			remove_filter( 'rest_authentication_errors', $prior_filter, 5 );
		}

		return $actual;
	}

	/**
	 * bill_rest_require_login() のテスト
	 *
	 * 未ログインの REST リクエストが 401 で拒否されて請求書の件名が露出しないこと、
	 * ログイン済みのリクエストは従来どおり通ること、
	 * 先行するフィルターの判定結果（WP_Error / true）を潰さないことを検証する。
	 *
	 * @return void
	 */
	public function test_bill_rest_require_login() {

		$test_cases = array(
			// --- 異常系：未ログインでのアクセス（本 issue の脆弱性そのもの） ---
			array(
				'test_condition_name' => '未ログインで /wp/v2/posts にアクセスした場合 => 401（請求書の件名を返さない）',
				'conditions'          => array(
					'user'         => 'anonymous',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => null,
				),
				'expected'            => array(
					'status'  => 401,
					'exposed' => array(),
				),
			),
			array(
				'test_condition_name' => '未ログインで /wp/v2/users にアクセスした場合 => 401（ユーザー名を返さない）',
				'conditions'          => array(
					'user'         => 'anonymous',
					'route'        => '/wp/v2/users',
					'params'       => array(),
					'prior_result' => null,
				),
				'expected'            => array(
					'status'  => 401,
					'exposed' => array(),
				),
			),
			// --- 正常系：ログイン済みのリクエストは従来どおり通る ---
			array(
				'test_condition_name' => '管理者でログインして /wp/v2/posts にアクセスした場合 => 200（請求書を取得できる）',
				'conditions'          => array(
					'user'         => 'admin',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => null,
				),
				'expected'            => array(
					'status'  => 200,
					'exposed' => array( self::TEST_INVOICE_TITLE ),
				),
			),
			array(
				'test_condition_name' => '購読者でログインして /wp/v2/posts にアクセスした場合 => 200（ログイン済みなら通す）',
				'conditions'          => array(
					'user'         => 'subscriber',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => null,
				),
				'expected'            => array(
					'status'  => 200,
					'exposed' => array( self::TEST_INVOICE_TITLE ),
				),
			),
			// --- 境界値：先行するフィルターの判定結果を尊重する ---
			array(
				'test_condition_name' => '未ログインで先行フィルターが403のWP_Errorを返している場合 => 403（先行のエラーを上書きしない）',
				'conditions'          => array(
					'user'         => 'anonymous',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => 'error_403',
				),
				'expected'            => array(
					'status'  => 403,
					'exposed' => array(),
				),
			),
			array(
				'test_condition_name' => '未ログインで先行フィルターが認証済み（true）を返している場合 => 200（他の認証方式の結果を潰さない）',
				'conditions'          => array(
					'user'         => 'anonymous',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => 'authenticated',
				),
				'expected'            => array(
					'status'  => 200,
					'exposed' => array( self::TEST_INVOICE_TITLE ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// ログイン状態を設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );

			// REST リクエストを実行する
			$actual = $this->request_rest_route(
				$case['conditions']['route'],
				$case['conditions']['params'],
				$case['conditions']['prior_result']
			);

			// 期待値テスト
			$this->assertEquals( $case['expected'], $actual, $case['test_condition_name'] );

			// ログイン状態をリセットする
			wp_set_current_user( 0 );
		}
	}
}
