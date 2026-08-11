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
	 * テスト前の共通セットアップ
	 *
	 * 取引先（省略名あり・省略名なし）と、各パターンを再現する書類を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

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
		$this->doc_ids['with_short_name'] = wp_insert_post(
			array(
				'post_title'  => '見積書（省略名あり取引先）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['with_short_name'], 'bill_client', (string) $this->client_with_short_name_id );

		// パターン: 取引先（登録済）に省略名が無い
		$this->doc_ids['without_short_name'] = wp_insert_post(
			array(
				'post_title'  => '見積書（省略名なし取引先）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['without_short_name'], 'bill_client', (string) $this->client_without_short_name_id );

		// パターン: 取引先が未設定（bill_client のメタ自体を保存しない）
		$this->doc_ids['no_client'] = wp_insert_post(
			array(
				'post_title'  => '見積書（取引先未設定）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);

		// パターン: 取引先（イレギュラー）が入力されている
		$this->doc_ids['manual'] = wp_insert_post(
			array(
				'post_title'  => '見積書（取引先イレギュラー）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['manual'], 'bill_client_name_manual', '手入力の取引先名' );

		// パターン: 取引先（イレギュラー）に文字列以外（配列）が保存されている
		$this->doc_ids['manual_non_scalar'] = wp_insert_post(
			array(
				'post_title'  => '見積書（取引先イレギュラーが配列）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['manual_non_scalar'], 'bill_client_name_manual', array( 'a', 'b' ) );

		// パターン: 取引先（登録済）に存在しない投稿IDが入っている
		$this->doc_ids['invalid_nonexistent'] = wp_insert_post(
			array(
				'post_title'  => '見積書（存在しない取引先ID）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['invalid_nonexistent'], 'bill_client', (string) $deleted_post_id );

		// パターン: 取引先（登録済）に別投稿タイプ（client 以外）のIDが入っている
		// （client_with_short_name の書類自身の投稿ID = estimate 投稿を指す）
		$this->doc_ids['invalid_other_post_type'] = wp_insert_post(
			array(
				'post_title'  => '見積書（別投稿タイプのID）',
				'post_status' => 'publish',
				'post_type'   => 'estimate',
			)
		);
		update_post_meta( $this->doc_ids['invalid_other_post_type'], 'bill_client', (string) $this->doc_ids['with_short_name'] );

		// パターン: 取引先（登録済）に数値以外の値が入っている
		$this->doc_ids['invalid_non_numeric'] = wp_insert_post(
			array(
				'post_title'  => '見積書（数値以外の取引先ID）',
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

		// 各パターンの書類を、後で行番号から特定できる順番で1つのクエリにまとめる
		$row_order = array(
			'with_short_name',
			'without_short_name',
			'no_client',
			'manual',
			'manual_non_scalar',
			'invalid_nonexistent',
			'invalid_other_post_type',
			'invalid_non_numeric',
		);

		// 書類一覧（見積書一覧）を模したメインクエリを組み立てる
		$query = new WP_Query(
			array(
				'post_type'      => 'estimate',
				'post__in'       => array_values( array_intersect_key( $this->doc_ids, array_flip( $row_order ) ) ),
				'orderby'        => 'post__in',
				'order'          => 'ASC',
				'posts_per_page' => 10,
			)
		);
		$query->is_archive = true;
		$wp_query          = $query;
		$wp_the_query      = $query;

		// index.php をレンダリングして出力を取得する
		ob_start();
		include get_theme_file_path( 'index.php' );
		$html = ob_get_clean();

		// メインクエリを元に戻す
		$wp_query     = $original_wp_query;
		$wp_the_query = $original_wp_the_query;
		wp_reset_postdata();

		// 各行の取引先カラムのセルを取り出す
		preg_match_all( '#<!-- \[ 取引先 \] -->\s*<td[^>]*>(.*?)</td>#s', $html, $matches );
		$client_cells = array_map( 'trim', $matches[1] );

		// row_order と同じ8件のセルが取れていないと、以降 row 番号で参照する検証が意味を成さない
		$this->assertCount( count( $row_order ), $client_cells, '書類一覧の全パターン分の取引先カラムがレンダリングされている' );

		// row_order 上のインデックスを取得するヘルパー
		$row = array_flip( $row_order );

		// テストの配列
		$test_cases = array(
			// --- 取引先（登録済）に省略名がある ---
			array(
				'test_condition_name' => '取引先（登録済）に省略名がある場合 => 省略名が表示される',
				'conditions'          => array(
					'row'    => $row['with_short_name'],
					'needle' => esc_html( '取引先の省略名' ),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に省略名がある場合 => 正式名称ではなく省略名が優先される',
				'conditions'          => array(
					'row'    => $row['with_short_name'],
					'needle' => '株式会社取引先正式名',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '取引先（登録済）に省略名がある場合 => 取引先ページへのリンクになる',
				'conditions'          => array(
					'row'    => $row['with_short_name'],
					'needle' => 'href="' . esc_url( get_permalink( $this->client_with_short_name_id ) ) . '"',
				),
				'expected'            => true,
			),
			// --- 取引先（登録済）に省略名が無い ---
			array(
				'test_condition_name' => '取引先（登録済）に省略名が無い場合 => 取引先の名前が表示される',
				'conditions'          => array(
					'row'    => $row['without_short_name'],
					'needle' => esc_html( $this->client_without_short_name_title ),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に省略名が無い場合 => 取引先ページへのリンクになる',
				'conditions'          => array(
					'row'    => $row['without_short_name'],
					'needle' => 'href="' . esc_url( get_permalink( $this->client_without_short_name_id ) ) . '"',
				),
				'expected'            => true,
			),
			// --- 取引先が未設定 ---
			array(
				'test_condition_name' => '取引先が未設定の場合 => リンクではなくダッシュを表示する',
				'conditions'          => array(
					'row'    => $row['no_client'],
					'needle' => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先が未設定の場合 => 代替テキスト「取引先なし」を表示する',
				'conditions'          => array(
					'row'    => $row['no_client'],
					'needle' => '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">取引先なし</span>',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先が未設定の場合 => リンクにしない',
				'conditions'          => array(
					'row'    => $row['no_client'],
					'needle' => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（イレギュラー）が入力されている ---
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => その文字列がそのまま表示される',
				'conditions'          => array(
					'row'    => $row['manual'],
					'needle' => '手入力の取引先名',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）が入力されている場合 => リンクにしない',
				'conditions'          => array(
					'row'    => $row['manual'],
					'needle' => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（イレギュラー）に文字列以外（配列など）が保存されている ---
			array(
				'test_condition_name' => '取引先（イレギュラー）に配列が保存されている場合 => 未入力として扱われダッシュを表示する（is_scalar() の型ガード）',
				'conditions'          => array(
					'row'    => $row['manual_non_scalar'],
					'needle' => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（イレギュラー）に配列が保存されている場合 => 配列を無理に文字列化した表示（"Array" など）をしない',
				'conditions'          => array(
					'row'    => $row['manual_non_scalar'],
					'needle' => 'Array',
				),
				'expected'            => false,
			),
			// --- 取引先（登録済）に存在しない投稿IDが入っている ---
			array(
				'test_condition_name' => '取引先（登録済）に存在しない投稿IDが入っている場合 => リンクを出さずダッシュを表示する',
				'conditions'          => array(
					'row'    => $row['invalid_nonexistent'],
					'needle' => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に存在しない投稿IDが入っている場合 => リンクにしない',
				'conditions'          => array(
					'row'    => $row['invalid_nonexistent'],
					'needle' => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（登録済）に別投稿タイプ（client 以外）のIDが入っている ---
			array(
				'test_condition_name' => '取引先（登録済）に別投稿タイプのIDが入っている場合 => リンクを出さずダッシュを表示する',
				'conditions'          => array(
					'row'    => $row['invalid_other_post_type'],
					'needle' => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に別投稿タイプのIDが入っている場合 => リンクにしない',
				'conditions'          => array(
					'row'    => $row['invalid_other_post_type'],
					'needle' => '<a',
				),
				'expected'            => false,
			),
			// --- 取引先（登録済）に数値以外の値が入っている ---
			array(
				'test_condition_name' => '取引先（登録済）に数値以外の値が入っている場合 => リンクを出さずダッシュを表示する',
				'conditions'          => array(
					'row'    => $row['invalid_non_numeric'],
					'needle' => '&#8212;',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先（登録済）に数値以外の値が入っている場合 => リンクにしない',
				'conditions'          => array(
					'row'    => $row['invalid_non_numeric'],
					'needle' => '<a',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 対象の行のセルを取得
			$cell = $client_cells[ $case['conditions']['row'] ];

			// テスト対象の文字列が対象セルに含まれるかを判定
			$actual = false !== strpos( $cell, $case['conditions']['needle'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] . ' / 実際のセル: ' . $cell );
		}
	}
}
