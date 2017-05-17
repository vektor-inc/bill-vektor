<?php
/*
* 請求品目のカスタムフィールド
*/

class Bill_Item_Custom_Fields {

	public static $version = '0.0.0';

	// define( 'Bill_URL', get_template_directory_uri() );

	public static function init() {
		add_action( 'admin_menu' , array( __CLASS__, 'add_metabox'), 10, 2);
		add_action( 'save_post' , array( __CLASS__, 'save_custom_fields'), 10, 2);
	}

	// add meta_box
	public static function add_metabox() 
	{

		$id = 'meta_box_bill_items';
		$title = __( '請求品目', '' );
		$callback = array( __CLASS__, 'fields_form');
		$screen = 'post';
		$context = 'advanced';
		$priority = 'high';
		$callback_args = '';

		add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );

	}

	public static function fields_form()
	{

		wp_nonce_field( wp_create_nonce(__FILE__), 'noncename__bill_fields' );

		global $post;
		$bill_items = get_post_meta( $post->ID, 'bill_items', true );

		$form_table = '<div class="vk-custom-field-builder">';
		$form_table .= '<table class="table table-striped table-bordered row-control">';

		$form_table .= '<thead><tr><th></th><th></th><th>品目</th><th>数量</th><th>単位</th><th>単価</th><th></th></tr></thead>';
		$form_table .= '<tbody id="sortable">';

		$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );

		// 品目の登録がない場合には8行分の配列を用意しておく
		if ( !$bill_items ){
			for ( $i = 0; $i <= 7 ;$i++ ) {
				$bill_items[$i] = array(
					'name' => '',
					'count' => '',
					'unit' => '',
					'price' => '',
				 );
			}
		}

		if ( isset( $bill_items[0]['total_row_count'] ) && $bill_items[0]['total_row_count'] ) {
			$total_row_count = $bill_items[0]['total_row_count'];
		} else {
			$total_row_count = 1;
		}

		// 行のループ
		foreach ($bill_items as $key => $value) {
			$form_table .= '<tr>';
			$number = intval( $key ) + 1;
			$form_table .= '<th class="text-center vertical-middle"><span class="icon-drag"></span></th>';
			$form_table .= '<th class="text-center vertical-middle"><span class="cell-number">'.$number.'</span></th>';

			// 列をループ
			foreach ($bill_item_sub_fields as $sub_field) {
				// php noindex 用に isset （ isset( $value[$sub_field] ) && $value[$sub_field] にすると 0円の時に0が表示されなくなる ）
				$bill_item_value[$sub_field] = ( isset( $value[$sub_field] ) ) ? $value[$sub_field] : '';
				$form_table .= '<td class="cell-'.$sub_field.'"><input class="bill-item-field" type="text" id="bill_items['.$key.']['.$sub_field.']" name="bill_items['.$key.']['.$sub_field.']" value="'.esc_attr($bill_item_value[$sub_field]).'"></td>';
			}
			$form_table .= '<td class="cell-control">
			<input type="button" class="add-row button button-primary" value="行を追加" />
			<input type="button" class="del-row button" value="行を削除" />
			</td>';
			$form_table .= '</tr>';
		}

		$form_table .= '</tbody>';
		$form_table .= '</table>';
		$form_table .= '</div>';
		echo $form_table;
	}

	/*
	/*  入力された値の保存
	*/
	public static function save_custom_fields($post_id){
		global $post;

		//設定したnonce を取得（CSRF対策）
		$noncename__bill_fields = isset( $_POST['noncename__bill_fields'] ) ? $_POST['noncename__bill_fields'] : null;

		//nonce を確認し、値が書き換えられていれば、何もしない（CSRF対策）
		if( !wp_verify_nonce( $noncename__bill_fields, wp_create_nonce(__FILE__) ) ) {  
		return $post_id;
		}

		//自動保存ルーチンかどうかチェック。そうだった場合は何もしない（記事の自動保存処理として呼び出された場合の対策）
		if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $post_id; }

		$field = 'bill_items';
		$field_value = ( isset( $_POST[$field] ) ) ? $_POST[$field] : '';

		// 配列の空の行を削除する
		if ( is_array( $field_value ) ){
			// $field_value = Bill_Item_Custom_Fields::delete_null_row( $field_value );
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
	* 空の行が送られてきた場合に配列から削除するための関数
	*/
	public static function delete_null_row( $field_value ){
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

}

Bill_Item_Custom_Fields::init();