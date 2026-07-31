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

				<h1><?php esc_html_e( 'この画面を表示する権限がありません', 'bill-vektor' ); ?></h1>

				<p><?php esc_html_e( '請求書・見積書の閲覧には、投稿を編集できる権限が必要です。', 'bill-vektor' ); ?></p>

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

				<p><?php esc_html_e( '書類を閲覧できるアカウントをお持ちの場合は、そのアカウントでログインし直してください。', 'bill-vektor' ); ?></p>

				<p><a href="<?php echo esc_url( $bill_relogin_url ); ?>"><?php esc_html_e( '別のアカウントでログインする', 'bill-vektor' ); ?></a></p>

			</div><!-- [ /.section ] -->
		</div>
	</div>
</div>
