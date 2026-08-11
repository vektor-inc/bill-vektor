<?php
/**
 * Class IndexTemplateDocumentListTest
 *
 * 書類一覧（?post_type=estimate 等）としての index.php の取引先カラムのテスト
 *
 * @package BillVektor
 */

/**
 * 書類一覧の取引先カラムのテスト
 *
 * index.php（77〜133行目）の取引先カラムには、共通関数のユニットテストでは
 * 通らない index.php 固有のロジック（省略名の参照、リンクとダッシュの出し分け、
 * 取引先（イレギュラー）の型ガード）がある。#293 の対応中にまさにこの箇所で
 * 2回続けて回帰が発生したため、実際にテンプレートをレンダリングして検証する。
 *
 * 取引先一覧側（?post_type=client）のレンダリングテストは
 * tests/test-index-template.php の IndexTemplateTest にある。あちらは
 * 「行自身が取引先」という特殊な分岐（index.php 84〜105行目）を検証しており、
 * 本クラスが検証する「書類に紐づく取引先」の分岐（index.php 106〜131行目）とは
 * 対象のロジックも必要なフィクスチャ（取引先そのもの vs 取引先を参照する書類）も
 * 異なるため、可読性を優先してファイルを分けている。
 *
 * かつては template-parts/breadcrumb.php の bill_bread_crumb() が
 * function_exists() で保護されておらず、1つのPHPプロセスの中で index.php を
 * 2回以上読み込むと Fatal error になっていたため、index.php をレンダリングする
 * テストはスイート全体で1件しか書けなかった（issue #315）。ガードが入ったことで
 * 本クラスを追加できるようになった。
 */
class IndexTemplateDocumentListTest extends WP_UnitTestCase {

	/**
	 * 省略名（client_short_name）を持つ登録済取引先の投稿ID
	 *
	 * @var int
	 */
	private $client_with_short_name_id;

	/**
	 * 省略名を持たない登録済取引先の投稿ID
	 *
	 * @var int
	 */
	private $client_without_short_name_id;

	/**
	 * 省略名を持たない登録済取引先の名前（投稿タイトル）
	 *
	 * @var string
	 */
	private $client_without_short_name_title = '株式会社取引先本名';

	/**
	 * テスト用の書類（estimate）投稿IDを保持する（キーはケース名）
	 *
	 * @var array
	 */
	private $doc_ids = array();

	/**
	 * テスト用の書類（estimate）投稿タイトルを保持する（キーは $doc_ids と共通のケース名）
	 *
	 * レンダリング結果から行を特定する際に、配列の並び順（挿入順・WP_Query の
	 * post__in の並び順）に依存せず、レンダリングされた件名セルの文字列そのもので
	 * 行を引き当てるために使う。
	 *
	 * @var array
	 */
	private $doc_titles = array();

	/**
	 * テスト前の共通セットアップ
	 *
	 * 取引先（省略名あり・省略名なし）と、各パターンを再現する書類を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		/*
		 * index.php（156〜183行目）はレンダリングのたびに billvektor.com へ実際に
		 * 外部リクエストを送る「お知らせ」欄を含む。テストで外部通信が発生すると
		 * CI が本番サイトへ都度リクエストしてしまい、かつネットワークの成否で
		 * date_default_timezone_set() の実行有無が変わってテストが flaky になるため、
		 * pre_http_request をスタブして常に失敗させる。
		 * WP_UnitTestCase::tear_down() の _restore_hooks() で自動的に外れるため、
		 * このテストクラス側での後片付けは不要。
		 */
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_blocked', 'テストでは外部リクエストを行わない' );
			}
		);

		// 省略名を持つ登録済取引先
		$this->client_with_short_name_id = wp_insert_post(
			array(
				'post_title'  => '株式会社取引先正式名',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		update_post_meta( $this->client_with_short_name_id, 'client_short_name', '取引先の省略名' );

		// 省略名を持たない登録済取引先
		$this->client_without_short_name_id = wp_insert_post(
			array(
				'post_title'  => $this->client_without_short_name_title,
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		// 削除済の投稿IDを、DBの状態に依存せず「存在しない投稿ID」として使うために作成してすぐ削除する
		$deleted_post_id = wp_insert_post(
			array(
				'post_title'  => '削除予定の取引先',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		wp_delete_post( $deleted_post_id, true );

		// パターン: 取引先（登録済）に省略名がある
		$this->doc_titles['with_short_name'] = '見積書（省略名あり取引先）';
		$this->doc_ids['with_short_name']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['with_short_name'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['with_short_name'], 'bill_client', (string) $this->client_with_short_name_id );

		// パターン: 取引先（登録済）に省略名が無い
		$this->doc_titles['without_short_name'] = '見積書（省略名なし取引先）';
		$this->doc_ids['without_short_name']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['without_short_name'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['without_short_name'], 'bill_client', (string) $this->client_without_short_name_id );

		// パターン: 取引先が未設定（bill_client のメタ自体を保存しない）
		$this->doc_titles['no_client'] = '見積書（取引先未設定）';
		$this->doc_ids['no_client']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['no_client'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);

		// パターン: 取引先（イレギュラー）が入力されている
		$this->doc_titles['manual'] = '見積書（取引先イレギュラー）';
		$this->doc_ids['manual']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['manual'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['manual'], 'bill_client_name_manual', '手入力の取引先名' );

		// パターン: 取引先（イレギュラー）に文字列以外（配列）が保存されている
		$this->doc_titles['manual_non_scalar'] = '見積書（取引先イレギュラーが配列）';
		$this->doc_ids['manual_non_scalar']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['manual_non_scalar'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['manual_non_scalar'], 'bill_client_name_manual', array( 'a', 'b' ) );

		// パターン: 取引先（登録済）に存在しない投稿IDが入っている
		$this->doc_titles['invalid_nonexistent'] = '見積書（存在しない取引先ID）';
		$this->doc_ids['invalid_nonexistent']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['invalid_nonexistent'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['invalid_nonexistent'], 'bill_client', (string) $deleted_post_id );

		// パターン: 取引先（登録済）に別投稿タイプ（client 以外）のIDが入っている
		// （client_with_short_name の書類自身の投稿ID = estimate 投稿を指す）
		$this->doc_titles['invalid_other_post_type'] = '見積書（別投稿タイプのID）';
		$this->doc_ids['invalid_other_post_type']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['invalid_other_post_type'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['invalid_other_post_type'], 'bill_client', (string) $this->doc_ids['with_short_name'] );

		// パターン: 取引先（登録済）に数値以外の値が入っている
		$this->doc_titles['invalid_non_numeric'] = '見積書（数値以外の取引先ID）';
		$this->doc_ids['invalid_non_numeric']    = wp_insert_post(
			array(
				'post_title'  => $this->doc_titles['invalid_non_numeric'],
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['invalid_non_numeric'], 'bill_client', 'abc' );
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * 作成した投稿とグローバルの $post を元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		// index.php のループが設定したグローバルの $post を破棄する
		unset( $GLOBALS['post'] );

		wp_delete_post( $this->client_with_short_name_id, true );
		wp_delete_post( $this->client_without_short_name_id, true );
		foreach ( $this->doc_ids as $doc_id ) {
			wp_delete_post( $doc_id, true );
		}

		parent::tear_down();
	}

	/**
	 * 書類一覧の取引先カラムのテスト
	 *
	 * 書類（estimate）一覧として index.php をレンダリングし、取引先カラムの
	 * 省略名優先表示・リンクとダッシュの出し分け・取引先（イレギュラー）の
	 * 型ガードを検証する。
	 *
	 * @return void
	 */
	public function test_index_template_client_column() {
		// index.php はグローバルの $post / $wp_query を参照するため、この scope でもグローバルを使う
		global $post, $wp_query, $wp_the_query;

		// レンダリング後に元へ戻せるようメインクエリを退避する
		$original_wp_query     = $wp_query;
		$original_wp_the_query = $wp_the_query;

		// include 前後で ob_get_level() の増減を見るための基準値
		$ob_level_before = ob_get_level();

		// 書類一覧（見積書一覧）を模したメインクエリを組み立てる
		// post__in の並び順は WP_Query の実際の並び順を保証するものではなく、
		// 以降の検証も行番号ではなく件名セルの文字列で行を特定するため、
		// ここでの並び順自体に意味は持たせていない。
		$query = new WP_Query(
			array(
				'post_type'      => 'estimate',
				'post__in'       => array_values( $this->doc_ids ),
				'posts_per_page' => 10,
			)
		);
		$query->is_archive = true;

		$html = '';

		try {
			$wp_query     = $query;
			$wp_the_query = $query;

			// index.php をレンダリングして出力を取得する
			ob_start();
			include get_theme_file_path( 'index.php' );
			$html = ob_get_clean();
		} finally {
			// include 中の例外でバッファが開いたままにならないよう、開始前のレベルまで閉じる
			while ( ob_get_level() > $ob_level_before ) {
				ob_end_clean();
			}

			// メインクエリを元に戻す
			$wp_query     = $original_wp_query;
			$wp_the_query = $original_wp_the_query;
			wp_reset_postdata();
		}

		// 各行の件名カラム（投稿タイトル）と取引先カラムのセルを、行の出現順を保ったまま取り出す
		preg_match_all( '#<!-- \[ 件名 \] -->\s*<td><a[^>]*>(.*?)</a></td>#s', $html, $title_matches );
		preg_match_all( '#<!-- \[ 取引先 \] -->\s*<td[^>]*>(.*?)</td>#s', $html, $client_matches );
		$title_cells  = array_map( 'trim', $title_matches[1] );
		$client_cells = array_map( 'trim', $client_matches[1] );

		// 件名カラムと取引先カラムは同じ <tr> から1個ずつ取れるはずなので、件数の対応が崩れていないか確認する
		$this->assertCount( count( $this->doc_ids ), $title_cells, '書類一覧の全パターン分の件名カラムがレンダリングされている' );
		$this->assertCount( count( $this->doc_ids ), $client_cells, '書類一覧の全パターン分の取引先カラムがレンダリングされている' );

		/*
		 * 件名（投稿タイトル）をキーに、その行の取引先カラムのセルを引けるようにする。
		 * $title_cells と $client_cells は同じ行の並び順で1件ずつ対応しているため、
		 * この組み合わせ方自体は WP_Query の実際の並び順に依存しない
		 * （並び順がどう変わっても、同じ行から取れたペアであることに変わりはないため）。
		 */
		$client_cell_by_title = array_combine( $title_cells, $client_cells );

		/**
		 * ケース名から、そのケースの取引先カラムのセルを取得する
		 *
		 * @param string $case_key set_up() で $this->doc_titles に登録したケース名。
		 * @return string 取引先カラムのセル（トリム済み）。
		 */
		$cell_for = function ( $case_key ) use ( $client_cell_by_title ) {
			$title = $this->doc_titles[ $case_key ];
			$this->assertArrayHasKey( $title, $client_cell_by_title, "件名「{$title}」の行がレンダリングされている" );
			return $client_cell_by_title[ $title ];
		};

		// テストの配列
		$test_cases = array(
			// --- 取引先（登録済）に省略名がある ---
			array(
				'test_condition_name' => '取引先（登録済）に省略名がある場合 => 省略名が表示される',
				'conditions'          => array(
					'case_key' => 'with_short_name',
					'needle'   => esc_html( '取引先の省略名' ),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に省略名がある場合 => 正式名称ではなく省略名が優先される',
				'conditions'          => array(
					'case_key' => 'with_short_name',
					'needle'   => '株式会社取引先正式名',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '取引先（登録済）に省略名がある場合 => 取引先ページへのリンクになる',
				'conditions'          => array(
					'case_key' => 'with_short_name',
					'needle'   => 'href="' . esc_url( get_permalink( $this->client_with_short_name_id ) ) . '"',
				),
				'expected'            => true,
			),
			// --- 取引先（登録済）に省略名が無い ---
			array(
				'test_condition_name' => '取引先（登録済）に省略名が無い場合 => 取引先の名前が表示される',
				'conditions'          => array(
					'case_key' => 'without_short_name',
					'needle'   => esc_html( $this->client_without_short_name_title ),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に省略名が無い場合 => 取引先ページへのリンクになる',
				'conditions'          => array(
					'case_key' => 'without_short_name',
					'needle'   => 'href="' . esc_url( get_permalink( $this->client_without_short_name_id ) ) . '"',
				),
				'expected'            => true,
			),
			// --- 取引先が未設定 ---
			array(
				'test_condition_name' => '取引先が未設定の場合 => リンクではなくダッシュを表示する',
				'conditions'          => array(
					'case_key' => 'no_client',
					'needle'   => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先が未設定の場合 => 代替テキスト「取引先なし」を表示する',
				'conditions'          => array(
					'case_key' => 'no_client',
					'needle'   => '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">取引先なし</span>',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先が未設定の場合 => リンクにしない',
				'conditions'          => array(
					'case_key' => 'no_client',
					'needle'   => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（イレギュラー）が入力されている ---
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => その文字列がそのまま表示される',
				'conditions'          => array(
					'case_key' => 'manual',
					'needle'   => '手入力の取引先名',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => リンクにしない',
				'conditions'          => array(
					'case_key' => 'manual',
					'needle'   => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（イレギュラー）に文字列以外（配列など）が保存されている ---
			array(
				'test_condition_name' => '取引先（イレギュラー）に配列が保存されている場合 => 未入力として扱われダッシュを表示する（is_scalar() の型ガード）',
				'conditions'          => array(
					'case_key' => 'manual_non_scalar',
					'needle'   => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）に配列が保存されている場合 => 配列を無理に文字列化した表示（"Array" など）をしない',
				'conditions'          => array(
					'case_key' => 'manual_non_scalar',
					'needle'   => 'Array',
				),
				'expected'            => false,
			),
			// --- 取引先（登録済）に存在しない投稿IDが入っている ---
			array(
				'test_condition_name' => '取引先（登録済）に存在しない投稿IDが入っている場合 => リンクを出さずダッシュを表示する',
				'conditions'          => array(
					'case_key' => 'invalid_nonexistent',
					'needle'   => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に存在しない投稿IDが入っている場合 => リンクにしない',
				'conditions'          => array(
					'case_key' => 'invalid_nonexistent',
					'needle'   => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（登録済）に別投稿タイプ（client 以外）のIDが入っている ---
			array(
				'test_condition_name' => '取引先（登録済）に別投稿タイプのIDが入っている場合 => リンクを出さずダッシュを表示する',
				'conditions'          => array(
					'case_key' => 'invalid_other_post_type',
					'needle'   => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に別投稿タイプのIDが入っている場合 => リンクにしない',
				'conditions'          => array(
					'case_key' => 'invalid_other_post_type',
					'needle'   => '<a',
				),
				'expected'            => false,
			),
			array(
				// 参照先（別投稿タイプ側）の件名がそのまま漏れて表示されていないことも明示する
				'test_condition_name' => '取引先（登録済）に別投稿タイプのIDが入っている場合 => 参照先の件名を取引先名として表示しない',
				'conditions'          => array(
					'case_key' => 'invalid_other_post_type',
					'needle'   => esc_html( $this->doc_titles['with_short_name'] ),
				),
				'expected'            => false,
			),
			// --- 取引先（登録済）に数値以外の値が入っている ---
			array(
				'test_condition_name' => '取引先（登録済）に数値以外の値が入っている場合 => リンクを出さずダッシュを表示する',
				'conditions'          => array(
					'case_key' => 'invalid_non_numeric',
					'needle'   => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に数値以外の値が入っている場合 => リンクにしない',
				'conditions'          => array(
					'case_key' => 'invalid_non_numeric',
					'needle'   => '<a',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 対象ケースの行のセルを、件名の文字列から特定して取得する
			$cell = $cell_for( $case['conditions']['case_key'] );

			// テスト対象の文字列が対象セルに含まれるかを判定
			$actual = false !== strpos( $cell, $case['conditions']['needle'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] . ' / 実際のセル: ' . $cell );
		}
	}
}
