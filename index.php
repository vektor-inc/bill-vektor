<?php get_header(); ?>

<?php $page_post_type = bill_get_post_type(); ?>

<?php get_template_part( 'template-parts/breadcrumb' ); ?>

  <div class="container">
	<div class="row">

		<?php get_sidebar(); ?>

	  <!-- [ #main ] -->
	  <div id="main" class="col-md-9">
	  <!-- [ 記事のループ ] -->

<?php if ( is_front_page() || is_archive() || is_tax() ) { ?>

<form action="" method="get">

<div class="section" id="search-box">
	<?php get_template_part( 'template-parts/search-box' ); ?>
</div>

	<?php $post_type = bill_get_post_type(); ?>

<div class="section">
	<?php if ( have_posts() ) { ?>
<table class="table table-striped table-borderd">
<tr>
<th>書類</th>
		<?php if ( $page_post_type['slug'] != 'client' ) { ?>
<th>発行日</th>
<?php } ?>

		<?php if ( $post_type['slug'] != 'salary' ) { ?>
<th>取引先</th>
<?php } ?>

		<?php if ( $page_post_type['slug'] != 'client' ) { ?>
	<th>件名</th>
			<?php if ( $post_type['slug'] != 'salary' ) { ?>
		<th>カテゴリー</th>
	<?php } elseif ( $post_type['slug'] == 'salary' ) { ?>
		<th>支給分</th>
	<?php } ?>
<?php } ?>
</tr>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
<tr>
<!-- [ 書類 ] -->
<td class="text-nowrap">
			<?php
			$post_type = bill_get_post_type();
			$post_type_slug = get_post_type();
			$post_type_object = get_post_type_object( $post_type_slug );
			echo '<a href="' . esc_url( get_post_type_archive_link( 'url' ) ) . '">' . esc_html( $post_type_object->labels->name ) . '</a>';
			?>
</td>

			<?php if ( $page_post_type['slug'] != 'client' ) { ?>
<!-- [ 発行日 ] -->
<td><?php echo esc_html( get_the_date( 'Y.m.d' ) ); ?></td>
<?php } ?>

			<?php if ( $post_type['slug'] != 'salary' ) { ?>
<!-- [ 取引先 ] -->
<td class="text-nowrap">
				<?php
				// 取引先（イレギュラー）も無加工の $_POST が保存されるため、文字列以外は未入力として扱う
				$client_name_manual = is_scalar( $post->bill_client_name_manual ) ? (string) $post->bill_client_name_manual : '';

				if ( 'client' === $page_post_type['slug'] ) {
					/*
					 * 取引先一覧ではこのカラムの行そのものが取引先なので、自身の名前を表示する。
					 * 従来は get_the_title() がグローバルの $post を参照することで
					 * 結果的にこの表示になっていたが、書類側の不具合修正でその経路が
					 * 塞がるため、意図した表示としてここで明示する。
					 */
					$client_name = (string) get_the_title();

					if ( '' !== $client_name ) {
						echo '<a href="' . esc_url( get_the_permalink() ) . '" target="_blank">' . esc_html( $client_name ) . '</a>';
					} else {
						/*
						 * 無題で保存された取引先はリンクの文字列が無く、
						 * そのままだとアクセシブルネームの無い空のリンクになる。
						 * 名前を直しに行く導線を残すためリンク自体は維持し、
						 * ダッシュと代替テキストでリンク先を説明する。
						 * 代替テキストはリンク先の説明になるため、書類側の「取引先なし」
						 * （値が無いという状態の説明）とは異なる文言にしている。
						 */
						echo '<a href="' . esc_url( get_the_permalink() ) . '" target="_blank"><span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( '名称未設定の取引先', 'bill-vektor' ) . '</span></a>';
					}
				} elseif ( '' !== $client_name_manual ) {
					echo esc_html( $client_name_manual );
				} else {
					/*
					 * 取引先（登録済）のIDと表示名（省略名があれば省略名）は共通関数に委譲する。
					 * IDの検証・省略名の有無による出し分けをこの箇所に重複させないため、
					 * CSVエクスポートと同じ関数を使う。
					 */
					$client_id   = bill_get_client_id( $post );
					$client_name = bill_get_client_short_name( $post );

					if ( $client_id && '' !== $client_name ) {
						// 取引先（登録済）が特定できている場合のみ取引先ページへのリンクにする
						echo '<a href="' . esc_url( get_the_permalink( $client_id ) ) . '" target="_blank">' . esc_html( $client_name ) . '</a>';
					} else {
						/*
						 * 取引先が未設定の場合はダッシュを表示する。
						 * この一覧は罫線が無く、空セルだと値が無いのか列がずれているのか
						 * 判別できないため、値が無いことを明示する。
						 * 代替テキストのクラスは、管理画面の取引先カラムと同じ
						 * .screen-reader-text に揃える（このテーマの .screen-reader-text は
						 * display:none で支援技術からも消えてしまっていたため、
						 * 視覚的非表示になるよう assets/_scss/style.scss を修正済み）。
						 */
						echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( '取引先なし', 'bill-vektor' ) . '</span>';
					}
				}
				?>
</td>
<?php } ?>

			<?php if ( $page_post_type['slug'] != 'client' ) { ?>

<!-- [ 件名 ] -->
<td><a href="<?php the_permalink(); ?>" target="_blank"><?php the_title(); ?></a></td>
<!-- [ カテゴリー ] -->
<td><?php echo bill_get_terms(); ?></td>

<?php } ?>

</tr>
<?php endwhile; ?>
</table>
		<?php the_posts_pagination(); ?>
		<?php
	} else {
		echo '<p>該当の書類はありません。</p>';
	} // if ( have_posts() ) {
	?>
</div>

<div id="news" class="section">
<h3>お知らせ</h3>
<ul class="post-list" id="newsEntries">
	<?php
	$rss     = 'https://billvektor.com/feed/';
	$content = wp_safe_remote_get( $rss );
	if ( ! isset( $content->errors ) ) {
		$count = 0;
		if ( $content['response']['code'] != 200 ) {
			return;
		}
		$xml = @simplexml_load_string( $content['body'] );
		foreach ( $xml->channel->item as $entry ) {
			$rss_date = $entry->pubDate;
			date_default_timezone_set( 'Asia/Tokyo' );
			$post_date = strtotime( $rss_date );
			echo '<li>';
			echo '<span class="post-date">' . date( 'Y.m.d', $post_date ) . '</span>';
			echo '<span class="post-cate">' . esc_html( $entry->category ) . '</span>';
			echo '<span class="post-title"><a href="' . esc_url( $entry->link ) . '?rel=rss" target="_blank">' . esc_html( $entry->title ) . '</a></span>';
			echo '</li>';
			$count++;
			if ( $count > 4 ) {
				break; }
		}
	} else {
		echo '<p>お知らせの取得に失敗しました。</p>';
	}// if ( !isset( $content->errors ) ) {
	?>
</ul>
</div>

<?php
// エクスポートは編集権限を持つユーザーのみ実行できるため、権限がない場合はボックス自体を出力しない
// （押しても何も起きないボタンを見せないようにする）
// CsvExport::can_export() は nonce 不正時に wp_nonce_ays() で処理を止めるため、表示判定には使わない
if ( current_user_can( 'edit_posts' ) ) {
	?>
<div id="csv-export" class="section">
	<?php get_template_part( 'template-parts/export-box' ); ?>
</div>
	<?php
}
?>

</form>

<?php } else { ?>

	<?php if ( have_posts() ) { ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
	 <article class="section">
	  <header class="page-header">
	  <h1><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1>
	  <div class="wck_post_meta">
	  <span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?php the_date(); ?>　
	  <span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span> <?php the_category( ',' ); ?>
	  </div>
	  </header>
	  <div>
	  <!-- [ 記事の本文 ] -->
			<?php the_content(); ?>
	  <!-- [ /記事の本文 ] -->
	  </div>
	</article>
	<?php endwhile; ?>
	<?php } // if ( have_posts() ) { ?>

<?php } ?>

	  <!-- [ /記事のループ ] -->
	  </div>
	  <!-- [ /#main ] -->

	</div>
</div>

<?php get_footer(); ?>
