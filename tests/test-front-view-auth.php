<?php
/**
 * Class FrontViewAuthTest
 *
 * フロント側の閲覧制限（bill_no_login_redirect() と bill_can_view_documents()）の検証テスト
 *
 * 修正前は「ログインさえしていれば権限を問わず」請求書・見積書の一覧と明細を閲覧できた。
 * このテストでは、購読者のような閲覧専用のロールが遮断されること、
 * 未ログインは従来どおりログインページへ誘導されること、
 * 書類を運用するロール（寄稿者以上）は従来どおり閲覧できることを検証する。
 *
 * @package BillVektor
 */

/**
 * リダイレクト・403 の出力を検知するための例外
 *
 * bill_no_login_redirect() は最後に exit するためテストから直接呼べない。
 * wp_redirect フィルター・status_header フィルターの時点で例外を投げると、
 * exit に到達する前に処理が抜けるため、どちらの経路に入ったのかを検証できる。
 */
class Bill_View_Auth_Halt_Exception extends Exception {}

/**
 * フロント側の閲覧制限のテスト
 */
class FrontViewAuthTest extends WP_UnitTestCase {

	/**
	 * テスト用に追加するロールのスラッグ（単独ロールの検証用）
	 *
	 * 組み込みロールの表示名は実行環境のロケールで変わるため、
	 * ロール名の組み立てはテスト専用のロールで検証する。
	 *
	 * @var string
	 */
	const TEST_ROLE_A = 'bill_test_role_a';

	/**
	 * テスト用に追加するロールの表示名（単独ロールの検証用）
	 *
	 * @var string
	 */
	const TEST_ROLE_A_NAME = 'テストロールA';

	/**
	 * テスト用に追加するロールのスラッグ（複数ロールの検証用）
	 *
	 * @var string
	 */
	const TEST_ROLE_B = 'bill_test_role_b';

	/**
	 * テスト用に追加するロールの表示名（複数ロールの検証用）
	 *
	 * @var string
	 */
	const TEST_ROLE_B_NAME = 'テストロールB';

	/**
	 * ロールをキーにしたテスト用ユーザーIDの配列
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * テスト用の請求書（投稿タイプ post）のID
	 *
	 * 「?p=ID」の直アクセスの検証に使う。
	 *
	 * @var int
	 */
	private $invoice_post_id = 0;

	/**
	 * wp_redirect フィルターで捕捉したリダイレクト先
	 *
	 * @var string
	 */
	private $redirect_location = '';

	/**
	 * テスト前の共通セットアップ
	 *
	 * 権限ごとのユーザー・テスト用ロール・検証用の請求書を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// 標準のロールごとにユーザーを作成する
		$roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		foreach ( $roles as $role ) {
			$user_id = wp_create_user( 'test_view_auth_' . $role, 'password', 'view-auth-' . $role . '@example.com' );
			$user    = new WP_User( $user_id );
			$user->set_role( $role );
			$this->user_ids[ $role ] = $user_id;
		}

		// ロール名の組み立て検証用のロールを追加する（表示名が翻訳で変わらないよう独自の名前にする）
		add_role( self::TEST_ROLE_A, self::TEST_ROLE_A_NAME, array( 'read' => true ) );
		add_role( self::TEST_ROLE_B, self::TEST_ROLE_B_NAME, array( 'read' => true ) );

		// 「?p=ID」の直アクセス検証用の請求書
		// （BillVektor の請求書は組み込み投稿タイプ post を流用している）
		$this->invoice_post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'テスト請求書の件名',
				'post_status' => 'publish',
				'post_author' => $this->user_ids['administrator'],
			)
		);
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * ログイン状態・追加したロール・作成したデータ・外したフックを元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		// ログイン状態をリセット
		wp_set_current_user( 0 );

		// テストで登録したフィルターを外す
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ), 10 );
		remove_filter( 'status_header', array( $this, 'throw_on_status_header' ), 10 );
		remove_filter( 'bill_vektor_can_view_documents', array( $this, 'filter_limit_to_edit_others_posts' ), 10 );

		// テストで外したガードを戻す（外れたままだと後続のテストの前提が変わる）
		if ( ! has_action( 'wp', 'bill_no_login_redirect' ) ) {
			add_action( 'wp', 'bill_no_login_redirect' );
		}

		// 追加したロールを削除する（$wp_roles はテスト間で保持されるため明示的に戻す）
		remove_role( self::TEST_ROLE_A );
		remove_role( self::TEST_ROLE_B );

		// 作成した請求書を削除
		if ( $this->invoice_post_id ) {
			wp_delete_post( $this->invoice_post_id, true );
			$this->invoice_post_id = 0;
		}

		// 作成したユーザーを削除
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = array();

		parent::tear_down();
	}

	/**
	 * ログイン状態をテスト条件に合わせて設定する
	 *
	 * @param string $role ロールのスラッグ。空文字の場合は未ログインにする。
	 * @return void
	 */
	private function set_current_user_by_role( $role ) {
		if ( '' === $role ) {
			wp_set_current_user( 0 );
			return;
		}

		wp_set_current_user( $this->user_ids[ $role ] );
	}

	/**
	 * 閲覧権限を「他人の投稿を編集できる権限」に絞り込むフィルター
	 *
	 * bill_vektor_can_view_documents フィルターで判定を差し替えられることの検証に使う。
	 *
	 * @param bool    $can_view 既定の判定結果。
	 * @param WP_User $user     判定対象のユーザー。
	 * @return bool 他人の投稿を編集できる場合は true。
	 */
	public function filter_limit_to_edit_others_posts( $can_view, $user ) {
		return user_can( $user, 'edit_others_posts' );
	}

	/**
	 * リダイレクトを検知して例外を投げる
	 *
	 * @param string $location リダイレクト先のURL。
	 * @return string 呼び出し元へは返らない。
	 * @throws Bill_View_Auth_Halt_Exception 常に送出する。
	 */
	public function throw_on_redirect( $location ) {
		$this->redirect_location = $location;
		throw new Bill_View_Auth_Halt_Exception( 'redirect' );
	}

	/**
	 * 403 のステータス送出を検知して例外を投げる
	 *
	 * @param string $status_header ステータス行。
	 * @param int    $code          HTTPステータスコード。
	 * @return string 403 以外の場合は $status_header をそのまま返す。
	 * @throws Bill_View_Auth_Halt_Exception ステータスコードが 403 の場合に送出する。
	 */
	public function throw_on_status_header( $status_header, $code ) {
		// 404 など他のステータスはテスト中に通常発生するため素通しする
		if ( 403 !== (int) $code ) {
			return $status_header;
		}

		throw new Bill_View_Auth_Halt_Exception( 'forbidden' );
	}

	/**
	 * bill_can_view_documents() のテスト
	 *
	 * 権限ごとの判定と、bill_vektor_can_view_documents フィルターによる差し替えを検証する。
	 *
	 * @return void
	 */
	public function test_bill_can_view_documents() {

		$test_cases = array(
			array(
				'test_condition_name' => '未ログインの場合 => false',
				'conditions'          => array(
					'role'   => '',
					'filter' => '',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '購読者の場合 => false（閲覧専用のロールは遮断する）',
				'conditions'          => array(
					'role'   => 'subscriber',
					'filter' => '',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '寄稿者の場合 => true（edit_posts を持つため従来どおり閲覧できる）',
				'conditions'          => array(
					'role'   => 'contributor',
					'filter' => '',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '投稿者の場合 => true（edit_posts を持つため従来どおり閲覧できる）',
				'conditions'          => array(
					'role'   => 'author',
					'filter' => '',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '編集者の場合 => true',
				'conditions'          => array(
					'role'   => 'editor',
					'filter' => '',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者の場合 => true',
				'conditions'          => array(
					'role'   => 'administrator',
					'filter' => '',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '購読者 でフィルターが true を返す場合 => true（閲覧専用アカウントにも見せられる）',
				'conditions'          => array(
					'role'   => 'subscriber',
					'filter' => '__return_true',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者 でフィルターが false を返す場合 => false（サイト側でさらに絞り込める）',
				'conditions'          => array(
					'role'   => 'administrator',
					'filter' => '__return_false',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログイン でフィルターが true を返す場合 => false（匿名公開に戻らない）',
				'conditions'          => array(
					'role'   => '',
					'filter' => '__return_true',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '投稿者 でフィルターを edit_others_posts に絞った場合 => false',
				'conditions'          => array(
					'role'   => 'author',
					'filter' => 'limit_to_edit_others_posts',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '編集者 でフィルターを edit_others_posts に絞った場合 => true',
				'conditions'          => array(
					'role'   => 'editor',
					'filter' => 'limit_to_edit_others_posts',
				),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// ログイン状態を設定
			$this->set_current_user_by_role( $case['conditions']['role'] );

			// フィルターを設定（引数を2つ受け取るコールバックのみメソッドで指定する）
			$filter_callback = '';
			if ( 'limit_to_edit_others_posts' === $case['conditions']['filter'] ) {
				$filter_callback = array( $this, 'filter_limit_to_edit_others_posts' );
			} elseif ( $case['conditions']['filter'] ) {
				$filter_callback = $case['conditions']['filter'];
			}
			if ( $filter_callback ) {
				add_filter( 'bill_vektor_can_view_documents', $filter_callback, 10, 2 );
			}

			// テスト関数実行
			$actual = bill_can_view_documents();

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// フィルターを削除
			if ( $filter_callback ) {
				remove_filter( 'bill_vektor_can_view_documents', $filter_callback, 10 );
			}
		}
	}

	/**
	 * bill_get_user_role_label() のテスト
	 *
	 * 403 のページに表示するロール名が、ロールの割り当て状況に応じて組み立てられることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_get_user_role_label() {

		$test_cases = array(
			array(
				'test_condition_name' => '未ログインの場合 => 空文字',
				'conditions'          => array( 'roles' => array() ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ロールが1つの場合 => そのロールの表示名',
				'conditions'          => array( 'roles' => array( self::TEST_ROLE_A ) ),
				'expected'            => self::TEST_ROLE_A_NAME,
			),
			array(
				'test_condition_name' => 'ロールが2つの場合 => 区切り文字で連結した表示名',
				'conditions'          => array( 'roles' => array( self::TEST_ROLE_A, self::TEST_ROLE_B ) ),
				'expected'            => self::TEST_ROLE_A_NAME . '、' . self::TEST_ROLE_B_NAME,
			),
			array(
				'test_condition_name' => 'ロールが割り当てられていない場合 => 空文字',
				'conditions'          => array( 'roles' => array( 'none' ) ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '登録されていないロールの場合 => スラッグをそのまま表示',
				'conditions'          => array( 'roles' => array( 'bill_unregistered_role' ) ),
				'expected'            => 'bill_unregistered_role',
			),
		);

		foreach ( $test_cases as $case ) {
			$roles = $case['conditions']['roles'];

			if ( ! $roles ) {
				// 未ログインのユーザー（ID 0 の WP_User）で判定する
				$user = new WP_User( 0 );
			} elseif ( array( 'bill_unregistered_role' ) === $roles ) {
				/*
				 * WP_User::get_role_caps() は登録済みのロールだけを $roles に残すため、
				 * 未登録のロールが入った状態は通常のユーザー作成では作れない。
				 * 防御的な分岐を検証するため、公開プロパティへ直接割り当てる。
				 */
				$user        = new WP_User( $this->user_ids['subscriber'] );
				$user->roles = array( 'bill_unregistered_role' );
			} else {
				$user = new WP_User( $this->user_ids['subscriber'] );

				if ( array( 'none' ) === $roles ) {
					// 空文字を渡すと全てのロールが外れる
					$user->set_role( '' );
				} else {
					$user->set_role( array_shift( $roles ) );
					foreach ( $roles as $role ) {
						$user->add_role( $role );
					}
				}

				// ロール変更後の状態で判定するため取得し直す
				$user = new WP_User( $this->user_ids['subscriber'] );
			}

			// テスト関数実行
			$actual = bill_get_user_role_label( $user );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * bill_no_login_redirect() のテスト
	 *
	 * 未ログインは従来どおりログインページへリダイレクトし、ログイン済みで権限が無い場合は
	 * 403 になること、そしてこの判定がフィードや「?p=ID」の直アクセスでも同じであることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_no_login_redirect() {

		/*
		 * ガードが wp フックに登録されていること自体を検証する。
		 * tests/test-search-keyword.php が remove_action( 'wp', 'bill_no_login_redirect' ) で
		 * このガードを外して go_to() しているため、関数名やフック名を変えると
		 * あちらの remove_action() が空振りして意図が失われる。
		 */
		$this->assertNotFalse(
			has_action( 'wp', 'bill_no_login_redirect' ),
			'bill_no_login_redirect() が wp フックに登録されていること'
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'トップページ に未ログインでアクセスした場合 => ログインページへリダイレクト',
				'conditions'          => array( 'role' => '' ),
				'target_url'          => home_url( '/' ),
				'expected'            => 'redirect',
			),
			array(
				'test_condition_name' => 'トップページ に購読者でアクセスした場合 => 403',
				'conditions'          => array( 'role' => 'subscriber' ),
				'target_url'          => home_url( '/' ),
				'expected'            => 'forbidden',
			),
			array(
				'test_condition_name' => 'トップページ に寄稿者でアクセスした場合 => 遮断しない',
				'conditions'          => array( 'role' => 'contributor' ),
				'target_url'          => home_url( '/' ),
				'expected'            => 'allow',
			),
			array(
				'test_condition_name' => 'トップページ に管理者でアクセスした場合 => 遮断しない',
				'conditions'          => array( 'role' => 'administrator' ),
				'target_url'          => home_url( '/' ),
				'expected'            => 'allow',
			),
			array(
				'test_condition_name' => 'フィード（?feed=rss2）に購読者でアクセスした場合 => 403',
				'conditions'          => array( 'role' => 'subscriber' ),
				'target_url'          => home_url( '/?feed=rss2' ),
				'expected'            => 'forbidden',
			),
			array(
				'test_condition_name' => 'フィード（?feed=rss2）に未ログインでアクセスした場合 => ログインページへリダイレクト',
				'conditions'          => array( 'role' => '' ),
				'target_url'          => home_url( '/?feed=rss2' ),
				'expected'            => 'redirect',
			),
			array(
				'test_condition_name' => 'フィード（?feed=rss2）に管理者でアクセスした場合 => 遮断しない',
				'conditions'          => array( 'role' => 'administrator' ),
				'target_url'          => home_url( '/?feed=rss2' ),
				'expected'            => 'allow',
			),
			array(
				'test_condition_name' => '「?p=ID」で請求書を直接開いた場合（購読者）=> 403',
				'conditions'          => array( 'role' => 'subscriber' ),
				'target_url'          => 'invoice',
				'expected'            => 'forbidden',
			),
			array(
				'test_condition_name' => '「?p=ID」で請求書を直接開いた場合（管理者）=> 遮断しない',
				'conditions'          => array( 'role' => 'administrator' ),
				'target_url'          => 'invoice',
				'expected'            => 'allow',
			),
			array(
				'test_condition_name' => '存在しない「?p=ID」を購読者が開いた場合 => 403（404 より先に遮断する）',
				'conditions'          => array( 'role' => 'subscriber' ),
				'target_url'          => home_url( '/?p=99999999' ),
				'expected'            => 'forbidden',
			),
		);

		/*
		 * go_to() は wp アクションを実行するため、ガードが付いたままだと
		 * クエリを組み立てる段階で遮断されてしまう。ここでは外しておき、
		 * クエリを組み立てた後にガードを直接呼び出して結果を検証する。
		 */
		remove_action( 'wp', 'bill_no_login_redirect' );

		// exit に到達する前に処理を抜けるため、リダイレクトと 403 を例外で捕捉する
		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ), 10, 1 );
		add_filter( 'status_header', array( $this, 'throw_on_status_header' ), 10, 2 );

		foreach ( $test_cases as $case ) {
			// ログイン状態を設定
			$this->set_current_user_by_role( $case['conditions']['role'] );

			// テストURLに移動（請求書の個別ページはセットアップで作成したIDを使う）
			$target_url = ( 'invoice' === $case['target_url'] )
				? home_url( '/?p=' . $this->invoice_post_id )
				: $case['target_url'];
			$this->go_to( $target_url );

			// テスト関数実行
			$this->redirect_location = '';
			$actual                  = 'allow';
			try {
				bill_no_login_redirect( null );
			} catch ( Bill_View_Auth_Halt_Exception $e ) {
				$actual = $e->getMessage();
			}

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// リダイレクトの場合は、従来どおりログインページへ戻していることまで確認する
			if ( 'redirect' === $case['expected'] ) {
				$this->assertStringContainsString(
					'wp-login.php',
					$this->redirect_location,
					$case['test_condition_name'] . '（リダイレクト先がログインページであること）'
				);
			}
		}

		// 外したガードを戻す
		add_action( 'wp', 'bill_no_login_redirect' );
	}
}
