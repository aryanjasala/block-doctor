/**
 * Block Doctor's scan and repair logic.
 *
 * Nothing here touches WordPress globals: every function that needs the block
 * API gets it as `blocks` (wp.blocks in the browser, a stub in the Node unit
 * tests). The screen script (admin.js) wires it to the REST API and the UI.
 *
 * Paths count real blocks from 0, one number per level, like PHP's
 * parse_blocks(): classic content between top-level blocks counts as a block,
 * blank lines do not.
 */
( function ( root, factory ) {
	const api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.blockDoctorCore = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis, function () {
	'use strict';

	// The block delimiter tokenizer from @wordpress/block-serialization-default-parser.
	const TOKEN = /<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+({(?:(?=([^}]+|}+(?=})|(?!}\s+\/?-->)[^])*)\5|[^]*?)}\s+)?(\/)?-->/g;

	// Default messages. The screen passes translated ones.
	const TEXT = {
		invalid: 'The saved markup no longer matches what the block produces.',
		missing: 'Block type %s is not registered. Its plugin may be inactive or deleted.',
		classic: 'Classic content between blocks: "%s"',
		classicWhole: 'The whole post is classic content, with no blocks.',
		deprecated: 'Saved by an older version of the block. The markup changes the next time the post is saved.',
		error: 'The scan could not read the blocks of this post, so they were not checked.',
	};

	/**
	 * Fills %s placeholders in order.
	 *
	 * @param {string} template Text with %s.
	 * @param {...*}   values   Values.
	 * @return {string} Text.
	 */
	function sprintf( template, ...values ) {
		return String( template ).replace( /%[sdo]/g, () => {
			const value = values.shift();
			if ( value === undefined ) {
				return '';
			}
			return typeof value === 'string' || typeof value === 'number' ? String( value ) : safeJson( value );
		} );
	}

	/**
	 * JSON for a log value, short and never throwing.
	 *
	 * @param {*} value Value.
	 * @return {string} JSON.
	 */
	function safeJson( value ) {
		try {
			const json = JSON.stringify( value );
			return json && json.length > 120 ? json.slice( 0, 117 ) + '...' : String( json );
		} catch ( error ) {
			return String( value );
		}
	}

	/**
	 * Plain text from some HTML, shortened.
	 *
	 * @param {string} html   HTML.
	 * @param {number} length Most characters.
	 * @return {string} Text.
	 */
	function excerpt( html, length = 60 ) {
		const text = String( html )
			.replace( /<[^>]*>/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
		return text.length > length ? text.slice( 0, length ) + '...' : text;
	}

	/**
	 * Finds every block delimiter and builds the tree with exact source offsets.
	 *
	 * Follows the default parser: a closer closes whatever block is open, a block
	 * left open runs to the end, and a closer with nothing open turns the rest
	 * into classic content. Classic content between top-level blocks becomes a
	 * node with no name, trimmed of the blank lines around it.
	 *
	 * @param {string} content Post content.
	 * @return {{nodes: Object[], malformed: boolean}} Top-level nodes: name (null for classic), attrs, start, end, children.
	 */
	function tokenize( content ) {
		content = String( content );
		const nodes = [];
		const stack = [];
		const re = new RegExp( TOKEN.source, 'g' );
		let offset = 0;
		let freeStart = 0;
		let malformed = false;

		const addFree = ( from, to ) => {
			const text = content.slice( from, to );
			const lead = text.search( /\S/ );
			if ( lead === -1 ) {
				return;
			}
			const trail = text.length - text.trimEnd().length;
			nodes.push( { name: null, attrs: {}, start: from + lead, end: to - trail, children: [] } );
		};
		const close = ( end ) => {
			const node = stack.pop();
			node.end = end;
			if ( stack.length ) {
				stack[ stack.length - 1 ].children.push( node );
			} else {
				nodes.push( node );
				freeStart = end;
			}
		};

		for ( ;; ) {
			re.lastIndex = offset;
			const match = re.exec( content );
			if ( ! match ) {
				if ( stack.length ) {
					malformed = true;
					while ( stack.length ) {
						close( content.length );
					}
				} else {
					addFree( freeStart, content.length );
				}
				break;
			}

			const start = match.index;
			const end = start + match[ 0 ].length;
			if ( match[ 1 ] ) {
				if ( ! stack.length ) {
					malformed = true;
					addFree( freeStart, content.length );
					break;
				}
				close( end );
				offset = end;
				continue;
			}

			let attrs = {};
			if ( match[ 4 ] ) {
				try {
					attrs = JSON.parse( match[ 4 ] ) || {};
				} catch ( error ) {
					attrs = {};
				}
			}
			const node = { name: ( match[ 2 ] || 'core/' ) + match[ 3 ], attrs, start, end, children: [] };
			if ( ! stack.length ) {
				addFree( freeStart, start );
			}
			if ( match[ 6 ] ) {
				if ( stack.length ) {
					stack[ stack.length - 1 ].children.push( node );
				} else {
					nodes.push( node );
					freeStart = end;
				}
			} else {
				stack.push( node );
			}
			offset = end;
		}

		return { nodes, malformed };
	}

	/**
	 * Removes every block delimiter, leaving the HTML.
	 *
	 * @param {string} markup Block markup.
	 * @return {string} HTML.
	 */
	function stripDelimiters( markup ) {
		return String( markup ).replace( new RegExp( TOKEN.source, 'g' ), '' ).trim();
	}

	/**
	 * A Custom HTML block holding some HTML.
	 *
	 * Up to WordPress 7.0 the HTML is the block's `content` attribute. From 7.1
	 * the block keeps its markup as it is, and the editor's own Convert to HTML
	 * passes it to createBlock() as inner content.
	 *
	 * @param {Object} blocks wp.blocks.
	 * @param {string} html   HTML.
	 * @return {Object} Block.
	 */
	function htmlBlock( blocks, html ) {
		const type = blocks.getBlockType( 'core/html' );
		const attribute = type && type.attributes && type.attributes.content;
		if ( attribute && attribute.source ) {
			return blocks.createBlock( 'core/html', { content: html } );
		}
		return blocks.createBlock( 'core/html', {}, [], [ html ] );
	}

	/**
	 * Parses the markup of one block.
	 *
	 * @param {Object} blocks wp.blocks.
	 * @param {string} source Markup.
	 * @return {Object|null} Block.
	 */
	function parseOne( blocks, source ) {
		const parsed = blocks.parse( source ) || [];
		return parsed.find( ( block ) => block && block.name ) || null;
	}

	/**
	 * The first useful validation message of an invalid block.
	 *
	 * @param {Object} block Parsed block.
	 * @return {string} Message, or ''.
	 */
	function validationMessage( block ) {
		for ( const issue of block.validationIssues || [] ) {
			const args = ( issue && issue.args ) || [];
			if ( typeof args[ 0 ] !== 'string' || /^Block validation failed/.test( args[ 0 ] ) ) {
				continue;
			}
			const message = sprintf( ...args ).replace( /\s+/g, ' ' ).trim();
			return message.length > 300 ? message.slice( 0, 297 ) + '...' : message;
		}
		return '';
	}

	/**
	 * Whether a valid block only parsed through a deprecation: its saved markup
	 * is not what the block saves now, so the next save rewrites it.
	 *
	 * @param {Object} blocks wp.blocks.
	 * @param {Object} block  Parsed block.
	 * @return {boolean} Outdated.
	 */
	function isOutdated( blocks, block ) {
		// Blocks a migration created have no saved markup of their own. From WordPress 7.1
		// some blocks, such as Custom HTML, keep their markup as it is (innerContent), and
		// the parser does not validate those either.
		if ( typeof block.originalContent !== 'string' || Array.isArray( block.innerContent ) ) {
			return false;
		}
		const type = blocks.getBlockType( block.name );
		if ( ! type ) {
			return false;
		}
		try {
			if ( typeof blocks.validateBlock === 'function' ) {
				return ! blocks.validateBlock( block, type )[ 0 ];
			}
			if ( typeof blocks.isValidBlockContent === 'function' ) {
				return ! blocks.isValidBlockContent( type, block.attributes, block.originalContent );
			}
		} catch ( error ) {
			return false;
		}
		return false;
	}

	/**
	 * The first words of a block, so people can find it in the post.
	 *
	 * @param {string} source Block markup.
	 * @return {string} Text, or ''.
	 */
	function blockExcerpt( source ) {
		return excerpt( stripDelimiters( source ), 60 );
	}

	/**
	 * Parses a post and classifies every block.
	 *
	 * @param {string}   content              Post content.
	 * @param {Object}   blocks               wp.blocks.
	 * @param {Object}   options              Options.
	 * @param {Object}   options.text         Messages to use instead of the English defaults.
	 * @param {Function} options.isRegistered ( name ) => true for block types the server registry knows. Those are never reported as unregistered.
	 * @return {{entries: Object[], issues: Object[], malformed: boolean}} Entries (node, block, path, kind, children) and the issues found.
	 */
	function analyse( content, blocks, options = {} ) {
		content = String( content );
		const text = Object.assign( {}, TEXT, options.text || {} );
		const known = typeof options.isRegistered === 'function' ? options.isRegistered : () => false;
		const missingName = typeof blocks.getUnregisteredTypeHandlerName === 'function' ? blocks.getUnregisteredTypeHandlerName() || 'core/missing' : 'core/missing';
		const { nodes, malformed } = tokenize( content );
		const named = nodes.some( ( node ) => node.name );
		const issues = [];

		const visit = ( node, block, path ) => {
			const entry = { node, block, path, kind: null, issue: null, zipped: false, children: [] };
			if ( ! block ) {
				return entry;
			}
			const source = () => content.slice( node.start, node.end );

			if ( block.name === missingName ) {
				const name = ( block.attributes && block.attributes.originalName ) || node.name;
				if ( known( name ) ) {
					// Registered on the server, but its editor script did not load on this screen. It is not
					// unregistered, and it cannot be validated here; the blocks inside it still can.
					node.children.forEach( ( child, index ) => {
						entry.children.push( visit( child, parseOne( blocks, content.slice( child.start, child.end ) ), path.concat( index ) ) );
					} );
					return entry;
				}
				entry.kind = 'missing';
				entry.issue = { kind: 'missing', path, name, message: sprintf( text.missing, name ), detail: '', excerpt: blockExcerpt( source() ) };
				issues.push( entry.issue );
				return entry;
			}
			if ( block.isValid === false ) {
				entry.kind = 'invalid';
				entry.issue = { kind: 'invalid', path, name: block.name, message: text.invalid, detail: validationMessage( block ), excerpt: blockExcerpt( source() ) };
			} else if ( isOutdated( blocks, block ) ) {
				entry.kind = 'deprecated';
				entry.issue = { kind: 'deprecated', path, name: block.name, message: text.deprecated, detail: '', excerpt: blockExcerpt( source() ) };
			}
			if ( entry.issue ) {
				issues.push( entry.issue );
			}

			const inner = block.innerBlocks || [];
			const same = ( child, index ) => inner[ index ] && ( inner[ index ].name === child.name || ( inner[ index ].attributes && inner[ index ].attributes.originalName === child.name ) );
			entry.zipped = inner.length === node.children.length && node.children.every( same );
			node.children.forEach( ( child, index ) => {
				const childBlock = entry.zipped ? inner[ index ] : parseOne( blocks, content.slice( child.start, child.end ) );
				entry.children.push( visit( child, childBlock, path.concat( index ) ) );
			} );
			return entry;
		};

		const entries = nodes.map( ( node, index ) => {
			const path = [ index ];
			if ( ! node.name ) {
				const source = content.slice( node.start, node.end );
				const issue = { kind: 'classic', path, name: 'core/freeform', message: named ? sprintf( text.classic, excerpt( source ) ) : text.classicWhole, detail: '', excerpt: excerpt( source ) };
				issues.push( issue );
				return { node, block: null, path, kind: 'classic', issue, zipped: false, children: [] };
			}
			return visit( node, parseOne( blocks, content.slice( node.start, node.end ) ), path );
		} );

		return { entries, issues, malformed };
	}

	/**
	 * Finds the issues in a post.
	 *
	 * @param {string} content Post content.
	 * @param {Object} blocks  wp.blocks.
	 * @param {Object} options text: translated messages.
	 * @return {Object[]} Issues: kind, path, name, message.
	 */
	function scan( content, blocks, options = {} ) {
		return analyse( content, blocks, options ).issues;
	}

	/**
	 * Finds the issues in a post, and reports a post the scan cannot read as an `error`
	 * issue instead of throwing, so it is never stored as clean.
	 *
	 * @param {string} content Post content.
	 * @param {Object} blocks  wp.blocks.
	 * @param {Object} options text, isRegistered.
	 * @return {Object[]} Issues.
	 */
	function scanPost( content, blocks, options = {} ) {
		try {
			return scan( content, blocks, options );
		} catch ( error ) {
			const text = Object.assign( {}, TEXT, options.text || {} );
			const detail = String( ( error && error.message ) || error || '' ).replace( /\s+/g, ' ' ).trim();
			return [ { kind: 'error', path: [ 0 ], name: '', message: text.error, detail: detail.length > 300 ? detail.slice( 0, 297 ) + '...' : detail, excerpt: '' } ];
		}
	}

	/**
	 * Repairs the chosen kinds of issues in a post and leaves every other byte alone.
	 *
	 * - invalid: rebuilt from its attributes and inner blocks, like Attempt Block
	 *   Recovery, or turned into a Custom HTML block when its strategy is `html`.
	 * - missing: turned into a Custom HTML block with the original markup, when `kinds.missing` is `html`.
	 * - classic: converted to blocks, like Convert to blocks.
	 * - deprecated: saved again with the block's current markup.
	 *
	 * A fixed block is saved again with everything inside it; blocks around it keep their exact markup.
	 *
	 * A block with no saved HTML, such as every dynamic block (`<!-- wp:acme/latest /-->`), is never
	 * turned into Custom HTML: the result would be an empty block, and the block and its settings would
	 * be gone. It is kept as it is and listed in `kept` with the reason `empty`.
	 *
	 * @param {string}   content          Post content.
	 * @param {Object}   blocks           wp.blocks.
	 * @param {Object}   options          Options.
	 * @param {Object}   options.kinds    invalid, classic, deprecated: true to fix; missing: 'html' to convert.
	 * @param {Object}   options.strategies Block name => 'recover' or 'html', for invalid blocks.
	 * @param {string}   options.strategy Strategy for block types not in strategies. Default 'recover'.
	 * @param {Function} options.fixBlock ( result, block, context ) => block, blocks, markup string or null to keep it. The blockDoctor.fixBlock filter.
	 * @param {Object}   options.text     Translated messages.
	 * @param {Function} options.isRegistered Block types the server registry knows, see analyse().
	 * @return {{content: string, changed: boolean, fixed: Object[], kept: Object[]}} New content, the issues fixed, and the issues left as they are with a `reason` (`empty` or `filter`).
	 */
	function repair( content, blocks, options = {} ) {
		content = String( content );
		const kinds = options.kinds || {};
		const strategies = options.strategies || {};
		const fallback = options.strategy === 'html' ? 'html' : 'recover';
		const strategyFor = ( name ) => ( strategies[ name ] === 'html' || strategies[ name ] === 'recover' ? strategies[ name ] : fallback );
		const { entries } = analyse( content, blocks, options );
		const replacements = [];
		const fixed = [];
		const kept = [];

		const wants = ( entry ) => {
			switch ( entry.kind ) {
				case 'missing':
					return kinds.missing === 'html' || kinds.missing === true;
				case 'invalid':
				case 'classic':
				case 'deprecated':
					return !! kinds[ entry.kind ];
				default:
					return false;
			}
		};
		const hasWanted = ( entry ) => wants( entry ) || entry.children.some( hasWanted );
		const collectFixed = ( entry ) => {
			if ( wants( entry ) ) {
				fixed.push( entry.issue );
			}
			entry.children.forEach( collectFixed );
		};

		const innerFor = ( entry ) => {
			if ( ! entry.zipped ) {
				return entry.block.innerBlocks || [];
			}
			const inner = [];
			entry.children.forEach( ( child ) => {
				if ( wants( child ) ) {
					const result = fixedBlocks( child );
					if ( result === null ) {
						inner.push( child.block );
					} else if ( typeof result === 'string' ) {
						inner.push( ...( blocks.parse( result ) || [] ) );
					} else {
						inner.push( ...result );
					}
				} else if ( child.block && child.children.some( hasWanted ) ) {
					inner.push( Object.assign( {}, child.block, { innerBlocks: innerFor( child ) } ) );
				} else if ( child.block ) {
					inner.push( child.block );
				}
			} );
			return inner;
		};

		const fixedBlocks = ( entry ) => {
			const source = content.slice( entry.node.start, entry.node.end );
			const block = entry.block;
			const strategy = entry.kind === 'invalid' ? strategyFor( block.name ) : '';
			let result;
			let reason = '';

			if ( entry.kind === 'classic' ) {
				result = blocks.rawHandler( { HTML: source } );
			} else if ( entry.kind === 'missing' || strategy === 'html' ) {
				const original = entry.kind === 'missing' && block.attributes && typeof block.attributes.originalUndelimitedContent === 'string' ? block.attributes.originalUndelimitedContent : stripDelimiters( source );
				if ( original.trim() ) {
					result = htmlBlock( blocks, entry.kind === 'missing' ? original : stripDelimiters( source ) );
				} else {
					// Nothing saved to keep: a Custom HTML block would be empty and the block lost.
					result = null;
					reason = 'empty';
				}
			} else {
				result = blocks.createBlock( block.name, block.attributes, innerFor( entry ) );
			}

			if ( typeof options.fixBlock === 'function' ) {
				const filtered = options.fixBlock( result, block, { kind: entry.kind, strategy, source, path: entry.path, reason } );
				if ( ( filtered === null || filtered === undefined || filtered === false ) && result ) {
					reason = 'filter';
				}
				result = filtered;
			}
			if ( result === null || result === undefined || result === false ) {
				kept.push( Object.assign( {}, entry.issue, { reason: reason || 'filter' } ) );
				return null;
			}
			if ( typeof result === 'string' ) {
				return result;
			}
			return Array.isArray( result ) ? result : [ result ];
		};

		const walk = ( entry ) => {
			if ( wants( entry ) ) {
				const result = fixedBlocks( entry );
				if ( result === null ) {
					// Kept as it is. Blocks inside it can still be fixed on their own.
					entry.children.forEach( walk );
					return;
				}
				const markup = typeof result === 'string' ? result : blocks.serialize( result );
				const source = content.slice( entry.node.start, entry.node.end );
				if ( markup !== source && ( markup !== '' || entry.kind !== 'classic' ) ) {
					replacements.push( { start: entry.node.start, end: entry.node.end, markup } );
					collectFixed( entry );
				}
				return;
			}
			entry.children.forEach( walk );
		};
		entries.forEach( walk );

		let output = content;
		replacements
			.sort( ( a, b ) => b.start - a.start )
			.forEach( ( { start, end, markup } ) => {
				output = output.slice( 0, start ) + markup + output.slice( end );
			} );

		return { content: output, changed: output !== content, fixed, kept };
	}

	/**
	 * Lines for a diff: block delimiters and long runs of tags get their own lines, so a change is easy to spot.
	 *
	 * @param {string} text Markup.
	 * @return {string[]} Lines.
	 */
	function diffableLines( text ) {
		const lines = [];
		String( text )
			.replace( /(-->)(?=\S)/g, '$1\n' )
			.replace( /(\S)(<!--)/g, '$1\n$2' )
			.split( '\n' )
			.forEach( ( line ) => {
				if ( line.length <= 160 ) {
					lines.push( line );
					return;
				}
				line.split( /(?<=>)(?=<)/ ).forEach( ( part ) => lines.push( part ) );
			} );
		return lines;
	}

	/**
	 * A line diff of two versions of a post, with unchanged runs folded.
	 *
	 * @param {string} before  Old markup.
	 * @param {string} after   New markup.
	 * @param {number} context Unchanged lines kept around each change.
	 * @return {Object[]} Rows: type 'same', 'del', 'add' or 'gap' (with count), and text.
	 */
	function diffLines( before, after, context = 2 ) {
		const a = diffableLines( before );
		const b = diffableLines( after );
		let head = 0;
		while ( head < a.length && head < b.length && a[ head ] === b[ head ] ) {
			head++;
		}
		let tail = 0;
		while ( tail < a.length - head && tail < b.length - head && a[ a.length - 1 - tail ] === b[ b.length - 1 - tail ] ) {
			tail++;
		}

		const midA = a.slice( head, a.length - tail );
		const midB = b.slice( head, b.length - tail );
		const middle = [];
		if ( midA.length * midB.length > 2500000 ) {
			midA.forEach( ( text ) => middle.push( { type: 'del', text } ) );
			midB.forEach( ( text ) => middle.push( { type: 'add', text } ) );
		} else {
			// Longest common subsequence, then walk it.
			const rows = midA.length + 1;
			const cols = midB.length + 1;
			const table = new Uint32Array( rows * cols );
			for ( let i = midA.length - 1; i >= 0; i-- ) {
				for ( let j = midB.length - 1; j >= 0; j-- ) {
					table[ i * cols + j ] = midA[ i ] === midB[ j ] ? table[ ( i + 1 ) * cols + j + 1 ] + 1 : Math.max( table[ ( i + 1 ) * cols + j ], table[ i * cols + j + 1 ] );
				}
			}
			let i = 0;
			let j = 0;
			while ( i < midA.length || j < midB.length ) {
				if ( i < midA.length && j < midB.length && midA[ i ] === midB[ j ] ) {
					middle.push( { type: 'same', text: midA[ i ] } );
					i++;
					j++;
				} else if ( i < midA.length && ( j >= midB.length || table[ ( i + 1 ) * cols + j ] >= table[ i * cols + j + 1 ] ) ) {
					middle.push( { type: 'del', text: midA[ i ] } );
					i++;
				} else {
					middle.push( { type: 'add', text: midB[ j ] } );
					j++;
				}
			}
		}

		const all = a
			.slice( 0, head )
			.map( ( text ) => ( { type: 'same', text } ) )
			.concat( middle, a.slice( a.length - tail ).map( ( text ) => ( { type: 'same', text } ) ) );

		// Fold unchanged runs, keeping `context` lines next to each change.
		const keep = all.map( () => false );
		all.forEach( ( row, index ) => {
			if ( row.type !== 'same' ) {
				for ( let k = Math.max( 0, index - context ); k <= Math.min( all.length - 1, index + context ); k++ ) {
					keep[ k ] = true;
				}
			}
		} );
		const out = [];
		let skipped = 0;
		all.forEach( ( row, index ) => {
			if ( keep[ index ] ) {
				if ( skipped ) {
					out.push( { type: 'gap', text: '', count: skipped } );
					skipped = 0;
				}
				out.push( row );
			} else {
				skipped++;
			}
		} );
		if ( skipped && out.length ) {
			out.push( { type: 'gap', text: '', count: skipped } );
		}
		return out;
	}

	/**
	 * Formats seconds as m:ss or h:mm:ss.
	 *
	 * @param {number} seconds Seconds.
	 * @return {string} Time.
	 */
	function formatDuration( seconds ) {
		seconds = Math.max( 0, Math.round( seconds ) );
		const h = Math.floor( seconds / 3600 );
		const m = Math.floor( ( seconds % 3600 ) / 60 );
		const s = seconds % 60;
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		return h ? `${ h }:${ pad( m ) }:${ pad( s ) }` : `${ m }:${ pad( s ) }`;
	}

	return { TEXT, sprintf, excerpt, blockExcerpt, tokenize, stripDelimiters, htmlBlock, validationMessage, isOutdated, analyse, scan, scanPost, repair, diffableLines, diffLines, formatDuration };
} );
