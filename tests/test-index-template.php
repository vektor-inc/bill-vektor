<?php
/**
 * Class IndexTemplateTest
 *
 * index.php（書類・取引先の一覧テンプレート）の取引先カラム・書類カラムのテスト
 *
 * @package BillVektor
 */

/**
 * 取引先一覧の取引先カラム・書類カラムのテスト
 *
 * 取引先一覧（?post_type=client）は書類一覧と同じ取引先カラムを共有しており、
 * 行そのものが取引先であるため自身の名前を表示する必要がある。
 * 書類側の不具合修正でこの表示が消える回帰が起きたため、テンプレートを
 * 実際にレンダリングして表示内容を検証する。
 *
 * 書類カラムについては、取引先一覧のように単一の投稿タイプに絞り込まれた一覧では
 * `get_post_type_archive_link( 'url' )` の呼び間違いにより常に `href=""` の空リンクに
 * なっていた不具合（issue #316）を検証する。取引先一覧はこの不具合が
 * 「必ず空リンクになる」形で現れる（client は has_archive => false のため常に false が
 * 返る）ため、単一種別に絞り込まれた一覧の代表としてここで検証する。
 * 単一種別絞り込みの判定ロジック自体（bill_get_single_post_type_slug()）は
 * inc/template-tags.php の単体テスト（tests/test-template-tags.php）で
 * フロントページの混在表示・請求書一覧・見積書一覧・カテゴリーアーカイブなど
 * 他のパターンも網羅している。
 *
 * かつては template-parts/breadcrumb.php が bill_bread_crumb() を
 * function_exists() で保護せずに定義していたため、1つのPHPプロセスの中で
 * index.php を2回以上読み込むと Fatal error になり、index.php をレンダリングする
 * テストはスイート全体で1件しか書けなかった（issue #315）。ガードが入ったことで
 * この制約は解消しており、このテストクラス内でも1回のレンダリングに複数の
 * 検証したい条件を行として並べて確認するスタイルを踏襲している。
 *
 * 書類一覧側（?post_type=estimate 等）の index.php のレンダリングは
 * tests/test-index-template-document-list.php の IndexTemplateDocumentListTest で
 * 別途カバーしている（省略名の参照・リンクとダッシュの出し分け・取引先（イレギュラー）の
 * 型ガードなど、bill_get_client_name() のユニットテストでは通らない index.php 固有のロジック）。
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
	 * モック用のお知らせ（RSS）タイトル
	 *
	 * @var string
	 */
	private $rss_entry_title = 'PR310 テスト用お知らせ';

	/**
	 * モック用のお知らせ（RSS）リンク先。add_query_arg() での rel=rss 連結（issue #310）を
	 * 検証するため、あらかじめクエリー文字列を含めている。
	 *
	 * @var string
	 */
	private $rss_entry_link = 'https://billvektor.com/test-news/?utm_source=feed';

	/**
	 * テスト実行前の既定タイムゾーン（PHPの date_default_timezone_get() の戻り値）を保持する
	 *
	 * index.php のお知らせセクションが date_default_timezone_set( 'Asia/Tokyo' ) を実行するため、
	 * このテストのレンダリングでは必ず実行される。PHPUnit は全テストクラスを同一プロセスで
	 * 実行し、WordPress はPHPの既定タイムゾーンがUTCであることを前提にしているため、
	 * このテストが変更したタイムゾーンを他のテストへ持ち越さないよう退避・復元する。
	 * set_up() で値が入るまでは未初期化状態を表す null。
	 *
	 * @var string|null
	 */
	private $original_timezone;

	/**
	 * テスト前の共通セットアップ
	 *
	 * 取引先一覧に表示するための取引先（通常・無題）を作成する。
	 * あわせて、お知らせセクションが実際に外部へ HTTP リクエストを送らないよう
	 * pre_http_request フィルターを1本だけ登録する（mock_rss_feed_response() 参照）。
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
		 * pre_http_request を横取りする。billvektor.com/feed 宛だけ固定のフィードを返し、
		 * それ以外の外部リクエストは WP_Error を返して常に失敗させる（1つのフィルターに
		 * 両方の意図を持たせる。同じフックへ意図の異なるフィルターを2本登録すると、
		 * 登録順に依存する動作になり事故のもとになるため）。
		 * WP_UnitTestCase::tear_down() の _restore_hooks() で自動的に外れるため、
		 * このテストクラス側での明示的な remove_filter() は不要。
		 */
		add_filter( 'pre_http_request', array( $this, 'mock_rss_feed_response' ), 10, 3 );

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

		// index.php のお知らせセクションが変更するタイムゾーンを、テスト後に復元できるよう退避する
		$this->original_timezone = date_default_timezone_get();
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

		// index.php のお知らせセクションが date_default_timezone_set() で変更したタイムゾーンを復元する
		if ( null !== $this->original_timezone ) {
			date_default_timezone_set( $this->original_timezone );
		}

		parent::tear_down();
	}

	/**
	 * お知らせセクションの pre_http_request フィルターコールバック
	 *
	 * billvektor.com/feed 宛のリクエストのみ固定のRSS 2.0フィードを返して横取りする。
	 * それ以外のURLへの外部リクエストは、CI から本番サイト等へ実際に飛ばさないよう
	 * WP_Error を返して常に失敗させる（テスト実行のたびに外部通信が発生する問題への対処）。
	 *
	 * @param false|array|WP_Error $preempt     短絡させる場合の戻り値（このコールバックでは未使用）。
	 * @param array                $parsed_args リクエスト引数（このモックでは未使用）。
	 * @param string               $url         リクエスト先URL。
	 * @return array|WP_Error billvektor.com/feed 宛は固定のフィードレスポンス配列、
	 *                         それ以外は常に WP_Error（リクエストを失敗させる）。
	 */
	public function mock_rss_feed_response( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, 'billvektor.com/feed' ) ) {
			return new WP_Error( 'http_request_blocked', 'テストでは外部リクエストを行わない' );
		}

		$feed_xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel><title>Test Feed</title>'
			. '<item>'
			. '<title>' . htmlspecialchars( $this->rss_entry_title, ENT_XML1 ) . '</title>'
			. '<link>' . htmlspecialchars( $this->rss_entry_link, ENT_XML1 ) . '</link>'
			. '<category>お知らせ</category>'
			. '<pubDate>Mon, 01 Jan 2024 00:00:00 +0900</pubDate>'
			. '</item>'
			. '</channel></rss>';

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $feed_xml,
		);
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

		/*
		 * index.php はテンプレートパーツの都合で1プロセス中に1回しかレンダリングできないため
		 * （クラスDocコメント参照）、CSVエクスポートボックス（current_user_can('edit_posts')で
		 * 出し分けられる）もこの1回のレンダリングでカバーする。set_up() でユーザーを設定すると
		 * 他のテストケースにも影響するため、このメソッドの中だけで一時的に編集者権限の
		 * ユーザーを設定し、レンダリング直後に元へ戻す。
		 */
		$original_user_id = get_current_user_id();
		$admin_user_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

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

		// 現在のユーザーを元に戻す（このテストのためだけに設定した編集者権限を残さない）
		wp_set_current_user( $original_user_id );

		// 各行の取引先カラムのセルを取り出す
		preg_match_all( '#<!-- \[ 取引先 \] -->\s*<td[^>]*>(.*?)</td>#s', $html, $matches );
		$client_cells = array_map( 'trim', $matches[1] );

		/*
		 * セルが2つ取得できていないと以降の検証が意味を成さないため確認する。
		 * セル自体が空文字（＝取引先名が表示されない退行）の場合はここではなく
		 * 後続のテストケースで検出されるので、ここでは件数だけを確認する。
		 */
		$this->assertCount( 2, $client_cells, '取引先一覧の2行分の取引先カラムがレンダリングされている' );

		// 各行の書類カラムのセルを取り出す（issue #316 のバグ検証用）
		preg_match_all( '#<!-- \[ 書類 \] -->\s*<td[^>]*>(.*?)</td>#s', $html, $doc_matches );
		$doc_cells = array_map( 'trim', $doc_matches[1] );

		$this->assertCount( 2, $doc_cells, '取引先一覧の2行分の書類カラムがレンダリングされている' );

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

				/*
				 * issue #310: target="_blank" のリンクは window.opener 経由で元タブを
				 * 操作されるのを防ぐため rel="noopener" を必ず伴う。
				 */
				'test_condition_name' => '取引先一覧の取引先カラム => rel="noopener" が付与されている（issue #310）',
				'conditions'          => array(
					'row'    => 0,
					'needle' => 'rel="noopener"',
				),
				'expected'            => true,
			),
			array(

				/*
				 * issue #310: 別タブで開くことをアイコン（aria-hidden）と
				 * screen-reader-text の併用で予告する。
				 */
				'test_condition_name' => '取引先一覧の取引先カラム => 外部リンクアイコンが付与されている（issue #310）',
				'conditions'          => array(
					'row'    => 0,
					'needle' => '<span class="glyphicon glyphicon-new-window" aria-hidden="true"></span>',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '取引先一覧の取引先カラム => 別タブで開くことを予告するscreen-reader-textが付与されている（issue #310）',
				'conditions'          => array(
					'row'    => 0,
					'needle' => '<span class="screen-reader-text">（新しいタブで開きます）</span>',
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

				/*
				 * issue #310 対応後は screen-reader-text に「新しいタブで開きます」を合成しているため、
				 * span を増やさず旧文言「名称未設定の取引先」のみでは終わらないことも合わせて確認する。
				 */
				'test_condition_name' => '無題の取引先の取引先カラム => ダッシュと代替テキスト「名称未設定の取引先（新しいタブで開きます）」を表示する（issue #310）',
				'conditions'          => array(
					'row'    => 1,
					'needle' => '<span aria-hidden="true">&#8212;</span><span class="glyphicon glyphicon-new-window" aria-hidden="true"></span><span class="screen-reader-text">名称未設定の取引先（新しいタブで開きます）</span>',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '無題の取引先の取引先カラム => rel="noopener" が付与されている（issue #310）',
				'conditions'          => array(
					'row'    => 1,
					'needle' => 'rel="noopener"',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '無題の取引先の取引先カラム => 名前を直しに行けるようリンクは維持する',
				'conditions'          => array(
					'row'    => 1,
					'needle' => '<a href="' . esc_url( get_permalink( $this->untitled_client_id ) ) . '" target="_blank" rel="noopener">',
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
			array(
				/*
				 * issue #316: get_post_type_archive_link( 'url' ) の呼び間違いにより、
				 * 単一種別に絞り込まれた一覧（取引先一覧はその代表例）の書類カラムが
				 * 常に href="" の空リンクになっていた。
				 */
				'test_condition_name' => '取引先一覧の書類カラム => href="" の空リンクを出力しない（issue #316）',
				'conditions'          => array(
					'column' => 'doc',
					'row'    => 0,
					'needle' => 'href=""',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '取引先一覧の書類カラム => 単一種別に絞り込まれた一覧のためリンクにせずラベルをテキスト表示する',
				'conditions'          => array(
					'column' => 'doc',
					'row'    => 0,
					'needle' => '<a',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '取引先一覧の書類カラム => 投稿タイプのラベル「取引先・送付状」が表示される',
				'conditions'          => array(
					'column' => 'doc',
					'row'    => 0,
					'needle' => '取引先・送付状',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '無題の取引先の行の書類カラム => 行のタイトルに関わらずリンクにせずラベルをテキスト表示する',
				'conditions'          => array(
					'column' => 'doc',
					'row'    => 1,
					'needle' => '<a',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '無題の取引先の行の書類カラム => 投稿タイプのラベル「取引先・送付状」が表示される',
				'conditions'          => array(
					'column' => 'doc',
					'row'    => 1,
					'needle' => '取引先・送付状',
				),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// 対象カラムのセル配列を選択（column 省略時は取引先カラム）
			$column = isset( $case['conditions']['column'] ) ? $case['conditions']['column'] : 'client';
			$cells  = ( 'doc' === $column ) ? $doc_cells : $client_cells;

			// 対象の行のセルを取得
			$cell = $cells[ $case['conditions']['row'] ];

			// テスト対象の文字列が対象セルに含まれるかを判定
			$actual = false !== strpos( $cell, $case['conditions']['needle'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] . ' / 実際のセル: ' . $cell );
		}

		/*
		 * template-parts/export-box.php は current_user_can( 'edit_posts' ) のガードで
		 * 出し分けられるため、上で編集者権限のユーザーを設定していないとこの1回の
		 * レンダリングに出力されず、下の不変条件アサーションが export-box を素通りしてしまう。
		 * 「id="csv-export"」の div 自体は中身が空でも出力されるため、それだけでは
		 * 中の target="_blank" リンク（MFクラウド会計・freee の2本）が実際に出力された
		 * 証明にならない。将来どちらかのリンクが消えても div の存在だけでは気づけないため、
		 * リンク先URLそのものが含まれることを直接確認する。
		 */
		$this->assertStringContainsString( 'accounting.moneyforward.com', $html, 'MFクラウド会計へのリンク（template-parts/export-box.php）がレンダリングされている' );
		$this->assertStringContainsString( 'secure.freee.co.jp', $html, 'freeeへのリンク（template-parts/export-box.php）がレンダリングされている' );

		/*
		 * issue #310: target="_blank" のリンクには例外なく rel="noopener" が付与されている
		 * ことを、ページ全体（get_header()・get_footer()・template-parts/export-box.php を含む）の
		 * 不変条件として検証する。個別セルではなくレンダリング結果全体（$html）に対して行うことで、
		 * ページ内の target="_blank" リンクを1つ残らずカバーする。
		 */
		$this->assertSame(
			substr_count( $html, 'target="_blank"' ),
			substr_count( $html, 'target="_blank" rel="noopener"' ),
			'ページ内のtarget="_blank"リンクにはすべてrel="noopener"が付与されている（issue #310）'
		);

		// お知らせ（RSS）セクションのHTMLを取り出す
		preg_match( '#<ul class="post-list" id="newsEntries">(.*?)</ul>#s', $html, $news_matches );
		$news_html = isset( $news_matches[1] ) ? $news_matches[1] : '';

		// お知らせが1件も含まれていないと以降の検証が意味を成さないため確認する
		$this->assertNotSame( '', $news_html, 'お知らせセクション（モックフィード）がレンダリングされている' );

		// お知らせリンクのテストの配列
		$news_test_cases = array(
			array(
				'test_condition_name' => 'お知らせのタイトルが表示される',
				'needle'              => esc_html( $this->rss_entry_title ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'お知らせリンクにrel="noopener"が付与されている（issue #310）',
				'needle'              => 'rel="noopener"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'お知らせリンクに外部リンクアイコンが付与されている（issue #310）',
				'needle'              => '<span class="glyphicon glyphicon-new-window" aria-hidden="true"></span>',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'お知らせリンクに「外部サイトが新しいタブで開きます」の予告が付与されている（issue #310）',
				'needle'              => '<span class="screen-reader-text">（外部サイトが新しいタブで開きます）</span>',
				'expected'            => true,
			),
			array(

				/*
				 * issue #310 レビュー対応: add_query_arg() で rel=rss を連結するため、
				 * フィード側の link が既にクエリー文字列を持っていても
				 * "...?p=1?rel=rss" のような壊れたURLにならないことを確認する。
				 * esc_url() は & を &#038; に変換するため、その形で連結される。
				 */
				'test_condition_name' => 'お知らせリンクのURLが既存クエリーとrel=rssを正しく連結している（issue #310）',
				'needle'              => 'href="https://billvektor.com/test-news/?utm_source=feed&#038;rel=rss"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'お知らせリンクのURLに二重の "?" が含まれない（issue #310）',
				'needle'              => '??',
				'expected'            => false,
			),
		);

		foreach ( $news_test_cases as $case ) {
			$actual = false !== strpos( $news_html, $case['needle'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] . ' / 実際のお知らせHTML: ' . $news_html );
		}
	}
}
