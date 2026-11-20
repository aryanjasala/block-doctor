// End-to-end tests for "Keep what they show": a plugin's dynamic blocks saved as Custom HTML before the plugin goes,
// and "Put the blocks back". A fixture plugin (Acme Events) registers dynamic blocks. Every test checks the database too.
const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const { MARKUP, EVENTS_MARKUP, createPost, content, option, revisions, installEvents, installSecretType, buildUsage, reset } = require( './seed' );

const PLUGIN = 'acme-events/acme-events.php';
const KEY = `plugin:${ PLUGIN }`;
const atLeast = ( wanted ) => {
	const [ major, minor ] = wp( 'core', 'version' ).split( '.' ).map( Number );
	const [ wantMajor, wantMinor ] = wanted.split( '.' ).map( Number );
	return major > wantMajor || ( major === wantMajor && minor >= wantMinor );
};
const active = () => wpEval( `echo is_plugin_active( '${ PLUGIN }' ) ? 'on' : 'off';` );
const stepBox = ( page ) => page.locator( '.block-doctor-warning-row .axle-confirm' );

test.describe.serial( 'Keep what they show', () => {
	let site;

	test.beforeEach( () => {
		reset();
		site = installEvents();
		buildUsage();
	} );

	test.afterAll( () => {
		try {
			wp( 'user', 'delete', 'editor-user', '--yes' );
		} catch ( error ) {
			// Not there.
		}
		reset();
	} );

	test( 'the step counts the dynamic blocks, focuses Cancel and makes the way on a danger button', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( 'tr[data-slug="acme-events"] .deactivate a' ).click();

		const step = stepBox( page );
		await expect( step ).toContainText( 'Acme Events provides blocks that posts use. Its 4 block types are used in 5 posts.' );
		await expect( step ).toContainText( '4 of its block types are dynamic, in 5 posts' );
		await expect( step ).toContainText( 'show nothing, and Block Doctor cannot convert them afterwards' );
		await expect( step ).not.toContainText( 'fix the posts with Block Doctor afterwards' );
		await expect( step.getByRole( 'button', { name: 'Cancel' } ) ).toBeFocused();
		await expect( step.locator( '.block-doctor-continue' ) ).toHaveClass( /axle-button--danger/ );
		await expect( step.getByRole( 'button', { name: 'Keep what they show, then deactivate' } ) ).toBeVisible();

		// Escape closes it and gives focus back to the link.
		await page.keyboard.press( 'Escape' );
		await expect( step ).toHaveCount( 0 );
		expect( active() ).toBe( 'on' );
		expect( content( site.ids.page ) ).toBe( site.original.page );
	} );

	test( 'keeping rewrites the posts, lists what was refused, and the plugin goes off only after a choice', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( 'tr[data-slug="acme-events"] .deactivate a' ).click();
		await stepBox( page ).getByRole( 'button', { name: 'Keep what they show, then deactivate' } ).click();

		// Blocks that depend on the visitor were refused, so the step waits for a choice.
		const step = stepBox( page );
		await expect( step ).toContainText( 'Some blocks stay dynamic.', { timeout: 30_000 } );
		await expect( step ).toContainText( 'Kept 3 blocks in 3 posts.' );
		await expect( step.locator( '.block-doctor-refused li' ) ).toHaveCount( 4 );
		await expect( step.locator( '.block-doctor-refused' ) ).toContainText( 'acme/greeting' );
		await expect( step.locator( '.block-doctor-refused' ) ).toContainText( 'Logged-in users see something else than visitors do.' );
		await expect( step.locator( '.block-doctor-refused' ) ).toContainText( 'acme/poll' );
		await expect( step.locator( '.block-doctor-refused' ) ).toContainText( 'acme/contact-form' );
		await expect( step.getByRole( 'link', { name: /Undo batch #1/ } ) ).toBeVisible();
		expect( active(), 'nothing is switched off until the person chooses' ).toBe( 'on' );

		// The page: the block output as it showed in its own post, in a Custom HTML block, with a revision before it.
		const page_content = content( site.ids.page );
		expect( page_content ).not.toContain( 'acme/events' );
		expect( page_content ).toContain( '<!-- wp:html -->' );
		expect( page_content ).toContain( '<ul class="acme-events" data-for="Gallery page"><li>Bake sale</li><li>Open day</li></ul>' );
		expect( page_content ).toContain( 'Intro.' );
		expect( content( site.ids.nested ) ).toContain( 'data-for="Nested events"' );
		expect( content( site.ids.nested ) ).toContain( '<div class="wp-block-group">' );
		// The mixed post keeps its events block and leaves the greeting and the poll as they are.
		const mixed = content( site.ids.mixed );
		expect( mixed ).toContain( EVENTS_MARKUP.greeting );
		expect( mixed ).toContain( EVENTS_MARKUP.poll );
		expect( mixed ).not.toContain( 'acme/events' );
		// Refused posts are not touched at all, byte for byte.
		expect( content( site.ids.poll ) ).toBe( site.original.poll );
		expect( content( site.ids.form ) ).toBe( site.original.form );

		const batch = option( 'block_doctor_batches' ).items[ 1 ];
		expect( batch.kinds ).toEqual( [ 'keep' ] );
		expect( batch.posts ).toHaveLength( 3 );
		const revisionContents = JSON.parse( wpEval( `echo wp_json_encode( array_values( wp_list_pluck( wp_get_post_revisions( ${ site.ids.page } ), 'post_content' ) ) );` ) );
		expect( revisionContents, 'a revision holds the exact content before' ).toContain( site.original.page );

		await step.getByRole( 'button', { name: 'Deactivate' } ).click();
		await expect( page.locator( '.block-doctor-affected' ) ).toBeVisible();
		expect( active() ).toBe( 'off' );
	} );

	test( 'a plugin whose blocks are all safe is kept and switched off in one go', async ( { page } ) => {
		// Only the events block is left in use.
		for ( const id of [ site.ids.mixed, site.ids.poll, site.ids.form ] ) {
			wp( 'post', 'delete', String( id ), '--force' );
		}
		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( 'tr[data-slug="acme-events"] .deactivate a' ).click();
		await stepBox( page ).getByRole( 'button', { name: 'Keep what they show, then deactivate' } ).click();

		await expect( page.locator( '.block-doctor-affected, #message' ).first() ).toBeVisible( { timeout: 30_000 } );
		await expect.poll( active ).toBe( 'off' );
		expect( content( site.ids.page ) ).toContain( 'data-for="Gallery page"' );
		// Nothing is left unregistered in the posts that were kept.
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.block-doctor-affected' ) ).toHaveCount( 0 );
	} );

	test( 'Put the blocks back restores the exact original markup, and edits made since stay', async ( { page } ) => {
		wp( 'block-doctor', 'keep', PLUGIN, '--user=admin' );
		expect( content( site.ids.page ) ).not.toBe( site.original.page );

		// Someone edits the intro while the blocks are kept.
		wpEval( `wp_update_post( array( 'ID' => ${ site.ids.page }, 'post_content' => str_replace( 'Intro.', 'New intro.', get_post( ${ site.ids.page } )->post_content ) ) );` );

		await page.goto( '/wp-admin/plugins.php' );
		const notice = page.locator( '.block-doctor-kept' );
		await expect( notice ).toContainText( 'Block Doctor kept 3 blocks of Acme Events as HTML.' );
		await notice.getByRole( 'link', { name: 'Put the blocks back' } ).click();
		await expect( page.locator( '.block-doctor-put-back' ) ).toContainText( 'Put the blocks back in 3 posts. 0 are still waiting.' );
		await expect( page.locator( '.block-doctor-kept' ) ).toHaveCount( 0 );

		expect( content( site.ids.page ) ).toBe( site.original.page.replace( 'Intro.', 'New intro.' ) );
		expect( content( site.ids.nested ) ).toBe( site.original.nested );
		expect( content( site.ids.mixed ) ).toBe( site.original.mixed );
		expect( option( 'block_doctor_keep' ) ).toBeNull();
		expect( wpEval( `echo get_post_meta( ${ site.ids.nested }, '_block_doctor_kept', true ) === '' ? 'clean' : 'left';` ) ).toBe( 'clean' );
		expect( option( 'block_doctor_batches' ).items[ 1 ].undone ).toBeGreaterThan( 0 );
	} );

	test( 'the notice after reactivating offers Put the blocks back, and the plugin turned off hides it', async ( { page } ) => {
		wp( 'block-doctor', 'keep', PLUGIN, '--user=admin' );
		wp( 'plugin', 'deactivate', 'acme-events' );

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.block-doctor-kept' ) ).toHaveCount( 0 );

		wp( 'plugin', 'activate', 'acme-events' );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.block-doctor-kept' ) ).toContainText( 'kept 3 blocks of Acme Events' );

		// Site Health says the same.
		const health = JSON.parse( wpEval( 'echo wp_json_encode( AryanX\\Axle\\BlockDoctor\\Plugin::instance()->keeper() ? ( new AryanX\\Axle\\BlockDoctor\\Health\\SiteHealth( AryanX\\Axle\\BlockDoctor\\Plugin::instance()->report(), AryanX\\Axle\\BlockDoctor\\Plugin::instance()->usage(), AryanX\\Axle\\BlockDoctor\\Plugin::instance()->deactivation(), AryanX\\Axle\\BlockDoctor\\Plugin::instance()->keeper() ) )->run_kept() : null );' ) );
		expect( health.status ).toBe( 'recommended' );
		expect( health.label ).toBe( '3 blocks are frozen as HTML although their plugin is on' );
	} );

	test( 'Undo batch also brings the content back from the revision', async () => {
		wp( 'block-doctor', 'keep', PLUGIN, '--user=admin' );
		const before = revisions( site.ids.page );
		expect( before ).toBeGreaterThan( 0 );

		wp( 'block-doctor', 'undo-batch', '1' );
		expect( content( site.ids.page ) ).toBe( site.original.page );
		expect( content( site.ids.nested ) ).toBe( site.original.nested );
	} );

	test( 'a post the person cannot edit is never touched, and its title never leaves', async ( { browser } ) => {
		installSecretType();
		const secret = site.secret();
		buildUsage();
		try {
			wp( 'user', 'delete', 'editor-user', '--yes' );
		} catch ( error ) {
			// Not there yet.
		}
		wp( 'user', 'create', 'editor-user', 'editor@example.com', '--role=editor', '--user_pass=password' );

		const { context, page } = await logInAs( browser, 'editor-user', 'password' );
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		let result;
		let restart = true;
		do {
			const response = await page.request.post( `/index.php?rest_route=${ encodeURIComponent( '/block-doctor/v1/keep' ) }`, { headers: { 'X-WP-Nonce': nonce }, data: { provider: KEY, restart } } );
			expect( response.status() ).toBe( 200 );
			result = await response.json();
			restart = false;
		} while ( result.status === 'running' );

		expect( result.not_yours ).toBe( 1 );
		expect( JSON.stringify( result ) ).not.toContain( 'Secret events' );
		expect( content( secret ), 'untouched, byte for byte' ).toBe( site.original.secret );
		// The editor's own posts were kept.
		expect( content( site.ids.page ) ).toContain( 'data-for="Gallery page"' );
		await context.close();
	} );

	test( 'a block that renders differently for two users, or has a form, is refused, and the filter can refuse more', async () => {
		const run = JSON.parse( wp( 'eval', 'wp_set_current_user( 1 ); $r = AryanX\\Axle\\BlockDoctor\\Plugin::instance()->keeper()->step( "plugin:acme-events/acme-events.php", true ); echo wp_json_encode( $r );' ) );
		const reasons = {};
		run.refused.forEach( ( post ) => post.blocks.forEach( ( block ) => ( reasons[ block.name ] = block.reason ) ) );
		expect( reasons ).toEqual( { 'acme/greeting': 'visitor', 'acme/poll': 'visitor', 'acme/contact-form': 'name' } );
	} );

	test( 'WP-CLI: a dry run counts, keep needs a user, put-back restores', async () => {
		const dry = wp( 'block-doctor', 'keep', 'acme-events', '--dry-run' );
		expect( dry ).toContain( '4 dynamic block types, used in 5 posts.' );
		expect( content( site.ids.page ) ).toBe( site.original.page );

		expect( () => wp( 'block-doctor', 'keep', 'acme-events' ) ).toThrow( /Pass --user/ );

		const out = wp( 'block-doctor', 'keep', 'acme-events', '--user=admin' );
		expect( out ).toContain( 'Kept 3 blocks in 3 posts (batch 1). 4 refused.' );
		const back = wp( 'block-doctor', 'put-back', 'acme-events', '--user=admin' );
		expect( back ).toContain( 'Put the blocks back in 3 posts, 0 skipped.' );
		expect( content( site.ids.page ) ).toBe( site.original.page );
	} );

	test( 'abilities: keep the blocks and put them back', async ( { rest } ) => {
		test.skip( ! atLeast( '6.9' ), 'The Abilities API arrived in WordPress 6.9.' );
		const run = '/wp-abilities/v1/abilities/block-doctor';

		const kept = await rest( 'DELETE', `${ run }/keep-blocks/run?input[plugin]=${ PLUGIN }` );
		expect( kept.status ).toBe( 200 );
		expect( kept.body ).toMatchObject( { status: 'done', kept: 3, kept_posts: 3, refused_n: 4 } );
		expect( content( site.ids.page ) ).not.toBe( site.original.page );

		const back = await rest( 'DELETE', `${ run }/put-blocks-back/run?input[plugin]=${ PLUGIN }` );
		expect( back.status ).toBe( 200 );
		expect( back.body ).toMatchObject( { restored: 3, remaining: 0 } );
		expect( content( site.ids.page ) ).toBe( site.original.page );
	} );

	test( 'only an administrator clears the results, and the button is not there for an editor', async ( { page, browser } ) => {
		const id = createPost( 'Classic one', MARKUP.classic );
		wp( 'block-doctor', 'scan' );
		try {
			wp( 'user', 'delete', 'editor-user', '--yes' );
		} catch ( error ) {
			// Not there yet.
		}
		wp( 'user', 'create', 'editor-user', 'editor@example.com', '--role=editor', '--user_pass=password' );

		const { context, page: editor } = await logInAs( browser, 'editor-user', 'password' );
		await editor.goto( '/wp-admin/tools.php?page=block-doctor' );
		await expect( editor.locator( '.bdr-report' ) ).toBeVisible( { timeout: 15_000 } );
		await expect( editor.locator( '.bdr-clear' ) ).toHaveCount( 0 );
		const nonce = ( await ( await editor.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const denied = await editor.request.delete( `/index.php?rest_route=${ encodeURIComponent( '/block-doctor/v1/results' ) }`, { headers: { 'X-WP-Nonce': nonce } } );
		expect( denied.status() ).toBe( 403 );
		expect( wpEval( `echo get_post_meta( ${ id }, '_block_doctor_issues', true ) ? 'stored' : 'wiped';` ) ).toBe( 'stored' );
		await context.close();

		await page.goto( '/wp-admin/tools.php?page=block-doctor' );
		await expect( page.locator( '.bdr-clear' ) ).toBeVisible();
	} );

	test( 'the report has a Hidden view for blocks hidden with block visibility', async ( { page } ) => {
		createPost( 'Hidden bits', [ '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} -->\n<p>Never shown</p>\n<!-- /wp:paragraph -->', '<!-- wp:paragraph {"metadata":{"blockVisibility":{"viewport":{"mobile":false}}}} -->\n<p>Desktop only</p>\n<!-- /wp:paragraph -->' ].join( '\n\n' ) );
		wp( 'block-doctor', 'scan' );

		await page.goto( '/wp-admin/tools.php?page=block-doctor' );
		const hidden = page.locator( '.bdr-tab[data-kind="hidden"]' );
		await expect( hidden ).toBeVisible();
		await expect( hidden.locator( '.bdr-count' ) ).toHaveText( '(1)' );
		await hidden.click();
		await expect( hidden ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.locator( '.bdr-table' ) ).toContainText( 'Hidden on every screen' );
		await page.locator( '.bdr-table .bdr-group-more summary, .bdr-table tbody tr' ).first().waitFor();
		expect( await page.locator( '.bdr-table' ).textContent() ).toContain( 'Hidden on mobile with CSS only' );
	} );
} );
