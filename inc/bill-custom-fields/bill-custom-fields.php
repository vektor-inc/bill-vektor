<?php

/*-------------------------------------------*/
/*  イベントのカスタムフィールド
/*-------------------------------------------*/
function custom_fields_array(){
    $custom_fields_array = array(
    // 'event_date' => array(
    //     'label' => __('開催日','vkPortalUnit'),
    //     'type' => 'datepicker',
    //     'description' => '',
    //     ),
    'bill_id' => array(
        'label' => __('請求番号','bill-vektor'),
        'type' => 'text',
        'description' => '',
        'required' => false,
        ),
    'event_venue' => array(
        'label' => __('イベント会場名','bill-vektor'),
        'type' => 'text',
        'description' => '',
        'required' => true,
        ),
    'event_venue_url' => array(
        'label' => __('イベント会場のURL','bill-vektor'),
        'type' => 'url',
        'description' => '例) http://www.google.com',
        ),
    'event_how_to_apply' => array(
        'label' => __('申し込み方法','bill-vektor'),
        'type' => 'textarea',
        'description' => '',
        'required' => true,
        ),
    'event_contact_mail' => array(
        'label' => __('お問い合わせ<br>メールアドレス','bill-vektor'),
        'type' => 'text',
        'description' => '',
        'hidden' => true,
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

/*-------------------------------------------*/
/*  メタボックスを追加
/*-------------------------------------------*/


// add meta_box
function bill_post_metabox() {

    $id = 'bill_post_meta_box';
    $title = __( '請求項目', '' );
    $callback = 'bill_post_fields';
    $screen = 'post';
    $context = 'advanced';
    $priority = 'high';
    $callback_args = '';

    add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );

}
add_action( 'admin_menu', 'bill_post_metabox' );


function bill_post_fields(){
		// $custom_fields_array = custom_fields_array();
		// VK_Custom_Field_Builder::form_table( $custom_fields_array );
	wp_nonce_field( wp_create_nonce(__FILE__), 'noncename__bill_fields' );

	global $post;
	// delete_post_meta( $post->ID, 'bill_items' );
	$bill_items = get_post_meta( $post->ID, 'bill_items', true );

	// print '<pre style="text-align:left">';print_r($bill_items);print '</pre>';

	$form_table = '<table class="table table-striped table-bordered row-control">';

	$form_table .= '<thead><tr><th></th><th>品目</th><th>数量</th><th>単位</th><th>商品単価</th><th></th></tr></thead>';
	$form_table .= '<tbody>';

	$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );

	// 品目の登録がない場合には１行目用の配列を用意しておく
	if ( !$bill_items ){
		$bill_items[0] = array(
			'name' => '',
			'count' => '',
			'unit' => '',
			'price' => '',
			'total_row_count' => 1
		 );
	}

	if ( isset( $bill_items[0]['total_row_count'] ) && $bill_items[0]['total_row_count'] ) {
		$total_row_count = $bill_items[0]['total_row_count'];
	} else {
		$total_row_count = 1;
	}

	// 行のループ
	foreach ($bill_items as $key => $value) {
		$form_table .= '<tr>';

		$form_table .= '<td><input type="hidden" id="bill_items[0][total_row_count]" name="bill_items[0][total_row_count]" value="'.$bill_items[0]['total_row_count'].'"></td>';

		// 列をループ
		foreach ($bill_item_sub_fields as $sub_field) {
			// php noindex 用に isset
			$bill_item_value[$sub_field] = ( isset( $value[$sub_field] ) && $value[$sub_field] ) ? $value[$sub_field] : '';
			$form_table .= '<td class="cell-'.$sub_field.'"><input class="bill-item-field" type="text" id="bill_items['.$key.']['.$sub_field.']" name="bill_items['.$key.']['.$sub_field.']" value="'.$bill_item_value[$sub_field].'"></td>';
		}
		$form_table .= '<td class="cell-control">
		<input type="button" class="add-row button" value="行を追加" />
		<input type="button" class="del-row button" value="行を削除" />
		</td>';
		$form_table .= '</tr>';
	}

	$form_table .= '</tbody>';
	$form_table .= '</table>';
	echo $form_table;
}

/*-------------------------------------------*/
/*  入力された値の保存
/*-------------------------------------------*/
add_action('save_post', 'bill_save_post_fields');

function bill_save_post_fields($post_id){
    global $post;

    //設定したnonce を取得（CSRF対策）
    $noncename__bill_fields = isset($_POST['noncename__bill_fields']) ? $_POST['noncename__bill_fields'] : null;

    //nonce を確認し、値が書き換えられていれば、何もしない（CSRF対策）
    if(!wp_verify_nonce($noncename__bill_fields, wp_create_nonce(__FILE__))) {  
        return $post_id;
    }

    //自動保存ルーチンかどうかチェック。そうだった場合は何もしない（記事の自動保存処理として呼び出された場合の対策）
    if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $post_id; }
    
    $field = 'bill_items';
    $field_value = ( isset( $_POST[$field] ) ) ? $_POST[$field] : '';

    // 配列の空の行を削除する
    if ( is_array( $field_value ) ){
    	$field_value = bill_delete_null_item_row( $field_value );
    }
    
    // データが空だったら入れる
    if( get_post_meta($post_id, $field ) == ""){
        add_post_meta($post_id, $field , $field_value, true);
    // 今入ってる値と違ってたらアップデートする
    } elseif( $field_value != get_post_meta( $post_id, $field , true)){
        update_post_meta($post_id, $field , $field_value);
    // 入力がなかったら消す
    } elseif( $field_value == "" ){
        delete_post_meta($post_id, $field , get_post_meta( $post_id, $field , true ));
    }

}

/*
* 空の行があった場合に配列から削除するための関数
*/
function bill_delete_null_item_row( $field_value ){
	foreach (  $field_value as $key => $value) {
		$total_sub_value = '';
		foreach ( $value as $sub_field => $sub_value) {
			$total_sub_value .= $sub_value;
		}
		if ( !$total_sub_value ){
			// 空の行を削除
			unset( $field_value[$key] );
		}
	}
	// Indexを詰める
	array_values($field_value);
	return $field_value;
}

