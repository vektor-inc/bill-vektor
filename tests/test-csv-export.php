<?php
/**
 * Class CsvExportTest
 *
 * CsvExport::can_export() / CsvExport::export_csv() のセキュリティ検証テスト
 *
 * @package BillVektor
 */

/**
 * CSV エクスポート機能のセキュリティテスト
 *
 * 未認証・権限不足・nonce 欠落のリクエストで請求書データの CSV が出力されないことを検証する。
 */
class CsvExportTest extends WP_UnitTestCase {

	/**
	 * テスト用管理者ユーザーID（edit_posts 権限あり）
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * テスト用購読者ユーザーID（edit_posts 権限なし）
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * テスト用寄稿者ユーザーID（edit_posts 権限あり）
	 *
	 * @var int
	 */
	private $contributor_user_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * 権限あり・権限なしのテスト用ユーザーを作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// 管理者ユーザーを作成（edit_posts 権限あり）
		$this->admin_user_id = wp_create_user( 'test_csv_admin', 'password', 'csv_admin@example.com' );
		$admin_user          = new WP_User( $this->admin_user_id );
		$admin_user->set_role( 'administrator' );

		// 購読者ユーザーを作成（edit_posts 権限なし）
		$this->subscriber_user_id = wp_create_user( 'test_csv_subscriber', 'password', 'csv_subscriber@example.com' );
		$subscriber_user          = new WP_User( $this->subscriber_user_id );
		$subscriber_user->set_role( 'subscriber' );

		// 寄稿者ユーザーを作成（edit_posts 権限あり）
		$this->contributor_user_id = wp_create_user( 'test_csv_contributor', 'password', 'csv_contributor@example.com' );
		$contributor_user          = new WP_User( $this->contributor_user_id );
		$contributor_user->set_role( 'contributor' );
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * スーパーグローバルとログイン状態、作成したユーザーを元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		// スーパーグローバルをリセット
		$_GET     = array();
		$_REQUEST = array();

		// ログイン状態をリセット
		wp_set_current_user( 0 );

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
	 * テスト条件から $_GET を組み立てる
	 *
	 * nonce はログインユーザーに紐づくため、ユーザー設定後に呼び出すこと。
	 *
	 * @param array $conditions 'action' と 'nonce' を含むテスト条件。
	 * @return void
	 */
	private function set_up_get_params( $conditions ) {
		$_GET = array();

		// action パラメーター（null の場合は付けない）
		if ( null !== $conditions['action'] ) {
			$_GET['action'] = $conditions['action'];
		}

		// nonce パラメーター
		if ( 'valid' === $conditions['nonce'] ) {
			$_GET['_wpnonce'] = wp_create_nonce( 'bill_csv_export' );
		} elseif ( 'invalid' === $conditions['nonce'] ) {
			$_GET['_wpnonce'] = 'invalid_nonce_string';
		}

		$_REQUEST = $_GET;
	}

	/**
	 * CsvExport::can_export() のテスト
	 *
	 * 未認証・権限不足・nonce 欠落／不正の各パターンで、
	 * CSV エクスポートの実行が許可されないことを検証する。
	 *
	 * @return void
	 */
	public function test_can_export() {

		$test_cases = array(
			// --- 異常系：未ログインでのアクセス（本 issue の脆弱性そのもの） ---
			array(
				'test_condition_name' => '未ログインで action=csv_freee にアクセスした場合 => false（エクスポートさせない）',
				'conditions'          => array(
					'user'   => 'anonymous',
					'action' => 'csv_freee',
					'nonce'  => 'none',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログインで action=csv_mf にアクセスした場合 => false（エクスポートさせない）',
				'conditions'          => array(
					'user'   => 'anonymous',
					'action' => 'csv_mf',
					'nonce'  => 'none',
				),
				'expected'            => false,
			),
			// --- 異常系：権限のないログインユーザー ---
			array(
				'test_condition_name' => 'edit_posts 権限のない購読者が有効な nonce でアクセスした場合 => false（エクスポートさせない）',
				'conditions'          => array(
					'user'   => 'subscriber',
					'action' => 'csv_freee',
					'nonce'  => 'valid',
				),
				'expected'            => false,
			),
			// --- 異常系：権限はあるが nonce が無い／不正（CSRF） ---
			array(
				'test_condition_name' => '管理者が nonce なしでアクセスした場合 => wp_die（CSRF として中断）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_freee',
					'nonce'  => 'none',
				),
				'expected'            => 'wp_die',
			),
			array(
				'test_condition_name' => '管理者が不正な nonce でアクセスした場合 => wp_die（CSRF として中断）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_mf',
					'nonce'  => 'invalid',
				),
				'expected'            => 'wp_die',
			),
			// --- 対象外リクエスト（境界値） ---
			array(
				'test_condition_name' => 'action パラメーターが無い通常のリクエストの場合 => false（対象外）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => null,
					'nonce'  => 'valid',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '検索フォームの絞り込み（action=send）の場合 => false（対象外・検索は素通し）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'send',
					'nonce'  => 'valid',
				),
				'expected'            => false,
			),
			// --- 正常系：権限と nonce が揃っている ---
			array(
				'test_condition_name' => '管理者が有効な nonce で action=csv_freee にアクセスした場合 => true（エクスポート可）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_freee',
					'nonce'  => 'valid',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者が有効な nonce で action=csv_mf にアクセスした場合 => true（エクスポート可）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_mf',
					'nonce'  => 'valid',
				),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// ログイン状態とリクエストパラメーターを設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );
			$this->set_up_get_params( $case['conditions'] );

			// wp_die を例外に置き換えて捕捉できるようにする
			// クロージャを変数に保持し、フレームワーク側のフィルターを消さないよう個別に外す
			$die_handler = function () {
				return function ( $message ) {
					throw new \Exception( 'wp_die called: ' . ( is_string( $message ) ? $message : '' ) );
				};
			};
			add_filter( 'wp_die_handler', $die_handler );

			$died   = false;
			$actual = null;
			try {
				$actual = CsvExport::can_export();
			} catch ( \Exception $e ) {
				$died = true;
			} finally {
				remove_filter( 'wp_die_handler', $die_handler );
				$_GET     = array();
				$_REQUEST = array();
			}

			if ( 'wp_die' === $case['expected'] ) {
				// nonce 不正時は wp_die で中断されること
				$this->assertTrue( $died, $case['test_condition_name'] );
			} else {
				// wp_die されず、期待した真偽値が返ること
				$this->assertFalse( $died, $case['test_condition_name'] . '（wp_die は発生しない想定）' );
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * CsvExport::export_csv() のテスト
	 *
	 * ガードを通過しないリクエストでは CSV が一切出力されないことを検証する。
	 * ガードを通過するケースは処理末尾の die() でテストプロセスごと終了してしまうため、
	 * ここでは扱わず can_export() の正常系テストと e2e テストで担保する。
	 *
	 * @return void
	 */
	public function test_export_csv() {

		$test_cases = array(
			// --- 異常系：未ログインでのアクセス（本 issue の脆弱性そのもの） ---
			array(
				'test_condition_name' => '未ログインで action=csv_freee にアクセスした場合 => 何も出力されない',
				'conditions'          => array(
					'user'   => 'anonymous',
					'action' => 'csv_freee',
					'nonce'  => 'none',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '未ログインで action=csv_mf にアクセスした場合 => 何も出力されない',
				'conditions'          => array(
					'user'   => 'anonymous',
					'action' => 'csv_mf',
					'nonce'  => 'none',
				),
				'expected'            => '',
			),
			// --- 異常系：権限のないログインユーザー ---
			array(
				'test_condition_name' => 'edit_posts 権限のない購読者が有効な nonce でアクセスした場合 => 何も出力されない',
				'conditions'          => array(
					'user'   => 'subscriber',
					'action' => 'csv_freee',
					'nonce'  => 'valid',
				),
				'expected'            => '',
			),
			// --- 対象外リクエスト（境界値）：検索の絞り込みでは何も出力しない ---
			array(
				'test_condition_name' => '検索フォームの絞り込み（action=send）の場合 => 何も出力されない',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'send',
					'nonce'  => 'valid',
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// ログイン状態とリクエストパラメーターを設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );
			$this->set_up_get_params( $case['conditions'] );

			// 出力をバッファリングして CSV が echo されないことを確認する
			ob_start();
			CsvExport::export_csv();
			$actual = ob_get_clean();

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			$_GET     = array();
			$_REQUEST = array();
		}
	}

	/**
	 * 寄稿者（contributor）が CSV をエクスポートできることのテスト
	 *
	 * ガードの権限は意図的に edit_posts としている。寄稿者は edit_posts を持つため
	 * エクスポートできるのが仕様であり、この挙動を後から変えられないよう固定しておく。
	 * （権限をこれ以上引き上げると、Author / Contributor 運用のサイトでエクスポートが
	 * 使えなくなる。個別の書類ページは寄稿者でも閲覧できるため、CSV だけ権限を上げても
	 * 漏洩量は変わらない。テーマ全体の認可モデルの見直しは別 issue で扱う。）
	 *
	 * @return void
	 */
	public function test_can_export__contributor() {

		$test_cases = array(
			// --- 正常系：寄稿者が有効な nonce で freee 用エクスポートを実行 ---
			array(
				'test_condition_name' => '寄稿者が有効な nonce で action=csv_freee にアクセスした場合 => true（edit_posts を持つのでエクスポート可）',
				'conditions'          => array(
					'user'   => 'contributor',
					'action' => 'csv_freee',
					'nonce'  => 'valid',
				),
				'expected'            => true,
			),
			// --- 正常系：寄稿者が有効な nonce で MF 用エクスポートを実行 ---
			array(
				'test_condition_name' => '寄稿者が有効な nonce で action=csv_mf にアクセスした場合 => true（edit_posts を持つのでエクスポート可）',
				'conditions'          => array(
					'user'   => 'contributor',
					'action' => 'csv_mf',
					'nonce'  => 'valid',
				),
				'expected'            => true,
			),
			// --- 異常系：寄稿者でも nonce が無ければ中断される ---
			array(
				'test_condition_name' => '寄稿者が nonce なしでアクセスした場合 => wp_die（権限があっても CSRF 検証は通す）',
				'conditions'          => array(
					'user'   => 'contributor',
					'action' => 'csv_freee',
					'nonce'  => 'none',
				),
				'expected'            => 'wp_die',
			),
		);

		// 前提として寄稿者が edit_posts を持つことを明示しておく
		// （WordPress のロール定義が変わった場合に、このテストの前提が崩れたと分かるようにする）
		$contributor = new WP_User( $this->contributor_user_id );
		$this->assertTrue( $contributor->has_cap( 'edit_posts' ), '寄稿者は edit_posts 権限を持つこと' );

		foreach ( $test_cases as $case ) {
			// ログイン状態とリクエストパラメーターを設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );
			$this->set_up_get_params( $case['conditions'] );

			// wp_die（wp_nonce_ays 経由を含む）を例外に置き換えて捕捉できるようにする
			$die_handler = function () {
				return function ( $message ) {
					throw new \Exception( 'wp_die called: ' . ( is_string( $message ) ? $message : '' ) );
				};
			};
			add_filter( 'wp_die_handler', $die_handler );

			$died   = false;
			$actual = null;
			try {
				$actual = CsvExport::can_export();
			} catch ( \Exception $e ) {
				$died = true;
			} finally {
				remove_filter( 'wp_die_handler', $die_handler );
				$_GET     = array();
				$_REQUEST = array();
			}

			if ( 'wp_die' === $case['expected'] ) {
				$this->assertTrue( $died, $case['test_condition_name'] );
			} else {
				$this->assertFalse( $died, $case['test_condition_name'] . '（wp_die は発生しない想定）' );
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 権限のないユーザーが nonce を持っていても CSV を取得できないことのテスト
	 *
	 * nonce は「操作元の正当性」を担保するもので権限の代替にはならないため、
	 * 有効な nonce があっても edit_posts 権限がなければエクスポートできないことを確認する。
	 *
	 * @return void
	 */
	public function test_can_export__capability() {

		$test_cases = array(
			// --- 異常系：未ログインユーザーが管理者の nonce を入手した場合 ---
			array(
				'test_condition_name' => '未ログインユーザーが管理者発行の nonce を使った場合 => false（エクスポートさせない）',
				'conditions'          => array(
					'nonce_owner' => 'admin',
					'user'        => 'anonymous',
				),
				'expected'            => false,
			),
			// --- 異常系：購読者が管理者の nonce を入手した場合 ---
			array(
				'test_condition_name' => '購読者が管理者発行の nonce を使った場合 => false（エクスポートさせない）',
				'conditions'          => array(
					'nonce_owner' => 'admin',
					'user'        => 'subscriber',
				),
				'expected'            => false,
			),
			// --- 正常系：管理者が自身の nonce を使った場合 ---
			array(
				'test_condition_name' => '管理者が自身の nonce を使った場合 => true（エクスポート可）',
				'conditions'          => array(
					'nonce_owner' => 'admin',
					'user'        => 'admin',
				),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// まず nonce の発行者としてログインし、nonce を生成する
			$this->set_current_user_by_type( $case['conditions']['nonce_owner'] );
			$nonce = wp_create_nonce( 'bill_csv_export' );

			// 実際にアクセスするユーザーへ切り替える
			$this->set_current_user_by_type( $case['conditions']['user'] );

			$_GET     = array(
				'action'   => 'csv_freee',
				'_wpnonce' => $nonce,
			);
			$_REQUEST = $_GET;

			// 権限チェックが nonce 検証より前にあるため wp_die は発生しない想定だが、
			// 念のため wp_die を例外化して検出できるようにしておく
			$die_handler = function () {
				return function ( $message ) {
					throw new \Exception( 'wp_die called: ' . ( is_string( $message ) ? $message : '' ) );
				};
			};
			add_filter( 'wp_die_handler', $die_handler );

			$died   = false;
			$actual = null;
			try {
				$actual = CsvExport::can_export();
			} catch ( \Exception $e ) {
				$died = true;
			} finally {
				remove_filter( 'wp_die_handler', $die_handler );
				$_GET     = array();
				$_REQUEST = array();
			}

			$this->assertFalse( $died, $case['test_condition_name'] . '（wp_die は発生しない想定）' );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
