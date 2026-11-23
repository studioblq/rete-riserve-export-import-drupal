<?php
/**
 * Plugin Name: Readabler Accessibility
 * Plugin URI:  https://www.area051.com
 * Description: Integra il widget di accessibilità Readabler con un interruttore per attivarlo/disattivarlo.
 * Version:     1.1.2
 * Author:      area051.com
 * Author URI:  https://area051.com
 * Update URI: https://github.com/studioblq/rete-riserve-export-import-drupal
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: readabler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/lib/class-area051-github-updater.php';
new Area051_GitHub_Updater_V2(__FILE__, 'readabler');

define( 'READABLER_VERSION', '1.1.2' );
define( 'READABLER_DIR', plugin_dir_path( __FILE__ ) );
define( 'READABLER_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Enqueue assets sul frontend (non nell'area admin)
// ---------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'readabler_enqueue_assets' );

function readabler_enqueue_assets() {
	if ( ! get_option( 'readabler_enabled', 0 ) ) {
		return;
	}

	wp_enqueue_style(
		'readabler-css',
		READABLER_URL . 'assets/css/readabler.min.css',
		[],
		READABLER_VERSION
	);

	wp_enqueue_script(
		'readabler-js',
		READABLER_URL . 'assets/js/readabler.min.js',
		[],
		READABLER_VERSION,
		true
	);

	wp_enqueue_script(
		'readabler-init',
		READABLER_URL . 'js/readabler-init.js',
		[ 'readabler-js' ],
		READABLER_VERSION,
		true
	);

	// Passa il percorso degli asset e le impostazioni al JS.
	wp_localize_script( 'readabler-init', 'readablerSettings', [
		'path'           => READABLER_URL . 'assets/',
		'buttonPosition' => get_option( 'readabler_position', 'bottom-right' ),
	] );
}

// ---------------------------------------------------------------------------
// Pagina di impostazioni nell'admin WordPress
// ---------------------------------------------------------------------------
add_action( 'admin_menu', 'readabler_add_settings_page' );

function readabler_add_settings_page() {
	add_options_page(
		__( 'Readabler Accessibility', 'readabler' ),
		__( 'Readabler', 'readabler' ),
		'manage_options',
		'readabler',
		'readabler_render_settings_page'
	);
}

function readabler_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Salvataggio.
	if ( isset( $_POST['readabler_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['readabler_nonce'] ) ), 'readabler_save_settings' ) ) {
		update_option( 'readabler_enabled', isset( $_POST['readabler_enabled'] ) ? 1 : 0 );
		$allowed_positions = [ 'bottom-right', 'bottom-left' ];
		$submitted_position = isset( $_POST['readabler_position'] ) ? sanitize_text_field( wp_unslash( $_POST['readabler_position'] ) ) : 'bottom-right';
		update_option( 'readabler_position', in_array( $submitted_position, $allowed_positions, true ) ? $submitted_position : 'bottom-right' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Impostazioni salvate.', 'readabler' ) . '</p></div>';
	}

	$enabled  = get_option( 'readabler_enabled', 0 );
	$position = get_option( 'readabler_position', 'bottom-right' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Readabler Accessibility', 'readabler' ); ?></h1>
		<form method="post" action="">
			<?php wp_nonce_field( 'readabler_save_settings', 'readabler_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="readabler_enabled"><?php esc_html_e( 'Abilita Readabler', 'readabler' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="readabler_enabled" name="readabler_enabled" value="1" <?php checked( 1, $enabled ); ?> />
						<p class="description">
							<?php esc_html_e( 'Attiva o disattiva il widget di accessibilità Readabler su tutte le pagine del sito.', 'readabler' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Posizione del bollino', 'readabler' ); ?>
					</th>
					<td>
						<label>
							<input type="radio" name="readabler_position" value="bottom-right" <?php checked( 'bottom-right', $position ); ?> />
							<?php esc_html_e( 'In basso a destra', 'readabler' ); ?>
						</label>
						&nbsp;&nbsp;
						<label>
							<input type="radio" name="readabler_position" value="bottom-left" <?php checked( 'bottom-left', $position ); ?> />
							<?php esc_html_e( 'In basso a sinistra', 'readabler' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Salva impostazioni', 'readabler' ) ); ?>
		</form>
	</div>
	<?php
}
