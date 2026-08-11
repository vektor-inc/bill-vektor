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
	<?php
	/*
	 * 「書類」列のリンク判定に使う、現在の一覧が単一の投稿タイプに絞り込まれているかどうか。
	 * 請求書一覧・見積書一覧・取引先一覧などでは行ごとに毎回同じ結果になるため、
	 * ループの外で1回だけ算出する。
	 */
	$single_list_post_type = bill_get_single_post_type_slug();
	?>

<div class="section">
	<?php
	if ( have_posts() ) {
		/*
		 * issue #310 レビュー対応: 件名列の予告アイコン（.glyphicon-new-window）が原因で
		 * 375px 幅にてページ全体の scrollWidth が溢れる回帰が起きたため、テーブルを
		 * Bootstrap 3 純正の .table-responsive（overflow-x: auto 等）で囲み、
		 * ページ全体ではなくテーブル内だけがスクロールするようにする。
		 * 767px以下で全セルに強制される white-space: nowrap は「件名」「カテゴリー」列の
		 * 折り返しを潰してしまうため、text-nowrap の付いていないセルだけ normal に戻す
		 * 指定を assets/_scss/style.scss に追加している（そちらにも詳細コメントあり）。
		 *
		 * tabindex="0" + role="region" + aria-label はキーボード操作者がこのスクロール領域に
		 * 直接フォーカスして到達できるようにするため付与する。カテゴリー列（5列目）は
		 * bill_get_terms() がタームなしの行では空を返すためリンクを持つとは限らず、
		 * カテゴリー未設定の行では行内リンクへの Tab 移動だけでは最右列に到達できない。
		 * 増えるタブストップはページ内で1つだけであり、読めない列が残るリスクの方が
		 * 重いという判断（植草さんの方針）。このコメントは、次にこの tabindex を
		 * 「不要なもの」として消さないための記録も兼ねる。
		 */
		?>
<?php
/*
 * aria-label 用のラベルを組み立てる。
 * - $page_post_type['name']（bill_get_post_type() の戻り値）は既に esc_html() 済みのため、
 *   ここで esc_attr() を重ねると二重エスケープになる（& を含むラベルだと &amp;amp; になり
 *   読み上げが不自然になる）。get_post_type_object() から素の値を取り直し、esc_attr() を
 *   1回だけ通す。bill_get_post_type() 自体は他の判定にも広く使われているため変更しない。
 * - フロントページ（$single_list_post_type が空文字＝請求書・見積書が混在する一覧）では
 *   単一の投稿タイプのラベル（例:「請求書」）を出すと、見積書も混ざった表を
 *   誤って案内することになるため、中立な「書類」を使う。
 */
$aria_label_post_type_object = get_post_type_object( $page_post_type['slug'] );
if ( '' !== $single_list_post_type && $aria_label_post_type_object ) {
	$table_aria_label = sprintf( __( '%s一覧の表', 'bill-vektor' ), $aria_label_post_type_object->labels->name );
} else {
	$table_aria_label = __( '書類一覧の表', 'bill-vektor' );
}
?>
<div class="table-responsive" tabindex="0" role="region" aria-label="<?php echo esc_attr( $table_aria_label ); ?>">
<table class="table table-striped table-borderd">
<tr>
<th scope="col">書類</th>
		<?php if ( $page_post_type['slug'] != 'client' ) { ?>
<th scope="col">発行日</th>
<?php } ?>

		<?php if ( $post_type['slug'] != 'salary' ) { ?>
<th scope="col">取引先</th>
<?php } ?>

		<?php if ( $page_post_type['slug'] != 'client' ) { ?>
	<th scope="col">件名</th>
			<?php if ( $post_type['slug'] != 'salary' ) { ?>
		<th scope="col">カテゴリー</th>
	<?php } elseif ( $post_type['slug'] == 'salary' ) { ?>
		<th scope="col">支給分</th>
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
			$post_type      = bill_get_post_type();
			$post_type_slug = get_post_type();
			// セル内容（テキストかリンクか、ラベル・URLの組み立て）は
			// PHPUnit で検証できるよう inc/template-tags.php 側の関数に委譲する。
			echo bill_get_document_type_column( $post_type_slug, $single_list_post_type );
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
						/*
						 * target="_blank" で別タブに遷移することを予告する。
						 * アイコンは aria-hidden で読み上げに乗せず、screen-reader-text は
						 * 画面拡大利用者には届かないため、両方を併用する（issue #310）。
						 * 予告のマークアップは bill_get_new_window_notice() に集約している。
						 * rel="noopener" は別タブ側から window.opener 経由で元のタブを
						 * 操作されるのを防ぐために付与する。
						 */
						echo '<a href="' . esc_url( get_the_permalink() ) . '" target="_blank" rel="noopener">' . esc_html( $client_name ) . bill_get_new_window_notice() . '</a>';
					} else {
						/*
						 * 無題で保存された取引先はリンクの文字列が無く、
						 * そのままだとアクセシブルネームの無い空のリンクになる。
						 * 名前を直しに行く導線を残すためリンク自体は維持し、
						 * ダッシュと代替テキストでリンク先を説明する。
						 * 代替テキストはリンク先の説明になるため、書類側の「取引先なし」
						 * （値が無いという状態の説明）とは異なる文言にしている。
						 * アイコンはダッシュの直後に置き、可視のダッシュ用spanとは別に aria-hidden で
						 * 読み上げから除外する（bill_get_new_window_icon() を直接使う）。
						 * screen-reader-text の文言は「名称未設定の取引先」の翻訳文字列と
						 * bill_get_new_window_notice_text() の予告文言をPHP側で連結し、span は
						 * 増やさない（リンクの読み上げ名は中の文字列を全て連結するため、span を
						 * 分けると区切りのない2文になり意味の切れ目が分からなくなるのを避ける）。
						 * 翻訳関数に複数の意味単位を入れないため、既存の「名称未設定の取引先」の
						 * 翻訳文字列はそのまま保ち、予告文言側は他3箇所と同じ文字列を再利用する。
						 */
						echo '<a href="' . esc_url( get_the_permalink() ) . '" target="_blank" rel="noopener"><span aria-hidden="true">&#8212;</span>' . bill_get_new_window_icon() . '<span class="screen-reader-text">' . esc_html__( '名称未設定の取引先', 'bill-vektor' ) . bill_get_new_window_notice_text() . '</span></a>';
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
						/*
						 * 取引先（登録済）が特定できている場合のみ取引先ページへのリンクにする。
						 * target="_blank" の予告・rel="noopener" の考え方は上の $client_name と同じ（issue #310）。
						 */
						echo '<a href="' . esc_url( get_the_permalink( $client_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $client_name ) . bill_get_new_window_notice() . '</a>';
					} else {
						/*
						 * 取引先が未設定の場合はダッシュを表示する。
						 * この一覧は罫線が無く、空セルだと値が無いのか列がずれているのか
						 * 判別できないため、値が無いことを明示する。
						 * 代替テキストのクラスは、管理画面の取引先カラムと同じ .screen-reader-text に揃える。
						 * このテーマの .screen-reader-text は assets/_scss/style.scss で
						 * 視覚的非表示（clip 方式）として定義しており、支援技術からは読み上げられる。
						 */
						echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( '取引先なし', 'bill-vektor' ) . '</span>';
					}
				}
				?>
</td>
<?php } ?>

			<?php if ( $page_post_type['slug'] != 'client' ) { ?>

<!-- [ 件名 ] -->
<td><a href="<?php the_permalink(); ?>" target="_blank" rel="noopener"><?php
	/*
	 * the_title() は未エスケープのため、unfiltered_html 権限を持つ管理者が件名に
	 * 未閉じタグ等を入れると、直後に連結した予告マークアップが飲み込まれる／DOM が壊れる
	 * おそれがある。他4箇所と同じく esc_html() を通す（issue #310 レビュー対応）。
	 */
	echo esc_html( get_the_title() ) . bill_get_new_window_notice();
?></a></td>
<!-- [ カテゴリー ] -->
<td><?php echo bill_get_terms(); ?></td>

<?php } ?>

</tr>
<?php endwhile; ?>
</table>
</div>
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

			/*
			 * RSS由来の $entry->title は外部入力のため esc_html() で個別にエスケープし、
			 * 追加するアイコン・screen-reader-text のマークアップ（bill_get_new_window_notice()）は
			 * esc_html() の外側（連結する側）に置いて外部文字列に混ぜ込まない。お知らせは
			 * billvektor.com という外部サイトへのリンクなので、内部リンク（取引先・件名）とは
			 * 異なる文言（$is_external = true）で「外部サイトが新しいタブで開く」ことを予告する（issue #310）。
			 *
			 * リンク先URLは add_query_arg() でクエリーを連結する。文字列連結（'?rel=rss'）だと
			 * フィード側の link が既にクエリーを持つ場合に "...?p=1?rel=rss" という壊れたURLになるため。
			 */
			$entry_url = add_query_arg( 'rel', 'rss', (string) $entry->link );
			echo '<span class="post-title"><a href="' . esc_url( $entry_url ) . '" target="_blank" rel="noopener">' . esc_html( $entry->title ) . bill_get_new_window_notice( true ) . '</a></span>';
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
