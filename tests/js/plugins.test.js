// Unit tests for the Plugins screen script: which links it holds back.
// Run: npm run test:unit:js
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { parseLink } = require( '../../assets/js/plugins.js' );

test( 'a Deactivate link gives the plugin file', () => {
	assert.deepEqual( parseLink( 'plugins.php?action=deactivate&plugin=acme%2Facme.php&plugin_status=all&paged=1&s&_wpnonce=abc' ), { action: 'deactivate', plugin: 'acme/acme.php' } );
	assert.deepEqual( parseLink( 'https://example.com/wp-admin/plugins.php?action=deactivate&plugin=hello.php&_wpnonce=abc' ), { action: 'deactivate', plugin: 'hello.php' } );
} );

test( 'a Delete link gives the plugin file', () => {
	// The link on a plugin's row, as WordPress prints it.
	assert.deepEqual( parseLink( 'plugins.php?action=delete-selected&checked%5B0%5D=acme-gallery%2Facme-gallery.php&plugin_status=all&paged=1&s&_wpnonce=38d901bb14' ), { action: 'delete', plugin: 'acme-gallery/acme-gallery.php' } );
	assert.deepEqual( parseLink( 'plugins.php?action=delete-selected&checked[]=acme%2Facme.php&plugin_status=all&_wpnonce=abc' ), { action: 'delete', plugin: 'acme/acme.php' } );
	// Several plugins at once is a bulk action, which gets the notice afterwards.
	assert.equal( parseLink( 'plugins.php?action=delete-selected&checked%5B0%5D=a%2Fa.php&checked%5B1%5D=b%2Fb.php' ), null );
} );

test( 'other links are left alone', () => {
	assert.equal( parseLink( 'plugins.php?action=activate&plugin=acme%2Facme.php' ), null );
	assert.equal( parseLink( 'themes.php?action=deactivate&plugin=acme%2Facme.php' ), null );
	assert.equal( parseLink( 'plugins.php?action=deactivate' ), null );
	assert.equal( parseLink( 'not a url at all' ), null );
} );

// The step says what happens to the blocks. Dynamic blocks have no saved HTML, so nothing converts them afterwards.
const i18n = {
	__: ( text ) => text,
	_n: ( one, many, count ) => ( count === 1 ? one : many ),
	sprintf: ( text, ...values ) => values.reduce( ( out, value, index ) => out.replace( new RegExp( `%${ index + 1 }\\$s|%s` ), String( value ) ), text ),
};
const { consequence } = require( '../../assets/js/plugins.js' );

test( 'the step counts the dynamic blocks and says they show nothing once the plugin is off', () => {
	const text = consequence( 'deactivate', { blocks: 3, posts: 12, can_keep: true }, i18n );
	assert.match( text, /3 of its block types are dynamic, in 12 posts/ );
	assert.match( text, /show nothing, and Block Doctor cannot convert them afterwards/ );
	assert.match( text, /Keep what they show saves their output as HTML first, with Undo/ );
	assert.doesNotMatch( text, /fix the posts with Block Doctor afterwards/ );
} );

test( 'a delete says the plugin would have to be installed again, and offers no keep sentence when it cannot keep', () => {
	const text = consequence( 'delete', { blocks: 1, posts: 1, can_keep: false }, i18n );
	assert.match( text, /1 of its block types is dynamic, in 1 posts/ );
	assert.match( text, /install the plugin again/ );
	assert.doesNotMatch( text, /Keep what they show/ );
} );

test( 'without dynamic blocks the old promise holds: the posts can be fixed afterwards', () => {
	assert.match( consequence( 'deactivate', { blocks: 0, posts: 0, can_keep: false }, i18n ), /fix the posts with Block Doctor afterwards/ );
	assert.match( consequence( 'delete', {}, i18n ), /Deleting removes its files/ );
} );
