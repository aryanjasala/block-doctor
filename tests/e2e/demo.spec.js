// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { content, reset, seed } = require( './seed' );

test( 'demo: scan the site, preview the fixes, apply them, undo the batch', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	reset();
	prepareSite( 'Corner Bakery' );
	const ids = seed();

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/tools.php?page=block-doctor' );
			await demo.start();
			await demo.pause( 1200 );

			await demo.click( page.locator( '.bdr-start' ), 400 );
			await expect( page.locator( '.bdr-last-scan' ) ).toBeVisible( { timeout: 30_000 } );
			await demo.moveTo( page.locator( '.bdr-tabs' ) );
			await demo.pause( 1600 );

			await demo.click( page.locator( '.bdr-tab[data-kind="invalid"]' ), 1000 );
			for ( const id of [ ids.hours, ids.nested, ids.badNotice ] ) {
				await demo.click( page.locator( `.bdr-table tr[data-post="${ id }"] input[type="checkbox"]` ), 300 );
			}
			await demo.click( page.locator( '.bdr-fix-selected' ), 1200 );

			const item = page.locator( `.bdr-preview-item[data-post="${ ids.hours }"]` );
			await demo.click( item.locator( '.bdr-toggle-diff' ), 400 );
			await demo.moveTo( item.locator( '.bdr-diff-add' ).first() );
			await demo.pause( 2400 );
			await demo.click( page.locator( '.bdr-apply' ), 400 );
			await expect( page.locator( '.bdr-modal .components-notice' ) ).toContainText( 'Fixed 3 posts' );
			expect( content( ids.hours ) ).toContain( '<h3 class="wp-block-heading">Opening hours</h3>' );
			await demo.pause( 1800 );

			await demo.click( page.locator( '.bdr-undo-now' ), 400 );
			await expect( page.locator( '.bdr-notice' ) ).toContainText( 'Batch #1 undone: 3 posts restored.' );
			expect( content( ids.hours ) ).toContain( '<h2 class="wp-block-heading">Opening hours</h2>' );
			await demo.moveTo( page.locator( '.bdr-notice' ) );
			await demo.pause( 2000 );
		} );
	} finally {
		reset();
	}
} );
