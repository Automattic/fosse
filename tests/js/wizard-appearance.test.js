describe( 'Wizard appearance step', () => {
	const HIDDEN_CLASS = 'is-hidden';

	function renderAppearanceStep() {
		document.body.innerHTML = `
			<div class="fosse-wizard">
				<label class="fosse-mode-card">
					<input class="fosse-mode-card__input" type="radio" name="activitypub_actor_mode" value="actor" checked />
				</label>
				<label class="fosse-mode-card">
					<input class="fosse-mode-card__input" type="radio" name="activitypub_actor_mode" value="blog" />
				</label>
				<label class="fosse-mode-card">
					<input class="fosse-mode-card__input" type="radio" name="activitypub_actor_mode" value="actor_blog" />
				</label>

				<div class="fosse-address-preview is-hidden" data-fosse-mode="actor">Author preview</div>
				<div class="fosse-address-preview" data-fosse-mode="blog">Site preview</div>
				<div class="fosse-address-preview" data-fosse-mode="actor_blog">Both preview</div>
				<div class="fosse-wizard__blog-handle" data-fosse-when="includes-blog">
						<input type="text" name="activitypub_blog_identifier" value="my-handle" />
					</div>
			</div>
		`;
	}

	function loadScript( readyState = 'complete' ) {
		const originalReadyStateDescriptor = Object.getOwnPropertyDescriptor(
			document,
			'readyState'
		);

		Object.defineProperty( document, 'readyState', {
			value: readyState,
			configurable: true,
		} );

		try {
			jest.isolateModules( () => {
				require( '../../src/Admin/assets/js/wizard-appearance.js' );
			} );
		} finally {
			if ( originalReadyStateDescriptor ) {
				Object.defineProperty(
					document,
					'readyState',
					originalReadyStateDescriptor
				);
			} else {
				delete document.readyState;
			}
		}
	}

	function previewFor( mode ) {
		return document.querySelector( `[data-fosse-mode="${ mode }"]` );
	}

	function blogHandle() {
		return document.querySelector( '[data-fosse-when="includes-blog"]' );
	}

	function blogHandleInput() {
		return document.querySelector(
			'input[name="activitypub_blog_identifier"]'
		);
	}

	function chooseMode( mode ) {
		const radios = document.querySelectorAll( '.fosse-mode-card__input' );
		const selected = document.querySelector(
			`.fosse-mode-card__input[value="${ mode }"]`
		);

		radios.forEach( ( radio ) => {
			radio.checked = radio === selected;
		} );
		selected.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function expectVisiblePreview( mode ) {
		[ 'actor', 'blog', 'actor_blog' ].forEach( ( previewMode ) => {
			expect(
				previewFor( previewMode ).classList.contains( HIDDEN_CLASS )
			).toBe( previewMode !== mode );
		} );
	}

	beforeEach( () => {
		document.body.innerHTML = '';
		jest.restoreAllMocks();
	} );

	test( 'initializes the visible preview from the checked actor mode', () => {
		renderAppearanceStep();

		// Deliberately stale server markup: blog is visible even though the
		// actor radio is checked. The script should correct this on init.
		expect( previewFor( 'blog' ).classList.contains( HIDDEN_CLASS ) ).toBe(
			false
		);

		loadScript();

		expectVisiblePreview( 'actor' );
		expect( blogHandle().classList.contains( HIDDEN_CLASS ) ).toBe( true );
	} );

	test( 'switching to blog mode reveals the blog handle row', () => {
		renderAppearanceStep();
		loadScript();

		chooseMode( 'blog' );

		expectVisiblePreview( 'blog' );
		expect( blogHandle().classList.contains( HIDDEN_CLASS ) ).toBe( false );
	} );

	test( 'switching to actor plus blog mode keeps the blog handle row visible', () => {
		renderAppearanceStep();
		loadScript();

		chooseMode( 'actor_blog' );

		expectVisiblePreview( 'actor_blog' );
		expect( blogHandle().classList.contains( HIDDEN_CLASS ) ).toBe( false );
	} );

	test( 'switching back to actor mode hides the blog handle row', () => {
		renderAppearanceStep();
		loadScript();

		chooseMode( 'blog' );
		chooseMode( 'actor' );

		expectVisiblePreview( 'actor' );
		expect( blogHandle().classList.contains( HIDDEN_CLASS ) ).toBe( true );
	} );

	test( 'disables the hidden handle input in author-only mode on init', () => {
		renderAppearanceStep();

		// Stale server markup leaves the input enabled; the script should
		// disable it on init so a hidden field stops submitting its value.
		expect( blogHandleInput().disabled ).toBe( false );

		loadScript();

		expect( blogHandleInput().disabled ).toBe( true );
	} );

	test( 'enables the handle input when switching to a blog-inclusive mode', () => {
		renderAppearanceStep();
		loadScript();

		expect( blogHandleInput().disabled ).toBe( true );

		chooseMode( 'blog' );
		expect( blogHandleInput().disabled ).toBe( false );

		chooseMode( 'actor_blog' );
		expect( blogHandleInput().disabled ).toBe( false );
	} );

	test( 're-disables the handle input when switching back to author-only mode', () => {
		renderAppearanceStep();
		loadScript();

		chooseMode( 'blog' );
		expect( blogHandleInput().disabled ).toBe( false );

		chooseMode( 'actor' );
		expect( blogHandleInput().disabled ).toBe( true );
	} );

	test( 'does not throw when the appearance radios are absent', () => {
		document.body.innerHTML = '<div class="fosse-wizard"></div>';

		expect( () => loadScript() ).not.toThrow();
	} );

	test( 'initializes after DOMContentLoaded when loaded early', () => {
		renderAppearanceStep();
		loadScript( 'loading' );

		expect( previewFor( 'blog' ).classList.contains( HIDDEN_CLASS ) ).toBe(
			false
		);

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		expectVisiblePreview( 'actor' );
		expect( blogHandle().classList.contains( HIDDEN_CLASS ) ).toBe( true );
	} );
} );
