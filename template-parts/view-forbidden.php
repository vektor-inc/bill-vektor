<?php
/**
 * 書類の閲覧権限が無いログインユーザーに表示する 403 のページ
 *
 * bill_render_forbidden_page() から読み込まれる。
 * 権限不足の原因はほとんどが「別のアカウントでログインしたままだった」ことのため、
 * ログイン中のアカウント名とロール名を明示したうえで、ログインし直す導線を置く。
 *
 * サイドバー（カテゴリー名を出力する）とパンくず（書類の件名を出力する）は、
 * 閲覧を塞いだはずの情報が漏れてしまうため、このページでは読み込まない。
 */

$bill_current_user = wp_get_current_user();
$bill_role_label   = bill_get_user_role_label( $bill_current_user );

// ログインし直すための URL。
// wp_login_url() へ直接飛ばすと、ログイン Cookie を持ったまま wp-login.php が
// redirect_to へ戻すため無限リダイレクトになる。必ずログアウトを挟む。
$bill_relogin_url = wp_logout_url( wp_login_url( home_url( '/' ) ) );
?>
<div class="container">
	<div class="row">
		<div class="col-md-12">
			<div class="section">

				<!-- 見出しのサイズと余白を他のページと揃えるため、index.php と同じ .page-header で囲む -->
				<header class="page-header">
					<h1><?php esc_html_e( 'この画面を表示する権限がありません', 'bill-vektor' ); ?></h1>
				</header>

				<!-- 「自分が今どの状態なのか」を最初に示す（理由と行動はその後に続ける） -->
				<p>
				<?php
				if ( $bill_role_label ) {
					printf(
						/* translators: 1: ログイン中のユーザーの表示名, 2: ログイン中のユーザーのロール名 */
						esc_html__( '現在 %1$s（%2$s）としてログインしています。', 'bill-vektor' ),
						esc_html( $bill_current_user->display_name ),
						esc_html( $bill_role_label )
					);
				} else {
					printf(
						/* translators: %s: ログイン中のユーザーの表示名 */
						esc_html__( '現在 %s としてログインしています。', 'bill-vektor' ),
						esc_html( $bill_current_user->display_name )
					);
				}
				?>
				</p>

				<?php
				// 必要な権限は「投稿」ではなくロール名で伝える。
				// この製品は管理画面の「投稿」を「請求書」に置換してユーザーから隠しているため、
				// この画面だけ「投稿」と表示すると一貫しない。
				?>
				<p><?php esc_html_e( '請求書・見積書などの書類を閲覧するには、書類を作成・編集できる権限（寄稿者以上）が必要です。', 'bill-vektor' ); ?></p>

				<?php
				// 権限を持つアカウントを持っている人・持っていない人の両方に次の一手を示す。
				// この画面には管理バーが出ないため、出口はこのページ内で揃えきる必要がある。
				?>
				<p>
					<?php esc_html_e( '書類を閲覧できるアカウントをお持ちの場合は、ログアウトして、そのアカウントでログインし直してください。', 'bill-vektor' ); ?>
					<?php esc_html_e( 'お持ちでない場合は、サイトの管理者に権限の変更を依頼してください。', 'bill-vektor' ); ?>
				</p>

				<?php
				// Bootstrap は a 要素の下線を消すため、リンクのままだと色だけで本文と区別されてしまう。
				// この画面唯一の出口なので、テーマ既存のボタン様式に合わせる。
				// 押すと現在のセッションが切れることが分かる文言にする。
				?>
				<p><a class="btn btn-primary" href="<?php echo esc_url( $bill_relogin_url ); ?>"><?php esc_html_e( 'ログアウトして別のアカウントでログインする', 'bill-vektor' ); ?></a></p>

			</div><!-- [ /.section ] -->
		</div>
	</div>
</div>
