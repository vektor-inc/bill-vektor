<?php
/*
* 請求書のカスタムフィールド（品目以外）
*/

class Estimate_Normal_Custom_Fields {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_metabox' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_custom_fields' ), 10, 2 );
	}

	// add meta_box
	public static function add_metabox() {

		$id            = 'meta_box_bill_normal';
		$title         = __( '見積書項目', '' );
		$callback      = array( __CLASS__, 'fields_form' );
		$screen        = 'estimate';
		$context       = 'advanced';
		$priority      = 'high';
		$callback_args = '';

		add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );

	}

	public static function fields_form() {
		global $post;

		$custom_fields_array = Estimate_Normal_Custom_Fields::custom_fields_array();
		$befor_custom_fields = '';
		VK_Custom_Field_Builder::form_table( $custom_fields_array, $befor_custom_fields );
	}

	public static function save_custom_fields() {
		$custom_fields_array = Estimate_Normal_Custom_Fields::custom_fields_array();
		// $custom_fields_array_no_cf_builder = arra();
		// $custom_fields_all_array = array_merge(  $custom_fields_array, $custom_fields_array_no_cf_builder );
		VK_Custom_Field_Builder::save_cf_value( $custom_fields_array );
	}

	public static function custom_fields_array() {

		$args = array(
			'post_type'      => 'client',
			'posts_per_page' => -1,
			'order'          => 'ASC',
			'orderby'        => 'title',
		);

		$client_posts = get_posts( $args );
		if ( $client_posts ) {
			$client = array( '' => '選択してください' );
			foreach ( $client_posts as $key => $post ) {
				// プルダウンに表示するかしないかの情報を取得
				$client_hidden = get_post_meta( $post->ID, 'client_hidden', true );
				// プルダウン非表示にチェックが入っていない項目だけ出力
				if ( ! $client_hidden ) {
						$client[ $post->ID ] = $post->post_title;
				}
			}
		} else {
			$client = array( '0' => '請求先が登録されていません' );
		}

		$custom_fields_array = array(
			'bill_client_name_manual'     => array(
				'label'       => __( '取引先（イレギュラー）', 'bill-vektor' ),
				'type'        => 'text',
				'description' => '複数回依頼の見込みのない取引先の場合はこちらに入力してください。<br>取引の多い取引先の場合は<a href="' . admin_url( '/post-new.php?post_type=client' ) . '" target="_blank">予め登録</a>すると便利です。',
				'required'    => false,
			),
			'bill_client'     => array(
				'label'       => __( '取引先（登録済）', 'bill-vektor' ),
				'type'        => 'select',
				'description' => '取引先は<a href="' . admin_url( '/post-new.php?post_type=client' ) . '" target="_blank">こちら</a>から登録してください。',
				'required'    => '',
				'options'     => $client,
			),
			// 'bill_id' => array(
			// 'label' => __('受け渡し期間','bill-vektor'),
			// 'type' => 'text',
			// 'description' => '',
			// 'required' => false,
			// ),
			// 'bill_Issue_date' => array(
			// 'label' => __('発行日','bill-vektor'),
			// 'type' => 'datepicker',
			// 'description' => '',
			// 'required' => true,
			// ),
			'bill_tax_rate'            => array(
				'label'       => __( '消費税率', 'bill-vektor' ),
				'type'        => 'radio',
				'description' => '',
				'required'    => false,
				'options'     => array(
					'10' => '10%',
					'8'  => '8%',
				),
			),
			'bill_tax_type'            => array(
				'label'       => __( '消費税', 'bill-vektor' ),
				'type'        => 'radio',
				'description' => '',
				'required'    => false,
				'options'     => array(
					'tax_auto'     => '最後にまとめて自動計算する（デフォルト）',
					'tax_not_auto' => '品目毎に予め消費税込の金額で入力する',
				),
			),
			'bill_total_price_display' => array(
				'label'       => __( '合計の表示', 'bill-vektor' ),
				'type'        => 'checkbox',
				'description' => '価格の目安リストなど、金額の合計を表示しない場合はチェックを入れてください。',
				'required'    => false,
				'options'     => array(
					'hidden' => '合計金額を表示しない',
				),
			),
			'bill_remarks'             => array(
				'label'       => __( '備考', 'bill-vektor' ),
				'type'        => 'textarea',
				'description' => '共通の備考は<a href="' . menu_page_url( 'bill-setting-page', false ) . '" target="_blank">' . '請求設定画面</a>から設定してください。<br>こちらの備考が記入されている場合は共通の備考は表示されません。',
				'required'    => false,
			),
			'bill_memo'                => array(
				'label'       => __( 'メモ', 'bill-vektor' ),
				'type'        => 'textarea',
				'description' => 'この項目は見積書には印刷されません。',
				'required'    => false,
			),
			'bill_send_pdf'            => array(
				'label'       => __( '送付済PDF', 'bill-vektor' ),
				'type'        => 'file',
				'description' => '客先に送付したPDFファイルを保存しておく場合に登録してください。',
				'hidden'      => true,
			),
		);
		return $custom_fields_array;
	}

}
Estimate_Normal_Custom_Fields::init();
