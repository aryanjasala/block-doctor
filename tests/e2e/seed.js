// Fixtures for the end-to-end tests, the screenshots and the demo: posts with
// deliberately broken blocks, a JavaScript-only block from a small must-use
// plugin, and a clean slate between tests.
const { wp, wpEval } = require( './utils' );

const OPTIONS = [ 'block_doctor_index', 'block_doctor_scan', 'block_doctor_server_scan', 'block_doctor_batches', 'block_doctor_js_blocks' ];

// Markup that is broken the same way on WordPress 6.6, 6.9 and 7.x.
const MARKUP = {
	paragraph: ( text ) => `<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`,
	// The heading says level 3, the HTML is an h2.
	badHeading: '<!-- wp:heading {"level":3} -->\n<h2 class="wp-block-heading">Opening hours</h2>\n<!-- /wp:heading -->',
	goodHeading: '<!-- wp:heading {"level":3} -->\n<h3 class="wp-block-heading">Opening hours</h3>\n<!-- /wp:heading -->',
	// An image block saved as a div instead of a figure.
	badImage: '<!-- wp:image -->\n<div class="wp-block-image"><img src="https://example.com/storefront.jpg" alt="Storefront"/></div>\n<!-- /wp:image -->',
	goodImage: '<!-- wp:image -->\n<figure class="wp-block-image"><img src="https://example.com/storefront.jpg" alt="Storefront"/></figure>\n<!-- /wp:image -->',
	widget: '<!-- wp:acme/widget {"color":"red"} -->\n<div class="acme-widget">Order online</div>\n<!-- /wp:acme/widget -->',
	classic: '<p>We bake <strong>sourdough</strong> every morning.</p>\n<p>Ask about gluten-free loaves.</p>',
	// A heading saved before WordPress added the wp-block-heading class.
	oldHeading: '<!-- wp:heading -->\n<h2>Our story</h2>\n<!-- /wp:heading -->',
	notice: '<!-- wp:acme/notice -->\n<div class="wp-block-acme-notice acme-notice"><p>Closed on Mondays</p></div>\n<!-- /wp:acme/notice -->',
	// Saved before the plugin's block got its generated class name.
	badNotice: '<!-- wp:acme/notice -->\n<div class="acme-notice"><p>Closed on Mondays</p></div>\n<!-- /wp:acme/notice -->',
	nested: '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Inside a group</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {"level":4} -->\n<h2 class="wp-block-heading">Wrong level inside</h2>\n<!-- /wp:heading --></div>\n<!-- /wp:group -->',
};

// A block registered only in JavaScript, on enqueue_block_editor_assets, like many plugins do.
const MU_PLUGIN = `<?php
add_action( 'enqueue_block_editor_assets', function () {
	wp_register_script( 'acme-notice', false, array( 'wp-blocks', 'wp-element' ), '1', true );
	wp_enqueue_script( 'acme-notice' );
	wp_add_inline_script( 'acme-notice', "wp.blocks.registerBlockType( 'acme/notice', { title: 'Notice', category: 'text', attributes: { text: { type: 'string', source: 'html', selector: 'p' } }, edit: function () { return null; }, save: function ( props ) { return wp.element.createElement( 'div', { className: 'acme-notice' }, wp.element.createElement( 'p', null, props.attributes.text ) ); } } );" );
} );
`;

const b64 = ( text ) => Buffer.from( String( text ), 'utf8' ).toString( 'base64' );

/**
 * Creates a post with raw content, past kses, and returns its ID.
 *
 * @param {string} title   Title.
 * @param {string} content Block markup.
 * @param {Object} fields  Other wp_insert_post() fields.
 * @return {number} Post ID.
 */
function createPost( title, content, fields = {} ) {
	const extra = Object.entries( fields )
		.map( ( [ key, value ] ) => `'${ key }' => base64_decode( '${ b64( value ) }' ),` )
		.join( ' ' );
	return Number(
		wpEval( `kses_remove_filters();
			echo wp_insert_post( wp_slash( array( 'post_title' => base64_decode( '${ b64( title ) }' ), 'post_content' => base64_decode( '${ b64( content ) }' ), 'post_status' => 'publish', ${ extra } ) ) );` )
	);
}

/** The raw content of a post. */
const content = ( id ) => wpEval( `echo get_post( ${ id } )->post_content;` );

/** A post's stored issues as kind@path strings. */
const issues = ( id ) =>
	JSON.parse( wpEval( `$r = get_post_meta( ${ id }, '_block_doctor_issues', true ); echo wp_json_encode( $r ? $r['issues'] : array() );` ) ).map( ( issue ) => `${ issue.kind }@${ issue.path.join( '.' ) }` );

/** An option as JSON. */
const option = ( name ) => JSON.parse( wpEval( `echo wp_json_encode( get_option( '${ name }', null ) );` ) );

/** How many revisions a post has. */
const revisions = ( id ) => Number( wpEval( `echo count( wp_get_post_revisions( ${ id } ) );` ) );

/** Installs the must-use plugin with the JavaScript-only block. */
function installNoticeBlock() {
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/acme-notice.php', base64_decode( '${ b64( MU_PLUGIN ) }' ) );` );
}

/** Empties the site and forgets everything Block Doctor stored. */
function reset() {
	wp( 'site', 'empty', '--yes' );
	const list = OPTIONS.map( ( name ) => `'${ name }'` ).join( ', ' );
	wpEval( `foreach ( array( ${ list } ) as $o ) { delete_option( $o ); } wp_clear_scheduled_hook( 'block_doctor_server_scan' ); if ( file_exists( WPMU_PLUGIN_DIR . '/acme-notice.php' ) ) { unlink( WPMU_PLUGIN_DIR . '/acme-notice.php' ); }` );
}

/**
 * The posts most tests start from. Returns their IDs by name.
 *
 * @return {Object<string, number>} IDs.
 */
function seed() {
	installNoticeBlock();
	const ids = {};
	ids.hours = createPost( 'Opening hours', [ MARKUP.paragraph( 'Come and visit.' ), MARKUP.badHeading, MARKUP.badImage ].join( '\n\n' ) );
	ids.order = createPost( 'Order online', [ MARKUP.paragraph( 'Order ahead and skip the queue.' ), MARKUP.widget ].join( '\n\n' ) );
	ids.classic = createPost( 'About the bakery', MARKUP.classic );
	ids.story = createPost( 'Our story', [ MARKUP.oldHeading, MARKUP.paragraph( 'It started with one oven.' ) ].join( '\n\n' ) );
	ids.nested = createPost( 'Seasonal menu', MARKUP.nested, { post_type: 'page' } );
	ids.notice = createPost( 'Holiday notice', MARKUP.notice );
	ids.badNotice = createPost( 'Old notice', MARKUP.badNotice );
	ids.refs = createPost( 'Footer links', [ '<!-- wp:block {"ref":99999} /-->', '<!-- wp:navigation {"ref":99998} /-->', '<!-- wp:image {"id":99997} -->\n<figure class="wp-block-image"><img src="https://example.com/old.jpg" alt="" class="wp-image-99997"/></figure>\n<!-- /wp:image -->' ].join( '\n\n' ) );
	ids.pattern = createPost( 'Call to action', MARKUP.badHeading, { post_type: 'wp_block' } );
	ids.healthy = createPost( 'Healthy post', MARKUP.paragraph( 'Nothing wrong here.' ) );
	ids.empty = Number(
		wpEval( `kses_remove_filters();
			$id = wp_insert_post( wp_slash( array( 'post_title' => 'Emptied post', 'post_status' => 'publish', 'post_content' => base64_decode( '${ b64( MARKUP.paragraph( 'This text was lost.' ) ) }' ) ) ) );
			wp_save_post_revision( $id );
			wp_update_post( array( 'ID' => $id, 'post_content' => '' ) );
			echo $id;` )
	);
	return ids;
}

module.exports = { MARKUP, OPTIONS, createPost, content, issues, option, revisions, installNoticeBlock, reset, seed };
