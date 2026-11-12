// End-to-end tests for the block usage index, the Block usage tab and the warnings before a plugin is switched off.
// Every test checks the database through WP-CLI as well as the screen.
const { execFileSync } = require( 'child_process' );
const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const { COMPOSE_DIR } = require( './utils/config' );
const { MARKUP, createPost, option, installAcme, buildUsage, installSecretType, reset } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=block-doctor';
const USAGE = `${ SCREEN }&tab=usage`;
const atLeast = ( wanted ) => {
	const [ major, minor ] = wp( 'core', 'version' ).split( '.' ).map( Number );
	const [ wantMajor, wantMinor ] = wanted.split( '.' ).map( Number );
	return major > wantMajor || ( major === wantMajor && minor >= wantMinor );
};

/** WP-CLI with standard error folded into the output, for the lines it prints as warnings. */
const wpAll = ( ...args ) =>
	execFileSync( 'docker', [ 'compose', 'exec', '-T', 'cli', 'sh', '-c', 'wp "$@" 2>&1', 'wp', ...args ], { cwd: COMPOSE_DIR, encoding: 'utf8' } ).trim();

/** The usage row of a block type. */
const usageRow = ( page, name ) => page.locator( `.bdr-usage tr[data-block="${ name }"]` );

test.describe.serial( 'Block usage', () => {
	let ids;

	test.beforeEach( () => {
		reset();
		ids = installAcme();
		buildUsage();
	} );

	test.afterAll( reset );

	test( 'the index counts every block type with its posts and uses, and the CLI lists them', async () => {
		const rows = JSON.parse( wp( 'block-doctor', 'usage', '--format=json' ) );
		const gallery = rows.find( ( row ) => row.block === 'acme/gallery' );
		expect( gallery ).toMatchObject( { provider: 'Acme Gallery', type: 'plugin', registered: 'yes', posts: 3, uses: 4 } );
		expect( rows.find( ( row ) => row.block === 'acme/ticker' ) ).toMatchObject( { provider: 'Acme Gallery', posts: 1, uses: 1 } );
		expect( rows.find( ( row ) => row.block === 'core/group' ) ).toMatchObject( { provider: 'WordPress', type: 'core', posts: 1 } );
		expect( rows.find( ( row ) => row.block === 'core/button' ), 'registered blocks nobody uses are listed too' ).toMatchObject( { posts: 0, uses: 0 } );

		// One meta row per post and block type, in the database.
		expect( wpEval( `echo get_post_meta( ${ ids.galleryA }, '_block_doctor_use_acme/gallery', true );` ) ).toBe( '2' );
		expect( wp( 'block-doctor', 'usage', '--filter=unused', '--format=count' ) ).not.toBe( '0' );

		const posts = JSON.parse( wp( 'block-doctor', 'usage', '--block=acme/gallery', '--format=json' ) );
		expect( posts.map( ( post ) => post.post_id ).sort() ).toEqual( [ ids.galleryA, ids.galleryB, ids.galleryC ].sort() );
		expect( posts.find( ( post ) => post.post_id === ids.galleryA ) ).toMatchObject( { uses: 2, positions: '0.0 0.1' } );

		const byPlugin = JSON.parse( wp( 'block-doctor', 'usage', '--provider=plugin:acme-gallery/acme-gallery.php', '--format=json' ) );
		// The block registered only in JavaScript has no plugin until a browser scan has seen its script.
		expect( byPlugin.map( ( post ) => post.post_id ).sort() ).toEqual( [ ids.galleryA, ids.galleryB, ids.galleryC, ids.ticker ].sort() );
	} );

	test( 'the index follows saves, trash and deletes without a rebuild', async () => {
		const id = createPost( 'Fresh', '<!-- wp:acme/ticker /-->' );
		const count = () => JSON.parse( wp( 'block-doctor', 'usage', '--format=json' ) ).find( ( row ) => row.block === 'acme/ticker' ).posts;
		expect( count() ).toBe( 2 );

		wp( 'post', 'update', String( id ), '--post_content=<!-- wp:acme/gallery /-->' );
		expect( count() ).toBe( 1 );
		expect( wpEval( `echo get_post_meta( ${ id }, '_block_doctor_use_acme/ticker', true ) === '' ? 'gone' : 'there';` ) ).toBe( 'gone' );
		expect( JSON.parse( wp( 'block-doctor', 'usage', '--block=acme/gallery', '--format=json' ) ) ).toHaveLength( 4 );

		wp( 'post', 'delete', String( id ) );
		expect( JSON.parse( wp( 'block-doctor', 'usage', '--block=acme/gallery', '--format=json' ) ) ).toHaveLength( 3 );
		wp( 'post', 'delete', String( ids.galleryB ), '--force' );
		expect( JSON.parse( wp( 'block-doctor', 'usage', '--block=acme/gallery', '--format=json' ) ) ).toHaveLength( 2 );
	} );

	test( 'the Block usage tab: filters, search, the posts of a block with their positions, and CSV', async ( { page } ) => {
		await page.goto( USAGE );
		await expect( usageRow( page, 'acme/gallery' ) ).toBeVisible();
		await expect( usageRow( page, 'acme/gallery' ) ).toContainText( 'Acme Gallery' );
		await expect( usageRow( page, 'acme/gallery' ).locator( 'td' ).nth( 2 ) ).toHaveText( '3' );
		await expect( usageRow( page, 'acme/gallery' ).locator( 'td' ).nth( 3 ) ).toHaveText( '4' );
		await expect( usageRow( page, 'acme/gallery' ).locator( 'time.axle-time' ) ).toHaveText( /ago|Just now/ );
		await expect( page.locator( '.bdr-usage-stats' ) ).toContainText( 'block types in use' );

		// Filters.
		await page.locator( '#bdr-usage-filter' ).selectOption( 'plugin' );
		await expect( page.locator( '.bdr-usage tbody tr' ).first() ).toContainText( /Acme/ );
		await expect( usageRow( page, 'core/group' ) ).toHaveCount( 0 );
		await page.locator( '#bdr-usage-filter' ).selectOption( 'unused' );
		await expect( usageRow( page, 'core/button' ) ).toBeVisible();
		await expect( usageRow( page, 'acme/gallery' ) ).toHaveCount( 0 );
		await page.locator( '#bdr-usage-filter' ).selectOption( '' );
		await page.locator( '#bdr-usage-search' ).fill( 'ticker' );
		await expect( page.locator( '.bdr-usage tbody tr' ) ).toHaveCount( 1 );
		await page.locator( '#bdr-usage-search' ).fill( 'zzzz-nothing' );
		await expect( page.locator( '.bdr-usage-empty' ) ).toContainText( 'No block type matches' );
		await page.locator( '.bdr-usage-empty' ).getByRole( 'button', { name: 'Clear the filters' } ).click();
		await expect( usageRow( page, 'acme/gallery' ) ).toBeVisible();

		// The posts of one block type, with where the block sits.
		await page.evaluate( () => {
			window.opened = [];
			window.wp.hooks.addAction( 'blockDoctor.usageOpened', 'test', ( block, provider ) => window.opened.push( [ block, provider ] ) );
		} );
		await usageRow( page, 'acme/gallery' ).locator( '.bdr-usage-open' ).click();
		const detail = page.locator( '.bdr-usage-detail' );
		await expect( detail.locator( 'h2' ) ).toBeFocused();
		await expect( detail.locator( 'tbody tr' ) ).toHaveCount( 3 );
		await expect( detail.locator( `tr[data-post="${ ids.galleryA }"]` ) ).toContainText( 'block 1 inside block 1; block 2 inside block 1' );
		await expect( detail.locator( `tr[data-post="${ ids.galleryC }"]` ).locator( 'td.bdr-where' ) ).toHaveText( 'block 2 from the top' );
		await expect( detail.locator( `tr[data-post="${ ids.galleryB }"] a.row-title` ) ).toHaveAttribute( 'href', new RegExp( `post=${ ids.galleryB }&action=edit` ) );
		expect( await page.evaluate( () => window.opened ) ).toEqual( [ [ 'acme/gallery', '' ] ] );

		const [ posts ] = await Promise.all( [ page.waitForEvent( 'download' ), detail.locator( '.bdr-usage-posts-export' ).click() ] );
		const postsCsv = require( 'fs' ).readFileSync( await posts.path(), 'utf8' );
		expect( postsCsv.split( '\r\n' )[ 0 ] ).toBe( '"post_id","title","post_type","status","uses","edit_link"' );
		expect( postsCsv ).toContain( `"${ ids.galleryA }","Gallery page","page","publish","2"` );
		await detail.locator( '.bdr-usage-close' ).click();
		await expect( detail ).toHaveCount( 0 );

		const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.locator( '.bdr-usage-export' ).click() ] );
		const csv = require( 'fs' ).readFileSync( await download.path(), 'utf8' );
		expect( csv.split( '\r\n' )[ 0 ] ).toBe( '"block","title","provider_type","provider","registered","posts","uses","last_used"' );
		expect( csv ).toContain( '"acme/gallery","Acme gallery","plugin","Acme Gallery","yes","3","4"' );
	} );

	test( 'a JavaScript hook can change the rows of the tab', async ( { page } ) => {
		await page.goto( USAGE );
		await expect( usageRow( page, 'acme/ticker' ) ).toBeVisible();
		await page.evaluate( () => window.wp.hooks.addFilter( 'blockDoctor.usageRows', 'test', ( rows ) => rows.filter( ( row ) => row.name !== 'acme/ticker' ) ) );
		await page.locator( '#bdr-usage-filter' ).selectOption( 'plugin' );
		await expect( usageRow( page, 'acme/gallery' ) ).toBeVisible();
		await expect( usageRow( page, 'acme/ticker' ) ).toHaveCount( 0 );
	} );

	test( 'the browser scan records which plugin registered a JavaScript-only block', async ( { page } ) => {
		await page.goto( USAGE );
		await expect( usageRow( page, 'acme/gallery' ) ).toBeVisible();
		// Until a browser scan has seen its script, nothing says who provides it.
		await expect( usageRow( page, 'acme/js-only' ).locator( '.bdr-provider' ) ).toHaveText( 'Unknown' );
		// Registered only in the browser, so the server knows nothing of it yet.
		expect( option( 'block_doctor_js_sources' ) ).toBeNull();

		await page.goto( SCREEN );
		await page.locator( '.bdr-start' ).click();
		await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 30_000 } );
		expect( Object.keys( option( 'block_doctor_js_sources' ) ) ).toEqual( [ 'acme/js-only' ] );
		expect( option( 'block_doctor_js_sources' )[ 'acme/js-only' ] ).toMatchObject( { type: 'plugin', name: 'Acme Gallery', basename: 'acme-gallery/acme-gallery.php' } );

		await page.goto( USAGE );
		await expect( usageRow( page, 'acme/js-only' ) ).toContainText( 'Acme Gallery' );
		await expect( usageRow( page, 'acme/js-only' ).locator( '.bdr-unregistered' ) ).toHaveCount( 0 );
	} );

	test( 'the Plugins screen shows a step before Deactivate, and Cancel changes nothing', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator( 'tr[data-slug="acme-gallery"]' );
		const link = row.locator( '.deactivate a' );
		await link.click();

		const step = page.locator( '.block-doctor-warning-row .axle-confirm' );
		await expect( step ).toContainText( 'Acme Gallery provides blocks that posts use. Its 2 block types are used in 4 posts.' );
		await expect( step ).toBeFocused();
		await expect( step.locator( '.block-doctor-top-posts li' ).first() ).toContainText( 'Gallery page' );
		await expect( step.locator( '.block-doctor-all-posts' ) ).toHaveAttribute( 'href', /tab=usage&provider=plugin%3Aacme-gallery%2Facme-gallery.php/ );

		await step.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( step ).toHaveCount( 0 );
		await expect( link ).toBeFocused();
		expect( wpEval( "echo is_plugin_active( 'acme-gallery/acme-gallery.php' ) ? 'on' : 'off';" ) ).toBe( 'on' );

		// Continue carries on and the plugin goes off.
		await page.evaluate( () => {
			window.wp.hooks.addAction( 'blockDoctor.deactivationContinue', 'test', ( plugin, action ) => window.sessionStorage.setItem( 'carried', JSON.stringify( [ plugin, action ] ) ) );
		} );
		await link.click();
		await page.locator( '.block-doctor-continue' ).click();
		// Core tidies the address after the redirect, so wait for what the page shows.
		await expect( page.locator( '.block-doctor-affected' ) ).toBeVisible();
		expect( wpEval( "echo is_plugin_active( 'acme-gallery/acme-gallery.php' ) ? 'on' : 'off';" ) ).toBe( 'off' );

		// And a notice says what it left behind, with a way to see the posts.
		const notice = page.locator( '.block-doctor-affected' );
		expect( JSON.parse( await page.evaluate( () => window.sessionStorage.getItem( 'carried' ) ) ) ).toEqual( [ 'acme-gallery/acme-gallery.php', 'deactivate' ] );
		await expect( notice ).toContainText( 'Acme Gallery is off, and its 2 block types are still used in 4 posts.' );
		await expect( notice.getByRole( 'link', { name: 'See the posts' } ) ).toHaveAttribute( 'href', /tab=usage&provider=/ );
		expect( Object.keys( option( 'block_doctor_affected' ) ) ).toEqual( [ 'plugin:acme-gallery/acme-gallery.php' ] );
	} );

	test( 'after the plugin is off its blocks stay attributed to it, in the tab, Site Health and the notice', async ( { page } ) => {
		wp( 'plugin', 'deactivate', 'acme-gallery' );

		await page.goto( USAGE );
		await expect( usageRow( page, 'acme/gallery' ).locator( '.bdr-unregistered' ) ).toBeVisible();
		await expect( usageRow( page, 'acme/gallery' ) ).toContainText( 'Acme Gallery' );
		await expect( page.locator( '.bdr-usage-stats .axle-stat--error' ) ).toBeVisible();
		await page.locator( '#bdr-usage-filter' ).selectOption( 'unregistered' );
		await expect( page.locator( '.bdr-usage tbody tr' ) ).toHaveCount( 3 );

		const health = JSON.parse( wpEval( 'echo wp_json_encode( AryanX\\Axle\\BlockDoctor\\Plugin::instance()->deactivation() instanceof AryanX\\Axle\\BlockDoctor\\Deactivation ? ( new AryanX\\Axle\\BlockDoctor\\Health\\SiteHealth( AryanX\\Axle\\BlockDoctor\\Plugin::instance()->report(), AryanX\\Axle\\BlockDoctor\\Plugin::instance()->usage(), AryanX\\Axle\\BlockDoctor\\Plugin::instance()->deactivation() ) )->run_orphans() : null );' ) );
		expect( health.status ).toBe( 'recommended' );
		expect( health.label ).toBe( '4 posts use blocks from a plugin or theme that is off' );
		expect( health.description ).toContain( 'Acme Gallery' );

		// Turning the plugin back on clears the problem.
		wp( 'plugin', 'activate', 'acme-gallery' );
		await page.goto( USAGE );
		await expect( usageRow( page, 'acme/gallery' ).locator( '.bdr-unregistered' ) ).toHaveCount( 0 );
		expect( option( 'block_doctor_affected' ) ).toBeNull();
	} );

	test( 'Delete is held back too, and the posts are listed', async ( { page } ) => {
		wp( 'plugin', 'deactivate', 'acme-gallery' );
		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator( 'tr[data-slug="acme-gallery"]' );
		await row.locator( '.delete a' ).click();
		const step = page.locator( '.block-doctor-warning-row .axle-confirm' );
		await expect( step ).toContainText( 'Its 2 block types are used in 4 posts.' );
		await expect( step ).toContainText( 'Deleting removes its files' );
		await expect( step.getByRole( 'button', { name: 'Delete anyway' } ) ).toBeVisible();
		await step.getByRole( 'button', { name: 'Cancel' } ).click();
		expect( wpEval( "echo is_dir( WP_PLUGIN_DIR . '/acme-gallery' ) ? 'there' : 'gone';" ) ).toBe( 'there' );
	} );

	test( 'WP-CLI is never blocked: deactivating prints a warning line and then does it', async () => {
		const output = wpAll( 'plugin', 'deactivate', 'acme-gallery' );
		expect( output ).toContain( 'Warning: Acme Gallery provides blocks that posts use. Its 2 block types are used in 4 posts.' );
		expect( output ).toContain( 'wp block-doctor usage --provider=plugin:acme-gallery/acme-gallery.php' );
		expect( output ).toContain( 'Success: Deactivated 1 of 1 plugins.' );
		expect( wpEval( "echo is_plugin_active( 'acme-gallery/acme-gallery.php' ) ? 'on' : 'off';" ) ).toBe( 'off' );
	} );

	test( 'the first build runs in the background, and Count now builds it from the screen', async ( { page } ) => {
		wp( 'block-doctor', 'clear', '--yes' );
		wpEval( "AryanX\\Axle\\BlockDoctor\\Plugin::instance()->usage()->clear();" );
		expect( option( 'block_doctor_usage_scan' ) ).toBeNull();

		await page.goto( USAGE );
		const card = page.locator( '.bdr-index' );
		await expect( card ).toContainText( 'Counting block usage' );
		// A visit by someone who may use Block Doctor schedules the first build.
		expect( wpEval( "echo wp_next_scheduled( 'block_doctor_usage_index' ) ? 'scheduled' : 'no';" ) ).toBe( 'scheduled' );

		await card.getByRole( 'button', { name: 'Count now' } ).click();
		await expect( card ).toHaveCount( 0, { timeout: 30_000 } );
		await expect( page.locator( '.bdr-notice' ) ).toContainText( 'The blocks of every post are counted.' );
		await expect( usageRow( page, 'acme/gallery' ) ).toBeVisible();
		expect( option( 'block_doctor_usage_scan' ).status ).toBe( 'done' );
	} );

	test( 'abilities: read block usage and build the index', async ( { rest } ) => {
		test.skip( ! atLeast( '6.9' ), 'The Abilities API arrived in WordPress 6.9.' );
		const run = '/wp-abilities/v1/abilities/block-doctor';

		const list = await rest( 'GET', `${ run }/get-block-usage/run?input[filter]=plugin` );
		expect( list.status ).toBe( 200 );
		expect( list.body.ready ).toBe( true );
		expect( list.body.blocks.map( ( block ) => block.name ) ).toEqual( expect.arrayContaining( [ 'acme/gallery', 'acme/ticker' ] ) );
		expect( list.body.blocks.find( ( block ) => block.name === 'acme/gallery' ) ).toMatchObject( { posts: 3, uses: 4, provider: { name: 'Acme Gallery', type: 'plugin' } } );

		const posts = await rest( 'GET', `${ run }/get-block-usage/run?input[block]=acme/gallery` );
		expect( posts.status ).toBe( 200 );
		expect( posts.body.total ).toBe( 3 );
		expect( posts.body.posts.find( ( post ) => post.id === ids.galleryA ).positions ).toEqual( [ [ 0, 0 ], [ 0, 1 ] ] );

		const index = await rest( 'POST', `${ run }/run-usage-index/run` );
		expect( index.status ).toBe( 200 );
		expect( index.body ).toMatchObject( { ready: true, status: 'done' } );
	} );

	test( 'people see usage only for the post types they may edit, and the report never lists a post they cannot', async ( { browser, page } ) => {
		installSecretType();
		const secret = createPost( 'Board minutes', MARKUP.classic + '\n\n<!-- wp:acme/ticker /-->', { post_type: 'bd_secret' } );
		wp( 'block-doctor', 'scan', '--format=count' );
		buildUsage();
		try {
			wp( 'user', 'delete', 'editor-user', '--yes' );
		} catch ( error ) {
			// Not there yet.
		}
		wp( 'user', 'create', 'editor-user', 'editor@example.com', '--role=editor', '--user_pass=password' );

		// The administrator sees it all, in the tab and the report.
		await page.goto( USAGE );
		await usageRow( page, 'acme/ticker' ).locator( '.bdr-usage-open' ).click();
		await expect( page.locator( `.bdr-usage-detail tr[data-post="${ secret }"]` ) ).toContainText( 'Board minutes' );
		const adminIssues = await page.evaluate( () => window.wp.apiFetch( { path: '/block-doctor/v1/issues?per_page=100' } ) );
		expect( adminIssues.items.map( ( item ) => item.id ) ).toContain( secret );

		// An editor may use Block Doctor, but cannot edit this post type.
		const { context, page: editor } = await logInAs( browser, 'editor-user', 'password' );
		const nonce = ( await ( await editor.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const get = async ( route ) => {
			const response = await editor.request.get( `/index.php?rest_route=${ encodeURIComponent( route.split( '?' )[ 0 ] ) }&${ route.split( '?' )[ 1 ] || '' }`, { headers: { 'X-WP-Nonce': nonce } } );
			return { status: response.status(), body: await response.json() };
		};

		const issues = await get( '/block-doctor/v1/issues?per_page=100' );
		expect( issues.status ).toBe( 200 );
		expect( issues.body.items.map( ( item ) => item.id ) ).not.toContain( secret );
		expect( issues.body.total ).toBe( issues.body.items.length );
		expect( JSON.stringify( issues.body ) ).not.toContain( 'Board minutes' );
		const ids_only = await get( '/block-doctor/v1/issues?ids_only=1' );
		expect( ids_only.body.ids ).not.toContain( secret );

		const usage = await get( '/block-doctor/v1/usage?per_page=100' );
		const ticker = usage.body.items.find( ( row ) => row.name === 'acme/ticker' );
		expect( ticker.posts, 'the secret post is not counted for an editor' ).toBe( 1 );
		const tickerPosts = await get( '/block-doctor/v1/usage/posts?block=acme%2Fticker' );
		expect( tickerPosts.body.items.map( ( item ) => item.id ) ).not.toContain( secret );
		expect( JSON.stringify( tickerPosts.body ) ).not.toContain( 'Board minutes' );

		await editor.goto( `${ SCREEN }` );
		const csv = await editor.evaluate( async ( url ) => ( await fetch( url ) ).text(), `/wp-admin/admin-post.php?action=block_doctor_export&_wpnonce=${ await editor.evaluate( () => window.blockDoctorData.exportUrl.match( /_wpnonce=(\w+)/ )[ 1 ] ) }` );
		expect( csv ).not.toContain( 'Board minutes' );

		await context.close();
		wp( 'user', 'delete', 'editor-user', '--yes' );
	} );
} );
