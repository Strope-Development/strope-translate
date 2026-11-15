<?php
/**
 * Displays the wizard last step
 *
 * @package Strope-Translate
 *
 * @since 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

?>
<h2><?php esc_html_e( "You're ready to translate your contents!", 'strope-translate' ); ?></h2>


<ul class="strope-wizard-next-steps">
	<li class="strope-wizard-next-step-item">
		<div class="strope-wizard-next-step-description">
			<p class="next-step-heading"><?php esc_html_e( 'Next step', 'strope-translate' ); ?></p>
			<h3 class="next-step-description"><?php esc_html_e( 'Create menus', 'strope-translate' ); ?></h3>
			<p class="next-step-extra-info">
				<?php esc_html_e( 'To get your website ready, there are still two steps you need to perform manually: add menus in each language, and add a language switcher to allow your visitors to select their preferred language.', 'strope-translate' ); ?>
			</p>
		</div>
		<div class="strope-wizard-next-step-action">
			<p class="strope-wizard-actions step">
				<a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>">
					<?php esc_html_e( 'Edit menus', 'strope-translate' ); ?>
				</a>
			</p>
		</div>
	</li>
	<li class="strope-wizard-next-step-item">
		<div class="strope-wizard-next-step-description">
			<p class="next-step-heading"><?php esc_html_e( 'Next step', 'strope-translate' ); ?></p>
			<h3 class="next-step-description"><?php esc_html_e( 'Translate some pages', 'strope-translate' ); ?></h3>
			<p class="next-step-extra-info"><?php esc_html_e( "You're ready to translate the posts on your website.", 'strope-translate' ); ?></p>
		</div>
		<div class="strope-wizard-next-step-action">
			<p class="strope-wizard-actions step">
				<a class="button button-large" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>">
					<?php esc_html_e( 'View pages', 'strope-translate' ); ?>
				</a>
			</p>
		</div>
	</li>
	<li class="strope-wizard-additional-steps">
		<div class="strope-wizard-next-step-action">
			<p class="strope-wizard-actions step">
				<a class="button button-large" href="<?php echo esc_url( admin_url() ); ?>">
					<?php esc_html_e( 'Return to the Dashboard', 'strope-translate' ); ?>
				</a>
			</p>
		</div>
	</li>
</ul>
