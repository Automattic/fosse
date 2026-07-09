/**
 * Wizard Appearance step — live preview swap.
 *
 * Toggles which fediverse-address preview container is visible based on the
 * currently selected actor-mode radio. Also toggles the inline Site Handle
 * row, which is only meaningful when the selected mode includes the blog
 * actor (`blog` or `actor_blog`).
 *
 * Progressive enhancement: the server renders all three preview containers
 * with the inactive ones tagged `is-hidden`. With JS off, the active mode's
 * container stays visible and the form still updates on submit.
 */
( function () {
	'use strict';

	const HIDDEN_CLASS = 'is-hidden';
	const BLOG_MODES = [ 'blog', 'actor_blog' ];

	function modeIncludesBlog( mode ) {
		return BLOG_MODES.indexOf( mode ) !== -1;
	}

	/**
	 * Apply the visibility state for a given mode value.
	 *
	 * @param {string}       mode       Selected actor mode (`actor`/`blog`/`actor_blog`).
	 * @param {NodeList}     previews   Preview containers tagged with `data-fosse-mode`.
	 * @param {Element|null} blogHandle The site handle row, if present.
	 */
	function applyMode( mode, previews, blogHandle ) {
		previews.forEach( function ( el ) {
			const matches = el.getAttribute( 'data-fosse-mode' ) === mode;
			el.classList.toggle( HIDDEN_CLASS, ! matches );
		} );

		if ( blogHandle ) {
			const includesBlog = modeIncludesBlog( mode );
			blogHandle.classList.toggle( HIDDEN_CLASS, ! includesBlog );

			// Disable the input while hidden so it stops submitting. A
			// `display:none` field still posts its value, which the PHP
			// handler would otherwise persist — and a collision in a field
			// the user cannot see would bounce them back with no way to fix
			// it. Toggling `disabled` (not just the CSS class) keeps the
			// hidden input out of the form submission entirely.
			const input = blogHandle.querySelector(
				'input[name="activitypub_blog_identifier"]'
			);
			if ( input ) {
				input.disabled = ! includesBlog;
			}
		}
	}

	function init( root ) {
		const scope = root || document;
		const radios = scope.querySelectorAll( '.fosse-mode-card__input' );
		if ( ! radios.length ) {
			return;
		}

		const previews = scope.querySelectorAll(
			'.fosse-address-preview[data-fosse-mode]'
		);
		const blogHandle = scope.querySelector(
			'[data-fosse-when="includes-blog"]'
		);

		function handleChange() {
			const checked = scope.querySelector(
				'.fosse-mode-card__input:checked'
			);
			if ( ! checked ) {
				return;
			}
			applyMode( checked.value, previews, blogHandle );
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', handleChange );
		} );

		// Run once on init so the visible state matches the checked radio
		// even if the server rendered a stale snapshot (e.g. after a back
		// button reload that restored a different selection from history).
		handleChange();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}
} )();
