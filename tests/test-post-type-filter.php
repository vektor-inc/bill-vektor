<?php
/**
 * Class PostTypeFilterTest
 *
 * 書類一覧の絞り込み検索（投稿タイプ）のテスト
 *
 * @package BillVektor
 */

/**
 * 投稿タイプ絞り込みのテストケース
 *
 * bill_custom_home_post_type()（inc/functions-pre-get-posts.php）が
 * $_GET['post_type'] をメインクエリへ反映する処理を検証する。
 *
 * $_GET['post_type'] に post_type[]=xxx のように配列で渡された場合に
 * esc_attr() へ配列を渡してしまい PHP の警告（Array to string conversion）が
 * 発生していた不具合（issue #318）の再現・修正確認のためのテスト。
 */
class PostTypeFilterTest extends WP_UnitTestCase {

	/**
	 * テスト用管理者ユーザーID
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * テスト用に作成した書類のIDを保持する（キーは件名）
	 *
	 * @var array
	 */
	private $post_ids = array();

	/**
	 * テスト前の共通セットアップ
	 *
	 * 既存投稿の削除・投稿タイプ違いのテスト用書類の作成・ログイン状態の準備を行う。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// WordPress の初期投稿（Hello world! 等）が一覧に混ざると
		// 期待値が環境依存になるため、先にすべて削除する。
		// WP_UnitTestCase はテストごとに DB をロールバックするため他のテストには影響しない。
		$existing_posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		foreach ( $existing_posts as $existing_post ) {
			wp_delete_post( $existing_post->ID, true );
		}

		// テスト用管理者ユーザーを作成してログイン状態にする
		// （未ログインだと bill_no_login_redirect() でログイン画面へリダイレクトされるため）
		$this->admin_user_id = wp_create_user( 'test_post_type_admin', 'password', 'post-type-admin@example.com' );
		$admin_user          = new WP_User( $this->admin_user_id );
		$admin_user->set_role( 'administrator' );
		wp_set_current_user( $this->admin_user_id );

		// go_to() は wp アクションを実行するため、万一ログイン判定が外れた場合に
		// wp_safe_redirect() + exit でテスト自体が終了してしまう。それを防ぐため
		// リダイレクト処理を外しておく（tear_down() で元に戻す）。
		remove_action( 'wp', 'bill_no_login_redirect' );

		// 投稿タイプ違いのテスト用書類を作成する
		// 発行日順（date DESC）が一意に決まるよう post_date を明示的にずらしている。
		$posts = array(
			array(
				'post_title' => '請求書テスト書類',
				'post_type'  => 'post',
				'post_date'  => '2024-01-01 00:00:00',
			),
			array(
				'post_title' => '見積書テスト書類',
				'post_type'  => 'estimate',
				'post_date'  => '2024-02-01 00:00:00',
			),
		);
		foreach ( $posts as $post ) {
			$this->post_ids[ $post['post_title'] ] = wp_insert_post(
				array(
					'post_title'  => $post['post_title'],
					'post_status' => 'publish',
					'post_type'   => $post['post_type'],
					'post_date'   => $post['post_date'],
				)
			);
		}
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * @return void
	 */
	public function tear_down() {
		// $_GET をリセット
		$_GET = array();

		// set_up() で外したリダイレクト処理を戻す
		add_action( 'wp', 'bill_no_login_redirect' );

		// 作成した書類を削除
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();

		// 作成したユーザーを削除
		if ( $this->admin_user_id ) {
			wp_delete_user( $this->admin_user_id );
		}

		parent::tear_down();
	}

	/**
	 * bill_custom_home_post_type() の投稿タイプ絞り込みのテスト
	 *
	 * $_GET['post_type'] を各種条件で渡し、メインクエリの post_type クエリー変数と
	 * 一覧に表示される書類を検証する。
	 *
	 * .phpunit.xml で convertWarningsToExceptions="true" が指定されているため、
	 * post_type に配列を渡したときに PHP の警告（Array to string conversion）が
	 * 発生すると go_to() の時点で例外になりテストが失敗する。
	 * これにより「警告が出ないこと」を明示的にアサーションしなくても検証できる。
	 *
	 * @return void
	 */
	public function test_bill_custom_home_post_type__post_type() {

		$test_cases = array(
			// --- 正常系：文字列で投稿タイプを指定 ---
			array(
				'test_condition_name' => '投稿タイプに文字列「estimate」を指定した場合 => post_type は estimate になり見積書のみ表示',
				'conditions'          => array( 'post_type' => 'estimate' ),
				'expected'            => array(
					'post_type' => 'estimate',
					'titles'    => array( '見積書テスト書類' ),
				),
			),
			// --- 正常系：パラメーター自体が無い場合 ---
			array(
				'test_condition_name' => '投稿タイプのパラメーター自体が無い場合 => 既定の post_type（post, estimate）で全2件表示',
				'conditions'          => array(),
				'expected'            => array(
					'post_type' => array( 'post', 'estimate' ),
					'titles'    => array( '見積書テスト書類', '請求書テスト書類' ),
				),
			),
			// --- 境界値・異常系：配列で渡された場合 ---
			array(
				// post_type[]=estimate&post_type[]=post のように配列で渡された場合、
				// 修正前は esc_attr() に配列を渡してしまい PHP 警告が発生し、
				// 一覧が空になっていた（esc_attr() が配列を文字列 'Array' に変換するため
				// 存在しない投稿タイプ扱いになる）。
				// 修正後は「指定なし」として扱われ、既定の post_type にフォールバックする。
				'test_condition_name' => '投稿タイプが配列で渡された場合 => 警告が出ず既定の post_type（post, estimate）にフォールバックして全2件表示',
				'conditions'          => array( 'post_type' => array( 'estimate', 'post' ) ),
				'expected'            => array(
					'post_type' => array( 'post', 'estimate' ),
					'titles'    => array( '見積書テスト書類', '請求書テスト書類' ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件をクエリー文字列に組み立ててトップページ（書類一覧）に移動
			$this->go_to( home_url( '/' ) . '?' . http_build_query( $case['conditions'] ) );

			global $wp_query;

			// メインクエリの post_type クエリー変数を検証
			$this->assertSame(
				$case['expected']['post_type'],
				$wp_query->get( 'post_type' ),
				$case['test_condition_name'] . '（post_type クエリー変数）'
			);

			// 一覧に表示される書類の件名を検証（発行日の新しい順）
			$this->assertSame(
				$case['expected']['titles'],
				wp_list_pluck( $wp_query->posts, 'post_title' ),
				$case['test_condition_name'] . '（一覧に表示される書類）'
			);
		}
	}
}
