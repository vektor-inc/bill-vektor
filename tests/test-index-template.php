<?php
/**
 * Class IndexTemplateTest
 *
 * index.php（書類・取引先の一覧テンプレート）の取引先カラムのテスト
 *
 * @package BillVektor
 */

/**
 * 取引先一覧の取引先カラムのテスト
 *
 * 取引先一覧（?post_type=client）は書類一覧と同じ取引先カラムを共有しており、
 * 行そのものが取引先であるため自身の名前を表示する必要がある。
 * 書類側の不具合修正でこの表示が消える回帰が起きたため、テンプレートを
 * 実際にレンダリングして表示内容を検証する。
 *
 * 注意: template-parts/breadcrumb.php は bill_bread_crumb() を
 * function_exists() で保護せずに定義しているため、1つのPHPプロセスの中で
 * index.php を2回以上読み込むと Fatal error になる。
 * そのため index.php をレンダリングするテストはこの1件のみとし、
 * 検証したい条件は1回のレンダリングに行として並べて確認する。
 *
 * 未カバー: 書類一覧側（?post_type=estimate 等）の index.php のレンダリングは、
 * 上記の制約により現状カバーされていない。省略名の参照・リンクとダッシュの出し分け・
 * 取引先（イレギュラー）の型ガードといった index.php 固有のロジックは、
 * bill_get_client_name() のユニットテストでは通らない（あれは共通関数の戻り値のみを見ている）。
 * 管理画面のカラムのe2e（pr-297-estimate-client-column.spec.js）も対象外。
 * bill_bread_crumb() に function_exists() ガードが入り次第、書類一覧側の
 * レンダリングテストを追加すること。
 */
class IndexTemplateTest extends WP_UnitTestCase {

	/**
	 * 登録済取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $client_id;

	/**
	 * 登録済取引先の名前
	 *
	 * @var string
	 */
	private $client_title = '株式会社テスト取引先';

	/**
	 * 投稿タイトルが空の登録済取引先（無題で保存された取引先）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $untitled_client_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * 取引先一覧に表示するための取引先（通常・無題）を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// テスト用の登録済取引先（client 投稿）を作成
		$this->client_id = wp_insert_post(
			array(
				'post_title'  => $this->client_title,
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		// 無題で保存された登録済取引先を作成（client は title のみサポートのため空でも保存できる）
		$this->untitled_client_id = wp_insert_post(
			array(
				'post_title'  => '',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		// 無題の取引先が作成できていないと空アンカーの検証ができないため確認する
		$this->assertGreaterThan( 0, $this->untitled_client_id, '無題の登録済取引先が作成できている' );
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

		wp_delete_post( $this->client_id, true );
		wp_delete_post( $this->untitled_client_id, true );

		parent::tear_down();
	}

	/**
	 * 取引先一覧の取引先カラムのテスト
	 *
	 * 取引先一覧では行自身の取引先名が表示され、リンク先が取引先ページになること、
	 * 無題の取引先では空のリンクではなくダッシュが表示されることを検証する。
	 *
	 * @return void
	 */
	public function test_index_template_client_column() {
		// index.php はグローバルの $post / $wp_query を参照するため、この scope でもグローバルを使う
		global $post, $wp_query, $wp_the_query;

		// レンダリング後に元へ戻せるようメインクエリを退避する
		$original_wp_query     = $wp_query;
		$original_wp_the_query = $wp_the_query;

		// 取引先アーカイブを模したメインクエリを組み立てる（1行目=通常、2行目=無題）
		$query             = new WP_Query(
			array(
				'post_type'      => 'client',
				'post__in'       => array( $this->client_id, $this->untitled_client_id ),
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

		/*
		 * セルが2つ取得できていないと以降の検証が意味を成さないため確認する。
		 * セル自体が空文字（＝取引先名が表示されない退行）の場合はここではなく
		 * 後続のテストケースで検出されるので、ここでは件数だけを確認する。
		 */
		$this->assertCount( 2, $client_cells, '取引先一覧の2行分の取引先カラムがレンダリングされている' );

		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 取引先自身の名前が表示される',
				'conditions'          => array(
					'row'    => 0,
					'needle' => $this->client_title,
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 取引先自身のページへのリンクになっている',
				'conditions'          => array(
					'row'    => 0,
					'needle' => 'href="' . esc_url( get_permalink( $this->client_id ) ) . '"',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 別タブで開く指定が維持されている',
				'conditions'          => array(
					'row'    => 0,
					'needle' => 'target="_blank"',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 名前のある取引先ではダッシュを表示しない',
				'conditions'          => array(
					'row'    => 0,
					'needle' => '&#8212;',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '無題の取引先の取引先カラム => ダッシュと代替テキスト「名称未設定の取引先」を表示する',
				'conditions'          => array(
					'row'    => 1,
					'needle' => '<span aria-hidden="true">&#8212;</span><span class="sr-only">名称未設定の取引先</span>',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '無題の取引先の取引先カラム => 名前を直しに行けるようリンクは維持する',
				'conditions'          => array(
					'row'    => 1,
					'needle' => '<a href="' . esc_url( get_permalink( $this->untitled_client_id ) ) . '" target="_blank">',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '無題の取引先の取引先カラム => アクセシブルネームの無い空のリンクにしない',
				'conditions'          => array(
					'row'    => 1,
					'needle' => '"_blank"></a>',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 対象の行のセルを取得
			$cell = $client_cells[ $case['conditions']['row'] ];

			// テスト対象の文字列が取引先セルに含まれるかを判定
			$actual = false !== strpos( $cell, $case['conditions']['needle'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] . ' / 実際のセル: ' . $cell );
		}
	}
}
