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
 * posts_orderby・posts_distinct・posts_fields の各フィルターを、実際に WP_Query を
 * 実行して検証する。
 *
 * 管理画面のクエリを模擬するため、run_admin_query() で WP_ADMIN 定数を定義したうえで、
 * メインクエリ相当のグローバル（$wp_the_query）を差し替えた WP_Query を直接実行する。
 * bill_is_target_admin_document_query()（inc/functions-admin-client-search.php）は
 * is_admin() ではなく `defined( 'WP_ADMIN' ) && WP_ADMIN` で管理画面かどうかを判定する
 * （フロント側で set_current_screen() を呼ぶ他のプラグインが同居すると is_admin() が
 * フロントでも true になり得るため。inc/functions-limit-view.php と同じ守り方）。
 *
 * WP_ADMIN はPHP定数のため一度定義すると取り消せず、同一プロセス内の他のテストへ
 * 漏れてしまう（特に「フロント側には影響しない」ことを検証するテストで問題になる）。
 * そのため、このクラスには @runTestsInSeparateProcesses を付け、テストメソッドごとに
 * 独立したPHPプロセスで実行させることで、WP_ADMIN の定義が他のテストへ波及しないようにしている。
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminClientSearchTest extends Bill_Document_List_TestCase {

	/**
	 * 管理画面の書類一覧クエリを模擬して実行する
	 *
	 * `defined( 'WP_ADMIN' ) && WP_ADMIN` が true を返すよう WP_ADMIN 定数を定義したうえで、
	 * WP_Posts_List_Table が使う $wp_query と同じ扱い（$wp_the_query との同一性）に
	 * なるようグローバルを差し替えた WP_Query を実行する。
	 * bill_is_target_admin_document_query()（inc/functions-admin-client-search.php）の
	 * 判定基準が実際の管理画面と同じ経路で true になることを担保するための共通処理。
	 *
	 * このメソッドを一度でも呼ぶと、以降そのテストメソッド（プロセス）内では
	 * ずっと管理画面扱いになる（PHP定数は取り消せないため）。フロント側の挙動を
	 * 検証したいテストではこのメソッドを呼ばないこと（このクラスに付けた
	 * @runTestsInSeparateProcesses により、他のテストメソッドへは影響しない）。
	 *
	 * @param array $args WP_Query に渡すクエリー引数。
	 * @return WP_Query 実行済みの WP_Query。
	 */
	private function run_admin_query( array $args ) {
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

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
	 * bill_admin_client_search() の括弧を含む検索語の回帰テスト
	 *
	 * 安藤さんのレビュー指摘（HIGH）の再発防止テスト。以前の実装は WordPress コアが
	 * 組み立てる $search 文字列（検索語がそのまま LIKE '%検索語%' として埋め込まれた形）を
	 * 括弧の対応を数えて解析しており、検索語に半角括弧が含まれると
	 * （日本語の業務データでは「(株)」のような社名表記で普通に起こりうる）、
	 * 対応する閉じ括弧の位置を取り違えて SQL構文エラー・意味の変わった検索結果を
	 * 引き起こしていた。修正後は $search の中身を解析しない実装にしている。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__parentheses() {

		// 取引先名に半角括弧を含む（日本語の業務データで実際に起こる表記）
		$client_id = wp_insert_post(
			array(
				'post_title'  => '(株)パーレン商事',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['(株)パーレン商事'] = $client_id;

		$doc_client_paren = wp_insert_post(
			array(
				'post_title'  => 'パーレン確認案件1',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['パーレン確認案件1'] = $doc_client_paren;
		update_post_meta( $doc_client_paren, 'bill_client', $client_id );

		// 件名に閉じ括弧を含む（取引先とは無関係。コア標準の件名検索の回帰確認用）
		$doc_title_paren = wp_insert_post(
			array(
				'post_title'  => '型番8)特価品',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['型番8)特価品'] = $doc_title_paren;

		// 件名に開き括弧付きの語と、通常の語の2語（複数語検索 + 括弧の組み合わせの回帰確認用）
		$doc_multi_word_paren = wp_insert_post(
			array(
				'post_title'  => 'a)特別セール bイベント',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['a)特別セール bイベント'] = $doc_multi_word_paren;

		$test_cases = array(
			array(
				'test_condition_name' => '検索語が開き括弧を含む「(株」の場合 => クラッシュせず、括弧を含む取引先名の書類がヒットする',
				's'                   => '(株',
				'expected_titles'     => array( 'パーレン確認案件1' ),
			),
			array(
				'test_condition_name' => '検索語が閉じ括弧を含む「8)」の場合 => クラッシュせず、コア標準の件名検索が機能する',
				's'                   => '8)',
				'expected_titles'     => array( '型番8)特価品' ),
			),
			array(
				'test_condition_name' => '複数語のうち1語が括弧を含む「a) b」の場合 => クラッシュせず、両語が件名に含まれる書類がヒットする',
				's'                   => 'a) b',
				'expected_titles'     => array( 'a)特別セール bイベント' ),
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
	 * bill_admin_client_search() の除外検索（-語）のテスト
	 *
	 * WordPress コア標準の除外検索（検索語の先頭が「-」の場合、その語を含まないことを
	 * AND で要求する）が、取引先名の検索でも同じ意味で機能することを検証する。
	 * 除外語を含めても取引先名検索そのものが無効化されないこと、
	 * 取引先名に文字どおり除外語が含まれる書類は（コア側の一致経路によらず）
	 * 必ず除外されることを確認する（安藤さんのレビュー指摘。取引先名の除外条件を
	 * 式全体の外側で独立した AND として効かせる実装になっていないと、コア側
	 * （件名・本文）だけで一致した書類には除外条件が一度も評価されず、取引先名に
	 * 除外語を含む書類がすり抜けてしまう）。
	 *
	 * 一方で、件名に肯定語・除外語の両方を含み、取引先名には肯定語だけを含む書類は
	 * 除外できない（既知のトレードオフ。bill_admin_client_search() のDocBlock参照）。
	 * この既知の挙動が意図せず変わっていないかも合わせて確認する。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__exclusion() {

		$client_id = wp_insert_post(
			array(
				'post_title'  => 'エクスクルード商事',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['エクスクルード商事'] = $client_id;

		// 除外語だけを含む取引先（登録済）。肯定語「エクスクルード」は含まない
		$client_with_exclusion_word_id = wp_insert_post(
			array(
				'post_title'  => '除外語建設',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['除外語建設'] = $client_with_exclusion_word_id;

		// 登録済取引先名にだけ「エクスクルード」を含み、「除外語」は含まない
		// （除外語を指定しても取引先名検索そのものは無効化されないことの確認用）
		$doc_keep = wp_insert_post(
			array(
				'post_title'  => '除外検索確認案件1',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['除外検索確認案件1'] = $doc_keep;
		update_post_meta( $doc_keep, 'bill_client', $client_id );

		// 手入力名に「エクスクルード」と「除外語」の両方を含む
		// （除外語を指定すると、この書類は除外されることの確認用）
		$doc_excluded = wp_insert_post(
			array(
				'post_title'  => '除外検索確認案件2',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['除外検索確認案件2'] = $doc_excluded;
		update_post_meta( $doc_excluded, 'bill_client_name_manual', 'エクスクルード除外語入り' );

		// 件名だけに「エクスクルード」を含み（＝取引先名検索を介さずコア標準の検索
		// だけで一致する）、登録済取引先名に「除外語」を含む。
		// 安藤さんのレビュー指摘の回帰テスト本体: 除外条件が取引先名検索の
		// ORブロックの中に閉じていた場合、この書類はコア側の一致だけでORが
		// 真になり除外を素通りしてしまう。除外条件を式全体の外側の独立したAND
		// にしたことで、コア側の一致経路であっても取引先名の除外語を検知できることを確認する
		$doc_excluded_via_client_only = wp_insert_post(
			array(
				'post_title'  => 'エクスクルード単体案件',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['エクスクルード単体案件'] = $doc_excluded_via_client_only;
		update_post_meta( $doc_excluded_via_client_only, 'bill_client', $client_with_exclusion_word_id );

		// 【既知のトレードオフ】件名に肯定語・除外語の両方を含み、取引先名（登録済）には
		// 肯定語だけを含む。$search を分解しない設計上、除外できない
		$doc_known_limitation = wp_insert_post(
			array(
				'post_title'  => 'エクスクルード除外語案件',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['エクスクルード除外語案件'] = $doc_known_limitation;
		update_post_meta( $doc_known_limitation, 'bill_client', $client_id );

		$test_cases = array(
			array(
				'test_condition_name' => '除外語を指定しない場合 => 「エクスクルード」に関連する書類が全てヒットする（回帰の前提確認）',
				's'                   => 'エクスクルード',
				'expected_titles'     => array( '除外検索確認案件1', '除外検索確認案件2', 'エクスクルード単体案件', 'エクスクルード除外語案件' ),
			),
			array(
				'test_condition_name' => '除外語「-除外語」を併用した場合 => 取引先名検索は無効化されず除外語を含む書類だけが除外される。取引先名に除外語を含む書類は、コア標準の検索だけで一致した場合でも除外される（安藤さんの指摘の回帰確認）。既知のトレードオフ（件名に両方・取引先名に肯定語だけの書類）は引き続きヒットする',
				's'                   => 'エクスクルード -除外語',
				'expected_titles'     => array( '除外検索確認案件1', 'エクスクルード除外語案件' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$query = $this->run_admin_query(
				array(
					'post_type' => 'post',
					's'         => $case['s'],
				)
			);

			// この一連のケースは「該当する書類の集合」を検証するのが目的で並び順は
			// 対象外（orderby を指定していないため、既定の発行日順は同時刻作成の
			// タイブレークに左右され不安定）。ソートしてから比較する
			$expected_titles = $case['expected_titles'];
			sort( $expected_titles );
			$actual_titles = wp_list_pluck( $query->posts, 'post_title' );
			sort( $actual_titles );

			$this->assertSame(
				$expected_titles,
				$actual_titles,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 除外語の判定が「-」1文字だけの検索語でもコアと一致することのテスト
	 *
	 * 安藤さんのレビュー指摘（LOW-4）の回帰テスト。WP_Query::parse_search_terms() は
	 * "-" だけ・短すぎる除外語を事前に取り除くため、検索語が "-" だけの場合は
	 * search_terms が空になり、コアは array( $q['s'] )（元の生の文字列 "-"）へ
	 * フォールバックする。コア自身の後段のループは長さを見ずプレフィックスだけで
	 * 除外判定するため、この "-" は除外語として扱われ、空文字を除外する
	 * （＝実質すべて不一致になる）条件になる。
	 *
	 * 以前の実装は `strlen( $term ) > 1` という長さの足切りを入れていたため、
	 * この "-" を除外語ではなく肯定語（リテラルなハイフン検索）として扱ってしまい、
	 * 取引先名にハイフンを含む書類が誤って検索結果に出ていた。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__lone_hyphen() {

		$client_id = wp_insert_post(
			array(
				'post_title'  => 'ハイフンA-B商事',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['ハイフンA-B商事'] = $client_id;

		$doc_id = wp_insert_post(
			array(
				'post_title'  => 'ハイフン確認案件',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['ハイフン確認案件'] = $doc_id;
		update_post_meta( $doc_id, 'bill_client', $client_id );

		$query = $this->run_admin_query(
			array(
				'post_type' => 'post',
				's'         => '-',
			)
		);

		$this->assertSame(
			array(),
			wp_list_pluck( $query->posts, 'post_title' ),
			'検索語が「-」1文字だけの場合 => コアと同じく除外語として扱われ、取引先名にハイフンを含む書類も含めて0件になる（肯定語として扱われない）'
		);
	}

	/**
	 * bill_admin_client_search() の fail-closed 動作のテスト
	 *
	 * 安藤さんのレビュー指摘（LOW-5）の回帰テスト。$search がコア標準の
	 * " AND (...)" という形で始まっていない場合（優先度10より前に動く他プラグインが
	 * posts_search を別の形へ丸ごと差し替えている等の想定外の状況）、取引先名の
	 * 条件を追加せず $search をそのまま返すことを検証する。
	 *
	 * WP_Query の実行を通さず、関数を直接呼び出して検証する
	 * （コアが実際にこの形以外の $search を組み立てることは無いため、
	 * WP_Query 経由では意図的にこの分岐を再現できない）。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__fail_closed_on_unexpected_search_format() {

		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		global $wp_the_query;
		$query        = new WP_Query();
		$wp_the_query = $query;
		// search_terms 等のクエリー変数を実際のコアの処理で組み立てさせるため、
		// 通常どおりクエリを実行しておく（$search 自体はこの後の直接呼び出しで
		// 想定外の値に差し替えるため、ここでの実行結果は使わない）。
		$query->query(
			array(
				'post_type'      => 'post',
				's'              => 'テスト',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		// コア標準の " AND (...)" という形になっていない、想定外の $search
		$malformed_search = ' OR 1=1 -- 想定外の形式';

		$actual = bill_admin_client_search( $malformed_search, $query );

		$this->assertSame(
			$malformed_search,
			$actual,
			'$search が想定する " AND (...)" 形式で始まっていない場合 => 取引先名の条件を追加せず $search をそのまま返す（fail-closed）'
		);
	}

	/**
	 * bill_admin_client_search() の不正な bill_client（数値混じり文字列）のテスト
	 *
	 * 安藤さんのレビュー指摘（MEDIUM）の回帰テスト。MySQL は文字列と数値の比較時、
	 * 先頭から解釈できる数値部分だけを読み取って暗黙に数値へ変換するため、
	 * REGEXP による検証が無いと『123abc』『 123'（前後空白）『123.0』のような
	 * 数値混じり・整形前の文字列が、実在する取引先IDに一致してしまう
	 * （bill_get_client_id() が弾く値と同じ集合）。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__invalid_client_id_formats() {

		$client_id = wp_insert_post(
			array(
				'post_title'  => 'フォーマット確認商事',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['フォーマット確認商事'] = $client_id;

		// 正しい形式（数字のみの文字列）。これだけがヒットする対照群
		$doc_valid = wp_insert_post(
			array(
				'post_title'  => 'フォーマット確認案件_正常',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['フォーマット確認案件_正常'] = $doc_valid;
		update_post_meta( $doc_valid, 'bill_client', (string) $client_id );

		$invalid_formats = array(
			'フォーマット確認案件_英字混在' => $client_id . 'abc',
			'フォーマット確認案件_前方空白' => ' ' . $client_id,
			'フォーマット確認案件_小数表記' => $client_id . '.0',
			'フォーマット確認案件_プラス符号' => '+' . $client_id,
		);
		foreach ( $invalid_formats as $title => $bill_client_value ) {
			$post_id = wp_insert_post(
				array(
					'post_title'  => $title,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);
			$this->post_ids[ $title ] = $post_id;
			update_post_meta( $post_id, 'bill_client', $bill_client_value );
		}

		$query = $this->run_admin_query(
			array(
				'post_type' => 'post',
				's'         => 'フォーマット確認商事',
			)
		);

		$this->assertSame(
			array( 'フォーマット確認案件_正常' ),
			wp_list_pluck( $query->posts, 'post_title' ),
			'bill_client が数値混じり・整形前の文字列（英字混在・前後空白・小数表記・符号付き）の場合 => 実在する取引先に誤って一致しない'
		);
	}

	/**
	 * 省略名（client_short_name）が検索対象に含まれないことのテスト
	 *
	 * 植草さんからの提案。現状は client_short_name を一切参照していないため
	 * 構造的に混入しえない設計だが、将来 client_short_name を検索対象に
	 * 追加しようとした変更に気づけるよう、直接アサートしておく
	 * （司からの確定スコープ: 省略名は検索対象にしない）。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__client_short_name_excluded() {

		$client_id = wp_insert_post(
			array(
				'post_title'  => '省略名確認株式会社',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		$this->post_ids['省略名確認株式会社'] = $client_id;
		update_post_meta( $client_id, 'client_short_name', '省略名確認商事の短縮表記' );

		$doc_id = wp_insert_post(
			array(
				'post_title'  => '省略名確認案件',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids['省略名確認案件'] = $doc_id;
		update_post_meta( $doc_id, 'bill_client', $client_id );

		$test_cases = array(
			array(
				'test_condition_name' => '登録済取引先の正式名称で検索した場合 => ヒットする（設定が正しく効いていることの前提確認）',
				's'                   => '省略名確認株式会社',
				'expected_titles'     => array( '省略名確認案件' ),
			),
			array(
				'test_condition_name' => '省略名（client_short_name）でしか一致しない語で検索した場合 => ヒットしない（省略名は検索対象外）',
				's'                   => '省略名確認商事の短縮表記',
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
	 * bill_admin_client_search() のスコープ限定テスト（対象外の投稿タイプ）
	 *
	 * 管理画面の対象投稿タイプ（post・estimate）以外のクエリには取引先名検索が
	 * 及ばないこと（既存の検索・他の投稿タイプのクエリを壊さないこと）を検証する。
	 *
	 * フロント側のクエリの検証は test_bill_admin_client_search__front_not_affected() に
	 * 分けている。run_admin_query() が定義する WP_ADMIN 定数はテストメソッド（プロセス）内で
	 * 取り消せないため、1つのテストメソッド内で「管理画面扱い」と「フロント扱い」の
	 * 両方を検証することができないため。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__out_of_scope_post_type() {

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
			)
		);
		$this->assertSame(
			array( 'ページ内にスコープ確認を含む固定ページ' ),
			wp_list_pluck( $page_query->posts, 'post_title' ),
			'投稿タイプが対象外（page）の場合 => コア標準の件名検索のみが効き、クラッシュしないこと'
		);
	}

	/**
	 * bill_admin_client_search() のスコープ限定テスト（フロント側）
	 *
	 * フロント側（WP_ADMIN 未定義）のクエリには取引先名検索が適用されないことを検証する。
	 * run_admin_query() を一切呼ばず、WP_ADMIN 定数を定義しないままテストを行うことで、
	 * 実際のフロントリクエストと同じ「WP_ADMIN 未定義」の状態を再現する。
	 *
	 * @return void
	 */
	public function test_bill_admin_client_search__front_not_affected() {

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

		$this->assertFalse(
			defined( 'WP_ADMIN' ) && WP_ADMIN,
			'前提確認: このテストでは WP_ADMIN が定義されていないこと（フロント相当であること）'
		);

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
			'フロント側（WP_ADMIN 未定義）のクエリでは取引先名検索が適用されないこと'
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
