<?php
/**
 * Class PostTypeFilterTest
 *
 * 書類一覧の絞り込み検索（投稿タイプ）のテスト
 *
 * @package BillVektor
 */

// bill_custom_home_post_type() のテストで共通するセットアップ・クリーンアップを
// まとめた抽象テストケース（tests/test-search-keyword.php と共有）。
require_once __DIR__ . '/class-bill-document-list-testcase.php';

/**
 * 投稿タイプ絞り込みのテストケース
 *
 * bill_custom_home_post_type()（inc/functions-pre-get-posts.php）が
 * $_GET['post_type'] をメインクエリへ反映する処理を検証する。
 *
 * $_GET['post_type'] に post_type[]=xxx のように配列で渡された場合に
 * esc_attr() へ配列を渡してしまい PHP の警告（Array to string conversion）が
 * 発生していた不具合（issue #318）の再現・修正確認のためのテスト。
 *
 * あわせて、同じ関数内の他パラメーター（bill_client・start_date・end_date）も
 * 同種の警告を起こしうるため、その回帰防止テストも含む。
 */
class PostTypeFilterTest extends Bill_Document_List_TestCase {

	/**
	 * テスト前のセットアップ
	 *
	 * 共通の前提（既存投稿の削除・管理者ログイン・認証リダイレクトの解除）は
	 * 親クラス（Bill_Document_List_TestCase）に任せ、このクラス固有の
	 * 投稿タイプ違いのテスト用書類のみ作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// 投稿タイプ違いのテスト用書類を作成する。
		// 発行日順（date DESC）が一意に決まるよう post_date を明示的にずらしている。
		// client（取引先）は has_archive が false で post_type の既定値
		// array( 'post', 'estimate' ) にも含まれないため、トップページの既定表示には
		// 混ざらず、かつ年別アーカイブでの明示的な post_type 指定の検証に使える。
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
			array(
				'post_title' => '取引先テスト書類',
				'post_type'  => 'client',
				'post_date'  => '2024-03-01 00:00:00',
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

		// 取引先（bill_client）の絞り込みテスト用に、見積書テスト書類にだけ
		// 取引先IDのメタを設定しておく（他の書類には設定しない）
		update_post_meta( $this->post_ids['見積書テスト書類'], 'bill_client', '42' );
	}

	/**
	 * bill_custom_home_post_type() の投稿タイプ絞り込みのテスト（トップページ）
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
				'test_condition_name' => '投稿タイプのパラメーター自体が無い場合 => 既定の post_type（post, estimate）で該当2件を表示（client は含まれない）',
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
				// 修正後は「指定なし」として扱われ、トップページの既定の post_type に
				// フォールバックする（トップページ以外での配列指定の挙動は
				// test_bill_custom_home_post_type__post_type_not_front_page() を参照）。
				'test_condition_name' => '投稿タイプが配列で渡された場合 => 警告が出ず既定の post_type（post, estimate）にフォールバックして該当2件を表示',
				'conditions'          => array( 'post_type' => array( 'estimate', 'post' ) ),
				'expected'            => array(
					'post_type' => array( 'post', 'estimate' ),
					'titles'    => array( '見積書テスト書類', '請求書テスト書類' ),
				),
			),
			// --- 境界値・異常系：未登録の投稿タイプ ---
			array(
				'test_condition_name' => '投稿タイプに未登録のスラッグ「nonexistent」を指定した場合 => post_type は nonexistent のまま渡され、該当する書類が無いため0件表示',
				'conditions'          => array( 'post_type' => 'nonexistent' ),
				'expected'            => array(
					'post_type' => 'nonexistent',
					'titles'    => array(),
				),
			),
			// --- 境界値・異常系：sanitize_key() で空文字に丸められる値 ---
			array(
				// sanitize_key() は半角英数字・ハイフン・アンダースコア以外を除去するため、
				// 日本語の「見積」は空文字に丸められる。ここで「指定なし」として扱わずに
				// $query->set( 'post_type', '' ) をそのまま実行すると、WP_Query が
				// post_type = 'post' 相当に解釈してしまい、意図せず見積書が消えて
				// 請求書だけの一覧になる（一見もっともらしく見えるため気づかれにくい）
				'test_condition_name' => '投稿タイプに sanitize_key() で空文字に丸められる「見積」を指定した場合 => 既定の post_type（post, estimate）にフォールバックして該当2件を表示',
				'conditions'          => array( 'post_type' => '見積' ),
				'expected'            => array(
					'post_type' => array( 'post', 'estimate' ),
					'titles'    => array( '見積書テスト書類', '請求書テスト書類' ),
				),
			),
			// --- 境界値・異常系：post_type が空文字で渡された場合 ---
			array(
				'test_condition_name' => '投稿タイプが空文字「」で渡された場合 => 既定の post_type（post, estimate）にフォールバックして該当2件を表示',
				'conditions'          => array( 'post_type' => '' ),
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

	/**
	 * bill_custom_home_post_type() の投稿タイプ絞り込みのテスト（トップページ以外）
	 *
	 * post_type は WordPress コアの public_query_vars に含まれ、この関数より前に走る
	 * WP::parse_request() によって $_GET['post_type'] がそのまま query_vars に入る。
	 * トップページ以外では post_type が配列または未指定（sanitize_key() で空文字に
	 * 丸められた場合を含む）のときはこの関数が post_type を上書きしないため、
	 * WordPress 標準の挙動がそのまま有効になる。一方、文字列で指定された場合は
	 * ページの種類を問わず sanitize_key() を通した値で上書きされる。
	 * 正常系（文字列指定・未指定）と異常系（配列指定）を対比させることで、
	 * 「トップページに限った挙動である」という前提（issue #318 のレビューで指摘）を
	 * 固定する。
	 *
	 * @return void
	 */
	public function test_bill_custom_home_post_type__post_type_not_front_page() {

		$test_cases = array(
			// --- 正常系：文字列で投稿タイプを指定（ページの種類を問わず上書きされる） ---
			array(
				'test_condition_name' => 'トップページ以外（年別アーカイブ）で投稿タイプに文字列「client」を指定した場合 => post_type は client で上書きされ取引先のみ表示',
				'conditions'          => array(
					'year'      => 2024,
					'post_type' => 'client',
				),
				'expected'            => array(
					'post_type' => 'client',
					'titles'    => array( '取引先テスト書類' ),
				),
			),
			// --- 正常系：パラメーター自体が無い場合（アーカイブ本来の挙動のまま） ---
			array(
				// トップページと違い is_front_page() の既定値フォールバックも起きないため、
				// post_type は空文字のまま WP_Query に渡り、WordPress の既定である
				// post_type = 'post' 相当の挙動になる（見積書・取引先は含まれない）
				'test_condition_name' => 'トップページ以外（年別アーカイブ）で投稿タイプを指定しない場合 => 既定値へのフォールバックは起きずアーカイブ本来の挙動（post のみ）になる',
				'conditions'          => array(
					'year' => 2024,
				),
				'expected'            => array(
					'post_type' => '',
					'titles'    => array( '請求書テスト書類' ),
				),
			),
			// --- 境界値・異常系：配列で渡された場合 ---
			array(
				'test_condition_name' => 'トップページ以外（年別アーカイブ）で投稿タイプに配列 [client, post] を指定した場合 => 警告が出ず、配列がそのまま有効になり該当2件を表示',
				'conditions'          => array(
					'year'      => 2024,
					'post_type' => array( 'client', 'post' ),
				),
				'expected'            => array(
					'post_type' => array( 'client', 'post' ),
					'titles'    => array( '取引先テスト書類', '請求書テスト書類' ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件をクエリー文字列に組み立てて年別アーカイブに移動
			$this->go_to( home_url( '/' ) . '?' . http_build_query( $case['conditions'] ) );

			global $wp_query;

			// トップページ扱いではなく年別アーカイブ扱いになっていることを確認する
			// （この前提が崩れると is_front_page() の既定値フォールバックの分岐に
			// 入ってしまい、このテストの意図と違う経路を検証してしまう）
			$this->assertFalse( is_front_page(), $case['test_condition_name'] . '（トップページ扱いにならないこと）' );
			$this->assertTrue( is_date(), $case['test_condition_name'] . '（年別アーカイブとして扱われること）' );

			// post_type クエリー変数が上書きされず、配列のまま渡っていることを検証
			$this->assertSame(
				$case['expected']['post_type'],
				$wp_query->get( 'post_type' ),
				$case['test_condition_name'] . '（post_type クエリー変数、上書きされず配列のまま）'
			);

			// 一覧に表示される書類の件名を検証（発行日の新しい順）
			$this->assertSame(
				$case['expected']['titles'],
				wp_list_pluck( $wp_query->posts, 'post_title' ),
				$case['test_condition_name'] . '（一覧に表示される書類）'
			);
		}
	}

	/**
	 * bill_custom_home_post_type() の bill_client・start_date・end_date のテスト
	 *
	 * この3パラメーターは post_type と同じく $_GET を型チェックせずに文字列処理
	 * （esc_attr()・文字列連結）へ渡していたため、配列で渡すと PHP 警告
	 * （Array to string conversion）が発生していた。pre_get_posts は
	 * bill_no_login_redirect() の認証ゲートより前に実行されるため、未認証の第三者でも
	 * 到達できる警告経路であり、この3パラメーターも塞いだことを固定する。
	 *
	 * あわせて、受け取り条件に is_string() を挿入したことで文字列指定時の絞り込みが
	 * 従来どおり効くことも固定する（正常系）。
	 *
	 * .phpunit.xml の convertWarningsToExceptions="true" により、警告が発生すると
	 * go_to() の時点で例外になりテストが失敗するため、配列指定のケースは正常に
	 * 完走すること自体が「警告が出ない」ことの検証になる。
	 *
	 * @return void
	 */
	public function test_bill_custom_home_post_type__client_and_date_params() {

		$test_cases = array(
			// --- 正常系：取引先（bill_client）を文字列で指定 ---
			array(
				// set_up() で見積書テスト書類にだけ bill_client = 42 のメタを設定している
				'test_condition_name' => '取引先（bill_client）に文字列「42」を指定した場合 => 該当する取引先のメタを持つ見積書のみ表示',
				'conditions'          => array( 'bill_client' => '42' ),
				'expected_titles'     => array( '見積書テスト書類' ),
			),
			// --- 正常系：発行日の期間を文字列で指定 ---
			array(
				// start_date のみ指定すると 2024-01-15 以降が対象になり、
				// 2024-01-01 発行の請求書テスト書類は範囲外になる
				'test_condition_name' => '発行日の開始日（start_date）に文字列「2024-01-15」を指定した場合 => それ以降に発行した見積書のみ表示',
				'conditions'          => array( 'start_date' => '2024-01-15' ),
				'expected_titles'     => array( '見積書テスト書類' ),
			),
			array(
				// end_date のみ指定すると 2024-01-31 23:59:59 までが対象になり、
				// 2024-02-01 発行の見積書テスト書類は範囲外になる
				'test_condition_name' => '発行日の終了日（end_date）に文字列「2024-01-31」を指定した場合 => それ以前に発行した請求書のみ表示',
				'conditions'          => array( 'end_date' => '2024-01-31' ),
				'expected_titles'     => array( '請求書テスト書類' ),
			),
			// --- 境界値・異常系：配列で渡された場合 ---
			array(
				'test_condition_name' => '取引先（bill_client）が配列で渡された場合 => 警告が出ず絞り込みなし扱いで既定の書類を表示',
				'conditions'          => array( 'bill_client' => array( '1', '2' ) ),
				'expected_titles'     => array( '見積書テスト書類', '請求書テスト書類' ),
			),
			array(
				'test_condition_name' => '発行日の開始日（start_date）が配列で渡された場合 => 警告が出ず絞り込みなし扱いで既定の書類を表示',
				'conditions'          => array( 'start_date' => array( '2024-01-01' ) ),
				'expected_titles'     => array( '見積書テスト書類', '請求書テスト書類' ),
			),
			array(
				'test_condition_name' => '発行日の終了日（end_date）が配列で渡された場合 => 警告が出ず絞り込みなし扱いで既定の書類を表示',
				'conditions'          => array( 'end_date' => array( '2024-12-31' ) ),
				'expected_titles'     => array( '見積書テスト書類', '請求書テスト書類' ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件をクエリー文字列に組み立ててトップページ（書類一覧）に移動
			$this->go_to( home_url( '/' ) . '?' . http_build_query( $case['conditions'] ) );

			global $wp_query;

			// 一覧に表示される書類の件名を検証
			$this->assertSame(
				$case['expected_titles'],
				wp_list_pluck( $wp_query->posts, 'post_title' ),
				$case['test_condition_name']
			);
		}
	}
}
