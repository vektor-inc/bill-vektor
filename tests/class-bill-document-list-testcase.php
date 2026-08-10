<?php
/**
 * Class Bill_Document_List_TestCase
 *
 * 書類一覧のメインクエリ（bill_custom_home_post_type()）を検証するテストで共通して
 * 使うセットアップ・クリーンアップをまとめた抽象テストケース。
 *
 * @package BillVektor
 */

/**
 * 書類一覧テストの共通フィクスチャ
 *
 * bill_custom_home_post_type()（inc/functions-pre-get-posts.php）を対象にした
 * テストクラス（キーワード絞り込み・投稿タイプ絞り込みなど）はいずれも
 * 「既存投稿の削除 → 管理者ユーザーでログイン → 認証リダイレクトの解除」という
 * 同じ前提を必要とするため、この抽象クラスに集約する。テスト用の書類データ
 * （$this->post_ids に格納する）自体はテストの観点ごとに異なるため、各サブクラスの
 * set_up() が parent::set_up() を呼んだ後に作成する。
 */
abstract class Bill_Document_List_TestCase extends WP_UnitTestCase {

	/**
	 * テスト用管理者ユーザーID
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * テスト用に作成した書類のIDを保持する（キーは件名）
	 *
	 * サブクラスの set_up() でテスト用の書類を作成する際にここへ格納しておくと、
	 * この抽象クラスの tear_down() がまとめて削除する。
	 *
	 * @var array
	 */
	protected $post_ids = array();

	/**
	 * テスト前の共通セットアップ
	 *
	 * 既存投稿の削除・管理者ユーザーでのログイン・認証リダイレクトの解除を行う。
	 * テスト用の書類データの作成はサブクラス側の責務。
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
		// （未ログインだと bill_no_login_redirect() でログイン画面へリダイレクトされるため）。
		// ユーザー名・メールアドレスはテストクラスをまたいで衝突しないよう乱数を含める。
		$unique             = wp_generate_password( 8, false, false );
		$this->admin_user_id = wp_create_user( 'test_doc_list_admin_' . $unique, 'password', 'doc-list-admin-' . $unique . '@example.com' );
		$admin_user          = new WP_User( $this->admin_user_id );
		$admin_user->set_role( 'administrator' );
		wp_set_current_user( $this->admin_user_id );

		// go_to() は wp アクションを実行するため、万一ログイン判定が外れた場合に
		// wp_safe_redirect() + exit でテスト自体が終了してしまう。それを防ぐため
		// リダイレクト処理を外しておく。WP_UnitTestCase::tear_down() が
		// _restore_hooks() でフックの状態をテスト前に戻すため、ここで
		// add_action() をやり直す必要はない。
		remove_action( 'wp', 'bill_no_login_redirect' );
	}

	/**
	 * テスト後の共通クリーンアップ
	 *
	 * $_GET のリセット・作成した書類とユーザーの削除を行う。
	 *
	 * @return void
	 */
	public function tear_down() {
		// $_GET をリセット
		$_GET = array();

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
}
