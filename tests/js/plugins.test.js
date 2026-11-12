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
