<?php
/**
 * Class AdminClientSearchTest
 *
 * 管理画面の書類一覧（edit.php）で取引先名（手入力・登録済）の検索・並び替えができることを
 * 検証するテスト（issue #295）。
 *
 * @package BillVektor
 */

// 既存投稿の削除・管理者ログインなど、書類一覧テストに共通する前提を持つ抽象クラス
// （tests/test-search-keyword.php・tests/test-post-type-filter.php と共有）。
require_once __DIR__ . '/class-bill-document-list-testcase.php';

/**
 * 管理画面の取引先名検索・並び替えのテストケース
 *
 * inc/functions-admin-client-search.php が登録する posts_join・posts_search・
 * posts_orderby・posts_distinct の各フィルターを、実際に WP_Query を実行して検証する。
 *
 * 管理画面のクエリを模擬するため、$this->go_to() ではなく set_current_screen() で
 * is_admin() を true にした上で、メインクエリ相当のグローバル（$wp_the_query）を
 * 差し替えた WP_Query を直接実行する（run_admin_query() に集約）。
 */
class AdminClientSearchTest extends Bill_Document_List_TestCase {

	/**
	 * テスト後のクリーンアップ
	 *
	 * set_current_screen() で書き換えた is_admin() の状態を次のテストへ
	 * 持ち越さないよう、管理画面スクリーンの模擬をフロント相当に戻してから、
	 * 親クラスの後片付け（$_GET のリセット・作成した投稿とユーザーの削除）を行う。
	 *
	 * @return void
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * 管理画面の書類一覧クエリを模擬して実行する
	 *
	 * is_admin() が true を返すよう管理画面のスクリーンを模擬したうえで、
	 * WP_Posts_List_Table が使う $wp_query と同じ扱い（$wp_the_query との同一性）に
	 * なるようグローバルを差し替えた WP_Query を実行する。
	 * bill_is_target_admin_document_query()（inc/functions-admin-client-search.php）の
	 * 判定基準（is_admin() && $query->is_main_query()）が実際の管理画面と同じ経路で
	 * true になることを担保するための共通処理。
	 *
	 * @param array  $args      WP_Query に渡すクエリー引数。
	 * @param string $screen_id set_current_screen() に渡すスクリーンID（既定は投稿一覧）。
	 * @return WP_Query 実行済みの WP_Query。
	 */
	private function run_admin_query( array $args, $screen_id = 'edit-post' ) {
		set_current_screen( $screen_id );

		global $wp_the_query;
		$query        = new WP_Query();
		$wp_the_query = $query;

		$default_args = array(
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);
		$query->query( array_merge( $default_args, $args ) );

		return $query;
	}

	/**
	 * bill_admin_client_search() の検索テスト
	 *
	 * 取引先（イレギュラー）の手入力名・取引先（登録済）の投稿タイトルの両方で
	 * 検索できること、既存の件名・本文検索が壊れていないこと（回帰）、複数語検索が
	 * 標準どおり動くこと、SQLワイルドカード・クォートのエスケープ、不正な bill_client を
	 * 持つ書類でクラッシュ・誤ヒットしないことを検証する。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search() {

		// 登録済取引先（client 投稿）
		$client_vektor_id = wp_insert_post(
			array(
				'post_title'  => '株式会社ベクトル',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['株式会社ベクトル'] = $client_vektor_id;

		// 取引先（client）以外の投稿。bill_client がこの投稿を指していても
		// 名前を拾ってはいけないことの検証用（PR #341 と同種の穴を防ぐ）。
		$non_client_id = wp_insert_post(
			array(
				'post_title'  => '秘密ページ',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$this->post_ids['秘密ページ'] = $non_client_id;

		// テスト用の書類（post_type=post）を作成する
		$docs = array(
			// 件名だけにキーワードを含む（件名検索の回帰確認用）
			'サイト制作費案件'      => array(),
			// 本文だけにキーワードを含む（本文検索の回帰確認用。管理画面の検索は
			// フロントの bill_keyword と違い件名限定にしていないため、本文もヒットする）
			'本文検索の回帰確認用'    => array( 'post_content' => '特別本文キーワード' ),
			// 取引先（登録済）の投稿タイトルだけで検索する対象（手入力なし）
			'案件A'            => array( 'bill_client' => $client_vektor_id ),
			// 取引先（イレギュラー）の手入力名だけで検索する対象（登録済なし）
			'案件B'            => array( 'bill_client_name_manual' => '山田太郎' ),
			// 手入力・登録済の両方が設定されている書類。
			// 表示は手入力が優先される（bill_get_client_name()）が、検索は
			// issue #295 のスコープ確定表（両方 "する"）どおり、どちらの名前でも
			// 独立にヒットする必要がある（表示の優先順位を検索に持ち込まない）
			'案件C'            => array(
				'bill_client_name_manual' => '鈴木一郎',
				'bill_client'             => $client_vektor_id,
			),
			// 複数語（2語）が両方とも件名に含まれる（コア標準の複数語検索の回帰確認用）
			'御中請求書 サンプル'    => array(),
			// 複数語（2語）が両方とも取引先（イレギュラー）の手入力名に含まれる
			'複数語手入力案件'      => array( 'bill_client_name_manual' => 'アルファ商事 ベータ支店' ),
			// 複数語の一方が件名、もう一方が取引先名だけに含まれる（混在）。
			// 本実装は「語ごとに (件名/本文 の標準一致) OR (取引先名の一致)」という
			// グループ単位の OR で拡張しており、語をまたいで件名と取引先名を
			// 混ぜてAND判定することはしない（既存の $search の構造を温存する設計上の判断）。
			// そのため、この書類は「ガンマ デルタ」の複数語検索ではヒットしない想定。
			'混在ケース'          => array(
				'post_title_override'     => 'ガンマ限定案件',
				'bill_client_name_manual' => 'デルタ限定商事',
			),
			// SQLワイルドカード（%）を含む検索語のエスケープ確認用。
			// 「50xyzOFF」はエスケープが効いていないと "50%OFF" の検索でも
			// ワイルドカード一致してしまうデコイ
			'ワイルドカード対象'     => array( 'bill_client_name_manual' => '50%OFFセール案件' ),
			'ワイルドカード非対象デコイ' => array( 'bill_client_name_manual' => '50xyzOFFセール案件' ),
			// クォートを含む検索語のエスケープ確認用
			'クォート対象'         => array( 'bill_client_name_manual' => "O'Brien商事案件" ),
			// 存在しない取引先IDが保存されている書類（クラッシュ・誤ヒットしないことの確認用）
			'不正な取引先ID対象'    => array( 'bill_client' => 999999 ),
			// 取引先（client）以外の投稿IDが保存されている書類。
			// bill_client が「秘密ページ」を指していても、そのタイトルで検索してヒットしては
			// いけない（PR #341 と同種の穴を防ぐ、requirement #6）
			'他投稿ID対象'        => array( 'bill_client' => $non_client_id ),
		);

		foreach ( $docs as $title => $meta ) {
			$post_title = isset( $meta['post_title_override'] ) ? $meta['post_title_override'] : $title;
			$post_id    = wp_insert_post(
				array(
					'post_title'   => $post_title,
					'post_content' => isset( $meta['post_content'] ) ? $meta['post_content'] : '',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			);
			$this->post_ids[ $title ] = $post_id;

			if ( isset( $meta['bill_client'] ) ) {
				update_post_meta( $post_id, 'bill_client', $meta['bill_client'] );
			}
			if ( isset( $meta['bill_client_name_manual'] ) ) {
				update_post_meta( $post_id, 'bill_client_name_manual', $meta['bill_client_name_manual'] );
			}
		}

		$test_cases = array(
			// --- 正常系：既存の検索（件名・本文）が壊れていないこと（回帰） ---
			array(
				'test_condition_name' => '件名に含まれるキーワードで検索した場合 => 該当する書類がヒットする（件名検索の回帰）',
				's'                   => 'サイト制作費',
				'expected_titles'     => array( 'サイト制作費案件' ),
			),
			array(
				'test_condition_name' => '本文だけに含まれるキーワードで検索した場合 => 該当する書類がヒットする（本文検索の回帰）',
				's'                   => '特別本文キーワード',
				'expected_titles'     => array( '本文検索の回帰確認用' ),
			),
			// --- 正常系：取引先名での検索（issue #295 の主目的） ---
			array(
				'test_condition_name' => '登録済取引先の投稿タイトルで検索した場合 => 紐づく書類がヒットする',
				's'                   => 'ベクトル',
				'expected_titles'     => array( '案件A', '案件C' ),
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）の手入力名で検索した場合 => 該当する書類がヒットする',
				's'                   => '山田太郎',
				'expected_titles'     => array( '案件B' ),
			),
			array(
				'test_condition_name' => '手入力・登録済の両方がある書類で手入力名で検索した場合 => ヒットする',
				's'                   => '鈴木一郎',
				'expected_titles'     => array( '案件C' ),
			),
			// --- 正常系：複数語検索 ---
			array(
				'test_condition_name' => '複数語がどちらも件名に含まれる場合 => ヒットする（コア標準の複数語検索の回帰）',
				's'                   => '請求書 サンプル',
				'expected_titles'     => array( '御中請求書 サンプル' ),
			),
			array(
				'test_condition_name' => '複数語がどちらも取引先（イレギュラー）の手入力名に含まれる場合 => ヒットする',
				's'                   => 'アルファ商事 ベータ支店',
				'expected_titles'     => array( '複数語手入力案件' ),
			),
			// --- 境界値：複数語が件名と取引先名に分散している場合 ---
			array(
				'test_condition_name' => '複数語の一方が件名、もう一方が取引先名だけにある場合 => ヒットしない（語をまたいだ混在ANDは対象外という設計判断）',
				's'                   => 'ガンマ デルタ',
				'expected_titles'     => array(),
			),
			// --- 異常系・境界値：エスケープ ---
			array(
				'test_condition_name' => '検索語に SQL ワイルドカード（%）を含む場合 => 文字どおりの一致のみヒットし、デコイはヒットしない（esc_like の確認）',
				's'                   => '50%OFF',
				'expected_titles'     => array( 'ワイルドカード対象' ),
			),
			array(
				'test_condition_name' => '検索語にシングルクォートを含む場合 => クォートを含む取引先名の書類がヒットする（SQLインジェクション対策の確認）',
				's'                   => "O'Brien",
				'expected_titles'     => array( 'クォート対象' ),
			),
			// --- 異常系：不正な bill_client ---
			array(
				'test_condition_name' => '存在しない取引先IDが保存された書類がある状態で無関係なキーワードを検索した場合 => クラッシュせず0件（該当なし）',
				's'                   => '該当しないキーワード',
				'expected_titles'     => array(),
			),
			array(
				'test_condition_name' => '取引先（client）以外の投稿IDが bill_client に保存されている場合 => その投稿のタイトルで検索してもヒットしない（無関係な投稿名を拾わない）',
				's'                   => '秘密ページ',
				'expected_titles'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$query = $this->run_admin_query(
				array(
					'post_type' => 'post',
					's'         => $case['s'],
				)
			);

			$this->assertSame(
				$case['expected_titles'],
				wp_list_pluck( $query->posts, 'post_title' ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * bill_admin_client_search() のスコープ限定テスト
	 *
	 * 管理画面の対象投稿タイプ（post・estimate）以外や、フロント側のクエリには
	 * 取引先名検索が及ばないこと（既存の検索・他の投稿タイプのクエリを壊さないこと）を検証する。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__scope() {

		$client_id = wp_insert_post(
			array(
				'post_title'  => '株式会社スコープ確認',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['株式会社スコープ確認'] = $client_id;

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'スコープ確認案件',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['スコープ確認案件'] = $post_id;
		update_post_meta( $post_id, 'bill_client', $client_id );

		// 対象外の投稿タイプ（page）に、検索語と同じ文字列を件名に持つ投稿を作成する。
		// 対象投稿タイプの判定に問題があった場合、この投稿がクラッシュを引き起こしたり
		// 意図せず巻き込まれたりしないこと（既存の検索が壊れないこと）を確認する
		$page_id = wp_insert_post(
			array(
				'post_title'  => 'ページ内にスコープ確認を含む固定ページ',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$this->post_ids['ページ内にスコープ確認を含む固定ページ'] = $page_id;

		// 対象外の投稿タイプ（page）を管理画面で検索した場合、
		// 取引先名の拡張は適用されず、コア標準の件名検索のみが効くことを確認する
		$page_query = $this->run_admin_query(
			array(
				'post_type' => 'page',
				's'         => 'スコープ確認',
			),
			'edit-page'
		);
		$this->assertSame(
			array( 'ページ内にスコープ確認を含む固定ページ' ),
			wp_list_pluck( $page_query->posts, 'post_title' ),
			'投稿タイプが対象外（page）の場合 => コア標準の件名検索のみが効き、クラッシュしないこと'
		);

		// フロント側（is_admin() が false）のクエリでは、取引先名検索が適用されないことを確認する。
		// set_current_screen( 'front' ) で is_admin() を明示的に false へ戻したうえで、
		// $wp_the_query との同一性（is_main_query()）だけを満たす WP_Query を実行する。
		set_current_screen( 'front' );
		global $wp_the_query;
		$front_query  = new WP_Query();
		$wp_the_query = $front_query;
		$front_query->query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				's'              => '株式会社スコープ確認',
			)
		);
		$this->assertSame(
			array(),
			wp_list_pluck( $front_query->posts, 'post_title' ),
			'フロント側（is_admin() が false）のクエリでは取引先名検索が適用されないこと'
		);
	}

	/**
	 * bill_admin_client_orderby() の並び替えテスト
	 *
	 * 手入力名が優先されること、登録済取引先名へのフォールバック、取引先が
	 * 未設定・不正な書類が結果から消えずに一方の端へまとまること、
	 * 昇順・降順の両方で件数が変わらないことを検証する。
	 *
	 * 照合順序（コレーション）依存の揺れを避けるため、並び順を比較する書類の
	 * 取引先名は ASCII 文字列にしている。post_type は estimate にして、
	 * test_bill_admin_client_search() が作成する post_type=post の書類と
	 * 混ざらないようにしている。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_orderby() {

		$client_alpha_id = wp_insert_post(
			array(
				'post_title'  => 'Alpha Trading Co',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['Alpha Trading Co'] = $client_alpha_id;

		$client_zeta_id = wp_insert_post(
			array(
				'post_title'  => 'Zeta Manufacturing',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['Zeta Manufacturing'] = $client_zeta_id;

		$page_id = wp_insert_post(
			array(
				'post_title'  => 'Not A Client Page',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$this->post_ids['Not A Client Page'] = $page_id;

		// 並び替えキー: "Alpha Trading Co"（登録済取引先の投稿タイトルをそのまま使用）。
		// 発行日（post_date）をあえて Beta より新しい日付にしている。
		// 取引先名の並び替えが実装されておらず、発行日順にフォールバックしている不具合を
		// 見逃さないようにするため（発行日順だと Alpha と Beta の順序が逆転する）
		$doc_alpha = wp_insert_post(
			array(
				'post_title'  => 'Doc Alpha',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
				'post_date'   => '2024-05-01 00:00:00',
			)
		);
		$this->post_ids['Doc Alpha'] = $doc_alpha;
		update_post_meta( $doc_alpha, 'bill_client', $client_alpha_id );

		// 並び替えキー: "Beta Store"（手入力名。登録済「Zeta Manufacturing」より優先される）。
		// 発行日は Alpha より古い日付にしている（理由は Alpha 側のコメントを参照）
		$doc_beta = wp_insert_post(
			array(
				'post_title'  => 'Doc Beta',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
				'post_date'   => '2024-04-01 00:00:00',
			)
		);
		$this->post_ids['Doc Beta'] = $doc_beta;
		update_post_meta( $doc_beta, 'bill_client', $client_zeta_id );
		update_post_meta( $doc_beta, 'bill_client_name_manual', 'Beta Store' );

		// 並び替えキー: NULL（取引先が未設定）。post_date を明示してNULL同士の
		// 内部順序（第2キー）も検証できるようにする
		$doc_unset = wp_insert_post(
			array(
				'post_title'  => 'Doc Unset',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
				'post_date'   => '2024-01-01 00:00:00',
			)
		);
		$this->post_ids['Doc Unset'] = $doc_unset;

		// 並び替えキー: NULL（存在しない取引先ID）
		$doc_invalid_id = wp_insert_post(
			array(
				'post_title'  => 'Doc Invalid Id',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
				'post_date'   => '2024-01-02 00:00:00',
			)
		);
		$this->post_ids['Doc Invalid Id'] = $doc_invalid_id;
		update_post_meta( $doc_invalid_id, 'bill_client', 999999 );

		// 並び替えキー: NULL（取引先以外の投稿タイプを指している）
		$doc_wrong_type = wp_insert_post(
			array(
				'post_title'  => 'Doc Wrong Type',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
				'post_date'   => '2024-01-03 00:00:00',
			)
		);
		$this->post_ids['Doc Wrong Type'] = $doc_wrong_type;
		update_post_meta( $doc_wrong_type, 'bill_client', $page_id );

		$test_cases = array(
			array(
				'test_condition_name' => '昇順（asc）の場合 => 取引先未設定・不正値の書類が先頭にまとまり、手入力優先で Alpha → Beta の順',
				'order'                => 'asc',
				'expected_titles'      => array( 'Doc Unset', 'Doc Invalid Id', 'Doc Wrong Type', 'Doc Alpha', 'Doc Beta' ),
			),
			array(
				'test_condition_name' => '降順（desc）の場合 => Beta → Alpha の順の後に、取引先未設定・不正値の書類が末尾にまとまる（一覧から消えないこと）',
				'order'                => 'desc',
				'expected_titles'      => array( 'Doc Beta', 'Doc Alpha', 'Doc Wrong Type', 'Doc Invalid Id', 'Doc Unset' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$query = $this->run_admin_query(
				array(
					'post_type' => 'estimate',
					'orderby'   => 'bill_client_name',
					'order'     => $case['order'],
				)
			);

			// 取引先が未設定・不正な書類も含めて全5件が表示され、
			// 並び替えによって一覧から消えないことを確認する（植草さんの必須要件）
			$this->assertSame(
				5,
				(int) $query->found_posts,
				$case['test_condition_name'] . '（該当件数。並び替えで書類が消えないこと）'
			);

			$this->assertSame(
				$case['expected_titles'],
				wp_list_pluck( $query->posts, 'post_title' ),
				$case['test_condition_name'] . '（並び順）'
			);
		}
	}

	/**
	 * bill_add_client_sortable_column() のテスト
	 *
	 * 取引先カラム（bill_get_client_column_key()）を orderby 識別子
	 * （bill_get_client_orderby_key()）付きでソート可能列の配列へ追加することを検証する。
	 * 見出しリンクの生成・aria-sort 属性は WordPress コアが担うため、
	 * ここでは「orderby 識別子が正しく登録されているか」だけを検証する
	 * （司からの指示: 見出しのリンクを自前で組まない）。
	 *
	 * @return void
	 */
	public function test_bill_add_client_sortable_column() {

		$test_cases = array(
			array(
				'test_condition_name' => '既存のソート可能列が無い場合 => 取引先カラムが追加される',
				'conditions'          => array(),
				'expected'            => array( bill_get_client_column_key() => bill_get_client_orderby_key() ),
			),
			array(
				'test_condition_name' => '既存のソート可能列（件名）がある場合 => 既存の列を維持したまま取引先カラムが追加される',
				'conditions'          => array( 'title' => 'title' ),
				'expected'            => array(
					'title'                          => 'title',
					bill_get_client_column_key() => bill_get_client_orderby_key(),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = bill_add_client_sortable_column( $case['conditions'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * bill_register_client_sortable_column() のテスト
	 *
	 * 対象の投稿タイプ（post・estimate）に対して、ソート可能列を追加するフィルターが
	 * 登録されることを検証する（tests/test-admin-columns.php の
	 * test_bill_register_client_admin_column() と同じ検証方法）。
	 *
	 * @return void
	 */
	public function test_bill_register_client_sortable_column() {

		// フックを登録
		bill_register_client_sortable_column();

		$test_cases = array(
			array(
				'test_condition_name' => '請求書（post）の場合 => ソート可能列フィルターが登録されている',
				'hook_name'           => 'manage_edit-post_sortable_columns',
			),
			array(
				'test_condition_name' => '見積書（estimate）の場合 => ソート可能列フィルターが登録されている',
				'hook_name'           => 'manage_edit-estimate_sortable_columns',
			),
			array(
				'test_condition_name' => '対象外の投稿タイプ（page）の場合 => ソート可能列フィルターが登録されていない',
				'hook_name'           => 'manage_edit-page_sortable_columns',
				'expected_registered' => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$expected = isset( $case['expected_registered'] ) ? $case['expected_registered'] : true;
			$this->assertSame(
				$expected,
				(bool) has_filter( $case['hook_name'], 'bill_add_client_sortable_column' ),
				$case['test_condition_name']
			);
		}
	}
}
