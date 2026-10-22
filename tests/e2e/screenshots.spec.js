// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { MARKUP, createPost, reset, seed } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=block-doctor';

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 2 } );
	let ids;

	test.beforeAll( () => {
		reset();
		prepareSite( 'Corner Bakery' );
		ids = seed();
	} );

	test.afterAll( reset );

	test( '1: the report after a scan', async ( { page } ) => {
		await page.goto( SCREEN );
		await page.locator( '.bdr-start' ).click();
		await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 30_000 } );
		await expect( page.locator( '.bdr-table tbody tr' ) ).toHaveCount( 9 );
		const height = await page.evaluate( () => document.querySelector( '.bdr-table' ).getBoundingClientRect().bottom + window.scrollY + 40 );
		await page.setViewportSize( { width: 1280, height: Math.ceil( height ) } );
		await shot( page, 1 );
	} );

	test( '2: the preview with a diff', async ( { page } ) => {
		await page.goto( SCREEN );
		for ( const id of [ ids.hours, ids.nested, ids.badNotice ] ) {
			await page.locator( `.bdr-table tr[data-post="${ id }"] input[type="checkbox"]` ).check();
		}
		await page.locator( '.bdr-fix-selected' ).click();
		await page.locator( '.bdr-strategy' ).filter( { hasText: 'core/image' } ).locator( 'select' ).selectOption( 'html' );
		const item = page.locator( `.bdr-preview-item[data-post="${ ids.hours }"]` );
		await item.locator( '.bdr-toggle-diff' ).click();
		await expect( item.locator( '.bdr-diff' ) ).toBeVisible();
		await shot( page, 2 );
	} );

	test( '3: a scan in progress', async ( { page } ) => {
		for ( let i = 1; i <= 60; i++ ) {
			createPost( `Recipe ${ i }`, MARKUP.paragraph( `Recipe ${ i }` ) );
		}
		await page.setViewportSize( { width: 1280, height: 640 } );
		await page.goto( SCREEN );
		await page.evaluate( () => window.wp.hooks.addFilter( 'blockDoctor.batchSize', 'screenshot', () => 5 ) );
		await page.route( /results/, async ( route ) => {
			await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );
			await route.continue();
		} );
		await page.locator( '.bdr-start' ).click();
		await expect( page.locator( '.bdr-progress-label' ) ).toContainText( /^(2\d|3\d) of 71 posts checked, about .* left/, { timeout: 30_000 } );
		await shot( page, 3, { keepHover: true } );
		await page.locator( '.bdr-pause' ).click();
		await expect( page.locator( '.bdr-resume' ) ).toBeVisible( { timeout: 15_000 } );
	} );

	test( '4: fix batches with Undo batch', async ( { page } ) => {
		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.goto( SCREEN );
		await page.locator( '.bdr-restart' ).click();
		await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 60_000 } );

		const fix = async ( postIds, tab ) => {
			if ( tab ) {
				await page.locator( `.bdr-tab[data-kind="${ tab }"]` ).click();
				await page.locator( '.bdr-fix-kind' ).click();
			} else {
				for ( const id of postIds ) {
					await page.locator( `.bdr-table tr[data-post="${ id }"] input[type="checkbox"]` ).check();
				}
				await page.locator( '.bdr-fix-selected' ).click();
			}
			await page.locator( '.bdr-apply' ).click();
			await page.locator( '.bdr-close' ).click();
			await page.locator( '.bdr-tab[data-kind="all"]' ).click();
		};
		await fix( [], 'deprecated' );
		await fix( [ ids.hours, ids.nested, ids.badNotice, ids.pattern ] );
		await page.locator( '.bdr-batches tr[data-batch="1"] .bdr-undo' ).click();
		await expect( page.locator( '.bdr-notice' ) ).toContainText( 'Batch #1 undone' );
		await page.setViewportSize( { width: 1280, height: 760 } );
		await page.locator( '.bdr-batches' ).evaluate( ( panel ) => panel.scrollIntoView( { block: 'end' } ) );
		await shot( page, 4 );
	} );
} );
