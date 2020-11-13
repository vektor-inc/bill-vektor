<div class="bill-wrap">
<div class="container">
<div class="row">
<div class="col-xs-6">
<h1 class="bill-title">御請求書</h1>
<h2 class="bill-destination">
<span class="bill-destination-client">
<?php echo esc_html( bill_get_client_name( $post ) ); ?>
</span>
<span class="bill-destination-honorific">
<?php
$client_honorific = esc_html( get_post_meta( $post->bill_client, 'client_honorific', true ) );
if ( $client_honorific ) {
	echo $client_honorific;
} else {
	echo '御中';
}
?>
</span>
</h2>

<p class="bill-message">平素は格別のご高配に賜り、誠にありがとう御座います。<br>
下記の通りご請求申し上げます。</p>

<dl class="bill-total">
<dt>合計金額</dt>
<?php
global $post;
if ( isset( $post->bill_tax_type ) && $post->bill_tax_type == 'tax_not_auto' ) {
	$bill_total = bill_total_no_tax( $post );
} else {
	$bill_total = bill_total_add_tax( $post );
}
?>
<dd id="bill-frame-total-price">￥ <?php echo number_format( $bill_total ); ?><span class="caption">(消費税含)</span></dd>
</dl>
</div>

<div class="col-xs-5 col-xs-offset-1">
<table class="bill-info-table">
<tr>
<th>請求番号</th>
<td><?php echo esc_html( $post->bill_id ); ?></td>
</tr>
<tr>
<th>発行日</th>
<td><?php the_date(); ?></td>
</tr>
<tr>
<th>お支払期日</th>
<td><?php echo esc_html( date( 'Y年n月j日', bill_raw_date( $post->bill_limit_date ) ) ); ?></td>
</tr>
</table>

<div class="bill-address-own">
<?php $options = get_option( 'bill-setting', Bill_Admin::options_default() ); ?>
<h4><?php echo nl2br( esc_textarea( $options['own-name'] ) ); ?></h4>
<div class="bill-address"><?php echo nl2br( esc_textarea( $options['own-address'] ) ); ?></div>
<?php
if ( isset( $options['own-seal'] ) && $options['own-seal'] ) {
	$attr = array(
		'id'    => 'bill-seal',
		'class' => 'bill-seal',
		'alt'   => trim( strip_tags( get_post_meta( $options['own-seal'], '_wp_attachment_image_alt', true ) ) ),
	);
	echo wp_get_attachment_image( $options['own-seal'], 'medium', false, $attr );
}
?>
</div><!-- [ /.address-own ] -->
</div><!-- [ /.col-xs-5 col-xs-offset-1 ] -->
</div><!-- [ /.row ] -->
</div><!-- [ /.container ] -->


<div class="container">

<?php get_template_part( 'template-parts/doc/table-price' ); ?>

<dl class="bill-remarks">
<dt>備考</dt>
<dd>
<?php
if ( $post->bill_remarks ) {
	// 請求書個別の備考
	echo apply_filters( 'the_content', $post->bill_remarks );
} else {
	// 共通の備考
	if ( isset( $options['remarks-bill'] ) ) {
		echo apply_filters( 'the_content', $options['remarks-bill'] );
	}
}
?>
</dd>
</dl>

<div class="bill-payee">
<table class="table table-bordered">
<tr>
<th class="active">振込口座</th>
<td >
<p class="bill-payee-text">
<?php echo nl2br( esc_textarea( $options['own-payee'] ) ); ?>
</p>
<?php
if ( isset( $options['own-logo'] ) && $options['own-logo'] ) {
	$attr = array(
		'id'    => 'bill-payee-logo',
		'class' => 'bill-payee-logo',
		'alt'   => trim( strip_tags( get_post_meta( $options['own-logo'], '_wp_attachment_image_alt', true ) ) ),
	);
	echo wp_get_attachment_image( $options['own-logo'], 'medium', false, $attr );
}
?>
</td>
</tr>
</table>
</div><!-- [ /.bill-payee ] -->
</div><!-- [ /.container ] -->
</div><!-- [ /.bill-wrap ] -->
