<?php
/**
 * Class SearchKeywordTest
 *
 * 書類一覧の絞り込み検索（キーワード）のテスト
 *
 * @package BillVektor
 */

/**
 * キーワード絞り込みのテストケース
 *
 * bill_get_search_keyword()（キーワードの受け取り・サニタイズ）と
 * bill_custom_home_post_type()（メインクエリへの絞り込み条件の反映）を検証する。
 */
class SearchKeywordTest extends WP_UnitTestCase {

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
	 * 既存投稿の削除・テスト用書類の作成・ログイン状態の準備を行う。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// WordPress の初期投稿（Hello world! 等）が検索結果に混ざると
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
		$this->admin_user_id = wp_create_user( 'test_search_admin', 'password', 'search-admin@example.com' );
		$admin_user          = new WP_User( $this->admin_user_id );
		$admin_user->set_role( 'administrator' );
		wp_set_current_user( $this->admin_user_id );

		// go_to() は wp アクションを実行するため、万一ログイン判定が外れた場合に
		// wp_safe_redirect() + exit でテスト自体が終了してしまう。それを防ぐため
		// リダイレクト処理を外しておく（tear_down() で元に戻す）。
		remove_action( 'wp', 'bill_no_login_redirect' );

		// 検索対象の書類を作成する
		// 発行日順（date DESC）が一意に決まるよう post_date を明示的にずらしている。
		// 本文は「件名だけを検索対象にする」ことを検証する1件を除き、
		// キーワード判定に影響させないため空にする。
		$posts = array(
			// 請求書（post）: 発行日 2024-01-01
			array(
				'post_title'   => 'ロゴ制作費',
				'post_content' => '',
				'post_type'    => 'post',
				'post_date'    => '2024-01-01 00:00:00',
			),
			// 請求書（post）: 発行日 2024-02-01
			array(
				'post_title'   => 'サイト制作費',
				'post_content' => '',
				'post_type'    => 'post',
				'post_date'    => '2024-02-01 00:00:00',
			),
			// 見積書（estimate）: 発行日 2024-03-01
			array(
				'post_title'   => '保守費用',
				'post_content' => '',
				'post_type'    => 'estimate',
				'post_date'    => '2024-03-01 00:00:00',
			),
			// 件名に「0」を含む書類（キーワードが "0" の1文字でも絞り込みが効くことの検証用）
			array(
				'post_title'   => '型番0123の部品代',
				'post_content' => '',
				'post_type'    => 'post',
				'post_date'    => '2024-04-01 00:00:00',
			),
			// 件名にシングルクォートを含む書類（クォート入りキーワードで検索できることの検証用）
			array(
				'post_title'   => "O'Brien商会 年間保守",
				'post_content' => '',
				'post_type'    => 'post',
				'post_date'    => '2024-05-01 00:00:00',
			),
			// 件名にバックスラッシュを含む書類（wp_slash() が外れると検索できなくなることの検証用）
			// wp_insert_post() は内部で wp_unslash() するためスラッシュを付けた状態で渡す。
			// 保存される件名は「バックスラッシュ\テスト書類」（バックスラッシュ1つ）になる。
			array(
				'post_title'   => 'バックスラッシュ\\\\テスト書類',
				'post_content' => '',
				'post_type'    => 'post',
				'post_date'    => '2024-06-01 00:00:00',
			),
			// 本文にだけキーワードを含む書類（検索対象が件名に限定されていることの検証用）
			array(
				'post_title'   => '本文テスト書類',
				'post_content' => '本文限定キーワード',
				'post_type'    => 'post',
				'post_date'    => '2024-07-01 00:00:00',
			),
		);
		foreach ( $posts as $post ) {
			$this->post_ids[ $post['post_title'] ] = wp_insert_post(
				array(
					'post_title'   => $post['post_title'],
					'post_content' => $post['post_content'],
					'post_status'  => 'publish',
					'post_type'    => $post['post_type'],
					'post_date'    => $post['post_date'],
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
	 * bill_get_search_keyword() のテスト
	 *
	 * $_GET['bill_keyword'] を各種条件で渡し、サニタイズ結果を検証する。
	 *
	 * @return void
	 */
	public function test_bill_get_search_keyword() {

		$test_cases = array(
			// --- 正常系 ---
			array(
				'test_condition_name' => 'キーワードに「サイト制作」を指定した場合 => サイト制作',
				'conditions'          => array( 'bill_keyword' => 'サイト制作' ),
				'expected'            => 'サイト制作',
			),
			array(
				'test_condition_name' => 'キーワードの前後に半角スペースが付いている場合 => 前後の空白を除去した サイト制作',
				'conditions'          => array( 'bill_keyword' => '  サイト制作  ' ),
				'expected'            => 'サイト制作',
			),
			array(
				'test_condition_name' => 'キーワードにHTMLタグが含まれる場合 => タグとスクリプトの中身を除去した 制作',
				'conditions'          => array( 'bill_keyword' => '<script>alert(1)</script>制作' ),
				'expected'            => '制作',
			),
			array(
				// 呼び出し側は返り値を空文字と比較して絞り込みの有無を判定するため、
				// 「0」が空文字に丸められないことがここで担保されている必要がある
				'test_condition_name' => 'キーワードが「0」の1文字の場合 => 0（空文字に丸められないこと）',
				'conditions'          => array( 'bill_keyword' => '0' ),
				'expected'            => '0',
			),
			array(
				// WordPress は $_GET にスラッシュを付与するため、実際のリクエストではこの形で渡ってくる
				'test_condition_name' => 'キーワードにスラッシュ付きのクォートが含まれる場合 => スラッシュを除去した \'制作\'',
				'conditions'          => array( 'bill_keyword' => "\\'制作\\'" ),
				'expected'            => "'制作'",
			),
			// --- 境界値・異常系 ---
			array(
				'test_condition_name' => 'キーワードが空文字の場合 => 空文字（絞り込みなし）',
				'conditions'          => array( 'bill_keyword' => '' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'キーワードが空白のみの場合 => 空文字（絞り込みなし）',
				'conditions'          => array( 'bill_keyword' => '   ' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'キーワードのパラメーター自体が無い場合 => 空文字（絞り込みなし）',
				'conditions'          => array(),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'キーワードが配列で渡された場合 => 空文字（絞り込みなし）',
				'conditions'          => array( 'bill_keyword' => array( '制作' ) ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// $_GET を条件どおりに差し替える
			$_GET = $case['conditions'];

			// テスト対象の関数を実行
			$actual = bill_get_search_keyword();

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// $_GET をクリーンアップ
			$_GET = array();
		}
	}

	/**
	 * bill_custom_home_post_type() のテスト
	 *
	 * キーワード（bill_keyword）を付けて書類一覧を表示した場合に、
	 * メインクエリのキーワード検索条件（s）へ渡されて絞り込みが効くこと、
	 * および一覧テンプレートの分岐が維持されること（is_search が true にならないこと）を検証する。
	 *
	 * @return void
	 */
	public function test_bill_custom_home_post_type() {

		// 絞り込み条件が付かないケースの期待値（発行日の新しい順で全7件）
		$all_titles = array(
			'本文テスト書類',
			'バックスラッシュ\テスト書類',
			"O'Brien商会 年間保守",
			'型番0123の部品代',
			'保守費用',
			'サイト制作費',
			'ロゴ制作費',
		);

		$test_cases = array(
			// --- 正常系：キーワードで絞り込まれる ---
			array(
				'test_condition_name' => 'キーワード「サイト」を指定した場合 => 件名に「サイト」を含む請求書のみ表示',
				'conditions'          => array( 'bill_keyword' => 'サイト' ),
				'expected'            => array(
					's'      => 'サイト',
					'titles' => array( 'サイト制作費' ),
				),
			),
			array(
				'test_condition_name' => 'キーワード「制作費」を指定した場合 => 件名に「制作費」を含む請求書2件を発行日の新しい順で表示',
				'conditions'          => array( 'bill_keyword' => '制作費' ),
				'expected'            => array(
					's'      => '制作費',
					'titles' => array( 'サイト制作費', 'ロゴ制作費' ),
				),
			),
			array(
				'test_condition_name' => 'キーワード「費」を指定した場合 => 請求書・見積書をまたいで3件を発行日の新しい順で表示',
				'conditions'          => array( 'bill_keyword' => '費' ),
				'expected'            => array(
					's'      => '費',
					'titles' => array( '保守費用', 'サイト制作費', 'ロゴ制作費' ),
				),
			),
			// --- 正常系：数字1文字・記号入りのキーワード ---
			array(
				// truthy 判定だと "0" が false になり絞り込みが無視されてしまうため、その回帰防止
				'test_condition_name' => 'キーワードが「0」の1文字の場合 => 件名に「0」を含む書類のみ表示（絞り込みが無視されないこと）',
				'conditions'          => array( 'bill_keyword' => '0' ),
				'expected'            => array(
					's'      => '0',
					'titles' => array( '型番0123の部品代' ),
				),
			),
			array(
				'test_condition_name' => 'キーワードにシングルクォートを含む「O\'Brien」を指定した場合 => 該当する書類が表示される',
				'conditions'          => array( 'bill_keyword' => "O'Brien" ),
				'expected'            => array(
					's'      => "O'Brien",
					'titles' => array( "O'Brien商会 年間保守" ),
				),
			),
			array(
				// $_GET は WordPress によってスラッシュが付与されるため、リクエストでは
				// バックスラッシュが2つになった状態で渡ってくる。
				// 実装から wp_slash() が外れると WP_Query::parse_search() の stripslashes() で
				// バックスラッシュが失われ、このケースがヒットしなくなる。
				'test_condition_name' => 'キーワードにバックスラッシュを含む場合 => 該当する書類が表示される（スラッシュ処理が正しいこと）',
				'conditions'          => array( 'bill_keyword' => 'バックスラッシュ\\\\テスト' ),
				'expected'            => array(
					's'      => 'バックスラッシュ\テスト',
					'titles' => array( 'バックスラッシュ\テスト書類' ),
				),
			),
			// --- 正常系：検索対象は件名だけ ---
			array(
				'test_condition_name' => '本文にだけ含まれるキーワードを指定した場合 => 件名に含まないため0件（検索対象が件名に限定されていること）',
				'conditions'          => array( 'bill_keyword' => '本文限定キーワード' ),
				'expected'            => array(
					's'      => '本文限定キーワード',
					'titles' => array(),
				),
			),
			// --- 境界値：絞り込み条件が付かない ---
			array(
				'test_condition_name' => 'キーワードが空文字の場合 => 絞り込みなしで全7件を表示',
				'conditions'          => array( 'bill_keyword' => '' ),
				'expected'            => array(
					's'      => '',
					'titles' => $all_titles,
				),
			),
			array(
				'test_condition_name' => 'キーワードが空白のみの場合 => 絞り込みなしで全7件を表示',
				'conditions'          => array( 'bill_keyword' => '   ' ),
				'expected'            => array(
					's'      => '',
					'titles' => $all_titles,
				),
			),
			array(
				'test_condition_name' => 'キーワードのパラメーター自体が無い場合 => 絞り込みなしで全7件を表示',
				'conditions'          => array(),
				'expected'            => array(
					's'      => '',
					'titles' => $all_titles,
				),
			),
			// --- 異常系：該当する書類がない ---
			array(
				'test_condition_name' => '該当する書類が無いキーワードを指定した場合 => 表示される書類は0件',
				'conditions'          => array( 'bill_keyword' => '該当しないキーワード' ),
				'expected'            => array(
					's'      => '該当しないキーワード',
					'titles' => array(),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件をクエリー文字列に組み立ててトップページ（書類一覧）に移動
			$this->go_to( home_url( '/' ) . '?' . http_build_query( $case['conditions'] ) );

			global $wp_query;

			// メインクエリのキーワード検索条件を検証
			$this->assertSame(
				$case['expected']['s'],
				$wp_query->get( 's' ),
				$case['test_condition_name'] . '（s クエリー変数）'
			);

			// 一覧に表示される書類の件名を検証
			$this->assertSame(
				$case['expected']['titles'],
				wp_list_pluck( $wp_query->posts, 'post_title' ),
				$case['test_condition_name'] . '（一覧に表示される書類）'
			);

			// bill_keyword を使う理由の担保。s ではなく bill_keyword で受け取ることで
			// 検索結果ページ扱いにならず、index.php の一覧表示の分岐が維持される。
			$this->assertFalse(
				$wp_query->is_search(),
				$case['test_condition_name'] . '（検索結果ページ扱いにならないこと）'
			);
			$this->assertTrue(
				is_front_page(),
				$case['test_condition_name'] . '（トップページ扱いが維持されること）'
			);
		}
	}

	/**
	 * bill_custom_home_post_type() のページ送り時のテスト
	 *
	 * 2ページ目以降でもキーワードの絞り込みが維持されること、
	 * およびページ送りリンクにキーワードが引き継がれることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_custom_home_post_type__pagination() {

		// 1ページあたり1件表示にしてページ送りが発生する状態にする
		$posts_per_page_backup = get_option( 'posts_per_page' );
		update_option( 'posts_per_page', 1 );

		$test_cases = array(
			// --- 正常系：1ページ目 ---
			array(
				'test_condition_name' => 'キーワード「制作費」で1ページ目を表示した場合 => 発行日の新しい サイト制作費 のみ表示され全2件が該当',
				'conditions'          => array( 'bill_keyword' => '制作費' ),
				'expected'            => array(
					's'           => '制作費',
					'titles'      => array( 'サイト制作費' ),
					'found_posts' => 2,
				),
			),
			// --- 正常系：2ページ目でもキーワードが維持される ---
			array(
				'test_condition_name' => 'キーワード「制作費」で2ページ目を表示した場合 => キーワードの絞り込みが維持され ロゴ制作費 のみ表示',
				'conditions'          => array(
					'bill_keyword' => '制作費',
					'paged'        => 2,
				),
				'expected'            => array(
					's'           => '制作費',
					'titles'      => array( 'ロゴ制作費' ),
					'found_posts' => 2,
				),
			),
			// --- 境界値：該当が無いキーワードではページ送りも発生しない ---
			array(
				'test_condition_name' => '該当する書類が無いキーワードで2ページ目を表示した場合 => 表示される書類は0件',
				'conditions'          => array(
					'bill_keyword' => '該当しないキーワード',
					'paged'        => 2,
				),
				'expected'            => array(
					's'           => '該当しないキーワード',
					'titles'      => array(),
					'found_posts' => 0,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件をクエリー文字列に組み立ててトップページ（書類一覧）に移動
			$this->go_to( home_url( '/' ) . '?' . http_build_query( $case['conditions'] ) );

			global $wp_query;

			// キーワード検索条件がページ送り後も維持されていることを検証
			$this->assertSame(
				$case['expected']['s'],
				$wp_query->get( 's' ),
				$case['test_condition_name'] . '（s クエリー変数）'
			);

			// 該当件数を検証
			$this->assertSame(
				$case['expected']['found_posts'],
				(int) $wp_query->found_posts,
				$case['test_condition_name'] . '（該当件数）'
			);

			// 当該ページに表示される書類の件名を検証
			$this->assertSame(
				$case['expected']['titles'],
				wp_list_pluck( $wp_query->posts, 'post_title' ),
				$case['test_condition_name'] . '（一覧に表示される書類）'
			);
		}

		// ページ送りリンクにキーワードが引き継がれることを検証
		// （the_posts_pagination() は現在のクエリー文字列を引き継ぐため追加実装は不要だが、
		//   キーワードが欠落すると2ページ目で絞り込みが外れてしまうためテストで担保する）
		$this->go_to( home_url( '/' ) . '?' . http_build_query( array( 'bill_keyword' => '制作費' ) ) );
		$pagination = get_the_posts_pagination();
		$this->assertStringContainsString(
			'bill_keyword=' . rawurlencode( '制作費' ),
			$pagination,
			'ページ送りリンクにキーワードが引き継がれること'
		);

		// オプションを元に戻す
		update_option( 'posts_per_page', $posts_per_page_backup );
	}
}
