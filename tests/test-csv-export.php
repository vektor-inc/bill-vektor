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
	 * nonce 検証失敗時に fail-closed になることのテスト
	 *
	 * wp_nonce_ays() は内部で wp_die() を呼ぶが、wp_die_handler フィルターで
	 * 「処理を終了せずに戻る」ハンドラへ差し替えることができる。その場合でも
	 * can_export() が true を返さない（＝ CSV を出力しない）ことを検証する。
	 *
	 * 他のテストは wp_die を例外化して捕捉しているため、この経路だけは
	 * 「戻ってくるハンドラ」を明示的に差し込まないと通らない。
	 *
	 * @return void
	 */
	public function test_can_export__fail_closed() {

		$test_cases = array(
			// --- 異常系：nonce なし ---
			array(
				'test_condition_name' => 'wp_die が戻る実装でも nonce なしなら false（CSV を出力しない）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_freee',
					'nonce'  => 'none',
				),
				'expected'            => false,
			),
			// --- 異常系：不正な nonce ---
			array(
				'test_condition_name' => 'wp_die が戻る実装でも不正な nonce なら false（CSV を出力しない）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_mf',
					'nonce'  => 'invalid',
				),
				'expected'            => false,
			),
			// --- 正常系：有効な nonce なら従来どおり true ---
			array(
				'test_condition_name' => 'wp_die が戻る実装でも有効な nonce なら true（従来どおりエクスポート可）',
				'conditions'          => array(
					'user'   => 'admin',
					'action' => 'csv_freee',
					'nonce'  => 'valid',
				),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// ログイン状態とリクエストパラメーターを設定する
			$this->set_current_user_by_type( $case['conditions']['user'] );
			$this->set_up_get_params( $case['conditions'] );

			// wp_die を「何もせず戻る」実装に差し替え、フェイルオープンしないことを確認する
			$die_handler = function () {
				return function ( $message, $title = '', $args = array() ) {
					// 意図的に処理を終了しない
				};
			};
			add_filter( 'wp_die_handler', $die_handler );

			// wp_nonce_ays() が出力するエラー画面の HTML を混ぜないようバッファリングする
			ob_start();
			$actual = CsvExport::can_export();
			ob_end_clean();

			remove_filter( 'wp_die_handler', $die_handler );
			$_GET     = array();
			$_REQUEST = array();

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
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

	/**
	 * CsvExport::format_csv_cell() のテスト
	 *
	 * CSV インジェクション（CSVの値が表計算ソフトで数式として実行される問題）対策として、
	 * 数式化されうる先頭文字の無害化と、"の二重化・全体の"囲みが行われることを検証する。
	 * 金額はマイナス表記で - から始まりうるうえ、number_format() を通さない素の数値
	 * （カンマなし）がそのまま渡る経路もあるため、符号・数字・カンマ・小数点だけで
	 * できた「純粋な数値」はカンマ・小数点の有無を問わず無害化の対象から外れ、
	 * 値が壊れないことも合わせて確認する。あわせて、先頭の空白・不可視文字を
	 * 読み飛ばした位置で判定すること（タブ自体は読み飛ばし対象から除く）、
	 * 不正な UTF-8 で判定不能な場合は安全側に倒して無害化することも確認する。
	 *
	 * @return void
	 */
	public function test_format_csv_cell() {

		$test_cases = array(
			// --- 異常系：数式として実行されうる値は先頭に ' を付けて無害化する ---
			array(
				'test_condition_name' => '値が "=1+1"（数式）の場合 => 先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => '=1+1' ),
				'expected'            => '"\'=1+1"',
			),
			array(
				'test_condition_name' => '値が "-1+1" の場合 => 先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => '-1+1' ),
				'expected'            => '"\'-1+1"',
			),
			array(
				'test_condition_name' => '値が "@SUM(A1)" の場合 => 先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => '@SUM(A1)' ),
				'expected'            => '"\'@SUM(A1)"',
			),
			array(
				'test_condition_name' => '値がタブ（0x09）で始まる場合 => 先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => "\t危険" ),
				'expected'            => "\"'\t危険\"",
			),
			array(
				'test_condition_name' => '値が改行（LF）で始まる場合 => 先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => "\n危険" ),
				'expected'            => "\"'\n危険\"",
			),
			array(
				'test_condition_name' => '値が復帰（CR）で始まる場合 => 先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => "\r危険" ),
				'expected'            => "\"'\r危険\"",
			),
			array(
				'test_condition_name' => '値が "-1E2"（指数表記）の場合 => 数値ではないため先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => '-1E2' ),
				'expected'            => '"\'-1E2"',
			),
			array(
				'test_condition_name' => '値が "=1+1\"" のように数式と " が両方含まれる場合 => 先頭に \' を付けたうえで " も二重化する',
				'conditions'          => array( 'value' => '=1+1"' ),
				'expected'            => '"\'=1+1"""',
			),
			array(
				'test_condition_name' => '値がフィールド内改行を含み、途中に "=1+1" が現れる場合 => 先頭でなければ無害化せず、" の二重化だけでマス外への脱出を防ぐ',
				'conditions'          => array( 'value' => "foo\"\r\n=1+1" ),
				'expected'            => "\"foo\"\"\r\n=1+1\"",
			),
			array(
				'test_condition_name' => '値の先頭が " そのものの場合 => " は無害化の対象文字ではないため \' は付けず、" の二重化のみ行う',
				'conditions'          => array( 'value' => '"=1+1' ),
				'expected'            => '"""=1+1"',
			),
			array(
				'test_condition_name' => '値が半角スペースに続けて数式（" =1+1"）の場合 => 空白を読み飛ばした位置で数式と判定し、先頭（元の値の先頭）に \' を付けて無害化する',
				'conditions'          => array( 'value' => ' =1+1' ),
				'expected'            => "\"' =1+1\"",
			),
			array(
				'test_condition_name' => '値がゼロ幅スペースに続けて数式の場合 => 不可視文字を読み飛ばした位置で数式と判定し、元の値の先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => "\u{200B}=1+1" ),
				'expected'            => "\"'\u{200B}=1+1\"",
			),
			array(
				'test_condition_name' => '値が半角スペース＋タブ＋数式（" \\t=1+1"）の場合 => 読み飛ばし対象の空白の後にタブが来ても正しく数式と判定し、元の値の先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => " \t=1+1" ),
				'expected'            => "\"' \t=1+1\"",
			),
			array(
				'test_condition_name' => '値の末尾に改行が付いた数値（"-1234\\n"）の場合 => \z アンカーにより「純粋な数値」とは判定されず、先頭に \' を付けて無害化する',
				'conditions'          => array( 'value' => "-1234\n" ),
				'expected'            => "\"'-1234\n\"",
			),
			array(
				'test_condition_name' => '値が不正な UTF-8 バイト列を含み先頭文字の判定ができない場合 => 安全側に倒して一律で無害化する',
				'conditions'          => array( 'value' => "\xFF\xFE=1+1" ),
				'expected'            => "\"'\xFF\xFE=1+1\"",
			),
			// --- 正常系：符号・数字・カンマ・小数点だけでできた「純粋な数値」は無害化しない（金額が壊れないこと） ---
			array(
				'test_condition_name' => '値が "-1,234"（マイナスの金額）の場合 => 無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => '-1,234' ),
				'expected'            => '"-1,234"',
			),
			array(
				'test_condition_name' => '値が "+1,000.50"（プラスの金額・小数あり）の場合 => 無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => '+1,000.50' ),
				'expected'            => '"+1,000.50"',
			),
			array(
				'test_condition_name' => '値が "1,234"（符号なしの金額）の場合 => 無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => '1,234' ),
				'expected'            => '"1,234"',
			),
			array(
				'test_condition_name' => '値が "+1"（カンマ・小数点なしのプラスの数値）の場合 => 無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => '+1' ),
				'expected'            => '"+1"',
			),
			array(
				'test_condition_name' => '値が "-500"（カンマ・小数点なしのマイナスの金額。number_format() を通さない素の数値経路を想定）の場合 => 無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => '-500' ),
				'expected'            => '"-500"',
			),
			array(
				'test_condition_name' => '値が "-500000"（カンマ・小数点なしの大きいマイナスの金額）の場合 => 無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => '-500000' ),
				'expected'            => '"-500000"',
			),
			array(
				'test_condition_name' => '値が半角スペースに続けて金額（" -500"）の場合 => 空白を読み飛ばした位置でも「純粋な数値」と判定され無害化しない（\' を付けない）',
				'conditions'          => array( 'value' => ' -500' ),
				'expected'            => '" -500"',
			),
			// --- 正常系：" のエスケープ（"" への二重化） ---
			array(
				'test_condition_name' => '値に " が含まれる場合 => "" に二重化する',
				'conditions'          => array( 'value' => 'あ"い"う' ),
				'expected'            => '"あ""い""う"',
			),
			// --- 正常系：& はエスケープしない（CSV は HTML ではないため esc_html しない） ---
			array(
				'test_condition_name' => '値に & が含まれる場合 => &amp; にせずそのまま & で出す',
				'conditions'          => array( 'value' => 'A&B' ),
				'expected'            => '"A&B"',
			),
			// --- 正常系：通常の日本語文字列はそのまま ---
			array(
				'test_condition_name' => '値が通常の日本語文字列の場合 => そのまま "" で囲んで返す',
				'conditions'          => array( 'value' => '株式会社ベクトル' ),
				'expected'            => '"株式会社ベクトル"',
			),
			// --- 境界値：空文字・数値・null を渡しても壊れない ---
			array(
				'test_condition_name' => '値が空文字の場合 => "" を返す（壊れない）',
				'conditions'          => array( 'value' => '' ),
				'expected'            => '""',
			),
			array(
				'test_condition_name' => '値が数値（int）の場合 => 文字列化して "" で囲んで返す（壊れない）',
				'conditions'          => array( 'value' => 1234 ),
				'expected'            => '"1234"',
			),
			array(
				'test_condition_name' => '値が null の場合 => "" を返す（壊れない）',
				'conditions'          => array( 'value' => null ),
				'expected'            => '""',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = CsvExport::format_csv_cell( $case['conditions']['value'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
