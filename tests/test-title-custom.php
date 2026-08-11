<?php
/**
 * Class TitleCustomTest
 *
 * PDF・ブラウザのタイトル（<title> タグ・PDF のファイル名）を組み立てる
 * functions.php の bill_title_custom() のテスト
 *
 * @package BillVektor
 */

/**
 * bill_title_custom() のテスト
 *
 * 取引先（登録済・敬称あり）の書類で「書類種別_取引先名敬称_件名_発行日」
 * （取引先名と敬称の間にアンダースコアは挟まない）の形式になること、
 * 取引先（イレギュラー）の書類で敬称が付かない（書類本体の表示と揃う）こと、
 * 返り値が <title> タグへそのまま出力される想定（_wp_render_title_tag() は
 * エスケープしない）のため、"&" などの特殊文字がエスケープ（&amp;）されて
 * 返ること、件名が wptexturize() を通った後の文字参照（&#8217; 等）に
 * esc_html() を掛けても二重エンコードされないことを検証する。
 */
class TitleCustomTest extends WP_UnitTestCase {

	/**
	 * 敬称「様」が登録されている取引先（client 投稿）の投稿IDを保持する
	 *
	 * @var int
	 */
	private $client_id;

	/**
	 * 取引先に登録した敬称
	 *
	 * @var string
	 */
	private $client_honorific = '様';

	/**
	 * 敬称が登録されていない取引先（client 投稿）の投稿IDを保持する
	 *
	 * 取引先は登録済でも敬称欄を空のまま運用しているケースを再現するために使う。
	 *
	 * @var int
	 */
	private $no_honorific_client_id;

	/**
	 * テスト前の共通セットアップ
	 *
	 * 敬称「様」が登録されている取引先と、敬称が未登録の取引先を作成する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->client_id = wp_insert_post(
			array(
				'post_title'  => '株式会社テスト取引先',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);
		update_post_meta( $this->client_id, 'client_honorific', $this->client_honorific );

		// 敬称が登録されていない取引先を作成（登録はしたが敬称欄は空のまま、という通常の運用を再現する）
		$this->no_honorific_client_id = wp_insert_post(
			array(
				'post_title'  => '有限会社敬称なし',
				'post_status' => 'publish',
				'post_type'   => 'client',
			)
		);

		/*
		 * go_to() は wp アクションを実行するため、フロント側の閲覧制限
		 * （inc/functions-limit-view.php の bill_no_login_redirect()）が付いたままだと、
		 * テスト実行時は未ログイン扱いになりログインページへリダイレクトしようとして
		 * 失敗する（テスト実行環境ではヘッダー送信前に出力が始まっているため）。
		 * この関数のテスト対象は bill_title_custom() の組み立てロジックであり
		 * 閲覧制限は対象外のため、tests/test-front-view-auth.php と同じ方法で
		 * 一時的にガードを外す。
		 */
		remove_action( 'wp', 'bill_no_login_redirect' );
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_delete_post( $this->client_id, true );
		wp_delete_post( $this->no_honorific_client_id, true );

		/*
		 * go_to() で移動したままだと、後続のテストが is_single() などこの
		 * テストで作ったクエリの状態を引き継いでしまう（実行順に依存する
		 * 脆いテストになる）。tests/test-front-view-auth.php と同じく
		 * トップページへ戻してからテストを終える。
		 *
		 * 必ずガードを戻す前に呼ぶこと。このテストではログイン状態にして
		 * いないため、先にガードを戻すと go_to() 自体がログインページへの
		 * リダイレクトを引き起こし、ヘッダー送信済みエラーで tear_down が
		 * 失敗する。
		 */
		$this->go_to( home_url( '/' ) );

		// 外したガードを戻す（外れたままだと後続のテストの前提が変わる）
		if ( ! has_action( 'wp', 'bill_no_login_redirect' ) ) {
			add_action( 'wp', 'bill_no_login_redirect' );
		}

		parent::tear_down();
	}

	/**
	 * bill_title_custom() のテスト
	 *
	 * @return void
	 */
	public function test_bill_title_custom() {

		// テストの配列（bill_client の 'client_id' は set_up で作成した取引先IDに差し替える）
		$test_cases = array(
			array(
				/*
				 * 取引先名と敬称の間にはアンダースコアを挟まない
				 * （「株式会社テスト取引先様」のように、日本語の敬称として
				 * 自然な連結になる。これは修正前から変わらない挙動）。
				 * アンダースコアは書類種別・取引先名+敬称・件名・発行日という
				 * フィールド単位の区切りとして使われている。
				 */
				'test_condition_name' => '取引先（登録済・敬称あり）の書類の場合 => 書類種別_取引先名敬称_件名_発行日',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'client_id',
				),
				'expected'            => '請求書_株式会社テスト取引先様_サイト制作費_20240101',
			),
			array(
				/*
				 * 取引先は登録済だが敬称欄を空のまま運用しているケース
				 * （異常値ではなく、通常あり得る運用）。
				 * bill_get_client_honorific() の「敬称が未登録なら既定の
				 * 「御中」を返す」経路が、タイトル生成側から見ても
				 * 正しく効いていることを固定する。
				 */
				'test_condition_name' => '取引先（登録済）だが敬称が未登録の書類の場合 => 書類種別_取引先名+既定の敬称「御中」_件名_発行日',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'no_honorific_client_id',
				),
				'expected'            => '請求書_有限会社敬称なし御中_サイト制作費_20240101',
			),
			array(
				/*
				 * 取引先（イレギュラー）の場合、bill_get_client_honorific() が
				 * 空文字を返すため敬称が付かない（書類本体の表示と揃う）ことを
				 * 確認する。取引先名に "&" を含めることで、bill_client_name_manual
				 * （無加工の $_POST が保存される値）に特殊文字が入っていても、
				 * 返り値の時点でエスケープ（&amp;）されていることも合わせて確認する。
				 */
				'test_condition_name' => '取引先（イレギュラー、"&" を含む）の書類の場合 => 敬称なし・エスケープされる',
				'conditions'          => array(
					'bill_client_name_manual' => '山田&商店',
					'bill_client'              => '',
				),
				'expected'            => '請求書_山田&amp;商店_サイト制作費_20240101',
			),
			array(
				/*
				 * 件名は get_the_title() で取得しており、これは the_title フィルター
				 * 経由で wptexturize() を通る。wptexturize() は素の引用符・ハイフン・
				 * 三点リーダーを「’」「“”」「—」「–」「…」的な文字参照
				 * （&#8217; 等）へ変換するため、その結果に esc_html() を掛けても
				 * 二重エンコード（&amp;#8217; のようになる）にならないことを固定する。
				 * esc_html() は内部で _wp_specialchars() に $double_encode = false を
				 * 渡しており、既存の文字参照は素通りするため二重エンコードは起きない
				 * （安藤さんが稼働中の wp-env で実測して確認済み）。
				 */
				'test_condition_name' => '件名に wptexturize() で文字参照化される記号を含む場合 => 二重エンコードされない',
				'conditions'          => array(
					'bill_client_name_manual' => '',
					'bill_client'              => 'client_id',
					'post_title'               => 'Bob\'s "Cafe" -- 10--20 ...',
				),
				'expected'            => '請求書_株式会社テスト取引先様_Bob&#8217;s &#8220;Cafe&#8221; &#8212; 10&#8211;20 &#8230;_20240101',
			),
		);

		foreach ( $test_cases as $case ) {
			// 件名（post_title）。指定が無いケースは既定の件名を使う
			$post_title = isset( $case['conditions']['post_title'] ) ? $case['conditions']['post_title'] : 'サイト制作費';

			// 発行日（post_date）を固定した書類を作成
			$bill_id = wp_insert_post(
				array(
					'post_title'  => $post_title,
					'post_status' => 'publish',
					'post_type'   => 'post',
					'post_date'   => '2024-01-01 10:00:00',
				)
			);

			// bill_client の 'client_id' 等の文字列は set_up で作成した取引先IDに差し替える
			$bill_client = $case['conditions']['bill_client'];
			if ( 'client_id' === $bill_client ) {
				$bill_client = $this->client_id;
			}
			if ( 'no_honorific_client_id' === $bill_client ) {
				$bill_client = $this->no_honorific_client_id;
			}

			update_post_meta( $bill_id, 'bill_client_name_manual', $case['conditions']['bill_client_name_manual'] );
			update_post_meta( $bill_id, 'bill_client', $bill_client );

			// 書類の個別ページへ移動する（is_single() を true にし、グローバルの $post をセットする）
			$this->go_to( home_url( '/?p=' . $bill_id ) );

			// テスト関数実行（pre_get_document_title フィルターと同じ形で呼び出す）
			$actual = bill_title_custom( '' );

			// 期待値テスト
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			wp_delete_post( $bill_id, true );
		}

		// 対象外の投稿タイプ（対象は post・estimate・receipt のみ）の場合は先行フィルターの値をそのまま返す
		$page_id = wp_insert_post(
			array(
				'post_title'  => '対象外の投稿タイプ',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$this->go_to( home_url( '/?page_id=' . $page_id ) );
		$this->assertSame( '先行フィルターの値', bill_title_custom( '先行フィルターの値' ), '対象外の投稿タイプ（page）の場合 => 先行フィルターの値をそのまま返す' );
		wp_delete_post( $page_id, true );
	}
}
