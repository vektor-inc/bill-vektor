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
 * 書類側の表示は bill_get_client_name() のユニットテストとe2eで担保する。
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
	 * テスト前の共通セットアップ
	 *
	 * 取引先一覧に表示するための取引先を作成する。
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
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * 作成した投稿を削除する。
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_delete_post( $this->client_id, true );

		parent::tear_down();
	}

	/**
	 * 取引先一覧の取引先カラムのテスト
	 *
	 * 取引先一覧では行自身の取引先名が表示され、リンク先が取引先ページになることを検証する。
	 *
	 * @return void
	 */
	public function test_index_template_client_column() {
		// index.php はグローバルの $post / $wp_query を参照するため、この scope でもグローバルを使う
		global $post, $wp_query, $wp_the_query;

		// レンダリング後に元へ戻せるようメインクエリを退避する
		$original_wp_query     = $wp_query;
		$original_wp_the_query = $wp_the_query;

		// 取引先アーカイブを模したメインクエリを組み立てる
		$query             = new WP_Query(
			array(
				'post_type'      => 'client',
				'post__in'       => array( $this->client_id ),
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

		// 取引先カラムのセルだけを取り出す
		$client_cell = '';
		if ( preg_match( '#<!-- \[ 取引先 \] -->\s*<td[^>]*>(.*?)</td>#s', $html, $matches ) ) {
			$client_cell = trim( $matches[1] );
		}

		// セルが取得できていないと以降の検証が意味を成さないため確認する
		$this->assertNotSame( '', $client_cell, '取引先カラムのセルがレンダリングされている' );

		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 取引先自身の名前が表示される',
				'conditions'          => array(
					'needle' => $this->client_title,
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 取引先自身のページへのリンクになっている',
				'conditions'          => array(
					'needle' => 'href="' . esc_url( get_permalink( $this->client_id ) ) . '"',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 別タブで開く指定が維持されている',
				'conditions'          => array(
					'needle' => 'target="_blank"',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 取引先なしのダッシュは表示されない',
				'conditions'          => array(
					'needle' => '&#8212;',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// テスト対象の文字列が取引先セルに含まれるかを判定
			$actual = false !== strpos( $client_cell, $case['conditions']['needle'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] . ' / 実際のセル: ' . $client_cell );
		}
	}
}
