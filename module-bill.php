


<div class="container">
<div class="row">
<div class="col-xs-6">
<h1 class="bill-title">御請求書</h1>
<h2 class="bill-destination"><?php echo esc_html( get_the_title( $post->bill_client ) );?></h2>

<p>平素は格別のご高配に賜り、誠にありがとう御座います。<br>
下記の通りご請求申し上げます。</p>

<dl class="bill-total">
<dt>合計金額</dt>
<dd>￥ 2,000,000 <span class="caption">(消費税含)</span></dd>
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
<td><?php echo esc_html( date("Y年n月j", bill_raw_date( $post->bill_limit_date) ) ); ?></td>
</tr>
</table>

<div class="bill-address-own">
<?php $options = get_option('bill-setting', Bill_Admin::options_default());?>
<h4><?php echo esc_html( $options['own-name'] );?></h4>
<div class="bill-address"><?php echo nl2br( esc_textarea($options['own-address']) );?></div>
<?php
if ( isset( $options['own-seal'] ) && $options['own-seal'] ){
	$attr = array(
		'id'    => 'bill-seal',
		'src'   => '',
		'class' => 'bill-seal',
		'alt'   => trim( strip_tags( get_post_meta( $options['own-seal'], '_wp_attachment_image_alt', true ) ) ),
	);
	echo wp_get_attachment_image( $options['own-seal'], 'medium', false, $attr );
} ?>
</div><!-- [ /.address-own ] -->

</div><!-- [ /.container ] -->


<div class="container">

<table class="table table-bordered bill-table">
<thead>
<tr class="active">
<th class="text-center">商品名</th>
<th class="text-center">数量</th>
<th class="text-center">単位</th>
<th class="text-center">商品単価</th>
<th class="text-center">金額</th>
</tr>
</thead>
<tbody>
<?php 
$bill_items = get_post_meta( $post->ID, 'bill_items', true );
$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );
$item_total = 0;
// 行のループ
foreach ($bill_items as $key => $value) { ?>

	<tr>
	<?php
	$item_count = intval( esc_html( $bill_items[$key]['count'] ) );
	$item_price = intval( esc_html( $bill_items[$key]['price'] ) );
	?>
	<td><?php echo esc_html( $bill_items[$key]['name'] );?></td>
	<td class="text-center"><?php echo $item_count ;?></td>
	<td class="text-center"><?php echo esc_html( $bill_items[$key]['unit'] );?></td>
	<td class="text-right yen"><?php echo number_format( $item_price );?></td>
	<td class="text-right yen"><?php echo number_format( $item_count * $item_price );?></td>
	</tr>

	<?php 
	$item_total += $item_count * $item_price;
} // foreach ($bill_items as $key => $value) {


$tax = round( $item_total * 0.08 );
$bill_total = $item_total + $tax;
?>
</tbody>
<tfoot>
<tr><th colspan="4">小計</th><td class="text-right yen"><?php echo number_format( $item_total );?></td></tr>
<tr><th colspan="4">消費税</th><td class="text-right yen"><?php echo number_format( $tax );?></td></tr>
<tr><th colspan="4">合計金額</th><td class="text-right yen"><?php echo number_format( $bill_total );?></td></tr>
</tfoot>
</table>

<dl class="bill-remarks">
<dt>備考</dt>
<dd>
<?php
if ( $post->bill_remarks ){
	// 請求書個別の備考
	echo apply_filters('the_content', $post->bill_remarks );
} else {
	// 共通の備考
	echo apply_filters('the_content', $options['remarks'] );
} ?>
</dd>
</dl>

<div class="bill-payee">
<table class="table table-bordered">
<tr>
<th class="active">振込口座</th>
<td >
<p class="bill-payee-text">
<?php echo nl2br( esc_textarea($options['own-payee']) );?>
</p>
<?php
if ( isset( $options['own-logo'] ) && $options['own-logo'] ){
	$attr = array(
		'id'    => 'bill-payee-logo',
		'src'   => '',
		'class' => 'bill-payee-logo',
		'alt'   => trim( strip_tags( get_post_meta( $options['own-logo'], '_wp_attachment_image_alt', true ) ) ),
	);
	echo wp_get_attachment_image( $options['own-logo'], 'medium', false, $attr );
} ?>
</td>
</tr>
</table>
</div><!-- [ /.bill-payee ] -->
</div><!-- [ /.container ] -->