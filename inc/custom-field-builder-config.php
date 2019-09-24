<?php

/*
  Load modules
/*-------------------------------------------*/
if ( ! class_exists( 'VK_Custom_Field_Builder' ) ) {
	require_once( dirname( __FILE__ ) . '/custom-field-builder/custom-field-builder.php' );

	global $custom_field_builder_url;
	$custom_field_builder_url = get_template_directory_uri() . '/inc/custom-field-builder/';

}
