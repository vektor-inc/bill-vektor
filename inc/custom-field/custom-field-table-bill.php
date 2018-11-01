<?php

add_action( 'admin_menu', 'bill_add_metabox_item_table', 10, 2 );

// add meta_box
function bill_add_metabox_item_table() {

	$id            = 'meta_box_bill_items';
	$title         = '請求品目';
	$callback      = array( 'Bill_Item_Custom_Fields', 'fields_form' );
	$screen        = 'post';
	$context       = 'advanced';
	$priority      = 'high';
	$callback_args = '';

	add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );

	$id            = 'meta_box_bill_items';
	$title         = '見積品目';
	$callback      = array( 'Bill_Item_Custom_Fields', 'fields_form' );
	$screen        = 'estimate';
	$context       = 'advanced';
	$priority      = 'high';
	$callback_args = '';

	add_meta_box( $id, $title, $callback, $screen, $context, $priority, $callback_args );
}
