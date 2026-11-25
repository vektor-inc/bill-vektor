<?php
/**
 * Class BreadcrumbTest
 *
 * パンくず（template-parts/breadcrumb.php）のテスト
 *
 * @package BillVektor
 */

/**
 * パンくずの HTML の組み立て（bill_bread_crumb()）と、最後の項目に aria-current を付ける処理
 * （bill_breadcrumb_add_aria_current()）のテスト
 *
 * パンくずは表示中のページの種類ごとに組み立てる分岐が違い、最後の項目（今表示しているページ）も
 * 分岐ごとに変わる。そのため、ページの種類ごとに実際の URL へ移動してテンプレートを
 * レンダリングし、nav が1つだけ出ること・最後の項目にだけ aria-current="page" が付くこと・
 * microdata が残ることを確かめる。
 */
class BreadcrumbTest extends WP_UnitTestCase {

	/**
	 * パンくずのテンプレートを、bill_panListHtml フィルターを通した出力まで含めて
	 * レンダリングし、HTML を返す
	 *
	 * @return string パンくずの HTML
	 */
	private function render_breadcrumb() {
		// include 前後で ob_get_level() の増減を見るための基準値
		$ob_level_before = ob_get_level();
		$html            = '';

		try {
			ob_start();
			get_template_part( 'template-parts/breadcrumb' );
			$html = ob_get_clean();
		} finally {
			// レンダリング中の例外でバッファが開いたままにならないよう、開始前のレベルまで閉じる
			while ( ob_get_level() > $ob_level_before ) {
				ob_end_clean();
			}
		}

		return $html;
	}

	/**
	 * パンくずの HTML を解析し、検証に使う値を配列で返す
	 *
	 * @param string $html パンくずの HTML
	 * @return array nav の数・nav のラベル・nav の親要素の class・aria-current の数・
	 *               最後の li に aria-current="page" が付いているか・最後の li の文字列・
	 *               microdata の項目数を持つ配列
	 */
	private function analyze_breadcrumb_html( $html ) {
		// HTML5 の nav を未知のタグとして警告しないよう、libxml のエラーを内部に溜めて捨てる
		$use_errors = libxml_use_internal_errors( true );
		$dom        = new DOMDocument();
		// 日本語が文字化けしないよう、文字コードを UTF-8 と明示して読み込む
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		// パンくずの nav・項目・aria-current の付いた要素を取り出す
		$xpath     = new DOMXPath( $dom );
		$navs      = $xpath->query( '//nav' );
		$items     = $xpath->query( '//ol[contains(concat(" ", normalize-space(@class), " "), " breadcrumb ")]/li' );
		$currents  = $xpath->query( '//*[@aria-current]' );
		$last_item = $items->length ? $items->item( $items->length - 1 ) : null;

		return array(
			'nav_count'            => $navs->length,
			'nav_label'            => $navs->length ? $navs->item( 0 )->getAttribute( 'aria-label' ) : '',
			'nav_parent_class'     => $navs->length ? $navs->item( 0 )->parentNode->getAttribute( 'class' ) : '',
			'aria_current_count'   => $currents->length,
			'last_item_is_current' => $last_item ? 'page' === $last_item->getAttribute( 'aria-current' ) : false,
			'last_item_text'       => $last_item ? trim( $last_item->textContent ) : '',
			'microdata_item_count' => $xpath->query( '//li[@itemscope][@itemprop="itemListElement"]' )->length,
		);
	}

	/**
	 * パンくずを、ページの種類（分岐）ごとにレンダリングして検証する
	 *
	 * 次を確かめる。
	 * - nav（aria-label 付き）が .breadcrumb-section の内側に1つだけ出る
	 * - aria-current="page" がページ全体で1つだけ、最後の li に付く
	 * - 既存の microdata（itemscope など）が残る
	 * - bill_panListHtml フィルターで一部を書き換えた場合も nav と aria-current が残り、
	 *   HTML 全体を作り直した場合は作り直した HTML がそのまま出る（テーマ側で書き足さない）
	 *
	 * @return void
	 */
	public function test_bill_bread_crumb() {
		// パンくずはログインしないと表示されない画面に出るため、管理者でログインしておく
		$original_user_id = get_current_user_id();
		$admin_user_id    = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'テスト管理者',
			)
		);

		try {
			wp_set_current_user( $admin_user_id );

			// 請求書（投稿）と、その親子カテゴリー・タグ
			$parent_category_id = self::factory()->category->create( array( 'name' => '親カテゴリー' ) );
			$child_category_id  = self::factory()->category->create(
				array(
					'name'   => '子カテゴリー',
					'parent' => $parent_category_id,
				)
			);
			$tag_id             = self::factory()->tag->create( array( 'name' => 'テストタグ' ) );
			$post_id            = self::factory()->post->create(
				array(
					'post_title'    => 'テスト請求書',
					'post_author'   => $admin_user_id,
					'post_date'     => '2025-04-15 10:00:00',
					'post_category' => array( $child_category_id ),
				)
			);
			wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );

			// 見積書（カスタム投稿タイプ）と、その親子の見積書カテゴリー
			$parent_estimate_term_id = self::factory()->term->create(
				array(
					'taxonomy' => 'estimate-cat',
					'name'     => '親見積書カテゴリー',
				)
			);
			$child_estimate_term_id  = self::factory()->term->create(
				array(
					'taxonomy' => 'estimate-cat',
					'name'     => '子見積書カテゴリー',
					'parent'   => $parent_estimate_term_id,
				)
			);
			$estimate_id             = self::factory()->post->create(
				array(
					'post_type'  => 'estimate',
					'post_title' => 'テスト見積書',
				)
			);
			wp_set_object_terms( $estimate_id, array( $child_estimate_term_id ), 'estimate-cat' );

			// 取引先（タクソノミーを持たないカスタム投稿タイプ）
			$client_id = self::factory()->post->create(
				array(
					'post_type'  => 'client',
					'post_title' => 'テスト取引先',
				)
			);

			// 固定ページ（階層あり・階層なし）
			$parent_page_id = self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => '親ページ',
				)
			);
			$child_page_id  = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_title'  => '子ページ',
					'post_parent' => $parent_page_id,
				)
			);
			$single_page_id = self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => '単独ページ',
				)
			);

			// bill_panListHtml フィルターで使う書き換え処理
			$filters = array(
				// 一部だけ書き換える（HOME の表記を変える）
				'replace_home' => function ( $html ) {
					return str_replace( ' HOME</span>', ' トップ</span>', $html );
				},
				// HTML 全体を作り直す
				'rebuild'      => function ( $html ) {
					return '<div class="my-breadcrumb"><ul><li>独自のパンくず</li></ul></div>';
				},
			);

			// テストの配列
			$test_cases = array(
				array(
					'test_condition_name' => '請求書（投稿）の個別ページ => 最後の項目（題名）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_permalink( $post_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'テスト請求書',
						'microdata_item_count' => 4,
					),
				),
				array(
					'test_condition_name' => '見積書（カスタム投稿タイプ）の個別ページ => 最後の項目（題名）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_permalink( $estimate_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'テスト見積書',
						'microdata_item_count' => 4,
					),
				),
				array(
					'test_condition_name' => '取引先（タクソノミーなし）の個別ページ => 最後の項目（題名）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_permalink( $client_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'テスト取引先',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '階層ありの固定ページ（子ページ） => 最後の項目（子ページ）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_permalink( $child_page_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => '子ページ',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '階層なしの固定ページ => 最後の項目（題名）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_permalink( $single_page_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => '単独ページ',
						'microdata_item_count' => 1,
					),
				),
				array(
					'test_condition_name' => '親のあるカテゴリーのアーカイブ => 最後の項目（子カテゴリー）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_category_link( $child_category_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => '子カテゴリー',
						'microdata_item_count' => 3,
					),
				),
				array(
					'test_condition_name' => 'タグのアーカイブ => 最後の項目（タグ名）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_tag_link( $tag_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'テストタグ',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '親のある見積書カテゴリー（カスタムタクソノミー）のアーカイブ => 最後の項目（子の見積書カテゴリー）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_term_link( $child_estimate_term_id, 'estimate-cat' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => '子見積書カテゴリー',
						'microdata_item_count' => 3,
					),
				),
				array(
					'test_condition_name' => '投稿者のアーカイブ => 最後の項目（表示名）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_author_posts_url( $admin_user_id ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'テスト管理者',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '年別アーカイブ => 最後の項目（年）に aria-current が付く',
					'conditions'          => array(
						'url'    => home_url( '/?year=2025' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'Yearly Archives: 2025',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '月別アーカイブ => 最後の項目（年月）に aria-current が付く',
					'conditions'          => array(
						'url'    => home_url( '/?year=2025&monthnum=4' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'Monthly Archives: April 2025',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '日別アーカイブ => 最後の項目（年月日）に aria-current が付く',
					'conditions'          => array(
						'url'    => home_url( '/?year=2025&monthnum=4&day=15' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'Daily Archives: April 15th, 2025',
						'microdata_item_count' => 2,
					),
				),
				array(
					'test_condition_name' => '見積書の一覧（投稿タイプのアーカイブ） => 最後の項目（見積書）に aria-current が付く',
					'conditions'          => array(
						'url'    => get_post_type_archive_link( 'estimate' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => '見積書',
						'microdata_item_count' => 1,
					),
				),
				array(
					'test_condition_name' => 'トップページ（書類一覧） => 最後の項目（請求書）に aria-current が付く',
					'conditions'          => array(
						'url'    => home_url( '/' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => '請求書',
						'microdata_item_count' => 1,
					),
				),
				array(
					'test_condition_name' => '検索結果（検索語にタグの文字列を含む） => 検索語はエスケープされ、最後の項目（検索結果）に aria-current が付く',
					'conditions'          => array(
						'url'    => home_url( '/?s=' . rawurlencode( '<li>テスト' ) ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'Search Results for : <li>テスト',
						'microdata_item_count' => 1,
					),
				),
				array(
					'test_condition_name' => '存在しないページ（404） => 最後の項目（Not found）に aria-current が付く',
					'conditions'          => array(
						'url'    => home_url( '/?p=999999' ),
						'filter' => '',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'Not found',
						'microdata_item_count' => 1,
					),
				),
				array(
					'test_condition_name' => 'bill_panListHtml で HOME の表記だけ書き換えた場合 => nav と aria-current は残る',
					'conditions'          => array(
						'url'    => get_permalink( $post_id ),
						'filter' => 'replace_home',
					),
					'expected'            => array(
						'nav_count'            => 1,
						'nav_label'            => 'パンくずリスト',
						'nav_parent_class'     => 'container breadcrumb-section',
						'aria_current_count'   => 1,
						'last_item_is_current' => true,
						'last_item_text'       => 'テスト請求書',
						'microdata_item_count' => 4,
					),
				),
				array(
					'test_condition_name' => 'bill_panListHtml で HTML 全体を作り直した場合 => 作り直した HTML がそのまま出て、nav も aria-current も足されない',
					'conditions'          => array(
						'url'    => get_permalink( $post_id ),
						'filter' => 'rebuild',
					),
					'expected'            => array(
						'nav_count'            => 0,
						'nav_label'            => '',
						'nav_parent_class'     => '',
						'aria_current_count'   => 0,
						'last_item_is_current' => false,
						'last_item_text'       => '',
						'microdata_item_count' => 0,
					),
				),
			);

			foreach ( $test_cases as $case ) {
				// フィルターを設定
				if ( $case['conditions']['filter'] ) {
					add_filter( 'bill_panListHtml', $filters[ $case['conditions']['filter'] ] );
				}

				// テストURLに移動してパンくずをレンダリング
				$this->go_to( $case['conditions']['url'] );
				$actual = $this->analyze_breadcrumb_html( $this->render_breadcrumb() );

				// フィルターを削除
				if ( $case['conditions']['filter'] ) {
					remove_filter( 'bill_panListHtml', $filters[ $case['conditions']['filter'] ] );
				}

				// 期待値テスト
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			}
		} finally {
			// 移動したままの状態とログイン状態を後続のテストへ持ち越さないよう戻す。
			// 未ログインで go_to() するとログイン画面への転送処理が走るため、ログイン中に移動してから戻す
			$this->go_to( home_url( '/' ) );
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * パンくずの HTML の最後の li に aria-current="page" を付ける処理を検証する
	 *
	 * @return void
	 */
	public function test_bill_breadcrumb_add_aria_current() {
		// テストの配列
		$test_cases = array(
			array(
				'test_condition_name' => 'li が複数ある場合 => 最後の li だけに付く',
				'conditions'          => array(
					'html' => '<ol><li id="panHome"><a href="/">HOME</a></li><li><span>題名</span></li></ol>',
				),
				'expected'            => '<ol><li id="panHome"><a href="/">HOME</a></li><li aria-current="page"><span>題名</span></li></ol>',
			),
			array(
				'test_condition_name' => 'li が1つだけの場合 => その li に付く',
				'conditions'          => array(
					'html' => '<ol><li id="panHome"><a href="/">HOME</a></li></ol>',
				),
				'expected'            => '<ol><li aria-current="page" id="panHome"><a href="/">HOME</a></li></ol>',
			),
			array(
				'test_condition_name' => '最後の li にすでに aria-current が付いている場合 => 値が page に置き換わり、重ねて付かない',
				'conditions'          => array(
					'html' => '<ol><li>HOME</li><li aria-current="true">題名</li></ol>',
				),
				'expected'            => '<ol><li>HOME</li><li aria-current="page">題名</li></ol>',
			),
			array(
				'test_condition_name' => 'li の後ろに li で始まる名前の別のタグ（link）がある場合 => link ではなく最後の li に付く',
				'conditions'          => array(
					'html' => '<ol><li>HOME</li><li>題名</li></ol><link rel="next" href="/">',
				),
				'expected'            => '<ol><li>HOME</li><li aria-current="page">題名</li></ol><link rel="next" href="/">',
			),
			array(
				'test_condition_name' => 'li が無い場合 => 受け取った HTML をそのまま返す',
				'conditions'          => array(
					'html' => '<div class="my-breadcrumb">独自のパンくず</div>',
				),
				'expected'            => '<div class="my-breadcrumb">独自のパンくず</div>',
			),
			array(
				'test_condition_name' => '空文字の場合 => 空文字を返す',
				'conditions'          => array(
					'html' => '',
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// テスト関数実行
			$actual = bill_breadcrumb_add_aria_current( $case['conditions']['html'] );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
