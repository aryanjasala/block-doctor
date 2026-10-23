// Unit tests for the scan and repair logic in assets/js/doctor.js, with a stubbed wp.blocks.
// Run: npm run test:unit:js
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const core = require( '../../assets/js/doctor.js' );

/**
 * A small stand-in for wp.blocks. Blocks with {"bad":true} are invalid, {"old":true}
 * only pass validation through a deprecation, and acme/* blocks are not registered.
 * serialize() writes the block's current markup, without bad or old.
 */
function stubBlocks( { registered = ( name ) => ! name.startsWith( 'acme/' ), calls = [] } = {} ) {
	const toBlock = ( source ) => ( node ) => {
		const markup = source.slice( node.start, node.end );
		if ( ! node.name ) {
			return { name: 'core/freeform', isValid: true, attributes: { content: markup }, innerBlocks: [], originalContent: markup };
		}
		if ( ! registered( node.name ) ) {
			return { name: 'core/missing', isValid: true, attributes: { originalName: node.name, originalContent: markup, originalUndelimitedContent: core.stripDelimiters( markup ) }, innerBlocks: [] };
		}
		const isValid = ! node.attrs.bad;
		return {
			name: node.name,
			isValid,
			validationIssues: isValid
				? []
				: [ { args: [ 'Expected tag name `%s`, instead saw `%s`.', 'h3', 'h2' ] }, { args: [ 'Block validation failed for `%s` (%o).', node.name, { a: 1 } ] } ],
			attributes: Object.assign( {}, node.attrs ),
			innerBlocks: node.children.map( toBlock( source ) ),
			originalContent: core.stripDelimiters( markup ),
		};
	};
	const short = ( name ) => name.replace( /^core\//, '' );
	const serializeOne = ( block ) => {
		const attrs = Object.assign( {}, block.attributes );
		delete attrs.bad;
		delete attrs.old;
		const content = attrs.content || '';
		delete attrs.content;
		const json = Object.keys( attrs ).length ? JSON.stringify( attrs ) + ' ' : '';
		const inner = ( block.innerBlocks || [] ).map( serializeOne ).join( '' );
		return `<!-- wp:${ short( block.name ) } ${ json }--><x>${ content }${ inner }</x><!-- /wp:${ short( block.name ) } -->`;
	};

	return {
		parse: ( source ) => {
			calls.push( source );
			return core.tokenize( source ).nodes.map( toBlock( source ) );
		},
		getBlockType: ( name ) => ( registered( name ) ? { name, attributes: name === 'core/html' ? { content: { type: 'string', source: 'raw' } } : {} } : undefined ),
		getUnregisteredTypeHandlerName: () => 'core/missing',
		validateBlock: ( block ) => [ ! block.attributes.old, [] ],
		createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes: Object.assign( {}, attributes ), innerBlocks, isValid: true } ),
		serialize: ( blocks ) => ( Array.isArray( blocks ) ? blocks : [ blocks ] ).map( serializeOne ).join( '\n\n' ),
		rawHandler: ( { HTML } ) => [ { name: 'core/paragraph', attributes: { content: core.excerpt( HTML, 200 ) }, innerBlocks: [] } ],
	};
}

const P = ( attrs = '', text = 'Hi' ) => `<!-- wp:paragraph ${ attrs ? attrs + ' ' : '' }--><p>${ text }</p><!-- /wp:paragraph -->`;

test( 'tokenize finds blocks with exact offsets, nesting and void blocks', () => {
	const content = `${ P() }\n\n<!-- wp:group --><div><!-- wp:spacer /--><!-- wp:paragraph --><p>In</p><!-- /wp:paragraph --></div><!-- /wp:group -->`;
	const { nodes, malformed } = core.tokenize( content );

	assert.equal( malformed, false );
	assert.deepEqual( nodes.map( ( node ) => node.name ), [ 'core/paragraph', 'core/group' ] );
	assert.equal( content.slice( nodes[ 0 ].start, nodes[ 0 ].end ), P() );
	assert.deepEqual( nodes[ 1 ].children.map( ( node ) => node.name ), [ 'core/spacer', 'core/paragraph' ] );
	assert.equal( content.slice( nodes[ 1 ].children[ 1 ].start, nodes[ 1 ].children[ 1 ].end ), '<!-- wp:paragraph --><p>In</p><!-- /wp:paragraph -->' );
	assert.equal( content.slice( nodes[ 1 ].start, nodes[ 1 ].end ).endsWith( '<!-- /wp:group -->' ), true );
} );

test( 'tokenize reads namespaces and attributes, and keeps bad JSON from breaking it', () => {
	const { nodes } = core.tokenize( '<!-- wp:acme/widget {"color":"red","n":{"a":1}} /-->\n<!-- wp:paragraph {bad json} --><p>x</p><!-- /wp:paragraph -->' );

	assert.equal( nodes[ 0 ].name, 'acme/widget' );
	assert.deepEqual( nodes[ 0 ].attrs, { color: 'red', n: { a: 1 } } );
	assert.equal( nodes.length, 2 );
	assert.equal( nodes[ 1 ].name, 'core/paragraph', 'invalid JSON still opens a block, like in the default parser' );
	assert.deepEqual( nodes[ 1 ].attrs, {} );
} );

test( 'tokenize turns classic content between blocks into trimmed nodes and skips blank lines', () => {
	const content = `\n\n<p>Classic</p>\n\n${ P() }\n\n   \n${ P() }\n<p>Tail</p>\n`;
	const { nodes } = core.tokenize( content );

	assert.deepEqual( nodes.map( ( node ) => node.name ), [ null, 'core/paragraph', 'core/paragraph', null ] );
	assert.equal( content.slice( nodes[ 0 ].start, nodes[ 0 ].end ), '<p>Classic</p>' );
	assert.equal( content.slice( nodes[ 3 ].start, nodes[ 3 ].end ), '<p>Tail</p>' );
} );

test( 'tokenize handles a missing closer and a stray closer like the default parser', () => {
	const open = core.tokenize( '<!-- wp:paragraph --><p>Never closed</p>' );
	assert.equal( open.nodes.length, 1 );
	assert.equal( open.nodes[ 0 ].end, '<!-- wp:paragraph --><p>Never closed</p>'.length );
	assert.equal( open.malformed, true );

	const stray = core.tokenize( `${ P() }<!-- /wp:paragraph --><p>after</p>` );
	assert.deepEqual( stray.nodes.map( ( node ) => node.name ), [ 'core/paragraph', null ] );
	assert.equal( stray.malformed, true );
} );

test( 'stripDelimiters keeps the HTML only', () => {
	assert.equal( core.stripDelimiters( '<!-- wp:group --><div><!-- wp:spacer /--><p>x</p></div><!-- /wp:group -->' ), '<div><p>x</p></div>' );
} );

test( 'scan reports invalid, unregistered, classic and outdated blocks with paths', () => {
	const content = [ '<p>Old</p>', P(), P( '{"bad":true}' ), '<!-- wp:acme/widget --><div>W</div><!-- /wp:acme/widget -->', P( '{"old":true}' ) ].join( '\n\n' );
	const issues = core.scan( content, stubBlocks() );

	assert.deepEqual(
		issues.map( ( issue ) => [ issue.kind, issue.path.join( '.' ), issue.name ] ),
		[
			[ 'classic', '0', 'core/freeform' ],
			[ 'invalid', '2', 'core/paragraph' ],
			[ 'missing', '3', 'acme/widget' ],
			[ 'deprecated', '4', 'core/paragraph' ],
		]
	);
	assert.equal( issues[ 0 ].message, 'Classic content between blocks: "Old"' );
	assert.equal( issues[ 2 ].message, 'Block type acme/widget is not registered. Its plugin may be inactive or deleted.' );
} );

test( 'an invalid block gets a plain message, the validation message as its detail, and its first words', () => {
	const issues = core.scan( `${ P() }

${ P( '{"bad":true}', 'Opening <b>hours</b>' ) }`, stubBlocks(), { text: { invalid: 'Plain words.' } } );

	assert.equal( issues.length, 1 );
	assert.equal( issues[ 0 ].message, 'Plain words.', 'no developer message with backticks and JSON in the main text' );
	assert.equal( issues[ 0 ].detail, 'Expected tag name `h3`, instead saw `h2`.', 'the specific message is kept as the technical detail' );
	assert.equal( issues[ 0 ].excerpt, 'Opening hours' );
	assert.equal( core.scan( P( '{"bad":true}' ), stubBlocks() )[ 0 ].message, core.TEXT.invalid );
	assert.doesNotMatch( core.TEXT.invalid, /`/ );
} );

test( 'block types the server registry knows are never reported as unregistered, and blocks inside them are still checked', () => {
	const content = `<!-- wp:acme/shelf {"ids":[1,2]} /-->\n\n<!-- wp:acme/box --><div>${ P( '{"bad":true}' ) }</div><!-- /wp:acme/box -->\n\n<!-- wp:acme/gone /-->`;
	const isRegistered = ( name ) => name === 'acme/shelf' || name === 'acme/box';

	// Without the server's list, every acme/* block is unregistered, as before.
	assert.deepEqual(
		core.scan( content, stubBlocks() ).map( ( issue ) => `${ issue.kind }@${ issue.path.join( '.' ) }` ),
		[ 'missing@0', 'missing@1', 'missing@2' ]
	);
	assert.deepEqual(
		core.scan( content, stubBlocks(), { isRegistered } ).map( ( issue ) => `${ issue.kind }@${ issue.path.join( '.' ) }:${ issue.name }` ),
		[ 'invalid@1.0:core/paragraph', 'missing@2:acme/gone' ]
	);

	// So "Convert to Custom HTML" never touches them either.
	const result = core.repair( content, stubBlocks(), { kinds: { missing: 'html' }, isRegistered } );
	assert.ok( result.content.startsWith( '<!-- wp:acme/shelf {"ids":[1,2]} /-->\n\n<!-- wp:acme/box -->' ) );
} );

test( 'Convert to Custom HTML keeps blocks with no saved HTML instead of leaving an empty Custom HTML block', () => {
	const dynamic = '<!-- wp:acme/shots {"ids":[4,5]} /-->';
	const hollow = '<!-- wp:acme/latest {"count":3} --><!-- /wp:acme/latest -->';
	const widget = '<!-- wp:acme/widget --><div class="w">W</div><!-- /wp:acme/widget -->';
	const content = [ dynamic, hollow, widget ].join( '\n\n' );
	const result = core.repair( content, stubBlocks(), { kinds: { missing: 'html' } } );

	assert.equal( result.content, [ dynamic, hollow, '<!-- wp:html --><x><div class="w">W</div></x><!-- /wp:html -->' ].join( '\n\n' ) );
	assert.doesNotMatch( result.content, /<!-- wp:html --><x><\/x>/ );
	assert.deepEqual( result.fixed.map( ( issue ) => issue.name ), [ 'acme/widget' ] );
	assert.deepEqual(
		result.kept.map( ( issue ) => `${ issue.name }:${ issue.reason }` ),
		[ 'acme/shots:empty', 'acme/latest:empty' ]
	);

	// Only dynamic blocks: nothing changes at all.
	const only = core.repair( dynamic, stubBlocks(), { kinds: { missing: 'html' } } );
	assert.deepEqual( { content: only.content, changed: only.changed, fixed: only.fixed.length }, { content: dynamic, changed: false, fixed: 0 } );

	// The same for an invalid block repaired as Custom HTML when its markup has no HTML of its own.
	const group = '<!-- wp:group {"bad":true} --><!-- wp:acme/shots /--><!-- /wp:group -->';
	const invalid = core.repair( group, stubBlocks(), { kinds: { invalid: true }, strategy: 'html' } );
	assert.equal( invalid.content, group );
	assert.deepEqual( invalid.kept.map( ( issue ) => `${ issue.kind }:${ issue.reason }` ), [ 'invalid:empty' ] );

	// A fixBlock filter sees why, and can still give its own repair.
	const filtered = core.repair( dynamic, stubBlocks(), {
		kinds: { missing: 'html' },
		fixBlock: ( value, block, context ) => ( context.reason === 'empty' ? '<!-- wp:shortcode -->[shots ids="4,5"]<!-- /wp:shortcode -->' : value ),
	} );
	assert.equal( filtered.content, '<!-- wp:shortcode -->[shots ids="4,5"]<!-- /wp:shortcode -->' );
	assert.deepEqual( filtered.kept, [] );
} );

test( 'scanPost reports a post it cannot read as an error issue instead of storing it as clean', () => {
	const blocks = stubBlocks();
	assert.deepEqual( core.scanPost( P( '{"bad":true}' ), blocks ), core.scan( P( '{"bad":true}' ), blocks ) );

	blocks.parse = () => {
		throw new TypeError( 'Cannot read properties of undefined (reading "attributes")' );
	};
	const issues = core.scanPost( P(), blocks, { text: { error: 'Not checked.' } } );
	assert.deepEqual( issues, [ { kind: 'error', path: [ 0 ], name: '', message: 'Not checked.', detail: 'Cannot read properties of undefined (reading "attributes")', excerpt: '' } ] );
} );

test( 'scan says when a whole post is classic, and uses translated messages', () => {
	const issues = core.scan( '<p>Just HTML</p>', stubBlocks(), { text: { classicWhole: 'Tout classique.' } } );

	assert.deepEqual( issues, [ { kind: 'classic', path: [ 0 ], name: 'core/freeform', message: 'Tout classique.', detail: '', excerpt: 'Just HTML' } ] );
	assert.deepEqual( core.scan( '', stubBlocks() ), [] );
	assert.deepEqual( core.scan( P() + '\n\n' + P(), stubBlocks() ), [] );
} );

test( 'scan walks inner blocks, and does not look inside unregistered ones', () => {
	const content = `<!-- wp:group --><div>${ P() }${ P( '{"bad":true}' ) }</div><!-- /wp:group -->\n\n<!-- wp:acme/box --><div>${ P( '{"bad":true}' ) }</div><!-- /wp:acme/box -->`;
	const issues = core.scan( content, stubBlocks() );

	assert.deepEqual(
		issues.map( ( issue ) => [ issue.kind, issue.path.join( '.' ) ] ),
		[
			[ 'invalid', '0.1' ],
			[ 'missing', '1' ],
		]
	);
} );

test( 'scan parses inner blocks on their own when a migration changed them', () => {
	const blocks = stubBlocks();
	const parse = blocks.parse;
	// The parent comes back with no inner blocks, as if a deprecation rebuilt them.
	blocks.parse = ( source ) => parse( source ).map( ( block ) => ( block.name === 'core/group' ? Object.assign( {}, block, { innerBlocks: [] } ) : block ) );
	const issues = core.scan( `<!-- wp:group --><div>${ P( '{"bad":true}' ) }</div><!-- /wp:group -->`, blocks );

	assert.deepEqual( issues.map( ( issue ) => [ issue.kind, issue.path.join( '.' ) ] ), [ [ 'invalid', '0.0' ] ] );
} );

test( 'a block a migration created has no saved markup, so it is never outdated', () => {
	const blocks = stubBlocks();
	assert.equal( core.isOutdated( blocks, { name: 'core/paragraph', attributes: { old: true } } ), false );
	assert.equal( core.isOutdated( blocks, { name: 'core/paragraph', attributes: { old: true }, originalContent: '<p>x</p>' } ), true );
	assert.equal( core.isOutdated( blocks, { name: 'acme/x', attributes: {}, originalContent: '' } ), false );
	assert.equal( core.isOutdated( blocks, { name: 'core/html', attributes: { old: true }, originalContent: '<b>x</b>', innerContent: [ '<b>x</b>' ] } ), false, 'WordPress 7.1 keeps Custom HTML markup as it is' );

	const legacy = stubBlocks();
	delete legacy.validateBlock;
	legacy.isValidBlockContent = () => false;
	assert.equal( core.isOutdated( legacy, { name: 'core/paragraph', attributes: {}, originalContent: '<p>x</p>' } ), true, 'falls back to isValidBlockContent' );
} );

test( 'repair recovers an invalid block and leaves every other byte alone', () => {
	const odd = '<!-- wp:paragraph {"keep":"  spaced  "} -->\n<p>Untouched</p>\n<!-- /wp:paragraph -->';
	const content = `${ odd }\n\n${ P( '{"bad":true}', 'Broken' ) }\n\n<p>Classic stays</p>`;
	const result = core.repair( content, stubBlocks(), { kinds: { invalid: true } } );

	assert.equal( result.changed, true );
	assert.equal( result.content, `${ odd }\n\n<!-- wp:paragraph --><x></x><!-- /wp:paragraph -->\n\n<p>Classic stays</p>` );
	assert.deepEqual( result.fixed.map( ( issue ) => issue.kind ), [ 'invalid' ] );
} );

test( 'repair fixes an invalid inner block inside a valid parent without touching the parent', () => {
	const content = `<!-- wp:group {"x":1} --><div class="g">${ P( '', 'Fine' ) }${ P( '{"bad":true}' ) }</div><!-- /wp:group -->`;
	const result = core.repair( content, stubBlocks(), { kinds: { invalid: true } } );

	assert.equal( result.content, `<!-- wp:group {"x":1} --><div class="g">${ P( '', 'Fine' ) }<!-- wp:paragraph --><x></x><!-- /wp:paragraph --></div><!-- /wp:group -->` );
	assert.deepEqual( result.fixed.map( ( issue ) => issue.path.join( '.' ) ), [ '0.1' ] );
} );

test( 'repair rebuilds an invalid parent with its fixed inner blocks', () => {
	const content = `<!-- wp:group {"bad":true} --><div>${ P( '{"bad":true}' ) }</div><!-- /wp:group -->`;
	const result = core.repair( content, stubBlocks(), { kinds: { invalid: true } } );

	assert.equal( result.content, '<!-- wp:group --><x><!-- wp:paragraph --><x></x><!-- /wp:paragraph --></x><!-- /wp:group -->' );
	assert.deepEqual( result.fixed.map( ( issue ) => issue.path.join( '.' ) ), [ '0', '0.0' ] );
} );

test( 'repair turns invalid blocks into Custom HTML when their strategy says so', () => {
	const content = `${ P( '{"bad":true}', 'One' ) }\n\n<!-- wp:heading {"bad":true} --><h2>Two</h2><!-- /wp:heading -->`;
	const result = core.repair( content, stubBlocks(), { kinds: { invalid: true }, strategies: { 'core/heading': 'html' } } );

	assert.equal( result.content, '<!-- wp:paragraph --><x></x><!-- /wp:paragraph -->\n\n<!-- wp:html --><x><h2>Two</h2></x><!-- /wp:html -->' );

	const all = core.repair( content, stubBlocks(), { kinds: { invalid: true }, strategy: 'html', strategies: { 'core/heading': 'recover' } } );
	assert.match( all.content, /^<!-- wp:html --><x><p>One<\/p><\/x>/ );
	assert.match( all.content, /<!-- wp:heading --><x><\/x>/ );
} );

test( 'Custom HTML blocks hold their HTML the way each WordPress version expects', () => {
	const blocks = stubBlocks();
	assert.deepEqual( core.htmlBlock( blocks, '<b>x</b>' ).attributes, { content: '<b>x</b>' } );

	// WordPress 7.1: the content attribute is local, and the markup goes in as inner content.
	const calls = [];
	blocks.getBlockType = () => ( { attributes: { content: { type: 'string', role: 'local' } } } );
	blocks.createBlock = ( ...args ) => {
		calls.push( args );
		return { name: args[ 0 ], attributes: {}, innerBlocks: [] };
	};
	core.htmlBlock( blocks, '<b>x</b>' );
	assert.deepEqual( calls, [ [ 'core/html', {}, [], [ '<b>x</b>' ] ] ] );
} );

test( 'repair keeps unregistered blocks unless asked to convert them to Custom HTML', () => {
	const content = `${ P() }\n\n<!-- wp:acme/widget {"a":1} --><div class="w">W</div><!-- /wp:acme/widget -->`;

	assert.equal( core.repair( content, stubBlocks(), { kinds: { invalid: true, missing: 'keep' } } ).changed, false );

	const result = core.repair( content, stubBlocks(), { kinds: { missing: 'html' } } );
	assert.equal( result.content, `${ P() }\n\n<!-- wp:html --><x><div class="w">W</div></x><!-- /wp:html -->` );
	assert.deepEqual( result.fixed.map( ( issue ) => issue.name ), [ 'acme/widget' ] );
} );

test( 'repair converts classic content only when asked', () => {
	const content = `${ P() }\n\n<p>Old <b>stuff</b></p>\n\n${ P() }`;

	assert.equal( core.repair( content, stubBlocks(), { kinds: { invalid: true } } ).changed, false );
	assert.equal( core.repair( content, stubBlocks(), { kinds: { classic: true } } ).content, `${ P() }\n\n<!-- wp:paragraph --><x>Old stuff</x><!-- /wp:paragraph -->\n\n${ P() }` );
} );

test( 'repair saves outdated blocks again', () => {
	const result = core.repair( P( '{"old":true,"align":"left"}' ), stubBlocks(), { kinds: { deprecated: true } } );

	assert.equal( result.content, '<!-- wp:paragraph {"align":"left"} --><x></x><!-- /wp:paragraph -->' );
	assert.equal( core.repair( P( '{"old":true}' ), stubBlocks(), { kinds: { invalid: true } } ).changed, false );
} );

test( 'the fixBlock callback can replace, rewrite or skip a repair', () => {
	const content = `${ P( '{"bad":true}', 'A' ) }\n\n${ P( '{"bad":true}', 'B' ) }`;
	const seen = [];
	const result = core.repair( content, stubBlocks(), {
		kinds: { invalid: true },
		fixBlock: ( value, block, context ) => {
			seen.push( [ context.kind, context.strategy, context.path.join( '.' ), context.source.includes( '<p>A</p>' ) ? 'A' : 'B' ] );
			return context.source.includes( '<p>A</p>' ) ? '<!-- wp:paragraph --><p>Custom</p><!-- /wp:paragraph -->' : null;
		},
	} );

	assert.deepEqual( seen, [
		[ 'invalid', 'recover', '0', 'A' ],
		[ 'invalid', 'recover', '1', 'B' ],
	] );
	assert.equal( result.content, `<!-- wp:paragraph --><p>Custom</p><!-- /wp:paragraph -->\n\n${ P( '{"bad":true}', 'B' ) }` );
	assert.equal( result.fixed.length, 1 );

	const split = core.repair( P( '{"bad":true}' ), stubBlocks(), {
		kinds: { invalid: true },
		fixBlock: ( value ) => [ value, { name: 'core/separator', attributes: {}, innerBlocks: [] } ],
	} );
	assert.equal( split.content, '<!-- wp:paragraph --><x></x><!-- /wp:paragraph -->\n\n<!-- wp:separator --><x></x><!-- /wp:separator -->' );
} );

test( 'repair with nothing to fix returns the content unchanged', () => {
	const content = `${ P() }\n\n${ P( '{"bad":true}' ) }`;
	const result = core.repair( content, stubBlocks(), { kinds: {} } );

	assert.deepEqual( result, { content, changed: false, fixed: [], kept: [] } );
} );

test( 'diffLines shows removed then added lines, and folds unchanged runs', () => {
	const before = [ 'a', 'b', 'c', 'd', 'e', 'f', 'old', 'g', 'h', 'i', 'j' ].join( '\n' );
	const after = [ 'a', 'b', 'c', 'd', 'e', 'f', 'new', 'g', 'h', 'i', 'j' ].join( '\n' );
	const rows = core.diffLines( before, after, 2 );

	assert.deepEqual(
		rows.map( ( row ) => ( row.type === 'gap' ? 'gap' + row.count : row.type + ':' + row.text ) ),
		[ 'gap4', 'same:e', 'same:f', 'del:old', 'add:new', 'same:g', 'same:h', 'gap2' ]
	);
	assert.deepEqual( core.diffLines( before, before ), [] );
} );

test( 'diffLines puts block delimiters and long tag runs on their own lines', () => {
	assert.deepEqual( core.diffableLines( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ), [ '<!-- wp:paragraph -->', '<p>x</p>', '<!-- /wp:paragraph -->' ] );
	const long = '<div>' + 'x'.repeat( 100 ) + '</div><div>' + 'y'.repeat( 100 ) + '</div>';
	assert.deepEqual( core.diffableLines( long ), [ '<div>' + 'x'.repeat( 100 ) + '</div>', '<div>' + 'y'.repeat( 100 ) + '</div>' ] );
} );

test( 'helpers: sprintf, validation messages, excerpts and durations', () => {
	assert.equal( core.sprintf( 'Expected %s, saw %o.', 'a', [ 'b', 1 ] ), 'Expected a, saw ["b",1].' );
	assert.equal( core.validationMessage( { validationIssues: [ { args: [ 'Block validation failed for `%s`', 'x' ] } ] } ), '' );
	assert.equal( core.validationMessage( { validationIssues: [ { args: [ 'Expected   %s\n here', 'x' ] } ] } ), 'Expected x here' );
	assert.equal( core.excerpt( '<p>Hello <b>there</b> world</p>', 11 ), 'Hello there...' );
	assert.equal( core.formatDuration( 65 ), '1:05' );
	assert.equal( core.formatDuration( 3725 ), '1:02:05' );
} );
