/**
 * Hidden wizard style toggle.
 *
 * Clicking the deliberately quiet button adds a CSS class that
 * turns the setup flow into a themed variant without changing any
 * form behavior. Session storage keeps the theme alive across wizard steps.
 */
( function () {
	'use strict';

	const THEME_CLASS = 'is-lizard-themed';
	const STORAGE_KEY = 'fosseWizardLizardTheme';
	const TOGGLE_SELECTOR = '[data-fosse-lizard-toggle]';
	const WIZARD_SELECTOR = '.fosse-wizard';

	function readStoredTheme() {
		try {
			return window.sessionStorage.getItem( STORAGE_KEY ) === '1';
		} catch {
			return false;
		}
	}

	function storeTheme( enabled ) {
		try {
			if ( enabled ) {
				window.sessionStorage.setItem( STORAGE_KEY, '1' );
			} else {
				window.sessionStorage.removeItem( STORAGE_KEY );
			}
		} catch {
			// Storage can be unavailable in restricted browser contexts.
		}
	}

	function applyTheme( wizard, toggle, enabled ) {
		wizard.classList.toggle( THEME_CLASS, enabled );
		toggle.setAttribute( 'aria-pressed', enabled ? 'true' : 'false' );
	}

	function init( root ) {
		const scope = root || document;
		const wizard = scope.querySelector( WIZARD_SELECTOR );

		if ( ! wizard ) {
			return;
		}

		const toggle = wizard.querySelector( TOGGLE_SELECTOR );

		if ( ! toggle ) {
			return;
		}

		if ( readStoredTheme() ) {
			applyTheme( wizard, toggle, true );
		}

		toggle.addEventListener( 'click', function () {
			const enabled = ! wizard.classList.contains( THEME_CLASS );

			applyTheme( wizard, toggle, enabled );
			storeTheme( enabled );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener(
			'DOMContentLoaded',
			function () {
				init();
			},
			{ once: true }
		);
	} else {
		init();
	}
} )();
