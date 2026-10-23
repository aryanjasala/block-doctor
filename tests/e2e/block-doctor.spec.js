// End-to-end tests for Block Doctor. Every test checks the database through WP-CLI as well as the screen.
const { test, expect, wp, wpEval, openEditor, logInAs } = require( './utils' );
const { MARKUP, createPost, content, issues, option, revisions, installShelfBlocks, installIndexCounter, indexWrites, reset, seed } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=block-doctor';
const version = () => wp( 'core', 'version' );
const atLeast = ( wanted ) => {
	const [ major, minor ] = version().split( '.' ).map( Number );
	const [ wantMajor, wantMinor ] = wanted.split( '.' ).map( Number );
	return major > wantMajor || ( major === wantMajor && minor >= wantMinor );
};

/**
 * Opens the screen and runs a full browser scan.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function scanSite( page ) {
	await page.goto( SCREEN );
	await page.locator( '.bdr-start' ).click();
	await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 30_000 } );
	await expect( page.locator( '.bdr-report' ) ).toBeVisible();
}

/** The report row of a post. */
const row = ( page, id ) => page.locator( `.bdr-table tr[data-post="${ id }"]` );

/**
 * Selects posts, previews the fixes and applies them. Returns the batch number.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {number[]}                         ids  Posts.
 * @return {Promise<number>} Batch.
 */
async function fixSelected( page, ids ) {
	for ( const id of ids ) {
		await row( page, id ).locator( 'input[type="checkbox"]' ).check();
	}
	await page.locator( '.bdr-fix-selected' ).click();
	await page.locator( '.bdr-apply' ).click();
	await expect( page.locator( '.bdr-modal .components-notice' ) ).toContainText( 'Fixed' );
	const text = await page.locator( '.bdr-modal .components-notice' ).innerText();
	await page.locator( '.bdr-close' ).click();
	return Number( text.match( /batch #(\d+)/ )[ 1 ] );
}

test.describe.serial( 'Block Doctor', () => {
	let ids;

	test.beforeEach( () => {
		reset();
		ids = seed();
	} );

	test.afterAll( reset );

	test( 'the browser scan finds every kind of issue and stores it per post', async ( { page } ) => {
		await scanSite( page );

		// On screen: one filter per kind with how many posts have it.
		const tab = ( kind ) => page.locator( `.bdr-tab[data-kind="${ kind }"] .bdr-count` );
		await expect( tab( 'all' ) ).toHaveText( '9' );
		await expect( tab( 'invalid' ) ).toHaveText( '4' );
		await expect( tab( 'missing' ) ).toHaveText( '1' );
		await expect( tab( 'classic' ) ).toHaveText( '1' );
		await expect( tab( 'deprecated' ) ).toHaveText( '1' );
		await expect( tab( 'empty' ) ).toHaveText( '1' );
		await expect( tab( 'pattern-ref' ) ).toHaveText( '1' );
		await expect( tab( 'navigation-ref' ) ).toHaveText( '1' );
		await expect( tab( 'missing-media' ) ).toHaveText( '1' );
		await expect( row( page, ids.hours ) ).toContainText( 'The saved markup no longer matches what the block produces.' );
		await expect( row( page, ids.hours ).locator( '.bdr-detail-text' ).first() ).toHaveText( 'Expected tag name h3, instead saw h2.' );
		await expect( row( page, ids.order ) ).toContainText( 'Block type acme/widget is not registered.' );

		// In the database: each post's issues, with the block path.
		expect( issues( ids.hours ) ).toEqual( [ 'invalid@1', 'invalid@2' ] );
		expect( issues( ids.order ) ).toEqual( [ 'missing@1' ] );
		expect( issues( ids.classic ) ).toEqual( [ 'classic@0' ] );
		expect( issues( ids.story ) ).toEqual( [ 'deprecated@0' ] );
		expect( issues( ids.nested ) ).toEqual( [ 'invalid@0.1' ] );
		expect( issues( ids.badNotice ) ).toEqual( [ 'invalid@0' ] );
		expect( issues( ids.refs ) ).toEqual( [ 'pattern-ref@0', 'navigation-ref@1', 'missing-media@2' ] );
		expect( issues( ids.pattern ) ).toEqual( [ 'invalid@0' ] );
		expect( issues( ids.empty ) ).toEqual( [ 'empty@0' ] );
		// A block only registered in JavaScript is known, and its valid markup is fine.
		expect( issues( ids.notice ) ).toEqual( [] );
		expect( issues( ids.healthy ) ).toEqual( [] );

		const state = option( 'block_doctor_scan' );
		expect( state.status ).toBe( 'done' );
		expect( state.done ).toBe( state.total );
		expect( Object.keys( option( 'block_doctor_index' ) ).map( Number ).sort( ( a, b ) => a - b ) ).toEqual( [ ids.hours, ids.order, ids.classic, ids.story, ids.nested, ids.badNotice, ids.refs, ids.pattern, ids.empty ].sort( ( a, b ) => a - b ) );
		expect( option( 'block_doctor_js_blocks' ) ).toContain( 'acme/notice' );
	} );

	test( 'fixing invalid blocks saves revisions, the editor accepts them, and Undo batch puts the old markup back', async ( { page } ) => {
		await scanSite( page );
		const before = { hours: content( ids.hours ), nested: content( ids.nested ), badNotice: content( ids.badNotice ) };
		const revisionsBefore = revisions( ids.hours );

		// Preview first: the diff shows the h2 becoming an h3.
		for ( const id of [ ids.hours, ids.nested, ids.badNotice ] ) {
			await row( page, id ).locator( 'input[type="checkbox"]' ).check();
		}
		await page.locator( '.bdr-fix-selected' ).click();
		await expect( page.locator( '.bdr-preview-count' ) ).toContainText( '3 of 3 posts change' );
		const item = page.locator( `.bdr-preview-item[data-post="${ ids.hours }"]` );
		await item.locator( '.bdr-toggle-diff' ).click();
		await expect( item.locator( '.bdr-diff-del' ).first() ).toContainText( '<h2 class="wp-block-heading">Opening hours</h2>' );
		await expect( item.locator( '.bdr-diff-add' ).first() ).toContainText( '<h3 class="wp-block-heading">Opening hours</h3>' );
		expect( content( ids.hours ) ).toBe( before.hours );

		await page.locator( '.bdr-apply' ).click();
		await expect( page.locator( '.bdr-modal .components-notice' ) ).toContainText( 'Fixed 3 posts in batch #1.' );
		await page.locator( '.bdr-close' ).click();

		// Only the broken blocks changed.
		expect( content( ids.hours ) ).toBe( [ MARKUP.paragraph( 'Come and visit.' ), MARKUP.goodHeading, MARKUP.goodImage ].join( '\n\n' ) );
		expect( content( ids.nested ) ).toContain( '<p>Inside a group</p>' );
		expect( content( ids.nested ) ).toContain( '<h4 class="wp-block-heading">Wrong level inside</h4>' );
		expect( content( ids.badNotice ) ).toContain( '<p>Closed on Mondays</p>' );
		expect( issues( ids.hours ) ).toEqual( [] );
		expect( issues( ids.nested ) ).toEqual( [] );
		expect( revisions( ids.hours ) ).toBeGreaterThan( revisionsBefore );
		const batch = option( 'block_doctor_batches' ).items[ '1' ];
		expect( batch.posts.sort() ).toEqual( [ ids.hours, ids.nested, ids.badNotice ].sort() );
		expect( batch.kinds ).toEqual( [ 'invalid', 'deprecated' ] );

		// The editor finds nothing wrong any more.
		await openEditor( page, ids.hours );
		const valid = await page.evaluate( () => {
			const all = ( blocks ) => blocks.every( ( block ) => block.isValid && all( block.innerBlocks ) );
			return all( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		} );
		expect( valid ).toBe( true );
		await expect( page.locator( '.block-editor-warning' ) ).toHaveCount( 0 );
		await expect( page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.block-editor-warning' ) ).toHaveCount( 0 );

		// Undo batch.
		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.goto( SCREEN );
		await page.locator( '.bdr-batches tr[data-batch="1"] .bdr-undo' ).click();
		await expect( page.locator( '.bdr-notice' ) ).toContainText( 'Batch #1 undone: 3 posts restored.' );
		await expect( page.locator( '.bdr-batches tr[data-batch="1"] .bdr-undone' ) ).toBeVisible();
		expect( content( ids.hours ) ).toBe( before.hours );
		expect( content( ids.nested ) ).toBe( before.nested );
		expect( content( ids.badNotice ) ).toBe( before.badNotice );
		expect( issues( ids.hours ) ).toEqual( [ 'invalid@1', 'invalid@2' ] );
		expect( option( 'block_doctor_batches' ).items[ '1' ].undone ).toBeGreaterThan( 0 );
	} );

	test( 'Undo batch skips a post edited after the fix', async ( { page } ) => {
		await scanSite( page );
		await fixSelected( page, [ ids.hours, ids.nested ] );
		wpEval( `wp_update_post( array( 'ID' => ${ ids.nested }, 'post_content' => 'Edited by hand' ) );` );

		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.goto( SCREEN );
		await page.locator( '.bdr-batches .bdr-undo' ).first().click();
		await expect( page.locator( '.bdr-notice' ) ).toContainText( '1 post restored.' );
		await expect( page.locator( '.bdr-notice' ) ).toContainText( `#${ ids.nested } (edited after the fix)` );

		expect( content( ids.nested ) ).toBe( 'Edited by hand' );
		expect( content( ids.hours ) ).toContain( '<h2 class="wp-block-heading">Opening hours</h2>' );
		expect( option( 'block_doctor_batches' ).items[ '1' ].skipped ).toEqual( { [ ids.nested ]: 'edited' } );
	} );

	test( 'fix all of one kind: unregistered blocks to Custom HTML, classic content to blocks, outdated blocks saved again', async ( { page } ) => {
		await scanSite( page );

		await page.locator( '.bdr-tab[data-kind="missing"]' ).click();
		await page.locator( '.bdr-fix-kind' ).click();
		await expect( page.locator( '.bdr-preview-count' ) ).toContainText( '1 of 1 posts change' );
		await page.locator( '.bdr-apply' ).click();
		await page.locator( '.bdr-close' ).click();
		expect( content( ids.order ) ).toBe( [ MARKUP.paragraph( 'Order ahead and skip the queue.' ), '<!-- wp:html -->\n<div class="acme-widget">Order online</div>\n<!-- /wp:html -->' ].join( '\n\n' ) );
		expect( issues( ids.order ) ).toEqual( [] );

		await page.locator( '.bdr-tab[data-kind="classic"]' ).click();
		await page.locator( '.bdr-fix-kind' ).click();
		await page.locator( '.bdr-apply' ).click();
		await page.locator( '.bdr-close' ).click();
		expect( content( ids.classic ) ).toContain( '<!-- wp:paragraph -->\n<p>We bake <strong>sourdough</strong> every morning.</p>\n<!-- /wp:paragraph -->' );
		expect( issues( ids.classic ) ).toEqual( [] );

		await page.locator( '.bdr-tab[data-kind="deprecated"]' ).click();
		await page.locator( '.bdr-fix-kind' ).click();
		await page.locator( '.bdr-apply' ).click();
		await page.locator( '.bdr-close' ).click();
		expect( content( ids.story ) ).toContain( '<h2 class="wp-block-heading">Our story</h2>' );
		expect( issues( ids.story ) ).toEqual( [] );

		await expect( page.locator( '.bdr-tab[data-kind="missing"]' ) ).toHaveCount( 0 );
		expect( Object.keys( option( 'block_doctor_batches' ).items ) ).toEqual( [ '1', '2', '3' ] );
	} );

	test( 'a block type can be repaired as Custom HTML instead, and unchecked kinds stay as they are', async ( { page } ) => {
		await scanSite( page );
		await row( page, ids.hours ).locator( 'input[type="checkbox"]' ).check();
		await row( page, ids.story ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '.bdr-fix-options' ).getByLabel( 'Outdated blocks' ).uncheck();
		await page.locator( '.bdr-fix-selected' ).click();

		await page.locator( '.bdr-strategy select' ).nth( 1 ).selectOption( 'html' );
		await expect( page.locator( '.bdr-strategy' ) ).toHaveCount( 2 );
		await expect( page.locator( '.bdr-preview-count' ) ).toContainText( '1 of 2 posts change' );
		await page.locator( '.bdr-apply' ).click();
		await page.locator( '.bdr-close' ).click();

		expect( content( ids.hours ) ).toContain( MARKUP.goodHeading );
		expect( content( ids.hours ) ).toContain( '<!-- wp:html -->\n<div class="wp-block-image"><img src="https://example.com/storefront.jpg" alt="Storefront"/></div>\n<!-- /wp:html -->' );
		expect( content( ids.story ) ).toContain( '<h2>Our story</h2>' );
		expect( issues( ids.hours ) ).toEqual( [], 'a Custom HTML block is never invalid or outdated' );
	} );

	test( 'template parts and synced patterns are scanned and fixed like posts', async ( { page } ) => {
		const part = createPost( 'Bakery footer', MARKUP.badHeading, { post_type: 'wp_template_part', post_name: 'bd-footer' } );
		wpEval( `wp_set_object_terms( ${ part }, get_stylesheet(), 'wp_theme' );` );
		await scanSite( page );
		expect( issues( part ) ).toEqual( [ 'invalid@0' ] );
		expect( issues( ids.pattern ) ).toEqual( [ 'invalid@0' ] );
		await expect( row( page, part ) ).toContainText( 'Template Part' );

		await fixSelected( page, [ part, ids.pattern ] );
		expect( content( part ) ).toContain( '<h3 class="wp-block-heading">Opening hours</h3>' );
		expect( content( ids.pattern ) ).toContain( '<h3 class="wp-block-heading">Opening hours</h3>' );
		expect( revisions( part ) ).toBeGreaterThan( 0 );
	} );

	test( 'the scan pauses, survives a reload and resumes from where it stopped', async ( { page } ) => {
		for ( let i = 1; i <= 8; i++ ) {
			createPost( `Filler ${ i }`, MARKUP.paragraph( `Filler ${ i }` ) );
		}
		await page.goto( SCREEN );
		await page.evaluate( () => window.wp.hooks.addFilter( 'blockDoctor.batchSize', 'test', () => 3 ) );
		// Slow the results down so there is time to pause.
		await page.route( /results/, async ( route ) => {
			await new Promise( ( resolve ) => setTimeout( resolve, 700 ) );
			await route.continue();
		} );
		await page.locator( '.bdr-start' ).click();
		await expect( page.locator( '.bdr-progress-label' ) ).toContainText( /^[1-9]\d* of 19 posts checked/ );
		await page.locator( '.bdr-pause' ).click();
		await expect( page.locator( '.bdr-resume' ) ).toBeVisible( { timeout: 15_000 } );

		const paused = option( 'block_doctor_scan' );
		expect( paused.status ).toBe( 'paused' );
		expect( paused.done ).toBeGreaterThan( 0 );
		expect( paused.done ).toBeLessThan( 19 );
		expect( paused.cursor ).toBeGreaterThan( 0 );

		await page.unroute( /results/ );
		await page.reload();
		await expect( page.locator( '.bdr-progress-label' ) ).toContainText( `${ paused.done } of 19 posts checked. Paused.` );
		await page.locator( '.bdr-resume' ).click();
		await expect( page.locator( '.bdr-last-scan' ) ).toContainText( '19 posts checked', { timeout: 30_000 } );

		const done = option( 'block_doctor_scan' );
		expect( done.status ).toBe( 'done' );
		expect( done.done ).toBe( 19 );
		expect( issues( ids.hours ) ).toEqual( [ 'invalid@1', 'invalid@2' ] );
	} );

	test( 'JavaScript hooks: batch size, skipping posts, custom repairs and the issueFound and postFixed actions', async ( { page } ) => {
		const sizes = [];
		page.on( 'request', ( request ) => {
			const match = request.url().match( /posts.*per_page(?:=|%3D)(\d+)/ );
			if ( match && /after/.test( request.url() ) ) {
				sizes.push( Number( match[ 1 ] ) );
			}
		} );
		await page.goto( SCREEN );
		await page.evaluate( ( skip ) => {
			window.bdrFound = [];
			window.bdrFixed = [];
			window.wp.hooks.addFilter( 'blockDoctor.batchSize', 'test', () => 4 );
			window.wp.hooks.addFilter( 'blockDoctor.shouldScanPost', 'test', ( scan, post ) => scan && post.id !== skip );
			window.wp.hooks.addAction( 'blockDoctor.issueFound', 'test', ( issue, post ) => window.bdrFound.push( post.id + ':' + issue.kind ) );
			window.wp.hooks.addAction( 'blockDoctor.postFixed', 'test', ( post, result, batch ) => window.bdrFixed.push( [ post.id, result.changed, batch ] ) );
			window.wp.hooks.addFilter( 'blockDoctor.fixBlock', 'test', ( result, block, context ) =>
				block && block.name === 'core/image' && context.kind === 'invalid' ? '<!-- wp:paragraph -->\n<p>Image removed by a hook</p>\n<!-- /wp:paragraph -->' : result
			);
		}, ids.story );
		await page.locator( '.bdr-start' ).click();
		await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 30_000 } );

		expect( sizes.length ).toBeGreaterThan( 1 );
		expect( sizes.every( ( size ) => size === 4 ) ).toBe( true );
		const found = await page.evaluate( () => window.bdrFound );
		expect( found ).toContain( `${ ids.hours }:invalid` );
		expect( found ).not.toContain( `${ ids.story }:deprecated` );
		expect( issues( ids.story ) ).toEqual( [], 'skipped by the filter, so the browser stored nothing for it' );

		await row( page, ids.hours ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '.bdr-fix-selected' ).click();
		await page.locator( '.bdr-apply' ).click();
		await expect( page.locator( '.bdr-modal .components-notice' ) ).toContainText( 'Fixed 1 post' );
		expect( await page.evaluate( () => window.bdrFixed ) ).toEqual( [ [ ids.hours, true, 1 ] ] );
		expect( content( ids.hours ) ).toContain( '<p>Image removed by a hook</p>' );
		expect( content( ids.hours ) ).toContain( MARKUP.goodHeading );
	} );

	test( 'the CSV download, the dashboard widget and Site Health', async ( { page } ) => {
		await scanSite( page );

		const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.locator( '.bdr-export' ).click() ] );
		const csv = require( 'fs' ).readFileSync( await download.path(), 'utf8' );
		const lines = csv.trim().split( '\r\n' );
		expect( lines[ 0 ] ).toBe( '"post_id","title","post_type","status","kind","block","path","message","detail","excerpt","source","changed_since_scan","scanned","edit_link"' );
		expect( lines ).toHaveLength( 13 );
		expect( csv ).toContain( `"${ ids.order }","Order online","post","publish","missing","acme/widget","1"` );

		await page.goto( '/wp-admin/index.php' );
		const widget = page.locator( '#block_doctor' );
		await expect( widget ).toContainText( '5 Invalid, in 4 posts' );
		await expect( widget.getByRole( 'link', { name: 'Open Block Doctor' } ) ).toHaveAttribute( 'href', /tools\.php\?page=block-doctor/ );

		const health = JSON.parse( wpEval( `$t = new AryanX\\BlockDoctor\\Health\\SiteHealth( AryanX\\BlockDoctor\\Plugin::instance()->report() ); echo wp_json_encode( $t->run() );` ) );
		expect( health.status ).toBe( 'recommended' );
		expect( health.label ).toMatch( /^7 posts have invalid or broken blocks \(last scan / );

		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( /7 posts have invalid or broken blocks/ ) ).toBeVisible( { timeout: 20_000 } );
	} );

	test( 'WP-CLI: server scan, report, batches, undo and clear', async ( { page } ) => {
		const scan = JSON.parse( wp( 'block-doctor', 'scan', '--format=json' ) );
		const found = scan.map( ( line ) => `${ line.post_id }:${ line.kind }@${ line.path }` );
		expect( found ).toEqual(
			expect.arrayContaining( [ `${ ids.order }:missing@1`, `${ ids.classic }:classic@0`, `${ ids.refs }:pattern-ref@0`, `${ ids.refs }:navigation-ref@1`, `${ ids.refs }:missing-media@2`, `${ ids.empty }:empty@0` ] )
		);
		// The server cannot validate markup, and has not seen the browser register acme/notice yet.
		expect( found.some( ( line ) => line.endsWith( ':invalid@1' ) ) ).toBe( false );
		expect( found ).toContain( `${ ids.notice }:missing@0` );
		expect( option( 'block_doctor_server_scan' ).status ).toBe( 'done' );
		expect( issues( ids.refs ) ).toEqual( [ 'pattern-ref@0', 'navigation-ref@1', 'missing-media@2' ] );

		// After a browser scan the server knows about the JavaScript-only block.
		await scanSite( page );
		expect( JSON.parse( wp( 'block-doctor', 'scan', '--format=json' ) ).some( ( line ) => line.post_id === ids.notice ) ).toBe( false );
		expect( issues( ids.hours ) ).toEqual( [ 'invalid@1', 'invalid@2' ], 'the server scan keeps what the browser found' );

		const report = JSON.parse( wp( 'block-doctor', 'report', '--kind=invalid', '--format=json' ) );
		expect( report.map( ( line ) => line.post_id ) ).toEqual( expect.arrayContaining( [ ids.hours, ids.nested, ids.badNotice, ids.pattern ] ) );
		expect( wp( 'block-doctor', 'report', '--format=count' ) ).toBe( '12' );

		const batch = await fixSelected( page, [ ids.hours ] );
		const batches = JSON.parse( wp( 'block-doctor', 'batches', '--format=json' ) );
		expect( batches[ 0 ] ).toMatchObject( { id: batch, posts: 1, undone: '' } );

		expect( wp( 'block-doctor', 'undo-batch', String( batch ) ) ).toContain( `Batch ${ batch } undone: 1 posts restored, 0 skipped.` );
		expect( content( ids.hours ) ).toContain( '<h2 class="wp-block-heading">Opening hours</h2>' );

		expect( wp( 'block-doctor', 'clear', '--yes' ) ).toContain( 'Cleared the results for' );
		expect( option( 'block_doctor_index' ) ).toBeNull();
		expect( issues( ids.hours ) ).toEqual( [] );
		expect( JSON.parse( wp( 'block-doctor', 'batches', '--format=json' ) ) ).toHaveLength( 1 );
	} );

	test( 'abilities: summary, issues, server scan and undo batch', async ( { page, rest } ) => {
		test.skip( ! atLeast( '6.9' ), 'The Abilities API arrived in WordPress 6.9.' );
		const run = '/wp-abilities/v1/abilities/block-doctor';

		const scan = await rest( 'POST', `${ run }/run-server-scan/run`, { input: { post_id: ids.refs } } );
		expect( scan.status ).toBe( 200 );
		expect( scan.body.issues.map( ( issue ) => issue.kind ) ).toEqual( [ 'pattern-ref', 'navigation-ref', 'missing-media' ] );
		expect( issues( ids.refs ) ).toEqual( [ 'pattern-ref@0', 'navigation-ref@1', 'missing-media@2' ] );

		const all = await rest( 'POST', `${ run }/run-server-scan/run`, { input: {} } );
		expect( all.status ).toBe( 200 );
		expect( all.body.state.status ).toBe( 'done' );

		const summary = await rest( 'GET', `${ run }/get-scan-summary/run` );
		expect( summary.status ).toBe( 200 );
		expect( summary.body.counts[ 'missing-media' ] ).toEqual( { issues: 1, posts: 1 } );
		expect( summary.body.server.status ).toBe( 'done' );

		const list = await rest( 'GET', `${ run }/list-issues/run?input[kind]=empty` );
		expect( list.status ).toBe( 200 );
		expect( list.body.total ).toBe( 1 );
		expect( list.body.items[ 0 ].id ).toBe( ids.empty );

		await scanSite( page );
		const batch = await fixSelected( page, [ ids.hours ] );
		const undo = await rest( 'DELETE', `${ run }/undo-batch/run?input[batch_id]=${ batch }` );
		expect( undo.status ).toBe( 200 );
		expect( undo.body ).toMatchObject( { batch_id: batch, undone: true, restored: [ ids.hours ], remaining: 0 } );
		expect( content( ids.hours ) ).toContain( '<h2 class="wp-block-heading">Opening hours</h2>' );

		const missing = await rest( 'DELETE', `${ run }/undo-batch/run?input[batch_id]=999` );
		expect( missing.status ).toBeGreaterThanOrEqual( 400 );
	} );

	test( 'plugin blocks registered from block.json, and blocks registered only in PHP, are never reported as unregistered', async ( { page } ) => {
		installShelfBlocks();
		const shelf = createPost( 'Top shelf', '<!-- wp:acme/shelf -->\n<div class="acme-shelf"><p>Top shelf</p></div>\n<!-- /wp:acme/shelf -->' );
		// Saved by an older version of the block, with a class it no longer adds.
		const oldShelf = createPost( 'Old shelf', '<!-- wp:acme/shelf -->\n<div class="acme-shelf acme-shelf--wide"><p>Old shelf</p></div>\n<!-- /wp:acme/shelf -->' );
		const clock = createPost( 'Opening clock', '<!-- wp:acme/clock {"format":"24h"} /-->\n\n' + MARKUP.paragraph( 'Come in.' ) );

		await scanSite( page );

		// The block's editor script loaded on the screen, so the block registered there too.
		expect( await page.evaluate( () => !! window.wp.blocks.getBlockType( 'acme/shelf' ) ) ).toBe( true );
		expect( issues( shelf ) ).toEqual( [] );
		// And its markup is really checked: the old one is invalid, not unregistered.
		expect( issues( oldShelf ) ).toEqual( [ 'invalid@0' ] );
		// Registered in PHP with no editor script: never unregistered.
		expect( issues( clock ) ).toEqual( [] );
		expect( issues( ids.order ) ).toEqual( [ 'missing@1' ], 'a block nothing registers is still reported' );
		await expect( page.locator( '.bdr-tab[data-kind="missing"] .bdr-count' ) ).toHaveText( '1' );
	} );

	test( 'Convert to Custom HTML never empties a dynamic block, and says why it stays', async ( { page } ) => {
		const dynamic = '<!-- wp:acme/plugin-shots {"ids":[4,5]} /-->';
		const mixed = createPost( 'Restore Blocks from Revisions', [ MARKUP.paragraph( 'Screenshots below.' ), dynamic, MARKUP.widget ].join( '\n\n' ) );
		const only = createPost( 'Screenshots only', dynamic );
		await scanSite( page );
		expect( issues( mixed ) ).toEqual( [ 'missing@1', 'missing@2' ] );

		await page.locator( '.bdr-tab[data-kind="missing"]' ).click();
		await page.locator( '.bdr-fix-kind' ).click();
		await expect( page.locator( '.bdr-preview-count' ) ).toContainText( '2 of 3 posts change.' );
		await expect( page.locator( '.bdr-preview-count' ) ).toContainText( '2 blocks stay as they are' );
		const kept = page.locator( `.bdr-preview-item[data-post="${ only }"] .bdr-kept` );
		await expect( kept ).toContainText( 'acme/plugin-shots' );
		await expect( kept ).toContainText( 'It has no saved HTML' );
		// The conversion drops the block's settings, so its diff is open straight away.
		await expect( page.locator( `.bdr-preview-item[data-post="${ mixed }"] .bdr-diff` ) ).toBeVisible();

		await page.locator( '.bdr-apply' ).click();
		await expect( page.locator( '.bdr-modal .components-notice' ) ).toContainText( 'Fixed 2 posts' );
		await page.locator( '.bdr-close' ).click();

		expect( content( mixed ) ).toBe( [ MARKUP.paragraph( 'Screenshots below.' ), dynamic, '<!-- wp:html -->\n<div class="acme-widget">Order online</div>\n<!-- /wp:html -->' ].join( '\n\n' ) );
		expect( content( only ) ).toBe( dynamic );
		expect( content( mixed ) ).not.toContain( '<!-- wp:html /-->' );
		expect( issues( mixed ) ).toEqual( [ 'missing@1' ] );
	} );

	test( 'a refused save shows only the error and keeps the preview, so the fixes can be applied later', async ( { page } ) => {
		await scanSite( page );
		await row( page, ids.hours ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '.bdr-fix-selected' ).click();
		const locked = { status: 423, contentType: 'application/json', body: JSON.stringify( { code: 'site_frozen', message: 'The site is read-only right now.', data: { status: 423 } } ) };

		// The batch itself is refused, like during a site freeze.
		await page.route( /block-doctor(\/|%2F)v1(\/|%2F)batches(\?|&|$)/, ( route ) => ( route.request().method() === 'POST' ? route.fulfill( locked ) : route.continue() ) );
		await page.locator( '.bdr-apply' ).click();
		await expect( page.locator( '.bdr-modal .components-notice.is-error' ) ).toContainText( 'The site is read-only right now.' );
		await expect( page.locator( '.bdr-modal .components-notice.is-success' ) ).toHaveCount( 0 );
		await expect( page.locator( '.bdr-modal' ) ).not.toContainText( 'Fixed 0' );
		await expect( page.locator( '.bdr-apply' ) ).toBeEnabled();
		await page.unroute( /block-doctor(\/|%2F)v1(\/|%2F)batches(\?|&|$)/ );

		// Every save is refused.
		await page.route( /block-doctor(\/|%2F)v1(\/|%2F)fix/, ( route ) => route.fulfill( locked ) );
		await page.locator( '.bdr-apply' ).click();
		await expect( page.locator( '.bdr-modal .components-notice.is-error' ) ).toContainText( 'The site is read-only right now.' );
		await expect( page.locator( '.bdr-modal .components-notice.is-success' ) ).toHaveCount( 0 );
		await expect( page.locator( '.bdr-apply' ) ).toBeEnabled();
		expect( content( ids.hours ) ).toContain( MARKUP.badHeading );
		await page.unroute( /block-doctor(\/|%2F)v1(\/|%2F)fix/ );

		// Later it works, from the same preview.
		await page.locator( '.bdr-apply' ).click();
		await expect( page.locator( '.bdr-modal .components-notice.is-success' ) ).toContainText( 'Fixed 1 post' );
		await expect( page.locator( '.bdr-modal .components-notice.is-error' ) ).toHaveCount( 0 );
		await page.locator( '.bdr-close' ).click();
		expect( content( ids.hours ) ).toContain( MARKUP.goodHeading );
		// The batch that saved nothing is not offered for undo.
		const batches = JSON.parse( wp( 'block-doctor', 'batches', '--format=json' ) );
		expect( batches.map( ( batch ) => batch.posts ) ).toEqual( [ 1 ] );
		await expect( page.locator( '.bdr-batches tbody tr' ) ).toHaveCount( 1 );
	} );

	test( 'dates use the site format and time zone, not the browser', async ( { page } ) => {
		const saved = { date_format: wp( 'option', 'get', 'date_format' ), time_format: wp( 'option', 'get', 'time_format' ), timezone_string: wp( 'option', 'get', 'timezone_string' ) };
		try {
			wp( 'option', 'update', 'date_format', 'Y-m-d' );
			wp( 'option', 'update', 'time_format', 'H:i' );
			wp( 'option', 'update', 'timezone_string', 'Pacific/Kiritimati' );
			await scanSite( page );
			const finished = wpEval( "echo wp_date( 'Y-m-d H:i', get_option( 'block_doctor_scan' )['finished'] );" );
			await expect( page.locator( '.bdr-last-scan' ) ).toContainText( `Last scan: ${ finished },` );

			await fixSelected( page, [ ids.hours ] );
			const time = wpEval( "$b = get_option( 'block_doctor_batches' ); echo wp_date( 'Y-m-d H:i', $b['items'][1]['time'] );" );
			await expect( page.locator( '.bdr-batches tr[data-batch="1"] td' ).nth( 1 ) ).toHaveText( time );
		} finally {
			for ( const [ name, value ] of Object.entries( saved ) ) {
				wp( 'option', 'update', name, value );
			}
		}
	} );

	test( 'the report speaks plainly: labels, block titles, grouped issues, sentence case and filter buttons', async ( { page } ) => {
		const logs = [];
		page.on( 'console', ( message ) => logs.push( message.text() ) );
		const outdated = createPost( 'History', [ 1, 2, 3, 4, 5 ].map( ( n ) => `<!-- wp:heading -->\n<h2>Chapter ${ n }</h2>\n<!-- /wp:heading -->` ).join( '\n\n' ) );
		const secret = createPost( 'Staff rota', MARKUP.badHeading, { post_status: 'private' } );
		await scanSite( page );

		// Status labels, not slugs.
		await expect( row( page, ids.hours ).locator( '.bdr-meta' ) ).toHaveText( 'Post · Published' );
		await expect( row( page, secret ).locator( '.bdr-meta' ) ).toHaveText( 'Post · Private' );

		// The block title, with the name second, its first words and where it is.
		const hours = row( page, ids.hours );
		await expect( hours.locator( '.bdr-block' ).first() ).toHaveText( 'Heading' );
		await expect( hours.locator( '.bdr-slug' ).first() ).toHaveText( 'core/heading' );
		await expect( hours.locator( '.bdr-excerpt' ).first() ).toHaveText( '“Opening hours”' );
		await expect( hours.locator( '.bdr-position' ).first() ).toHaveText( 'block 2 from the top' );
		await expect( row( page, ids.nested ).locator( '.bdr-position' ) ).toHaveText( 'block 2 inside block 1' );
		// A plain message; the validation message with its code is a click away, without backticks.
		expect( await hours.locator( '.bdr-message' ).first().innerText() ).toBe( 'The saved markup no longer matches what the block produces.' );
		await expect( hours.locator( '.bdr-detail-text' ).first() ).toBeHidden();
		await hours.locator( '.bdr-detail summary' ).first().click();
		await expect( hours.locator( '.bdr-detail-text code' ).first() ).toHaveText( 'h3' );
		expect( await hours.innerText() ).not.toContain( '`' );

		// One group per kind: the count, the titles, the shared message once, and the blocks folded away.
		const group = row( page, outdated ).locator( '.bdr-group[data-kind="deprecated"]' );
		await expect( group.locator( '.bdr-group-summary' ) ).toHaveText( '5 blocks: Heading ×5' );
		await expect( group.locator( '.bdr-message' ) ).toHaveCount( 1 );
		await expect( group.locator( '.bdr-group-more summary' ) ).toHaveText( 'Show all 5' );
		await expect( group.locator( '.bdr-issue' ).first() ).toBeHidden();

		// Filter buttons, not tabs, each counting posts.
		await expect( page.locator( '.bdr-tabs' ) ).toHaveAttribute( 'role', 'group' );
		await expect( page.locator( '[role="tab"], [role="tablist"]' ) ).toHaveCount( 0 );
		const invalid = page.locator( '.bdr-tab[data-kind="invalid"]' );
		await expect( invalid ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( invalid.locator( '.bdr-count' ) ).toHaveText( '5' );
		await invalid.click();
		await expect( invalid ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( page.locator( '.bdr-tab[data-kind="all"]' ) ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( page.locator( '.bdr-kind-description' ) ).toContainText( '6 blocks in 5 posts.' );

		// Sentence case, like the rest of wp-admin.
		expect( await page.locator( '.bdr-badge' ).first().evaluate( ( node ) => getComputedStyle( node ).textTransform ) ).toBe( 'none' );
		expect( await page.locator( '.bdr-missing-option label' ).first().evaluate( ( node ) => getComputedStyle( node ).textTransform ) ).toBe( 'none' );

		// The preview's per-block choice uses the title too, and no select warns about its old size.
		await row( page, ids.hours ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '.bdr-fix-selected' ).click();
		await expect( page.locator( '.bdr-strategy' ).first() ).toContainText( 'Heading, 1 block' );
		expect( logs.filter( ( text ) => /36px default size|__next40pxDefaultSize/.test( text ) ) ).toEqual( [] );
	} );

	test( 'a post the scan cannot read is reported as not checked, not stored as clean', async ( { page } ) => {
		const broken = createPost( 'Broken widget', '<!-- wp:acme/boom -->\n<div class="boom" data-x="1">Boom</div>\n<!-- /wp:acme/boom -->' );
		await page.goto( SCREEN );
		// A block type whose attribute selector makes the editor's parser throw.
		await page.evaluate( () =>
			window.wp.blocks.registerBlockType( 'acme/boom', {
				title: 'Boom',
				category: 'text',
				attributes: { x: { type: 'string', source: 'attribute', selector: '[[[', attribute: 'data-x' } },
				edit: () => null,
				save: () => null,
			} )
		);
		await page.locator( '.bdr-start' ).click();
		await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 30_000 } );

		expect( issues( broken ) ).toEqual( [ 'error@0' ] );
		await expect( page.locator( '.bdr-tab[data-kind="error"]' ) ).toContainText( 'Not checked' );
		await expect( row( page, broken ) ).toContainText( 'The scan could not read the blocks of this post, so they were not checked.' );
		expect( issues( ids.hours ) ).toEqual( [ 'invalid@1', 'invalid@2' ], 'the other posts are checked as usual' );
	} );

	test( 'the results index is written once per batch of posts, not once per post', async ( { page } ) => {
		installIndexCounter();
		await scanSite( page );
		expect( Object.keys( option( 'block_doctor_index' ) ) ).toHaveLength( 9 );
		expect( indexWrites() ).toBe( 1 );

		// Deleting several posts with issues in one request writes it once too.
		installIndexCounter();
		wp( 'post', 'delete', String( ids.hours ), String( ids.order ), String( ids.classic ), '--force' );
		expect( indexWrites() ).toBe( 1 );
		expect( Object.keys( option( 'block_doctor_index' ) ) ).toHaveLength( 6 );
	} );

	test( 'people without the capability cannot open the screen or call the routes', async ( { browser } ) => {
		try {
			wp( 'user', 'delete', 'writer', '--yes' );
		} catch ( error ) {
			// Not there yet.
		}
		wp( 'user', 'create', 'writer', 'writer@example.com', '--role=author', '--user_pass=password' );
		const { context, page } = await logInAs( browser, 'writer', 'password' );
		await expect( page ).toHaveURL( /wp-admin/ );

		await page.goto( SCREEN );
		await expect( page.locator( 'body' ) ).toContainText( 'Sorry, you are not allowed to access this page.' );
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const response = await page.request.get( '/index.php?rest_route=/block-doctor/v1/posts', { headers: { 'X-WP-Nonce': nonce } } );
		expect( response.status() ).toBe( 403 );
		const fix = await page.request.post( '/index.php?rest_route=/block-doctor/v1/fix', { headers: { 'X-WP-Nonce': nonce }, data: { post_id: ids.hours, batch: 1, content: 'x', hash: 'y' } } );
		expect( fix.status() ).toBe( 403 );
		expect( content( ids.hours ) ).toContain( MARKUP.badHeading );

		await context.close();
		wp( 'user', 'delete', 'writer', '--yes' );
	} );
} );
