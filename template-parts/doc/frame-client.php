<div class="client-wrap">
<div class="container">
<div class="row">
<div class="col-xs-7">
<div class="address-area">
<?php
// 郵便番号
if ( $post->client_zip ) {
	echo '〒' . esc_html( $post->client_zip ) . '<br>';
}

// 住所
if ( $post->client_address ) {
	echo nl2br( esc_textarea( $post->client_address ) ) . '<br>';
}

// 宛先
// 特定のダグのみ許可
$allowed_html = array(
	'span'   => array(
		'style' => array(),
	),
	'br'     => array(),
	'em'     => array(),
	'strong' => array(),
);

if ( $post->client_doc_destination ) {
	echo esc_html( get_the_title() ) . '<br>';
	if ( $post->client_section ) {
		echo nl2br( esc_textarea( $post->client_section, $allowed_html ) ) . '<br>';
	}
	echo '<h2 class="client-destination">' . wp_kses( $post->client_doc_destination, $allowed_html ) . '</h2>';
} else {
	echo '<h2 class="client-destination">' . esc_html( get_the_title() ) . ' ' . esc_html( $post->client_honorific ) . '</h2>';
}
?>
</div><!-- [ /.address-area ] -->
</div>
<div class="col-xs-5">

<div class="bill-address-own">
<p class="text-right">

<?php
if ( $post->client_doc_send_date ) {
	echo date( 'Y年n月j日', bill_raw_date( $post->client_doc_send_date ) );
} else {
	echo date( 'Y年n月j日' );
}
?>
</p>
<?php $options = get_option( 'bill-setting', Bill_Admin::options_default() ); ?>
<div class="text-right"><?php echo nl2br( esc_textarea( $options['own-address'] ) ); ?></div>
<h5 class="text-right"><?php echo esc_html( $options['own-name'] ); ?></h5>
<?php if ( $post->client_doc_tantou ) { ?>
<div class="text-right">担当 : <?php echo esc_html( $post->client_doc_tantou ); ?></div>
<?php } ?>
</div><!-- [ /.address-own ] -->

	</div>
	</div><!-- [ /.row ] -->
<h1 class="client-doc-title">書類送付のご案内</h1>
<p>拝啓</p>
<?php
if ( isset( $options['client-doc-message'] ) ) {
	$message = $options['client-doc-message'];
} else {
	$message = '平素は格別のお引き立てにあずかり、厚く御礼申し上げます。
早速ではございますが下記書類をお送りします。御査収の上よろしく御取計らいの程お願い申し上げます。';
}
?>
<p><?php echo nl2br( wp_kses_post( $message ) ); ?></p>
<p class="text-right">敬具</p>

<h4 class="text-center">記</h4>
<div class="client-doc-content">
<?php echo apply_filters( 'the_content', get_post_meta( $post->ID, 'client_doc_content', true ) ); ?>
</div>
<p>以上よろしくお願いいたします。</p>
</div><!-- [ /.container ] -->
</div><!-- [ /.bill-wrap ] -->
