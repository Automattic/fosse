describe( 'Wizard style toggle', () => {
	const STORAGE_KEY = 'fosseWizardLizardTheme';

	function renderWizard() {
		document.body.innerHTML = `
			<div class="wrap fosse-wizard">
				<button type="button" data-fosse-lizard-toggle aria-pressed="false">
					<span aria-hidden="true">&#x1F98E;</span>
				</button>
			</div>
		`;
	}

	function getElements() {
		return {
			wizard: document.querySelector( '.fosse-wizard' ),
			toggle: document.querySelector( '[data-fosse-lizard-toggle]' ),
		};
	}

	function loadScript( readyState = 'complete' ) {
		jest.spyOn( document, 'readyState', 'get' ).mockReturnValue(
			readyState
		);

		jest.isolateModules( () => {
			require( '../../src/Admin/assets/js/wizard-lizard.js' );
		} );
	}

	beforeEach( () => {
		document.body.innerHTML = '';
		window.sessionStorage.clear();
		jest.restoreAllMocks();
	} );

	test( 'clicking the style toggle changes the theme class', () => {
		renderWizard();
		loadScript();

		const { wizard, toggle } = getElements();

		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( window.sessionStorage.getItem( STORAGE_KEY ) ).toBe( '1' );

		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect( window.sessionStorage.getItem( STORAGE_KEY ) ).toBeNull();
	} );

	test( 'restores the theme across wizard steps in the same session', () => {
		window.sessionStorage.setItem( STORAGE_KEY, '1' );
		renderWizard();
		loadScript();

		const { wizard, toggle } = getElements();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	test( 'initializes after DOMContentLoaded when loaded early', () => {
		renderWizard();
		loadScript( 'loading' );

		const { wizard, toggle } = getElements();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( false );

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	test( 'ignores storage read failures and still attaches the click handler', () => {
		const getItemSpy = jest
			.spyOn( Storage.prototype, 'getItem' )
			.mockImplementation( () => {
				throw new Error( 'storage unavailable' );
			} );

		renderWizard();
		loadScript();

		const { wizard, toggle } = getElements();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'false' );

		getItemSpy.mockRestore();
		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	test( 'keeps in-memory state when storage writes fail', () => {
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'storage unavailable' );
		} );
		jest.spyOn( Storage.prototype, 'removeItem' ).mockImplementation(
			() => {
				throw new Error( 'storage unavailable' );
			}
		);

		renderWizard();
		loadScript();

		const { wizard, toggle } = getElements();

		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'true' );

		toggle.click();

		expect( wizard.classList.contains( 'is-lizard-themed' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
	} );
} );
