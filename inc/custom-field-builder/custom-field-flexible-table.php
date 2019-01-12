<?php
/*
このファイルの元ファイルは
https://github.com/vektor-inc/vektor-wp-libraries
にあります。
修正の際は上記リポジトリのデータを修正してください。
編集権限を持っていない方で何か修正要望などありましたら
各プラグインのリポジトリにプルリクエストで結構です。
*/
/*
* 項目変動・多行のカスタムフィールド
*/

class VK_Custom_Field_Builder_Flexible_Table {

	public static $version = '0.0.0';

	/**
	 * 入力フォームの生成
	 *
	 * @param  [type] $custom_fields_array [description]
	 * @return [type]                      [description]
	 */
	public static function form_table_flexible( $custom_fields_array ) {

		$nonce_name = 'noncename__' . $custom_fields_array['field_name'];
		wp_nonce_field( wp_create_nonce( __FILE__ ), $nonce_name );

		global $post;
		// 既に保存されている値を取得
		$fields_value = get_post_meta( $post->ID, $custom_fields_array['field_name'], true );

		// $fields_value が空の時、配列にしておかないと PHP 7.1 でエラーになる
		if ( ! is_array( $fields_value ) ) {
			$fields_value = array();
		}

		$form_table  = '<div class="vk-custom-field-builder">';
		$form_table .= '<table class="table table-striped table-bordered row-control">';

		/*
		  thead
		/*-------------------------------------------*/

		$form_table .= '<thead><tr><th></th><th></th>';
		foreach ( $custom_fields_array['items'] as $key => $value ) {
			$form_table .= '<th>' . esc_html( $value['label'] ) . '</th>';
		}
		$form_table .= '<th></th>';
		$form_table .= '</tr></thead>';

		/*
		  tbody
		/*-------------------------------------------*/

		$form_table .= '<tbody class="sortable">';

		// 品目の登録がない場合に配列を用意しておく
		if ( ! $fields_value ) {
			for ( $i = 0; $i < $custom_fields_array['row_default'];$i++ ) {
				foreach ( $custom_fields_array['items'] as $key => $value ) {
						$fields_value[ $i ][ $key ] = '';
				}
			}
		}

		if ( isset( $fields_value[0]['total_row_count'] ) && $fields_value[0]['total_row_count'] ) {
			$total_row_count = $fields_value[0]['total_row_count'];
		} else {
			$total_row_count = 1;
		}

		// 行のループ
		// print '<pre style="text-align:left">';
		// print_r( $fields_value );
		// print '</pre>';
		foreach ( $fields_value as $key => $value ) {
			$form_table .= '<tr>';
			$number      = intval( $key ) + 1;
			$form_table .= '<th class="text-center vertical-middle"><span class="icon-drag"></span></th>';
			$form_table .= '<th class="text-center vertical-middle"><span class="cell-number">' . $number . '</span></th>';

			// 列をループ
			foreach ( $custom_fields_array['items'] as $field_key => $value ) {
				// $bill_item_value[ $sub_field ] = ( isset( $value[ $sub_field ] ) ) ? $value[ $sub_field ] : '';
				$form_table .= '<td class="cell-' . $key . '">';
				$form_table .= '<input class="flexible-field-item" type="text" id="' . $custom_fields_array['field_name'] . '[' . $key . '][' . $field_key . ']" name="' . $custom_fields_array['field_name'] . '[' . $key . '][' . $field_key . ']" value="' . esc_attr( $fields_value[ $key ][ $field_key ] ) . '"></td>';
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

	/**
	 * 入力された値の保存
	 *
	 * @param  [type] $custom_fields_array [description]
	 * @return [type]                      [description]
	 */
	public static function save_cf_value( $custom_fields_array ) {

		global $post;

		// 設定したnonce を取得（CSRF対策）
		$nonce_name             = 'noncename__' . $custom_fields_array['field_name'];
		$noncename__bill_fields = isset( $_POST[ $nonce_name ] ) ? $_POST[ $nonce_name ] : null;

		// nonce を確認し、値が書き換えられていれば、何もしない（CSRF対策）
		if ( ! wp_verify_nonce( $noncename__bill_fields, wp_create_nonce( __FILE__ ) ) ) {
			return;
		}

		// 自動保存ルーチンかどうかチェック。そうだった場合は何もしない（記事の自動保存処理として呼び出された場合の対策）
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$field       = $custom_fields_array['field_name'];
		$field_value = ( isset( $_POST[ $field ] ) ) ? $_POST[ $field ] : '';

		// 配列の空の行を削除する
		if ( is_array( $field_value ) ) {
			// $field_value = Bill_Salary_Custom_Fields::delete_null_row( $field_value );
		}

		// データが空だったら入れる
		if ( get_post_meta( $post->ID, $field ) == '' ) {
			add_post_meta( $post->ID, $field, $field_value, true );
			// 今入ってる値と違ってたらアップデートする
		} elseif ( $field_value != get_post_meta( $post->ID, $field, true ) ) {
			update_post_meta( $post->ID, $field, $field_value );
			// 入力がなかったら消す
		} elseif ( $field_value == '' ) {
			delete_post_meta( $post->ID, $field, get_post_meta( $post->ID, $field, true ) );
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

	public static function get_view_table_body( $custom_fields_array ) {
		global $post;

		$table_values    = get_post_meta( $post->ID, $custom_fields_array['field_name'], true );
		$table_body_html = '';

		foreach ( $table_values as $key => $cells ) {

			// 空の行を表示するかどうか
			$exist_value = false;
			foreach ( $cells as $cell_key => $cell_value ) {
				if ( ! empty( $cell_value ) ) {
					$exist_value = true;
				}
			}

			// 値が存在するか、空の行の出力指定がされている場合のみ行を出力
			if ( $exist_value || $custom_fields_array['row_empty_display'] ) {

				$table_body_html .= '<tr>';

				foreach ( $cells as $cell_key => $cell_value ) {
					if ( ! empty( $custom_fields_array['items'][ $cell_key ]['display_callback'] ) ) {
						$cell_value = call_user_func( $custom_fields_array['items'][ $cell_key ]['display_callback'], $cell_value );
					}

					$class = '';

					// クラス指定があったらそのまま入れる
					if ( ! empty( $custom_fields_array['items'][ $cell_key ]['class'] ) ) {
						$class = $custom_fields_array['items'][ $cell_key ]['class'];
					}

					// align指定があったら追加する
					if ( ! empty( $custom_fields_array['items'][ $cell_key ]['align'] ) ) {
						if ( $class ) {
							$class .= ' ';
						}
						$class .= 'text-' . $custom_fields_array['items'][ $cell_key ]['align'];
					}

					if ( $class ) {
						$class = ' class="' . esc_attr( $class ) . '"';
					}

					$table_body_html .= '<td' . $class . '>' . $cell_value . '</td>';
				} // foreach ( $cells as $cell_key => $cell_value ) {

				$table_body_html .= '</tr>';

			} // if ( $exist_value || $custom_fields_array['row_empty_display'] ) {

		}
		return $table_body_html;
	}

}

// VK_Custom_Field_Builder_Flexible_Table::init();
