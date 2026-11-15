<?php
/**
 * Displays the wizard
 *
 * @package Strope-Translate
 *
 * @since 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}
$admin_body_class = array( 'strope-wizard', 'wp-core-ui' );
if ( is_rtl() ) {
	$admin_body_class[] = 'rtl';
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta name="viewport" content="width=device-width" />
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>
		<?php
		printf(
			/* translators: %s is the plugin name */
			esc_html__( '%s &rsaquo; Setup', 'strope-translate' ),
			esc_html( STROPE_TRANSLATE )
		);
		?>
		</title>
		<script>
			var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php', 'relative' ) ); ?>';
		</script>
		<?php do_action( 'admin_enqueue_scripts' ); ?>
		<?php wp_print_scripts( $this->steps[ $this->step ]['scripts'] ); ?>
		<?php wp_print_styles( array_merge( $this->styles, $this->steps[ $this->step ]['styles'] ) ); ?>
		<?php do_action( 'admin_head' ); ?>
	</head>
	<body class="<?php echo join( ' ', array_map( 'sanitize_key', $admin_body_class ) ); ?>">
		<h1 id="strope-logo">
			<img class="strope-emblem" src="<?php echo esc_url( plugins_url( 'modules/wizard/images/strope-emblem.svg', STROPE_TRANSLATE_FILE ) ); ?>" alt="" width="54" height="48" />
			<span>
				<span class="title"><?php echo esc_html( STROPE_TRANSLATE ); ?></span>
				<span class="strope-eyebrow"><?php esc_html_e( 'Setup', 'strope-translate' ); ?></span>
			</span>
		</h1>
		<ol class="strope-wizard-steps">
			<?php
			foreach ( $this->steps as $step_key => $step ) {
				$is_completed = array_search( $this->step, array_keys( $this->steps ), true ) > array_search( $step_key, array_keys( $this->steps ), true );

				if ( $step_key === $this->step ) {
					?>
					<li class="active"><?php echo esc_html( $step['name'] ); ?></li>
					<?php
				} elseif ( $is_completed ) {
					?>
					<li class="done">
						<a
							href="<?php echo esc_url( add_query_arg( 'step', $step_key, remove_query_arg( 'activate_error' ) ) ); ?>"
						>
							<?php echo esc_html( $step['name'] ); ?>
						</a>
					</li>
					<?php
				} else {
					?>
					<li><?php echo esc_html( $step['name'] ); ?></li>
					<?php
				}
			}
			?>
		</ol>
		<div class="strope-wizard-content">
			<form method="post" class="<?php echo esc_attr( "{$this->step}-step" ); ?>">
				<?php
				wp_nonce_field( 'strope-wizard', '_strope_nonce' );

				if ( ! empty( $this->steps[ $this->step ]['view'] ) ) {
					call_user_func( $this->steps[ $this->step ]['view'], $this );
				}
				?>
				<?php if ( 'last' !== $this->step ) : ?>
				<p class="strope-wizard-actions step">
					<button
						type="submit"
						class="button-primary button button-large button-next"
						value="continue"
						name="save_step"
					>
						<?php esc_html_e( 'Continue', 'strope-translate' ); ?><span class="dashicons dashicons-arrow-right-alt2"></span>
					</button>
				</p>
				<?php endif; ?>
			</form>
		</div>
		<div class="strope-wizard-footer">
			<?php if ( 'last' !== $this->step ) : ?>
				<a
					class="strope-wizard-footer-links"
					href="<?php echo esc_url( admin_url() ); ?>"
				>
					<?php esc_html_e( 'Not right now', 'strope-translate' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</body>
</html>
