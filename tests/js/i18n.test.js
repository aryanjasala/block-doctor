// Every translatable string in the screen script has to name the text domain
// as a literal, or `wp i18n make-pot` skips it and the string never gets translated.
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'fs' );
const path = require( 'path' );

const DOMAIN = "'block-doctor'";

test( 'admin.js: every translation call has the literal text domain', () => {
	const source = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'admin.js' ), 'utf8' );
	const calls = source.match( /\b(?:__|_x|_n|_nx)\(\s*(?:\/\*[\s\S]*?\*\/\s*)?'/g ) || [];
	const domains = source.match( new RegExp( DOMAIN + '\\s*\\)', 'g' ) ) || [];

	assert.ok( calls.length > 50, 'expected the screen to have translation calls' );
	assert.strictEqual( domains.length, calls.length, `${ calls.length } translation calls, ${ domains.length } with ${ DOMAIN }` );
} );

test( 'doctor.js has no translation calls and no WordPress globals, so it runs in Node', () => {
	const source = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'doctor.js' ), 'utf8' );

	assert.doesNotMatch( source, /\b(?:__|_n|_x)\(/ );
	assert.doesNotMatch( source, /(?:^|[^.\w])wp\.\w+(?:\.\w+)*\s*\(/m );
} );
