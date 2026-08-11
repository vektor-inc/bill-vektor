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
 * 取引先（イレギュラー）の書類で敬称が付かず区切りのアンダースコアが
 * 二重にならないこと、返り値が <title> タグへそのまま出力される想定
 * （_wp_render_title_tag() はエスケープしない）のため、"&" などの
 * 特殊文字がエスケープ（&amp;）されて返ることを検証する。
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
	 * テスト前の共通セットアップ
	 *
	 * 敬称「様」が登録されている取引先を作成する。
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
				 * 取引先（イレギュラー）の場合、bill_get_client_honorific() が
				 * 空文字を返すため、区切りのアンダースコアが二重（__）にならず
				 * 1つだけになることを確認する。取引先名に "&" を含めることで、
				 * bill_client_name_manual（無加工の $_POST が保存される値）に
				 * 特殊文字が入っていても、返り値の時点でエスケープ（&amp;）
				 * されていることも合わせて確認する。
				 */
				'test_condition_name' => '取引先（イレギュラー、"&" を含む）の書類の場合 => 敬称なし・区切りが二重にならない・エスケープされる',
				'conditions'          => array(
					'bill_client_name_manual' => '山田&商店',
					'bill_client'              => '',
				),
				'expected'            => '請求書_山田&amp;商店_サイト制作費_20240101',
			),
		);

		foreach ( $test_cases as $case ) {
			// 発行日（post_date）を固定した書類を作成
			$bill_id = wp_insert_post(
				array(
					'post_title'  => 'サイト制作費',
					'post_status' => 'publish',
					'post_type'   => 'post',
					'post_date'   => '2024-01-01 10:00:00',
				)
			);

			// bill_client の 'client_id' は set_up で作成した取引先IDに差し替える
			$bill_client = $case['conditions']['bill_client'];
			if ( 'client_id' === $bill_client ) {
				$bill_client = $this->client_id;
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
