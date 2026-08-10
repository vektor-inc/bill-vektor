<?php
/**
 * Class CustomFieldBuilderNonceTickTest
 *
 * VK_Custom_Field_Builder::save_cf_value() の nonce 検証テスト（issue #312）
 *
 * 修正前は wp_nonce_field() / wp_verify_nonce() の action 引数に
 * wp_create_nonce( __FILE__ ) の戻り値を渡していた。この戻り値は nonce の
 * 内部値（tick）が12時間ごとに切り替わるたびに変化するため、action 文字列自体が
 * 時間で変わってしまう。編集画面を開いた時点と保存した時点で tick をまたぐと
 * 検証に必ず失敗し、保存処理がそのまま return していた（エラー表示なし。
 * 利用者には「更新を押したのにカスタムフィールドだけ元に戻る」という形でしか見えない）。
 *
 * WordPress コアに tick そのものを差し替えるフィルターは存在しない
 * （`wp_nonce_tick()` は `nonce_life` フィルターの戻り値からticknを算出する）。
 * そのためこのテストでは `nonce_life` フィルターを操作し、
 * `wp_nonce_tick()` が狙った tick 値を返すように仕向けて、
 * 「画面を開いた時点」と「保存した時点」の tick をずらして保存の成否を検証する。
 *
 * @package BillVektor
 */
class CustomFieldBuilderNonceTickTest extends WP_UnitTestCase {

	/**
	 * nonce_life フィルターから返す固定値（秒）
	 *
	 * set_nonce_tick() で、狙った tick 値になるよう逆算して設定する。
	 *
	 * @var float
	 */
	private $fake_nonce_life = 0;

	/**
	 * テスト対象の投稿ID
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * テスト用カスタムフィールド定義（1項目のみ）
	 *
	 * @var array
	 */
	private $custom_fields_array = array();

	/**
	 * テスト前の共通セットアップ
	 *
	 * nonce の tick を固定するフィルターを登録し、検証対象の投稿とユーザーを用意する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		/*
		 * nonce_life を経由して tick をテストから自由に切り替えられるようにする。
		 * フィルターは常時登録するが、既定値（WordPress 標準の DAY_IN_SECONDS）を
		 * 初期値にしておく。投稿作成（self::factory()->post->create()）は
		 * save_post フックを発火させ、テーマの保存処理が内部で wp_verify_nonce() /
		 * wp_nonce_tick() を呼ぶため、tick を切り替えていない間は既定値と
		 * 同じ挙動にしておかないと 0除算などで落ちてしまう。
		 */
		$this->fake_nonce_life = DAY_IN_SECONDS;
		add_filter( 'nonce_life', array( $this, 'filter_nonce_life' ) );

		// nonce のハッシュに使われるユーザーを固定する（生成時と検証時で同一ユーザーである必要がある）
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// form_table() / save_cf_value() が参照する投稿を用意する
		$this->post_id   = self::factory()->post->create();
		$GLOBALS['post'] = get_post( $this->post_id );

		// 保存の成否を確認できればよいので、項目は1つだけにする
		$this->custom_fields_array = array(
			'nonce_tick_test_field' => array(
				'label'       => 'テスト項目',
				'type'        => 'text',
				'description' => '',
				'required'    => false,
			),
		);
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * 登録したフィルター・ユーザー・投稿・$_POST の値を元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		// 投稿削除でも save_post 系フックが動く可能性があるため、既定値に戻してから削除する
		$this->fake_nonce_life = DAY_IN_SECONDS;

		delete_post_meta( $this->post_id, 'nonce_tick_test_field' );
		wp_delete_post( $this->post_id, true );
		unset( $GLOBALS['post'] );

		remove_filter( 'nonce_life', array( $this, 'filter_nonce_life' ) );
		wp_set_current_user( 0 );
		unset( $_POST['nonce_tick_test_field'], $_POST['noncename__fields'] );

		parent::tear_down();
	}

	/**
	 * nonce_life フィルターのコールバック
	 *
	 * @return float テストから指定した固定 nonce_life 値（秒）。
	 */
	public function filter_nonce_life() {
		return $this->fake_nonce_life;
	}

	/**
	 * wp_nonce_tick() が指定した tick 値を返すように nonce_life を逆算して設定する
	 *
	 * wp_nonce_tick() は `ceil( time() / ( nonce_life / 2 ) )` で tick を算出する。
	 * `nonce_life = 2 * time() / $target_tick` にすると ceil() の結果がちょうど
	 * $target_tick になる境界の真上を狙うことになり、テスト実行中に time() が
	 * 1秒進んだだけで tick が意図せずずれてしまう（例: 5.0 → 5.0000000029 で ceil が 6 になる）。
	 * そのため tick の窓の「真ん中」（$target_tick - 0.5）を狙うことで、
	 * 実行中に time() が多少進んでも tick が変わらないようにする。
	 *
	 * 設定できたことを毎回 assert する。フィルターが効いていない場合に
	 * 気づかないまま「たまたま」テストが通ってしまう事故を防ぐため。
	 *
	 * @param int $target_tick 狙う tick 値。
	 * @return void
	 */
	private function set_nonce_tick( $target_tick ) {
		// tick の窓の「真ん中」を狙うことで、テスト実行中に time() が進んでも tick が変わらないようにする
		$this->fake_nonce_life = 2 * time() / ( $target_tick - 0.5 );

		$this->assertSame(
			$target_tick,
			(int) wp_nonce_tick(),
			'tick を ' . $target_tick . ' に設定できていること（nonce_life フィルターが効いていること）'
		);
	}

	/**
	 * 出力された nonce フィールドの value 属性を取得する
	 *
	 * @param string $html       wp_nonce_field() を含む出力済みHTML。
	 * @param string $field_name nonce フィールドの name 属性。
	 * @return string 抽出した nonce 値。見つからない場合は空文字。
	 */
	private function extract_nonce_value( $html, $field_name ) {
		if ( preg_match( '/name="' . preg_quote( $field_name, '/' ) . '" value="([^"]+)"/', $html, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * VK_Custom_Field_Builder::save_cf_value() のテスト
	 *
	 * 編集画面を開いた時点（form_table() 実行時）を基準の tick とし、
	 * 保存した時点（save_cf_value() 実行時）の tick をずらして、
	 * 保存が成功・失敗する境界を検証する。
	 *
	 * @return void
	 */
	public function test_save_cf_value() {

		// 基準となる tick 値（小さい整数にして、テスト実行中の時刻のずれの影響を受けにくくする）
		$base_tick = 5;

		$test_cases = array(
			array(
				'test_condition_name' => '画面を開いてすぐ保存した場合（tick変化なし） => 保存される',
				'tick_offset'         => 0,
				'expected_saved'      => true,
			),
			array(
				'test_condition_name' => '画面を開いてから12〜24時間後に保存した場合（tickが1つ進む） => 保存される（修正前は今回のバグにより保存されない）',
				'tick_offset'         => 1,
				'expected_saved'      => true,
			),
			array(
				'test_condition_name' => '画面を開いてから24時間以上経って保存した場合（tickが2つ進む） => nonce切れのため保存されない（修正の有無で差は出ない正常な期限切れ）',
				'tick_offset'         => 2,
				'expected_saved'      => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 前回保存値を既知の値にリセットしておく（保存されたかどうかを判定するため）
			update_post_meta( $this->post_id, 'nonce_tick_test_field', 'before_value' );

			// 編集画面を開いた時点の tick を固定して nonce フィールドを出力する
			$this->set_nonce_tick( $base_tick );
			ob_start();
			VK_Custom_Field_Builder::form_table( $this->custom_fields_array, '', false );
			$html  = ob_get_clean();
			$nonce = $this->extract_nonce_value( $html, 'noncename__fields' );
			$this->assertNotSame( '', $nonce, $case['test_condition_name'] . '（nonceが出力されていること）' );

			// 保存した時点の tick をずらす
			$this->set_nonce_tick( $base_tick + $case['tick_offset'] );

			// フォーム送信を再現する
			$_POST['noncename__fields']     = $nonce;
			$_POST['nonce_tick_test_field'] = 'after_value';

			// テスト関数実行
			VK_Custom_Field_Builder::save_cf_value( $this->custom_fields_array );

			// 期待値テスト
			$actual   = get_post_meta( $this->post_id, 'nonce_tick_test_field', true );
			$expected = $case['expected_saved'] ? 'after_value' : 'before_value';
			$this->assertSame( $expected, $actual, $case['test_condition_name'] );
		}
	}
}
