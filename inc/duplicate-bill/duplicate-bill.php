<?php

/*-------------------------------------------*/
/*  レビュー送信metabox
/*-------------------------------------------*/
add_action( 'post_submitbox_start','send_review' );
function send_review(){
	?>

	<div id="review_section">
		<input type="submit" name="send_review_mail" id="send_review_mail" class="button button-default" value="<?php _e( '複製','bill-vektor' );?>">
	</div><!-- [ / #review_section ] -->
	<?php
}