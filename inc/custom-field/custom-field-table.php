<?php
/*
* 請求品目のカスタムフィールド
*/

class Bill_Item_Custom_Fields {

	public static $version = '0.0.0';

	// define( 'Bill_URL', get_template_directory_uri() );
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'save_custom_fields' ), 10, 2 );
	}

	public static function fields_form() {

		wp_nonce_field( wp_create_nonce( __FILE__ ), 'noncename__bill_fields' );

		global $post;
		$bill_items   = get_post_meta( $post->ID, 'bill_items', true );
		$old_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
		$old_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );

		// $bill_items が空の時、配列にしておかないと PHP 7.1 でエラーになる
		if ( ! is_array( $bill_items ) ) {
			$bill_items = array();
		}

		$form_table  = '<div class="vk-custom-field-builder">';
		$form_table .= '<table class="table admin-bill-table table-striped table-bordered row-control">';

		$form_table .= '<thead><tr><th></th><th></th><th>品目</th><th>数量</th><th>単位</th><th>単価</th><th>税抜/税込</th><th >消費税率</th><th>行の追加・削除</th></tr></thead>';
		$form_table .= '<tbody class="sortable">';

		$bill_item_sub_fields = array(
			'name'  => 'text',
			'count' => 'number',
			'unit'  => 'text',
			'price' => 'number',
		);

		$tax_rate_array = bill_vektor_tax_array();

		// 品目の登録がない場合には8行分の配列を用意しておく
		if ( ! $bill_items ) {
			for ( $i = 0; $i <= 7;$i++ ) {
				$bill_items[ $i ] = array(
					'name'     => '',
					'count'    => '',
					'unit'     => '',
					'price'    => '',
					'tax-rate' => '10%',
					'tax-type' => 'tax_excluded',
				);
			}
		}

		if ( isset( $bill_items[0]['total_row_count'] ) && $bill_items[0]['total_row_count'] ) {
			$total_row_count = $bill_items[0]['total_row_count'];
		} else {
			$total_row_count = 1;
		}

		// 行のループ
		foreach ( $bill_items as $key => $value ) {
			$form_table .= '<tr>';
			$number      = intval( $key ) + 1;
			$form_table .= '<th class="text-center vertical-middle bill-cell-toggle"><span class="icon-drag"></span></th>';
			$form_table .= '<th class="text-center vertical-middle bill-cell-number"><span class="bill-cell-number">' . $number . '</span></th>';

			// 列をループ
			foreach ( $bill_item_sub_fields as $sub_field => $input_type ) {
				// php noindex 用に isset （ isset( $value[$sub_field] ) && $value[$sub_field] にすると 0円の時に0が表示されなくなる ）
				$bill_item_value[ $sub_field ] = ( isset( $value[ $sub_field ] ) ) ? $value[ $sub_field ] : '';
				$form_table                   .= '<td class="bill-cell-' . $sub_field . '"><input class="flexible-field-item" type="'. $input_type . '" id="bill_items[' . $key . '][' . $sub_field . ']" name="bill_items[' . $key . '][' . $sub_field . ']" value="' . esc_attr( $bill_item_value[ $sub_field ] ) . '"></td>';
			}

			// 税抜きか税込みか
			$tax_type_array = array(
				array( 
					'label'    => '税抜',
					'value'    => 'tax_excluded',
					'old_type' => 'tax_auto',
				),
				array( 
					'label'    => '税込',
					'value'    => 'tax_included',
					'old_type' => 'tax_not_auto',
				)
			);
			$form_table .= '<td class="bill-cell-tax-type">';
			$form_table .= '<select id="bill_items[' . $key . '][tax-type]" name="bill_items[' . $key . '][tax-type]">';
			$form_table .= '<option value="">選択してください</option>';
			foreach ( $tax_type_array as $tax_type ) {
				$selected = false;
				if (  ! empty( $value['tax-type'] ) && $tax_type['value'] === $value['tax-type'] ) {
					$selected = true;
				} elseif ( ! empty( $old_tax_type ) && $tax_type['old_type'] === $old_tax_type ) {
					$selected = true;
				} elseif( empty( $value['tax-type'] ) && empty( $old_tax_type ) && 'tax_excluded' === $tax_type['value'] ) {
					$selected = true;
				}
				$form_table .= '<option value="' . $tax_type['value'] . '" ' . selected( $selected, true, false ) . '>' . $tax_type['label'] . '</option>';
			}
			$form_table .= '</select>';

			// 消費税率
			$form_table .= '<td class="bill-cell-tax-rate">';
			$form_table .= '<select id="bill_items[' . $key . '][tax-rate]" name="bill_items[' . $key . '][tax-rate]">';
			$form_table .= '<option value="">選択してください</option>';
			foreach ( $tax_rate_array as $tax_rate ) {
				$selected = false;
				if (  ! empty( $value['tax-rate'] ) && $tax_rate ===  $value['tax-rate'] ) {
					$selected = true;
				} elseif ( ! empty( $old_tax_rate ) && $tax_rate === $old_tax_rate . '%' ) {
					$selected = true;
				}
				elseif( empty( $value['tax-rate'] ) && empty( $old_tax_rate ) && '10%' === $tax_rate ) {
					$selected = true;
				}
				$form_table .= '<option value="' . $tax_rate . '" ' . selected( $selected, true, false ) . '>' . $tax_rate . '</option>';
			}
			$form_table .= '</select>';
			$form_table .= '<td class="bill-cell-control">
			<div class="bill-cell-control-cols">
			<input type="button" class="add-row button button-primary" value="追加" />
			<input type="button" class="del-row button" value="削除" />
			</div>
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
	public static function save_custom_fields( $post_id ) {
		global $post;

		// 設定したnonce を取得（CSRF対策）
		$noncename__bill_fields = isset( $_POST['noncename__bill_fields'] ) ? $_POST['noncename__bill_fields'] : null;

		// nonce を確認し、値が書き換えられていれば、何もしない（CSRF対策）
		if ( ! wp_verify_nonce( $noncename__bill_fields, wp_create_nonce( __FILE__ ) ) ) {
			return $post_id;
		}

		// 自動保存ルーチンかどうかチェック。そうだった場合は何もしない（記事の自動保存処理として呼び出された場合の対策）
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id; 
		}

		$field       = 'bill_items';
		$field_value = ( isset( $_POST[ $field ] ) ) ? $_POST[ $field ] : '';

		// 古いカスタムフィールドを削除する
		if ( ! empty( get_post_meta( $post->ID, 'bill_tax_rate', true ) ) || ! empty( get_post_meta( $post->ID, 'bill_tax_type', true ) ) ) {
			delete_post_meta( $post->ID, 'bill_tax_rate' );
			delete_post_meta( $post->ID, 'bill_tax_type' );
		}

		// 配列の空の行を削除する
		if ( is_array( $field_value ) ) {
			// $field_value = Bill_Item_Custom_Fields::delete_null_row( $field_value );
		}

		// データが空だったら入れる
		if ( get_post_meta( $post_id, $field ) == '' ) {
			add_post_meta( $post_id, $field, $field_value, true );
			// 今入ってる値と違ってたらアップデートする
		} elseif ( $field_value != get_post_meta( $post_id, $field, true ) ) {
			update_post_meta( $post_id, $field, $field_value );
			// 入力がなかったら消す
		} elseif ( $field_value == '' ) {
			delete_post_meta( $post_id, $field, get_post_meta( $post_id, $field, true ) );
		}
	}

	/*
	* 空の行が送られてきた場合に配列から削除するための関数
	*/
	public static function delete_null_row( $field_value ) {
		foreach ( $field_value as $key => $value ) {
			$total_sub_value = '';
			foreach ( $value as $sub_field => $sub_value ) {
				$total_sub_value .= $sub_value;
			}
			if ( ! $total_sub_value ) {
				// 空の行を削除
				unset( $field_value[ $key ] );
			}
		}
		// Indexを詰める
		array_values( $field_value );
		return $field_value;
	}

}

Bill_Item_Custom_Fields::init();
