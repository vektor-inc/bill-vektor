<?php
if ( ! class_exists( 'VK_Custom_Field_Builder' ) ) {

    class VK_Custom_Field_Builder {

      public static function form_post_value( $post_field, $type = false )
      {
            $value = '';
            global $post;
              if ( isset( $_POST[$post_field] ) && $_POST[$post_field] ) {
                if ( isset( $type ) && $type == 'textarea' ) {
                  // n2brはフォームにbrがそのまま入ってしまうので入れない
                    $value = esc_textarea( $_POST[$post_field] );
                } else {
                    $value = esc_attr( $_POST[$post_field] );
                } 
              } else if ( isset( $post->$post_field ) && $post->$post_field ) {
                $value = $post->$post_field;
              }
            return $value;
      }

      public static function form_required()
      {
            $required = '<span class="required">必須</span>';
            return $required;
      }

      public static function form_table( $custom_fields_array, $befor_items = '', $echo = true ){

          // wp_nonce_field(wp_create_nonce(__FILE__), 'noncename__fields');

          global $post;

          $form_html = '';

          $form_html .= '<table class="cf_table table-striped table">';

          $form_html .= $befor_items;

          foreach ($custom_fields_array as $key => $value) {
              $form_html .= '<tr class="cf_item"><th class="text-nowrap"><label>'.$value['label'].'</label>';
              $form_html .= ( isset( $value['required'] ) && $value['required'] ) ? VK_Custom_Field_Builder::form_required() : '';
              $form_html .= '</th><td>';
              if ( $value['type'] == 'text' || $value['type'] == 'url' ){
                  $form_html .= '<input class="form-control" type="text" id="'.$key.'" name="'.$key.'" value="'.VK_Custom_Field_Builder::form_post_value($key).'" size="70">';
              } else if ( $value['type'] == 'datepicker' ){
                  $form_html .= '<input class="form-control" type="text" id="'.$key.'" name="'.$key.'" value="'.VK_Custom_Field_Builder::form_post_value($key).'" size="70" class="datepicker">';
              } else if ( $value['type'] == 'textarea' ){
                  $form_html .= '<textarea class="form-control" class="cf_textarea_wysiwyg" name="'.$key.'" cols="70" rows="3">'.VK_Custom_Field_Builder::form_post_value($key,'textarea').'</textarea>';
              } else if ( $value['type'] == 'image' ){
                  $attr = array(
                    'id'    => 'thumb_'.$key,
                    'src'   => '',
                    'class' => 'input_thumb',
                    );
                  if ( isset( $_POST[$key] ) && $_POST[$key] ){
                    $form_html .= wp_get_attachment_image( $_POST[$key], 'medium', false, $attr );
                  } else if ( $post->$key ){
                    $form_html .= wp_get_attachment_image( $post->$key, 'medium', false, $attr );
                  } else {
                    $form_html .= '<img src="'.VK_PORTAL_URL.'/images/no_image.png" id="thumb_'.$key.'" alt="" class="input_thumb" style="width:200px;height:auto;">';
                  }
                  
                  $form_html .= '<input type="hidden" name="'.$key.'" id="'.$key.'" value="'.VK_Custom_Field_Builder::form_post_value($key).'" style="width:60%;" /> 
    <button id="media_'.$key.'" class="media_btn btn btn-default button button-default">'.__('画像を選択', '').'</button>';
              }
              if ( $value['description'] ) {
                  $form_html .= '<div>'.esc_html( $value['description'] ).'</div>';
              }
              $form_html .= '</td></tr>';
          }
          $form_html .= '</table>';
          if ( $echo ) {
            wp_enqueue_media();
            echo $form_html;
          } else {
            wp_enqueue_media();
            return $form_html;
          }
?>
<?php
      } // public static function form_table( $custom_fields_array, $befor_items, $echo = true ){

      /*-------------------------------------------*/
      /*  実行
      /*-------------------------------------------*/
      public function __construct(){
          // add_action( 'init', array( $this, 'add_post_type' ),0 );
      }

      } // class Vk_custom_field_builder

} // if ( ! class_exists( 'Vk_custom_field_builder' ) ) {

// $Vk_custom_field_builder = new Vk_custom_field_builder();
