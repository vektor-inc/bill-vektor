<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head();?>
</head>
<body>
<div class="bill-wrap">
<?php if ( have_posts() ) { ?>
<?php while( have_posts() ) : the_post(); ?>

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
<th>No</th>
<td><?php echo esc_html( $post->bill_id ); ?></td>
</tr>
<tr>
<th>発行日</th>
<td><?php the_date(); ?></td>
</tr>
<tr>
<th>お支払期日</th>
<td><?php echo esc_html( $post->bill_limit ); ?></td>
</tr>
</table>

<div class="bill-address-own">
<?php $options = get_option('bill-setting', bill_options_default());?>
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

<?php if ( get_field('bill-items') ) : ?>
<?php while ( has_sub_field('bill-items') ) : ?>
<tr>
<?php
$item_count = intval( esc_html( get_sub_field('item-count') ) );
$item_price = intval( esc_html( get_sub_field('item-price') ) );
?>
<td><?php echo esc_html( get_sub_field('item-name') );?></td>
<td class="text-center"><?php echo $item_count ;?></td>
<td class="text-center"><?php echo esc_html( get_sub_field('item-unit') );?></td>
<td class="text-right yen"><?php echo number_format( $item_price );?></td>
<td class="text-right yen"><?php echo number_format( $item_count * $item_price );?></td>
</tr>
<?php endwhile; ?>
<?php endif; ?>

</tbody>
<tfoot>
<tr><th colspan="4">小計</th><td class="text-right yen">29,000</td></tr>
<tr><th colspan="4">消費税</th><td class="text-right yen">400</td></tr>
<tr><th colspan="4">合計金額</th><td class="text-right yen">300,000</td></tr>
</tfoot>
</table>

<?php endwhile; ?>
<?php } ?>

<dl class="bill-remarks">
<dt>備考</dt>
<dd><?php echo apply_filters('the_content', $options['remarks'] );?></dd>
</dl

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


</div>
<?php wp_footer();?>
</body>
</html>