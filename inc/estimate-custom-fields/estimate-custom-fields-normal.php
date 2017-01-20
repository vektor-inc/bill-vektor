<?php
/*
* 請求書のカスタムフィールド（品目以外）
*/

class Estimate_Normal_Custom_Fields {
	public static function init() {
		add_action( 'admin_menu' , array( __CLASS__, 'add_metabox'), 10, 2);
		add_action( 'save_post' , array( __CLASS__, 'save_custom_fields'), 10, 2);
	}

	// add meta_box
	public static function add_metabox() 
	{

		$id = 'meta_box_bill_normal';
		$title = __( '見積書項目', '' );
		$callback = array( __CLASS__, 'fields_form');
		$screen = 'estimate';
		$context = 'advanced';
		$priority = 'high';
		$callback_args = '';

		add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );

	}

	public static function fields_form()
	{
		global $post;

		$custom_fields_array = Estimate_Normal_Custom_Fields::custom_fields_array();
		$befor_custom_fields = '';
		VK_Custom_Field_Builder::form_table( $custom_fields_array, $befor_custom_fields );
	}

	public static function save_custom_fields()
	{
		$custom_fields_array = Estimate_Normal_Custom_Fields::custom_fields_array();
		// $custom_fields_array_no_cf_builder = arra();
		// $custom_fields_all_array = array_merge(  $custom_fields_array, $custom_fields_array_no_cf_builder );
		VK_Custom_Field_Builder::save_cf_value( $custom_fields_array );
	}

	public static function custom_fields_array()
	{

		$args = array(
			'post_type' => 'client',
			'post_per_pages' => -1,
			);

		$client_posts = get_posts($args);
		if ( $client_posts ) {
			foreach ($client_posts as $key => $post) {
				$client[$post->ID] = $post->post_title;
			}	
		} else {
			$client = array( "0" => "請求先が登録されていません");
		}

		$custom_fields_array = array(
			'bill_client' => array(
				'label' => __('クライアント','bill-vektor'),
				'type' => 'select',
				'description' => '',
				'required' => true,
				'options' => $client,
			),
			// 'bill_id' => array(
			// 	'label' => __('請求番号','bill-vektor'),
			// 	'type' => 'text',
			// 	'description' => '',
			// 	'required' => false,
			// ),
			// 'bill_id' => array(
			// 	'label' => __('受け渡し期間','bill-vektor'),
			// 	'type' => 'text',
			// 	'description' => '',
			// 	'required' => false,
			// ),
			'bill_Issue_date' => array(
				'label' => __('発行日','bill-vektor'),
				'type' => 'datepicker',
				'description' => '',
				'required' => true,
			),
			'bill_remarks' => array(
				'label' => __('備考','bill-vektor'),
				'type' => 'textarea',
				'description' => '共通の備考は請求設定画面から設定ください。<br>こちらの備考が記入されている場合は共通の備考は表示されません。',
				'required' => false,
			),
		// 'event_image_main' => array(
		//     'label' => __('メインイメージ','bill-vektor'),
		//     'type' => 'image',
		//     'description' => '',
		//     'hidden' => true,
		//     ),
		);
		return $custom_fields_array;
	}

}
Estimate_Normal_Custom_Fields::init();



