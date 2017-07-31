<?php
/*
* 取引先のカスタムフィールド
*/

class Client_Custom_Fields {
	public static function init() {
		add_action( 'admin_menu' , array( __CLASS__, 'add_metabox'), 10, 2);
		add_action( 'save_post' , array( __CLASS__, 'save_custom_fields'), 10, 2);
	}

	// add meta_box
	public static function add_metabox() 
	{

		$id = 'meta_box_bill_normal';
		$title = __( '取引先情報', '' );
		$callback = array( __CLASS__, 'fields_form');
		$screen = 'client';
		$context = 'advanced';
		$priority = 'high';
		$callback_args = '';

		add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );

	}

	public static function fields_form()
	{
		global $post;

		$custom_fields_array = Client_Custom_Fields::custom_fields_array();
		$befor_custom_fields = '';
		VK_Custom_Field_Builder::form_table( $custom_fields_array, $befor_custom_fields );
	}

	public static function save_custom_fields()
	{
		$custom_fields_array = Client_Custom_Fields::custom_fields_array();
		// $custom_fields_array_no_cf_builder = arra();
		// $custom_fields_all_array = array_merge(  $custom_fields_array, $custom_fields_array_no_cf_builder );
		VK_Custom_Field_Builder::save_cf_value( $custom_fields_array );
	}

	public static function custom_fields_array()
	{

		$honorific_options = array(
			"御中" => "御中",
			"様" => "様"
			);

		$custom_fields_array = array(
			'client_honorific' => array(
				'label' => __('敬称','bill-vektor'),
				'type' => 'select',
				'description' => '',
				'required' => false,
				'options' => $honorific_options,
			),
			'client_short_name' => array(
				'label' => __('短縮名','bill-vektor'),
				'type' => 'text',
				'description' => '本システム内での短縮表記名です。',
				'required' => false,
			),
			'client_zip' => array(
				'label' => __('郵便番号','bill-vektor'),
				'type' => 'text',
				'description' => '',
				'required' => false,
			),
			'client_address' => array(
				'label' => __('住所','bill-vektor'),
				'type' => 'textarea',
				'description' => '',
				'required' => false,
			),
			'client_section' => array(
				'label' => __('担当者部署名','bill-vektor'),
				'type' => 'textarea',
				'description' => '',
				'required' => false,
			),
			'client_doc_destination' => array(
				'label' => __('[ 送付状 ] 宛名','bill-vektor'),
				'type' => 'text',
				'description' => '宛名が未入力の場合は「取引先名 + 敬称」が表記されます。',
				'required' => false,
			),
			'client_doc_content' => array(
				'label' => __('[ 送付状 ] 同封書類内容','bill-vektor'),
				'type' => 'textarea',
				'description' => '例）請求書・・・・・・・・・・・・・・・・・・・・・・・・１通',
				'required' => false,
			),
			'client_doc_tantou' => array(
				'label' => __('[ 送付状 ] 担当者名（送信者名）','bill-vektor'),
				'type' => 'text',
				'description' => '',
				'required' => false,
			),
			'client_doc_send_date' => array(
				'label' => __('[ 送付状 ] 表記する書類送付日','bill-vektor'),
				'type' => 'datepicker',
				'description' => '印刷日と同じ日で良い場合は空欄でかまいません。',
				'required' => false,
			),
			'client_remarks' => array(
				'label' => __('メモ','bill-vektor'),
				'type' => 'textarea',
				'description' => '取引上の注意や担当者情報などを必要に応じてメモするためのものです。<br>この項目はどこにも反映されません。',
				'required' => false,
			),
		);
		return $custom_fields_array;
	}

}
Client_Custom_Fields::init();



