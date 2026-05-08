describe( 'Wizard lizard easter egg', () => {
	function renderWizard() {
		document.body.innerHTML = `
			<div class="wrap fosse-wizard">
				<button type="button" data-fosse-lizard-toggle aria-pressed="false">
					<span aria-hidden="true">&#x1F98E;</span>
				</button>
			</div>
		`;
	}

	function loadScript() {
		jest.isolateModules( () => {
			require( '../../src/Admin/assets/js/wizard-lizard.js' );
		} );

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	}

	beforeEach( () => {
		document.body.innerHTML = '';
		window.sessionStorage.clear();
	} );

	test( 'clicking the lizard toggles the theme class', () => {
		renderWizard();
		loadScript();

		const wizard = document.querySelector( '.fosse-wizard' );
		const toggle = document.querySelector( '[data-fosse-lizard-toggle]' );

		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect(
			window.sessionStorage.getItem( 'fosseWizardLizardTheme' )
		).toBe( '1' );

		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect(
			window.sessionStorage.getItem( 'fosseWizardLizardTheme' )
		).toBeNull();
	} );

	test( 'restores the theme across wizard steps in the same session', () => {
		window.sessionStorage.setItem( 'fosseWizardLizardTheme', '1' );
		renderWizard();
		loadScript();

		const wizard = document.querySelector( '.fosse-wizard' );
		const toggle = document.querySelector( '[data-fosse-lizard-toggle]' );

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );
} );
