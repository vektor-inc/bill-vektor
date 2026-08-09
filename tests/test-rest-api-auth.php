<?php
/**
 * Class RestApiAuthTest
 *
 * bill_rest_require_view_permission() と bill_rest_is_own_application_passwords_request() の
 * セキュリティ検証テスト
 *
 * @package BillVektor
 */

/**
 * REST API のアクセス制限（未ログイン拒否・閲覧権限拒否）のテスト
 *
 * 未ログインの REST リクエストが 401 で拒否され、ログイン済みでも書類の閲覧権限が無いユーザー
 * （購読者など）のリクエストが 403 で拒否されることを検証する。請求書の件名やユーザー名などが
 * これらのユーザーに取得されないことも合わせて確認する。
 * あわせて、閲覧権限のあるユーザーのリクエストは従来どおり通ること、および2つの例外
 * （「本人自身のアプリケーションパスワード」「本人自身のユーザー情報（/wp/v2/users/me の
 * 完全一致）」のエンドポイント）は閲覧権限が無いユーザーでも通ることを検証する。
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
	 * テスト用寄稿者ユーザーID
	 *
	 * 寄稿者は edit_posts を持つため、書類の閲覧権限があるユーザー（購読者以外）の代表として使う。
	 *
	 * @var int
	 */
	private $contributor_user_id;

	/**
	 * テスト用の請求書（投稿タイプ post）のID
	 *
	 * @var int
	 */
	private $invoice_post_id;

	/**
	 * request_rest_route() が最後にディスパッチしたレスポンスの生データ
	 *
	 * exposed（件名・ユーザー名一覧）の抽出はコレクション応答（配列の配列）を前提にしており、
	 * /wp/v2/users/me のような単一アイテムの応答では何も検査せず素通り（常に真）になってしまう。
	 * 「本人の情報しか返っていないこと」を個別に確認したいケースのために、生データを保持しておく。
	 *
	 * @var array|null
	 */
	private $last_response_data;

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

		// 寄稿者ユーザーを作成（閲覧権限のあるユーザーに影響が無いことの確認用）
		$this->contributor_user_id = wp_create_user( 'test_rest_contributor', 'password', 'rest_contributor@example.com' );
		$contributor_user          = new WP_User( $this->contributor_user_id );
		$contributor_user->set_role( 'contributor' );

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
		if ( $this->contributor_user_id ) {
			wp_delete_user( $this->contributor_user_id );
		}

		// REST サーバーを破棄して次のテストに持ち越さない
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * テスト条件からログインユーザーを設定する
	 *
	 * @param string $user_type 'anonymous' / 'admin' / 'subscriber' / 'contributor' のいずれか。
	 * @return void
	 */
	private function set_current_user_by_type( $user_type ) {
		if ( 'admin' === $user_type ) {
			wp_set_current_user( $this->admin_user_id );
		} elseif ( 'subscriber' === $user_type ) {
			wp_set_current_user( $this->subscriber_user_id );
		} elseif ( 'contributor' === $user_type ) {
			wp_set_current_user( $this->contributor_user_id );
		} else {
			// 未ログイン状態
			wp_set_current_user( 0 );
		}
	}

	/**
	 * テスト条件から、先行フィルターが返す値を組み立てる
	 *
	 * @param string|null $prior_result 'error_403' / 'authenticated' / 'false_value' / null。
	 * @return WP_Error|true|false|null 先行フィルターが返す値。
	 */
	private function get_prior_result_value( $prior_result ) {
		if ( 'error_403' === $prior_result ) {
			// 既に別のエラーが入っているケース
			return new WP_Error( 'prior_rest_error', 'prior error', array( 'status' => 403 ) );
		} elseif ( 'authenticated' === $prior_result ) {
			// 別の認証方式で認証済みと明示されているケース
			return true;
		} elseif ( 'false_value' === $prior_result ) {
			// rest_authentication_errors の契約（WP_Error / true / null）から外れた falsy 値を
			// 返すフィルターが同居しているケース
			return false;
		}

		// 判定されていない状態
		return null;
	}

	/**
	 * bill_rest_require_view_permission() の戻り値を、期待値と比較できる文字列に変換する
	 *
	 * 戻り値そのものを期待値に書くと WP_Error のインスタンス比較になり読みにくいため、
	 * 種別（と WP_Error の場合はステータスコード）が分かる文字列に変換する。
	 *
	 * @param mixed $value bill_rest_require_view_permission() の戻り値。
	 * @return string 'null' / 'true' / 'false' / 'WP_Error(401)' のような文字列。
	 */
	private function describe_filter_result( $value ) {
		if ( is_wp_error( $value ) ) {
			$error_data = $value->get_error_data();
			$status     = isset( $error_data['status'] ) ? $error_data['status'] : 0;
			return 'WP_Error(' . $status . ')';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}

		// 想定外の型（テストの失敗メッセージで気づけるようにしておく）
		return 'other';
	}

	/**
	 * 実際の REST リクエストと同じ手順でルートへアクセスする
	 *
	 * WP_REST_Server::serve_request() と同じく、まず check_authentication()（＝
	 * rest_authentication_errors フィルター）で認証を判定し、エラーであればその時点で
	 * ステータスを返す。認証を通過した場合のみルートへディスパッチする。
	 *
	 * ただし serve_request() 自体は通しておらず、その手順を手組みで再現したもの。
	 * ここで検証しているのは rest_authentication_errors フィルター単体の振る舞い
	 * （＝閲覧権限の無いリクエストがルートへ到達しないこと）であり、
	 * 個々のエンドポイントの権限チェックまでを検証するものではない。
	 *
	 * $GLOBALS['wp']->query_vars['rest_route'] を実行前に設定しているのは、実際のリクエストでは
	 * コア（rest_api_loaded()）が check_authentication() を呼ぶより前にこの値を確定させており、
	 * bill_rest_is_own_application_passwords_request() がその値を見て自分自身のアプリケーション
	 * パスワードのエンドポイントかどうかを判定するため。ここで設定しないと本番の状況を再現できない。
	 *
	 * ディスパッチしたレスポンスの生データは $this->last_response_data にも保持する。
	 * 'exposed' の抽出はコレクション応答（配列の配列）しか見ていないため、/wp/v2/users/me の
	 * ような単一アイテムの応答では常に空になり検査が空振りする。単一アイテムの内容を個別に
	 * 確認したい場合は、呼び出し元でこのプロパティを直接見ること。
	 *
	 * @param string      $route        アクセスするルート（例: '/wp/v2/posts'）。
	 * @param array       $params       リクエストパラメーター。
	 * @param string|null $prior_result 先行フィルターの結果。'error_403' / 'authenticated' / 'false_value' / null。
	 * @return array 'status'（HTTPステータスコード）と 'exposed'（露出した件名・ユーザー名の配列）。
	 */
	private function request_rest_route( $route, $params, $prior_result ) {
		// 他のプラグインが先に認証結果を返しているケースを再現する（優先度5で本体より先に実行）
		$prior_filter = null;
		if ( null !== $prior_result ) {
			$prior_value  = $this->get_prior_result_value( $prior_result );
			$prior_filter = function () use ( $prior_value ) {
				return $prior_value;
			};
			add_filter( 'rest_authentication_errors', $prior_filter, 5 );
		}

		// 実際のリクエストと同じく、認証フィルターが走る前にルートを確定させておく
		$GLOBALS['wp']->query_vars['rest_route'] = $route;

		// 前回のケースのレスポンスを持ち越さない
		$this->last_response_data = null;

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

			// 単一アイテムの応答（/wp/v2/users/me など）を個別に検査したいケースのために保持する
			$this->last_response_data = $data;

			$actual = array(
				'status'  => $response->get_status(),
				'exposed' => $exposed,
			);
		}

		// 先行フィルターを外す
		if ( null !== $prior_filter ) {
			remove_filter( 'rest_authentication_errors', $prior_filter, 5 );
		}

		// 次のテストに持ち越さないようルートをリセットする
		unset( $GLOBALS['wp']->query_vars['rest_route'] );

		return $actual;
	}

	/**
	 * bill_rest_require_view_permission() のテスト
	 *
	 * 未ログインの REST リクエストが 401 で拒否されて請求書の件名が露出しないこと、
	 * ログイン済みでも書類の閲覧権限が無いユーザー（購読者）のリクエストが 403 で拒否されて
	 * 請求書の件名・ユーザー名が露出しないこと、閲覧権限のあるユーザー（管理者・寄稿者）の
	 * リクエストは従来どおり通ること、bill_vektor_can_view_documents フィルターの判定結果が
	 * そのまま反映されること、先行するフィルターの判定結果（WP_Error / true）を潰さないこと、
	 * 契約外の falsy な値でガードが無効化されないこと、および2つの例外（「本人自身の
	 * アプリケーションパスワード」「本人自身のユーザー情報（/wp/v2/users/me の完全一致）」の
	 * エンドポイント）は閲覧権限が無くても通ることを検証する。
	 *
	 * 期待値の 'filter_result' は bill_rest_require_view_permission() の戻り値そのもの。
	 * 通す場合に 'null'（＝判定しない）であることが重要で、ここで 'true' を返すと
	 * コアの rest_cookie_check_errors()（優先度100）が即 return して
	 * Cookie 認証の REST リクエストに対する nonce 検証（CSRF対策）が失われる。
	 *
	 * @return void
	 */
	public function test_bill_rest_require_view_permission() {

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
					'filter_result' => 'WP_Error(401)',
					'status'        => 401,
					'exposed'       => array(),
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
					'filter_result' => 'WP_Error(401)',
					'status'        => 401,
					'exposed'       => array(),
				),
			),
			// --- 正常系：閲覧権限のあるログイン済みユーザーのリクエストは従来どおり通る ---
			array(
				'test_condition_name' => '管理者でログインして /wp/v2/posts にアクセスした場合 => nullを返して200（請求書を取得でき、コアのnonce検証も残る）',
				'conditions'          => array(
					'user'         => 'admin',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'null',
					'status'        => 200,
					'exposed'       => array( self::TEST_INVOICE_TITLE ),
				),
			),
			array(
				'test_condition_name' => '寄稿者（edit_postsを持つ）でログインして /wp/v2/posts にアクセスした場合 => nullを返して200（権限のあるユーザーに影響が無いこと）',
				'conditions'          => array(
					'user'         => 'contributor',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'null',
					'status'        => 200,
					'exposed'       => array( self::TEST_INVOICE_TITLE ),
				),
			),
			// --- 異常系：書類の閲覧権限が無いログインユーザーのアクセス（本 issue の脆弱性そのもの） ---
			array(
				'test_condition_name' => '購読者でログインして /wp/v2/posts にアクセスした場合 => 403（閲覧権限が無いため請求書の件名を返さない）',
				'conditions'          => array(
					'user'         => 'subscriber',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => '購読者でログインして /wp/v2/search にアクセスした場合 => 403（検索結果から請求書の件名を返さない）',
				'conditions'          => array(
					'user'         => 'subscriber',
					'route'        => '/wp/v2/search',
					'params'       => array( 'search' => self::TEST_INVOICE_TITLE ),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => '購読者でログインして /wp/v2/users にアクセスした場合 => 403（ユーザー名を返さない）',
				'conditions'          => array(
					'user'         => 'subscriber',
					'route'        => '/wp/v2/users',
					'params'       => array(),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
				),
			),
			// --- 正常系：bill_vektor_can_view_documents フィルターの判定結果がREST APIにも反映される ---
			array(
				'test_condition_name' => 'bill_vektor_can_view_documentsフィルターで購読者にも閲覧を許可した場合、購読者で/wp/v2/postsにアクセス => nullを返して200（フロントと同じ判定を共有している）',
				'conditions'          => array(
					'user'                  => 'subscriber',
					'route'                 => '/wp/v2/posts',
					'params'                => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result'          => null,
					'allow_subscriber_view' => true,
				),
				'expected'            => array(
					'filter_result' => 'null',
					'status'        => 200,
					'exposed'       => array( self::TEST_INVOICE_TITLE ),
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
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
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
					'filter_result' => 'true',
					'status'        => 200,
					'exposed'       => array( self::TEST_INVOICE_TITLE ),
				),
			),
			array(
				'test_condition_name' => '購読者でログインして先行フィルターが認証済み（true）を返している場合 => 200（既存仕様どおり通り、意図しない後退でないこと）',
				'conditions'          => array(
					'user'         => 'subscriber',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => 'authenticated',
				),
				'expected'            => array(
					'filter_result' => 'true',
					'status'        => 200,
					'exposed'       => array( self::TEST_INVOICE_TITLE ),
				),
			),
			// --- 境界値：契約外の falsy な値でガードが無効化されないこと（fail-closed） ---
			array(
				'test_condition_name' => '未ログインで先行フィルターが契約外のfalseを返している場合 => 401（ガードが無効化されない）',
				'conditions'          => array(
					'user'         => 'anonymous',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => 'false_value',
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(401)',
					'status'        => 401,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => 'ログイン済みで先行フィルターが契約外のfalseを返している場合 => falseをそのまま返して200（ログイン済みは通す）',
				'conditions'          => array(
					'user'         => 'admin',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'include' => array( $this->invoice_post_id ) ),
					'prior_result' => 'false_value',
				),
				'expected'            => array(
					'filter_result' => 'false',
					'status'        => 200,
					'exposed'       => array( self::TEST_INVOICE_TITLE ),
				),
			),
			// --- B案の例外：本人自身のアプリケーションパスワードのエンドポイントは閲覧権限が無くても通る ---
			array(
				'test_condition_name' => '購読者が自分自身（me指定）のアプリケーションパスワード一覧にアクセスした場合 => nullを返して200（プロフィール画面の操作を妨げない）',
				'conditions'          => array(
					'user'                          => 'subscriber',
					'route'                         => '/wp/v2/users/me/application-passwords',
					'params'                        => array(),
					'prior_result'                  => null,
					// wp_is_application_passwords_available()（is_ssl() || 'local' ===
					// wp_get_environment_type() が既定条件）に結果が左右されないよう明示的に有効化する。
					// これが無いと、この2件の「→200」は実行環境（HTTPSかどうか・WP_ENVIRONMENT_TYPE）に
					// 暗黙依存し、環境が変わるとコアが501を返して「セキュリティの回帰に見える失敗」に
					// なってしまう。ここで検証したいのは本テーマ独自のゲート（bill_rest_require_view_permission）
					// が通すかどうかであって、コア機能の有効・無効ではないため固定する。
					'force_app_passwords_available' => true,
				),
				'expected'            => array(
					'filter_result' => 'null',
					'status'        => 200,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => '購読者が自分自身（数値ID指定）のアプリケーションパスワード一覧にアクセスした場合 => nullを返して200（"me"以外の自分自身の指定でも通る）',
				'conditions'          => array(
					'user'                          => 'subscriber',
					// 実行時にしか分からない自分自身のユーザーIDへ差し替えるプレースホルダー
					'route'                         => '/wp/v2/users/__SELF_ID__/application-passwords',
					'params'                        => array(),
					'prior_result'                  => null,
					// 上のケースと同じ理由（wp_is_application_passwords_available() への暗黙依存を断つ）
					'force_app_passwords_available' => true,
				),
				'expected'            => array(
					'filter_result' => 'null',
					'status'        => 200,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => '購読者が他人（管理者）のアプリケーションパスワード一覧にアクセスした場合 => 403（本人自身の例外をすり抜けられない）',
				'conditions'          => array(
					'user'         => 'subscriber',
					// 実行時にしか分からない他人（管理者）のユーザーIDへ差し替えるプレースホルダー
					'route'        => '/wp/v2/users/__OTHER_ID__/application-passwords',
					'params'       => array(),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
				),
			),
			// --- B-2案の例外：自分自身のユーザー情報（/wp/v2/users/me の完全一致）は閲覧権限が無くても通る ---
			array(
				'test_condition_name' => '購読者が自分自身のユーザー情報（/wp/v2/users/me）にアクセスした場合 => nullを返して200（コアのユーザー設定永続化を妨げない）',
				'conditions'          => array(
					'user'                => 'subscriber',
					'route'               => '/wp/v2/users/me',
					'params'              => array(),
					'prior_result'        => null,
					// 'exposed' の抽出は単一アイテムの応答に対しては何も検査せず素通りしてしまう
					// （コレクション応答しか見ていないため）。「本人の情報しか返っていないこと」を
					// 実際に確認するため、応答の id が購読者本人のIDと一致することを別途検証する
					'expect_own_user_id' => true,
				),
				'expected'            => array(
					'filter_result' => 'null',
					'status'        => 200,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => '購読者が/wp/v2/users/meに継ぎ足したパスにアクセスした場合 => 403（完全一致以外は例外にしない）',
				'conditions'          => array(
					'user'         => 'subscriber',
					'route'        => '/wp/v2/users/me/xxx',
					'params'       => array(),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
				),
			),
			array(
				'test_condition_name' => '購読者が他人（管理者）のユーザー情報を数値IDで指定した場合 => 403（"me"以外の指定は例外にしない。issue #320で塞いだユーザー名の露出そのもの）',
				'conditions'          => array(
					'user'         => 'subscriber',
					// 実行時にしか分からない他人（管理者）のユーザーIDへ差し替えるプレースホルダー
					'route'        => '/wp/v2/users/__OTHER_ID__',
					'params'       => array(),
					'prior_result' => null,
				),
				'expected'            => array(
					'filter_result' => 'WP_Error(403)',
					'status'        => 403,
					'exposed'       => array(),
				),
			),
		);

		// 実行時にしか分からないユーザーIDをルートのプレースホルダーへ差し込む
		// （配列のインデックス番号に依存すると、ケースの追加・並べ替えでずれるため文字列置換にする）
		foreach ( $test_cases as $index => $case ) {
			$test_cases[ $index ]['conditions']['route'] = str_replace(
				array( '__SELF_ID__', '__OTHER_ID__' ),
				array( $this->subscriber_user_id, $this->admin_user_id ),
				$case['conditions']['route']
			);
		}

		foreach ( $test_cases as $case ) {
			// ログイン状態を設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );

			// bill_vektor_can_view_documents フィルターで購読者にも閲覧を許可するケースを再現する
			$allow_filter_added = false;
			if ( ! empty( $case['conditions']['allow_subscriber_view'] ) ) {
				add_filter( 'bill_vektor_can_view_documents', '__return_true' );
				$allow_filter_added = true;
			}

			// アプリケーションパスワード機能の可否（wp_is_application_passwords_available()）を
			// 実行環境（HTTPS/WP_ENVIRONMENT_TYPE）に依存させないよう固定するケースを再現する
			$app_passwords_filter_added = false;
			if ( ! empty( $case['conditions']['force_app_passwords_available'] ) ) {
				add_filter( 'wp_is_application_passwords_available', '__return_true' );
				$app_passwords_filter_added = true;
			}

			// bill_rest_require_view_permission() の戻り値そのものを直接確認する。
			// bill_rest_is_own_application_passwords_request() がルートを見て判定するため、
			// request_rest_route() の内部と同じく、ここでも先に rest_route を確定させておく
			// （実際のリクエストでも、認証フィルターが走る前にコアがこの値を確定させている）
			$GLOBALS['wp']->query_vars['rest_route'] = $case['conditions']['route'];
			$filter_result                           = bill_rest_require_view_permission( $this->get_prior_result_value( $case['conditions']['prior_result'] ) );
			unset( $GLOBALS['wp']->query_vars['rest_route'] );

			// REST リクエストを実行する
			$actual = $this->request_rest_route(
				$case['conditions']['route'],
				$case['conditions']['params'],
				$case['conditions']['prior_result']
			);

			if ( $allow_filter_added ) {
				remove_filter( 'bill_vektor_can_view_documents', '__return_true' );
			}
			if ( $app_passwords_filter_added ) {
				remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			}

			// 戻り値の判定結果を先頭に加える
			$actual = array_merge( array( 'filter_result' => $this->describe_filter_result( $filter_result ) ), $actual );

			// 期待値テスト
			$this->assertEquals( $case['expected'], $actual, $case['test_condition_name'] );

			// 'exposed' の抽出は単一アイテムの応答（/wp/v2/users/me 等）では何も検査せず
			// 素通りしてしまうため、「本人の情報しか返っていないこと」を id で個別に確認する
			if ( ! empty( $case['conditions']['expect_own_user_id'] ) ) {
				$this->assertIsArray( $this->last_response_data, $case['test_condition_name'] . '（応答データの取得確認）' );
				$this->assertSame(
					$this->subscriber_user_id,
					isset( $this->last_response_data['id'] ) ? $this->last_response_data['id'] : null,
					$case['test_condition_name'] . '（応答のidが購読者本人と一致すること）'
				);
			}

			// ログイン状態をリセットする
			wp_set_current_user( 0 );
		}
	}

	/**
	 * bill_rest_is_own_application_passwords_request() のテスト
	 *
	 * 「本人自身のアプリケーションパスワード」のエンドポイントだけを厳密に判定できることを検証する。
	 * この判定はルートの部分一致・strpos的な緩い判定にすると、細工したパスで他人の
	 * アプリケーションパスワードのエンドポイントへの例外扱いをすり抜けられてしまうため、
	 * 正規表現の前後アンカー（^ $）が実際に効いていることを、末尾に文字列を足す・
	 * "me" に似た別の文字列を使うといった攻撃的なパスも含めて確認する。
	 *
	 * @return void
	 */
	public function test_bill_rest_is_own_application_passwords_request() {

		$test_cases = array(
			// --- 正常系：本人自身のエンドポイント ---
			array(
				'test_condition_name' => '"me"で自分自身のアプリケーションパスワード一覧を指定した場合 => true',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/application-passwords',
				),
				'expected'            => true,
			),
			// --- 異常系：未ログインでの "me" 指定（先頭の is_user_logged_in() ガードの固定化） ---
			array(
				'test_condition_name' => '未ログインで"me"のアプリケーションパスワード一覧を指定した場合 => false（"me"分岐はガードが無ければ無条件でtrueを返す位置のため、最も価値のある回帰確認）',
				'conditions'          => array(
					'user'  => 'anonymous',
					'route' => '/wp/v2/users/me/application-passwords',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '数値IDで自分自身のアプリケーションパスワード個別項目（UUID指定）を指定した場合 => true',
				'conditions'          => array(
					'user'  => 'subscriber',
					// 実行時にしか分からない自分自身のユーザーIDへ差し替えるプレースホルダー
					'route' => '/wp/v2/users/__SELF_ID__/application-passwords/dummy-uuid-1234',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '"me"で使用中のパスワード参照（/introspect）を指定した場合 => true（"[\w-]+"に包含される1セグメントとして通る）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/application-passwords/introspect',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '"me"のエンドポイントに末尾スラッシュを付けて指定した場合 => true（untrailingslashit()で除去してから判定する）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/application-passwords/',
				),
				'expected'            => true,
			),
			// --- 異常系・境界値：本人以外、または他のルートとの混同 ---
			array(
				'test_condition_name' => '数値IDで他人（管理者）のアプリケーションパスワード一覧を指定した場合 => false（本人以外は例外にしない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					// 実行時にしか分からない他人（管理者）のユーザーIDへ差し替えるプレースホルダー
					'route' => '/wp/v2/users/__OTHER_ID__/application-passwords',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '"me"に似ているが別の文字列（"meta"）をIDとして指定した場合 => false（前方一致で"me"と誤認しない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/meta/application-passwords',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'エンドポイント名の末尾に文字列を継ぎ足したパスを指定した場合 => false（末尾アンカーにより部分一致で通らない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/application-passwords-unrelated',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'エンドポイントの後ろに余分なセグメントを継ぎ足したパスを指定した場合 => false（1セグメントを超える継ぎ足しは通らない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/application-passwords/x/y',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '関係の無いルート（/wp/v2/posts）を指定した場合 => false',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/posts',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'REST のルートが未確定（$GLOBALS[\'wp\']にrest_routeが無い）場合 => false（安全側に倒す）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => false, // rest_route を設定しないことを表す
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'rest_routeが文字列でない場合（?rest_route[]=のような配列化）=> false（is_string()のfail-closed分岐）',
				'conditions'          => array(
					'user'  => 'subscriber',
					// 文字列以外を指定した rest_route（配列）をそのまま query_vars に設定するための値
					'route' => array( '/wp/v2/users/me/application-passwords' ),
				),
				'expected'            => false,
			),
		);

		// 実行時にしか分からないユーザーIDをルートのプレースホルダーへ差し込む
		// （配列のインデックス番号に依存すると、ケースの追加・並べ替えでずれるため文字列置換にする。
		//  'route' が false のケース（rest_route 未確定）は置換対象外のため is_string() で除外する）
		foreach ( $test_cases as $index => $case ) {
			if ( ! is_string( $case['conditions']['route'] ) ) {
				continue;
			}
			$test_cases[ $index ]['conditions']['route'] = str_replace(
				array( '__SELF_ID__', '__OTHER_ID__' ),
				array( $this->subscriber_user_id, $this->admin_user_id ),
				$case['conditions']['route']
			);
		}

		foreach ( $test_cases as $case ) {
			// ログイン状態を設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );

			// 現在の REST ルートを再現する
			// (コア（rest_api_loaded()）が check_authentication() 実行より前に
			//  $GLOBALS['wp']->query_vars['rest_route'] へ確定させる値と同じ形式で設定する)
			if ( false === $case['conditions']['route'] ) {
				unset( $GLOBALS['wp']->query_vars['rest_route'] );
			} else {
				$GLOBALS['wp']->query_vars['rest_route'] = $case['conditions']['route'];
			}

			$actual = bill_rest_is_own_application_passwords_request();

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// ログイン状態と rest_route をリセットする
			wp_set_current_user( 0 );
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}
	}

	/**
	 * bill_rest_is_own_user_info_request() のテスト
	 *
	 * B-2案で追加した「自分自身のユーザー情報（/wp/v2/users/me の完全一致）」の例外が、
	 * 完全一致のみを許可し、一覧（/wp/v2/users）・継ぎ足し（/wp/v2/users/me/xxx）・
	 * 他人の数値ID（/wp/v2/users/<他人のID>）のいずれも通さないことを検証する。
	 * とくに /wp/v2/users（末尾に me が付かない一覧）を通さないことは、issue #320 で塞いだ
	 * 「権限のないユーザーにユーザー名を返してしまう」抜け道そのものの再発防止であり、
	 * このテストの中で最も重要な固定化。
	 *
	 * @return void
	 */
	public function test_bill_rest_is_own_user_info_request() {

		$test_cases = array(
			// --- 正常系：本人自身のエンドポイント ---
			array(
				'test_condition_name' => '/wp/v2/users/me を指定した場合 => true',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '/wp/v2/users/me に末尾スラッシュを付けて指定した場合 => true（untrailingslashit()で除去してから判定する）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/',
				),
				'expected'            => true,
			),
			// --- 異常系・境界値：一覧・継ぎ足し・他人の指定、未ログイン ---
			array(
				'test_condition_name' => '/wp/v2/users（末尾にmeが付かない一覧）を指定した場合 => false（issue #320で塞いだユーザー名の露出を再発させない、最重要の固定化）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '/wp/v2/users/me の後ろに文字列を継ぎ足したパスを指定した場合 => false（末尾アンカーにより部分一致で通らない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => '/wp/v2/users/me/xxx',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '数値IDで他人（管理者）のユーザー情報を指定した場合 => false（"me"以外は例外にしない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					// 実行時にしか分からない他人（管理者）のユーザーIDへ差し替えるプレースホルダー
					'route' => '/wp/v2/users/__OTHER_ID__',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '数値IDで自分自身のユーザー情報を指定した場合 => false（コアが実際に使う形は"me"のみのため、数値IDは"自分自身"でも例外にしない）',
				'conditions'          => array(
					'user'  => 'subscriber',
					// 実行時にしか分からない自分自身のユーザーIDへ差し替えるプレースホルダー
					'route' => '/wp/v2/users/__SELF_ID__',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログインで/wp/v2/users/meを指定した場合 => false（既存の未ログインガードの確認）',
				'conditions'          => array(
					'user'  => 'anonymous',
					'route' => '/wp/v2/users/me',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'REST のルートが未確定（$GLOBALS[\'wp\']にrest_routeが無い）場合 => false（安全側に倒す）',
				'conditions'          => array(
					'user'  => 'subscriber',
					'route' => false, // rest_route を設定しないことを表す
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'rest_routeが文字列でない場合（?rest_route[]=のような配列化）=> false（is_string()のfail-closed分岐。例外1側の対称なケース）',
				'conditions'          => array(
					'user'  => 'subscriber',
					// 文字列以外を指定した rest_route（配列）をそのまま query_vars に設定するための値
					'route' => array( '/wp/v2/users/me' ),
				),
				'expected'            => false,
			),
		);

		// 実行時にしか分からないユーザーIDをルートのプレースホルダーへ差し込む
		// （配列のインデックス番号に依存すると、ケースの追加・並べ替えでずれるため文字列置換にする。
		//  'route' が false のケース（rest_route 未確定）・配列のケース（非文字列化）は
		//  置換対象外のため is_string() で除外する）
		foreach ( $test_cases as $index => $case ) {
			if ( ! is_string( $case['conditions']['route'] ) ) {
				continue;
			}
			$test_cases[ $index ]['conditions']['route'] = str_replace(
				array( '__SELF_ID__', '__OTHER_ID__' ),
				array( $this->subscriber_user_id, $this->admin_user_id ),
				$case['conditions']['route']
			);
		}

		foreach ( $test_cases as $case ) {
			// ログイン状態を設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );

			// 現在の REST ルートを再現する
			// (コア（rest_api_loaded()）が check_authentication() 実行より前に
			//  $GLOBALS['wp']->query_vars['rest_route'] へ確定させる値と同じ形式で設定する)
			if ( false === $case['conditions']['route'] ) {
				unset( $GLOBALS['wp']->query_vars['rest_route'] );
			} else {
				$GLOBALS['wp']->query_vars['rest_route'] = $case['conditions']['route'];
			}

			$actual = bill_rest_is_own_user_info_request();

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// ログイン状態と rest_route をリセットする
			wp_set_current_user( 0 );
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}
	}
}
