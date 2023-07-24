<?php

class Bill_Admin {

	public static $version = '0.0.0';

	// define( 'Bill_URL', get_template_directory_uri() );
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ), 10, 2 );
		add_action( 'admin_print_styles-toplevel_page_bill-setting-page', array( __CLASS__, 'admin_enqueue_scripts' ) );
	}

	public static function add_admin_menu() {
		$page_title = 'BillVektor設定';
		$menu_title = 'BillVektor設定';
		$capability = 'administrator';
		$menu_slug  = 'bill-setting-page';
		$function   = array( __CLASS__, 'setting_page' );
		// $icon_url	= '';
		// $position	= '';
		add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function );
	}

	public static function setting_page() {
		// delete_option('bill-setting');
		?>
		<div class="wrap">
		<h2>BillVektor設定</h2>
		<form id="bill-setting-form" method="post" action="">
		<?php wp_nonce_field( 'bill-nonce-key', 'bill-setting-page' ); ?>
		<?php
		$options = get_option( 'bill-setting', self::options_default() );
		$options = wp_parse_args( $options, self::options_default() );
		?>
		<table class="form-table">
		<tr>
		<th>請求者名</th>
		<td>
		<textarea name="bill-setting[own-name]" rows="2"><?php echo esc_textarea( $options['own-name'] ); ?></textarea></td>
		</tr>
		<tr>
		<th>インボイス制度の登録番号</th>
		<td>
		<input type="number" name="bill-setting[invoice-number]" value="<?php echo esc_attr( $options['invoice-number'] ); ?>"/></td>
		</tr>
		<tr>
		<th>住所</th>
		<td><textarea name="bill-setting[own-address]" rows="4"><?php echo esc_textarea( $options['own-address'] ); ?></textarea></td>
		</tr>
		<tr>
		<th>ロゴ画像</th>
		<td>
		<?php
		$attr = array(
			'id'    => 'thumb_own-logo',
			'class' => 'input_thumb',
		);
		// no image 画像
		$no_image = '<img src="' . get_template_directory_uri() . '/assets/images/no-image.png" id="thumb_own-logo" alt="" class="input_thumb" style="width:150px;height:auto;">';
		if ( isset( $options['own-logo'] ) && $options['own-logo'] ) {
			if ( wp_get_attachment_image( $options['own-logo'], 'medium', false, $attr ) ) {
				echo wp_get_attachment_image( $options['own-logo'], 'medium', false, $attr );
			} else {
				// 画像自体がメディアかさ削除されてしまった場合
				echo $no_image;
			}
		} else {
			echo $no_image;
		}
		?>
		<input type="hidden" name="bill-setting[own-logo]" id="own-logo" value="<?php echo esc_attr( $options['own-logo'] ); ?>" style="width:60%;" />
		<button id="media_own-logo" class="media_btn btn btn-default button button-default"><?php _e( '画像を選択', '' ); ?></button></td>
		</tr>
		<tr>
		<th>印鑑画像</th>
		<td>
		<?php
		$attr     = array(
			'id'    => 'thumb_own-seal',
			'class' => 'input_thumb',
		);
		$no_image = '<img src="' . get_template_directory_uri() . '/assets/images/no-image.png" id="thumb_own-seal" alt="" class="input_thumb" style="width:150px;height:auto;">';
		if ( isset( $options['own-seal'] ) && $options['own-seal'] ) {
			if ( wp_get_attachment_image( $options['own-seal'], 'medium', false, $attr ) ) {
				echo wp_get_attachment_image( $options['own-seal'], 'medium', false, $attr );
			} else {
				// 画像自体がメディアかさ削除されてしまった場合
				echo $no_image;
			}
		} else {
			echo $no_image;
		}
		?>
		<input type="hidden" name="bill-setting[own-seal]" id="own-seal" value="<?php echo esc_attr( $options['own-seal'] ); ?>" style="width:60%;" />
		<button id="media_own-seal" class="media_btn btn btn-default button button-default"><?php _e( '画像を選択', '' ); ?></button></td>
		</tr>
		<tr>
		<th>振込先</th>
		<td><textarea name="bill-setting[own-payee]" rows="3"><?php echo esc_textarea( $options['own-payee'] ); ?></textarea></td>
		</tr>
		<tr>
		<th>備考（請求）</th>
		<?php $remarks_bill = ( isset( $options['remarks-bill'] ) ) ? $options['remarks-bill'] : ''; ?>
		<td><textarea name="bill-setting[remarks-bill]" rows="4"><?php echo esc_textarea( $remarks_bill ); ?></textarea></td>
		</tr>
		<tr>
		<th>備考（見積）</th>
		<?php $remarks_estimate = ( isset( $options['remarks-estimate'] ) ) ? $options['remarks-estimate'] : ''; ?>
		<td><textarea name="bill-setting[remarks-estimate]" rows="4"><?php echo esc_textarea( $remarks_estimate ); ?></textarea></td>
		</tr>
		<tr>
		<th>送付状メッセージ</th>
		<?php $client_doc_message = ( isset( $options['client-doc-message'] ) ) ? $options['client-doc-message'] : ''; ?>
		<td><textarea name="bill-setting[client-doc-message]" rows="4"><?php echo esc_textarea( $client_doc_message ); ?></textarea></td>
		</tr>
		</table>
		<p><input type="submit" value="設定を保存" class="button button-primary button-large"></p>
		</form>
		</div>
		<?php
	}

	public static function options_default() {
		$default = array(
			'own-name'           => '株式会社ベクトル',
			'own-address'        => '〒460-0008
名古屋市中区栄1-22-16
ミナミ栄ビル 302号
TEL : 000-000-0000',
			'own-logo'           => '',
			'own-seal'           => '',
			'own-payee'          => '三菱東京UFJ銀行
尾張新川支店 普通 0040364
株式会社ベクトル',
			'remarks-bill'       => '恐れ入りますがお振込手数料は御社でご負担いただけますようお願い申し上げます。',
			'remarks-estimate'   => '本見積もりの有効期限は3ヶ月となります。',
			'client-doc-message' => '平素は格別のお引き立てにあずかり、厚く御礼申し上げます。
早速ではございますが下記書類をお送りします。御査収の上よろしく御取計らいの程お願い申し上げます。',
			'invoice-number'     => '',
		);
		return $default;
	}

	public static function admin_enqueue_scripts() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_media();
		wp_enqueue_script( 'vk_mediauploader', get_template_directory_uri() . '/inc/setting-page/js/mediauploader.js', array( 'jquery' ), self::$version );
		wp_enqueue_style( 'bill-setting-page-style', get_template_directory_uri() . '/inc/setting-page/setting-page.css', array(), self::$version, 'all' );
	}

	public static function admin_init() {

		if ( isset( $_POST['bill-setting-page'] ) && $_POST['bill-setting-page'] ) {

			if ( check_admin_referer( 'bill-nonce-key', 'bill-setting-page' ) ) {
				// 保存処理
				if ( isset( $_POST['bill-setting'] ) && $_POST['bill-setting'] ) {
					update_option( 'bill-setting', $_POST['bill-setting'] );
				} else {
					update_option( 'bill-setting', '' );
				}

				wp_safe_redirect( menu_page_url( 'bill-setting-page', false ) );
			}
		}
	}

}

Bill_Admin::init();
