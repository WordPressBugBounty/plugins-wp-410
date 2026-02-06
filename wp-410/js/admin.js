/**
 * Admin scripts for HTTP 410 (Gone) responses plugin.
 *
 * @package MCLV_410_Plugin
 */

document.addEventListener( 'DOMContentLoaded', () => {
	// Hide message on captcha settings change.
	const captchaSettings = document.getElementById( 'wp-gone-captcha-settings' );
	if ( captchaSettings ) {
		captchaSettings.querySelectorAll( 'input' ).forEach( ( input ) => {
			input.addEventListener( 'change', () => {
				const message = document.getElementById( 'message' );
				if ( message ) {
					message.style.transition = 'opacity 0.5s ease';
					message.style.opacity = '0';
					setTimeout( () => {
						message.style.display = 'none';
					}, 500 );
				}
			} );
		} );
	}

	// Select all checkboxes functionality.
	const selectAllButtons = document.querySelectorAll( '#select-all-wildcards, #select-all-410, #select-all-404' );
	selectAllButtons.forEach( ( selectAll ) => {
		selectAll.addEventListener( 'change', function() {
			const table = this.closest( 'table' );
			if ( table ) {
				const checkboxes = table.querySelectorAll( 'tbody input[type="checkbox"]' );
				const isChecked = this.checked;
				checkboxes.forEach( ( checkbox ) => {
					checkbox.checked = isChecked;
				} );
			}
		} );
	} );
} );

