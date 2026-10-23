/**
 * Tools > Block Doctor: runs the scan in the browser, shows the report, previews
 * and saves fixes, and undoes batches.
 *
 * The scan and repair logic is in doctor.js (window.blockDoctorCore). This file
 * connects it to the block editor's block API, the REST routes and the screen.
 *
 * JavaScript hooks (wp.hooks), all read when they are used, so any script can add them:
 * - filter blockDoctor.batchSize ( size ): posts per scan request.
 * - filter blockDoctor.shouldScanPost ( true, post ): false skips a post in the browser scan.
 * - filter blockDoctor.fixBlock ( result, block, context ): change or replace one repair.
 * - action blockDoctor.issueFound ( issue, post ): for every issue the browser scan finds.
 * - action blockDoctor.postFixed ( post, result, batchId ): after each fixed post is saved.
 */
( function ( wp, core, data ) {
	'use strict';

	if ( ! wp || ! core || ! data ) {
		return;
	}

	const el = wp.element.createElement;
	const { Fragment, useEffect, useMemo, useRef, useState } = wp.element;
	const { __, _n, sprintf } = wp.i18n;
	const { Button, CheckboxControl, Modal, Notice, SelectControl, Spinner } = wp.components;
	const { addQueryArgs } = wp.url;
	const ROUTE = '/block-doctor/v1';

	const TEXT = {
		invalid: __( 'The saved markup no longer matches what the block produces.', 'block-doctor' ),
		/* translators: %s: block name, such as acme/widget. */
		missing: __( 'Block type %s is not registered. Its plugin may be inactive or deleted.', 'block-doctor' ),
		/* translators: %s: start of the text. */
		classic: __( 'Classic content between blocks: "%s"', 'block-doctor' ),
		classicWhole: __( 'The whole post is classic content, with no blocks.', 'block-doctor' ),
		deprecated: __( 'Saved by an older version of the block. The markup changes the next time the post is saved.', 'block-doctor' ),
		error: __( 'The scan could not read the blocks of this post, so they were not checked.', 'block-doctor' ),
	};

	const KEPT_REASONS = {
		empty: __( 'It has no saved HTML, because its plugin renders it, so a Custom HTML block would be empty. It stays as it is.', 'block-doctor' ),
		filter: __( 'A blockDoctor.fixBlock filter left it as it is.', 'block-doctor' ),
	};

	const SKIP_REASONS = {
		edited: __( 'edited after the fix', 'block-doctor' ),
		deleted: __( 'deleted', 'block-doctor' ),
		forbidden: __( 'you cannot edit it', 'block-doctor' ),
		nothing: __( 'nothing to undo', 'block-doctor' ),
		revision: __( 'the revision from before the fix is gone', 'block-doctor' ),
	};

	const FIXABLE = [ 'invalid', 'missing', 'classic', 'deprecated' ];

	const request = ( method, path, body ) => wp.apiFetch( { method, path: ROUTE + path, data: body } );

	// Block types the server registry knows: never reported as unregistered, even when their editor script does not load here.
	const serverBlocks = new Set( data.serverBlocks || [] );
	const isRegistered = ( name ) => serverBlocks.has( name );
	const scanOptions = () => ( { text: TEXT, isRegistered } );

	/** Posts per scan request, through the blockDoctor.batchSize filter. */
	function batchSize() {
		/**
		 * Filters how many posts the browser scan asks for at a time.
		 *
		 * @param {number} size Default from the block_doctor_batch_size PHP filter (20). Clamped to 1 to 100.
		 */
		const size = parseInt( wp.hooks.applyFilters( 'blockDoctor.batchSize', data.batchSize ), 10 );
		return Math.max( 1, Math.min( 100, size || data.batchSize ) );
	}

	/** Registers core blocks unless another script already did. */
	function bootBlocks() {
		if ( ! wp.blocks.getBlockType( 'core/paragraph' ) && wp.blockLibrary && wp.blockLibrary.registerCoreBlocks ) {
			wp.blockLibrary.registerCoreBlocks();
		}
	}

	/** The repair filter, wrapped so a broken callback cannot stop a run. */
	function fixBlockFilter( result, block, context ) {
		/**
		 * Filters one repair. Return a block, an array of blocks, block markup, or null to leave the block as it is.
		 *
		 * @param {Object|Object[]} result  What Block Doctor would put in its place.
		 * @param {Object}          block   The block as parsed, with isValid and validationIssues.
		 * @param {Object}          context kind, strategy ('recover' or 'html'), source (original markup), path, post.
		 */
		return wp.hooks.applyFilters( 'blockDoctor.fixBlock', result, block, context );
	}

	const kindInfo = ( key ) => data.kinds.find( ( kind ) => kind.key === key ) || { key, label: key, description: '', fix: '' };

	/**
	 * Where a block is, in words: "block 3 from the top", or "block 1 inside block 3".
	 *
	 * @param {number[]} path Path from 0, one number per level.
	 * @return {string} Position.
	 */
	const position = ( path ) => {
		const steps = ( path && path.length ? path : [ 0 ] ).map( ( n ) => n + 1 );
		if ( steps.length === 1 ) {
			/* translators: %s: position of a top-level block in the post, such as 3. */
			return sprintf( __( 'block %s from the top', 'block-doctor' ), steps[ 0 ] );
		}
		return sprintf(
			/* translators: 1: position of the block inside its parent, such as 1, 2: position of the parent, such as 3 or 3 › 2. */
			__( 'block %1$s inside block %2$s', 'block-doctor' ),
			steps[ steps.length - 1 ],
			steps.slice( 0, -1 ).join( ' › ' )
		);
	};

	/**
	 * The block's title, such as Heading, or its name when no script registers it here.
	 *
	 * @param {string} name Block name.
	 * @return {string} Title.
	 */
	const blockTitle = ( name ) => {
		const type = name ? wp.blocks.getBlockType( name ) : null;
		return type && type.title ? String( type.title ) : name;
	};

	/**
	 * A date and time in the site's format and time zone, like the rest of wp-admin.
	 *
	 * @param {number} time Unix time.
	 * @return {string} Date.
	 */
	const formatDate = ( time ) => {
		if ( ! time ) {
			return '';
		}
		return wp.date && wp.date.dateI18n ? wp.date.dateI18n( data.dateFormat, time * 1000 ) : new Date( time * 1000 ).toLocaleString();
	};

	/**
	 * Technical text with `code` in backticks, as elements: the backticks themselves never show.
	 *
	 * @param {string} text Text.
	 * @return {Array} Strings and code elements.
	 */
	const withCode = ( text ) => String( text ).split( /`([^`]*)`/ ).map( ( part, index ) => ( index % 2 ? el( 'code', { key: index }, part ) : part ) );

	function errorMessage( error ) {
		return ( error && error.message ) || __( 'Something went wrong. Reload the page and try again.', 'block-doctor' );
	}

	/**
	 * Works out the fixes for some posts.
	 *
	 * @param {Object[]} posts   Posts with content and hash.
	 * @param {Object}   options kinds, strategies.
	 * @return {Object[]} One item per post: post, result, left (issues still there).
	 */
	function prepare( posts, options ) {
		return posts.map( ( post ) => {
			let result;
			try {
				result = core.repair(
					post.content,
					wp.blocks,
					Object.assign( scanOptions(), {
						kinds: options.kinds,
						strategies: options.strategies,
						strategy: data.strategy,
						fixBlock: ( value, block, context ) => fixBlockFilter( value, block, Object.assign( {}, context, { post } ) ),
					} )
				);
			} catch ( error ) {
				result = { content: post.content, changed: false, fixed: [], kept: [], error: error.message };
			}
			const left = result.changed ? core.scanPost( result.content, wp.blocks, scanOptions() ) : null;
			return { post, result, left };
		} );
	}

	function Progress( { done, total, label } ) {
		const percent = total ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		return el(
			'div',
			{ className: 'bdr-progress-wrap' },
			el( 'div', { className: 'bdr-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': percent }, el( 'div', { className: 'bdr-progress-bar', style: { width: percent + '%' } } ) ),
			el( 'p', { className: 'bdr-progress-label' }, label )
		);
	}

	function KindBadge( { kind } ) {
		return el( 'span', { className: 'bdr-badge bdr-badge-' + kind }, kindInfo( kind ).label );
	}

	function Diff( { before, after } ) {
		const rows = useMemo( () => core.diffLines( before, after ), [ before, after ] );
		return el(
			'div',
			{ className: 'bdr-diff' },
			rows.map( ( row, index ) =>
				row.type === 'gap'
					? el(
							'div',
							{ key: index, className: 'bdr-diff-gap' },
							sprintf(
								/* translators: %s: number of lines. */
								_n( '%s unchanged line', '%s unchanged lines', row.count, 'block-doctor' ),
								row.count
							)
					  )
					: el( 'div', { key: index, className: 'bdr-diff-' + row.type }, el( 'span', { className: 'bdr-diff-sign', 'aria-hidden': true }, { del: '-', add: '+', same: ' ' }[ row.type ] ), row.text || ' ' )
			)
		);
	}

	function PreviewItem( { item } ) {
		// Custom HTML keeps the markup but drops the block's settings: show what changes straight away.
		const [ open, setOpen ] = useState( () => item.result.fixed.some( ( issue ) => issue.kind === 'missing' ) );
		const kept = item.result.kept || [];
		const counts = {};
		item.result.fixed.forEach( ( issue ) => {
			counts[ issue.kind ] = ( counts[ issue.kind ] || 0 ) + 1;
		} );
		return el(
			'li',
			{ className: 'bdr-preview-item', 'data-post': item.post.id },
			el(
				'div',
				{ className: 'bdr-preview-head' },
				el( 'strong', null, item.post.title || sprintf( /* translators: %d: post ID. */ __( 'Post #%d', 'block-doctor' ), item.post.id ) ),
				' ',
				Object.keys( counts ).map( ( kind ) => el( 'span', { key: kind, className: 'bdr-chip' }, el( KindBadge, { kind } ), ' × ' + counts[ kind ] ) ),
				item.left && item.left.length
					? el(
							'span',
							{ className: 'bdr-left' },
							sprintf(
								/* translators: %s: number of issues. */
								_n( '%s issue left', '%s issues left', item.left.length, 'block-doctor' ),
								item.left.length
							)
					  )
					: null,
				item.result.changed
					? el( Button, { variant: 'link', className: 'bdr-toggle-diff', 'aria-expanded': open, onClick: () => setOpen( ! open ) }, open ? __( 'Hide changes', 'block-doctor' ) : __( 'Show changes', 'block-doctor' ) )
					: null
			),
			kept.length
				? el(
						'ul',
						{ className: 'bdr-kept' },
						kept.map( ( issue, index ) =>
							el(
								'li',
								{ key: index, 'data-reason': issue.reason },
								el( 'strong', null, blockTitle( issue.name ) ),
								issue.name && blockTitle( issue.name ) !== issue.name ? el( 'code', { className: 'bdr-slug' }, issue.name ) : null,
								' ',
								KEPT_REASONS[ issue.reason ] || KEPT_REASONS.filter
							)
						)
				  )
				: null,
			open && item.result.changed && el( Diff, { before: item.post.content, after: item.result.content } )
		);
	}

	function Preview( { ids, kinds, onClose, onDone } ) {
		const [ posts, setPosts ] = useState( null );
		const [ loaded, setLoaded ] = useState( 0 );
		const [ strategies, setStrategies ] = useState( () => Object.assign( {}, data.strategies ) );
		const [ phase, setPhase ] = useState( 'loading' );
		const [ applied, setApplied ] = useState( { done: 0, saved: [], errors: [], batch: null } );
		const [ error, setError ] = useState( '' );

		useEffect( () => {
			let cancelled = false;
			( async () => {
				const all = [];
				try {
					for ( let i = 0; i < ids.length && ! cancelled; i += batchSize() ) {
						const chunk = ids.slice( i, i + batchSize() );
						const page = await request( 'GET', addQueryArgs( '/posts', { include: chunk, per_page: Math.min( 100, chunk.length ) } ) );
						all.push( ...page.posts );
						setLoaded( Math.min( ids.length, i + chunk.length ) );
					}
				} catch ( caught ) {
					setError( errorMessage( caught ) );
				}
				if ( ! cancelled ) {
					setPosts( all );
					setPhase( 'review' );
				}
			} )();
			return () => {
				cancelled = true;
			};
		}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

		const items = useMemo( () => ( posts ? prepare( posts, { kinds, strategies } ) : [] ), [ posts, kinds, strategies ] );
		const changed = items.filter( ( item ) => item.result.changed );
		const listed = items.filter( ( item ) => item.result.changed || ( item.result.kept && item.result.kept.length ) );
		const keptCount = items.reduce( ( sum, item ) => sum + ( item.result.kept ? item.result.kept.length : 0 ), 0 );

		// Block types with invalid content in these posts: each can be recovered or turned into Custom HTML.
		const invalidTypes = useMemo( () => {
			const names = {};
			if ( kinds.invalid && posts ) {
				posts.forEach( ( post ) => {
					core.scanPost( post.content, wp.blocks, scanOptions() ).forEach( ( issue ) => {
						if ( issue.kind === 'invalid' ) {
							names[ issue.name ] = ( names[ issue.name ] || 0 ) + 1;
						}
					} );
				} );
			}
			return names;
		}, [ posts, kinds ] );

		async function apply() {
			setPhase( 'saving' );
			setError( '' );
			const state = { done: 0, saved: [], errors: [], batch: null };
			setApplied( Object.assign( {}, state ) );
			let batch;
			try {
				batch = await request( 'POST', '/batches', { kinds: Object.keys( kinds ).filter( ( kind ) => kinds[ kind ] && kinds[ kind ] !== 'keep' ) } );
			} catch ( caught ) {
				// Nothing was saved. Stay on the preview, so the fixes can be applied again later.
				setError( errorMessage( caught ) );
				setPhase( 'review' );
				return;
			}
			state.batch = batch.id;
			for ( const item of changed ) {
				try {
					const result = await request( 'POST', '/fix', { post_id: item.post.id, batch: batch.id, content: item.result.content, hash: item.post.hash, issues: item.left || [] } );
					state.saved.push( item.post.id );
					/**
					 * Fires after a fixed post is saved.
					 *
					 * @param {Object} post    The post as it was before the fix: id, type, title, content, hash.
					 * @param {Object} result  From the server: post_id, changed, revision (with the content before the fix), issues (how many are left).
					 * @param {number} batchId The batch, for Undo batch.
					 */
					wp.hooks.doAction( 'blockDoctor.postFixed', item.post, result, batch.id );
				} catch ( caught ) {
					state.errors.push( { post: item.post, message: errorMessage( caught ) } );
				}
				state.done++;
				setApplied( Object.assign( {}, state ) );
			}

			if ( ! state.saved.length ) {
				// Every save was refused, for example during a site freeze: only the error, and the preview stays.
				const messages = Array.from( new Set( state.errors.map( ( entry ) => entry.message ) ) );
				setError( messages.length === 1 ? messages[ 0 ] : __( 'None of the fixes could be saved.', 'block-doctor' ) );
				setApplied( { done: 0, saved: [], errors: messages.length > 1 ? state.errors : [], batch: null } );
				setPhase( 'review' );
				return;
			}
			setApplied( Object.assign( {}, state ) );
			setPhase( 'done' );
		}

		const errorList = applied.errors.length
			? el(
					'ul',
					{ className: 'bdr-errors' },
					applied.errors.map( ( entry ) => el( 'li', { key: entry.post.id }, el( 'strong', null, entry.post.title ), ': ', entry.message ) )
			  )
			: null;

		let body;
		if ( phase === 'loading' ) {
			body = el( Progress, {
				done: loaded,
				total: ids.length,
				label: sprintf(
					/* translators: 1: posts loaded, 2: posts in total. */
					__( 'Loading %1$s of %2$s posts...', 'block-doctor' ),
					loaded,
					ids.length
				),
			} );
		} else if ( phase === 'saving' ) {
			body = el( Progress, {
				done: applied.done,
				total: changed.length,
				label: sprintf(
					/* translators: 1: posts saved, 2: posts to save. */
					__( 'Saving %1$s of %2$s posts...', 'block-doctor' ),
					applied.done,
					changed.length
				),
			} );
		} else if ( phase === 'done' ) {
			body = el(
				Fragment,
				null,
				el(
					Notice,
					{ status: applied.errors.length ? 'warning' : 'success', isDismissible: false },
					sprintf(
						/* translators: 1: number of posts, 2: batch number. */
						_n( 'Fixed %1$s post in batch #%2$s. Each fix is saved as a revision.', 'Fixed %1$s posts in batch #%2$s. Each fix is saved as a revision.', applied.saved.length, 'block-doctor' ),
						applied.saved.length,
						applied.batch
					)
				),
				errorList
			);
		} else {
			body = el(
				Fragment,
				null,
				errorList,
				Object.keys( invalidTypes ).length
					? el(
							'div',
							{ className: 'bdr-strategies' },
							el( 'h3', null, __( 'Invalid blocks', 'block-doctor' ) ),
							el( 'p', { className: 'description' }, __( 'Recover rebuilds the block from its settings, like Attempt Block Recovery in the editor. Custom HTML keeps the markup exactly as it is, in a Custom HTML block.', 'block-doctor' ) ),
							el(
								'div',
								{ className: 'bdr-strategy-list' },
								Object.keys( invalidTypes )
									.sort()
									.map( ( name ) =>
										el( SelectControl, {
											key: name,
											className: 'bdr-strategy',
											label: sprintf(
												/* translators: 1: block title, such as Image, 2: number of blocks. */
												_n( '%1$s, %2$s block', '%1$s, %2$s blocks', invalidTypes[ name ], 'block-doctor' ),
												blockTitle( name ),
												invalidTypes[ name ]
											),
											help: name,
											value: strategies[ name ] || data.strategy,
											options: [
												{ value: 'recover', label: __( 'Recover the block', 'block-doctor' ) },
												{ value: 'html', label: __( 'Convert to Custom HTML', 'block-doctor' ) },
											],
											onChange: ( value ) => setStrategies( Object.assign( {}, strategies, { [ name ]: value } ) ),
											__nextHasNoMarginBottom: true,
											__next40pxDefaultSize: true,
										} )
									)
							)
					  )
					: null,
				el(
					'p',
					{ className: 'bdr-preview-count' },
					changed.length
						? sprintf(
								/* translators: 1: posts that change, 2: posts checked. */
								__( '%1$s of %2$s posts change. Nothing is saved until you apply the fixes.', 'block-doctor' ),
								changed.length,
								items.length
						  )
						: __( 'Nothing to change in these posts with the chosen fixes.', 'block-doctor' ),
					keptCount
						? ' ' +
								sprintf(
									/* translators: %s: number of blocks. */
									_n( '%s block stays as it is, see why below.', '%s blocks stay as they are, see why below.', keptCount, 'block-doctor' ),
									keptCount
								)
						: ''
				),
				el(
					'ul',
					{ className: 'bdr-preview-list' },
					listed.map( ( item ) => el( PreviewItem, { key: item.post.id, item } ) )
				)
			);
		}

		const footer =
			phase === 'review'
				? el(
						'div',
						{ className: 'bdr-modal-footer' },
						el( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'block-doctor' ) ),
						el(
							Button,
							{ variant: 'primary', className: 'bdr-apply', disabled: ! changed.length, onClick: apply },
							sprintf(
								/* translators: %s: number of posts. */
								_n( 'Apply fixes to %s post', 'Apply fixes to %s posts', changed.length, 'block-doctor' ),
								changed.length
							)
						)
				  )
				: phase === 'done'
				? el(
						'div',
						{ className: 'bdr-modal-footer' },
						el( Button, { variant: 'secondary', className: 'bdr-undo-now', disabled: ! applied.saved.length, onClick: () => onDone( applied.batch, true ) }, __( 'Undo batch', 'block-doctor' ) ),
						el( Button, { variant: 'primary', className: 'bdr-close', onClick: () => onDone( applied.batch, false ) }, __( 'Close', 'block-doctor' ) )
				  )
				: null;

		return el(
			Modal,
			{
				title: __( 'Preview fixes', 'block-doctor' ),
				className: 'bdr-modal',
				onRequestClose: phase === 'saving' ? () => {} : phase === 'done' ? () => onDone( applied.batch, false ) : onClose,
				shouldCloseOnClickOutside: false,
			},
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			body,
			footer
		);
	}

	function ScanPanel( { summary, setSummary, onFinished, setNotice } ) {
		const [ running, setRunning ] = useState( false );
		const [ rate, setRate ] = useState( null );
		const stop = useRef( false );
		const state = summary.browser;

		async function run( fresh ) {
			stop.current = false;
			setRunning( true );
			setRate( null );
			try {
				let current = await request( 'POST', '/scan', fresh ? { action: 'start', blocks: wp.blocks.getBlockTypes().map( ( type ) => type.name ) } : { action: 'resume' } );
				setSummary( current );
				let cursor = current.browser.cursor;
				const startedAt = Date.now();
				let checked = 0;

				while ( ! stop.current ) {
					const page = await request( 'GET', addQueryArgs( '/posts', { after: cursor, per_page: batchSize() } ) );
					const results = [];
					page.posts.forEach( ( post ) => {
						/**
						 * Filters whether the browser scan checks a post. Skipped posts keep their stored results.
						 *
						 * @param {boolean} scan Default true.
						 * @param {Object}  post id, type, status, title, hash, content.
						 */
						if ( ! wp.hooks.applyFilters( 'blockDoctor.shouldScanPost', true, post ) ) {
							return;
						}
						// A post the scan cannot read gets an error issue, so it is never stored as clean.
						const issues = core.scanPost( post.content, wp.blocks, scanOptions() );
						issues.forEach( ( issue ) => {
							/**
							 * Fires for every issue the browser scan finds.
							 *
							 * @param {Object} issue kind, path, name, message.
							 * @param {Object} post  id, type, status, title, hash, content.
							 */
							wp.hooks.doAction( 'blockDoctor.issueFound', issue, post );
						} );
						results.push( { id: post.id, hash: post.hash, issues } );
					} );
					current = await request( 'POST', '/results', { cursor: page.next, count: page.count, finished: page.done, results } );
					setSummary( current );
					cursor = page.next;
					checked += page.count;
					setRate( checked / Math.max( 1, ( Date.now() - startedAt ) / 1000 ) );
					if ( page.done ) {
						break;
					}
				}

				if ( stop.current ) {
					setSummary( await request( 'POST', '/scan', { action: 'pause' } ) );
				} else {
					onFinished();
				}
			} catch ( error ) {
				setNotice( { status: 'error', text: errorMessage( error ) } );
				try {
					setSummary( await request( 'POST', '/scan', { action: 'pause' } ) );
				} catch ( ignore ) {}
			}
			setRunning( false );
		}

		const unfinished = ! running && ( state.status === 'running' || state.status === 'paused' );
		const left = Math.max( 0, state.total - state.done );
		let label = '';
		if ( running || unfinished ) {
			label = sprintf(
				/* translators: 1: posts checked, 2: posts in total. */
				__( '%1$s of %2$s posts checked', 'block-doctor' ),
				Math.min( state.done, state.total ),
				state.total
			);
			if ( running && rate && left ) {
				label +=
					', ' +
					sprintf(
						/* translators: %s: time left, such as 1:20. */
						__( 'about %s left', 'block-doctor' ),
						core.formatDuration( left / rate )
					);
			}
			if ( unfinished ) {
				label += '. ' + __( 'Paused.', 'block-doctor' );
			}
		}

		return el(
			'div',
			{ className: 'bdr-panel bdr-scan', 'data-status': running ? 'running' : state.status },
			el( 'h2', null, __( 'Scan', 'block-doctor' ) ),
			state.status === 'done' && ! running
				? el(
						'p',
						{ className: 'bdr-last-scan' },
						sprintf(
							/* translators: 1: date and time, 2: number of posts. */
							_n( 'Last scan: %1$s, %2$s post checked.', 'Last scan: %1$s, %2$s posts checked.', state.done, 'block-doctor' ),
							formatDate( state.finished ),
							state.done
						)
				  )
				: null,
			state.status === 'idle' && ! running ? el( 'p', null, __( 'Checks every block with the block editor\'s own validation, a batch at a time. Nothing changes until you fix something.', 'block-doctor' ) ) : null,
			running || unfinished ? el( Progress, { done: state.done, total: state.total, label } ) : null,
			el(
				'div',
				{ className: 'bdr-actions' },
				running
					? el(
							Button,
							{
								variant: 'secondary',
								className: 'bdr-pause',
								onClick: () => {
									stop.current = true;
								},
							},
							__( 'Pause', 'block-doctor' )
					  )
					: unfinished
					? el(
							Fragment,
							null,
							el( Button, { variant: 'primary', className: 'bdr-resume', onClick: () => run( false ) }, __( 'Resume scan', 'block-doctor' ) ),
							el( Button, { variant: 'secondary', className: 'bdr-restart', onClick: () => run( true ) }, __( 'Start over', 'block-doctor' ) )
					  )
					: el( Button, { variant: 'primary', className: 'bdr-start', onClick: () => run( true ) }, state.status === 'done' ? __( 'Scan again', 'block-doctor' ) : __( 'Scan site', 'block-doctor' ) ),
				running ? el( Spinner ) : null
			)
		);
	}

	function FixOptions( { kinds, setKinds } ) {
		const toggle = ( kind ) => ( value ) => setKinds( Object.assign( {}, kinds, { [ kind ]: value } ) );
		return el(
			'fieldset',
			{ className: 'bdr-fix-options' },
			el( 'legend', null, __( 'Fix', 'block-doctor' ) ),
			el( CheckboxControl, { label: __( 'Invalid blocks', 'block-doctor' ), checked: !! kinds.invalid, onChange: toggle( 'invalid' ), __nextHasNoMarginBottom: true } ),
			el( CheckboxControl, { label: __( 'Outdated blocks (save them again)', 'block-doctor' ), checked: !! kinds.deprecated, onChange: toggle( 'deprecated' ), __nextHasNoMarginBottom: true } ),
			el( CheckboxControl, { label: __( 'Classic content (convert to blocks)', 'block-doctor' ), checked: !! kinds.classic, onChange: toggle( 'classic' ), __nextHasNoMarginBottom: true } ),
			el( SelectControl, {
				label: __( 'Unregistered blocks', 'block-doctor' ),
				className: 'bdr-missing-option',
				value: kinds.missing,
				options: [
					{ value: 'keep', label: __( 'Leave them as they are', 'block-doctor' ) },
					{ value: 'html', label: __( 'Convert to Custom HTML', 'block-doctor' ) },
				],
				help: __( 'Blocks with no saved HTML, such as dynamic blocks, always stay as they are.', 'block-doctor' ),
				onChange: toggle( 'missing' ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} )
		);
	}

	/**
	 * One block with an issue: its title, its name, its first words, where it is, and the message unless the group shows it.
	 *
	 * @param {Object}  props        Props.
	 * @param {Object}  props.issue  Issue.
	 * @param {boolean} props.shared Whether the group already shows the message.
	 */
	function IssueItem( { issue, shared } ) {
		const title = blockTitle( issue.name );
		// Classic content is not a block of its own: the badge and the message say all there is.
		const named = issue.name && issue.kind !== 'classic';
		return el(
			'li',
			{ className: 'bdr-issue', 'data-kind': issue.kind },
			named ? el( 'span', { className: 'bdr-block' }, title ) : null,
			named && title !== issue.name ? el( 'code', { className: 'bdr-slug' }, issue.name ) : null,
			named && issue.excerpt ? el( 'span', { className: 'bdr-excerpt' }, '“' + issue.excerpt + '”' ) : null,
			named ? el( 'span', { className: 'bdr-position' }, position( issue.path ) ) : null,
			shared ? null : el( 'span', { className: 'bdr-message' }, issue.message ),
			issue.detail
				? el( 'details', { className: 'bdr-detail' }, el( 'summary', null, __( 'Technical details', 'block-doctor' ) ), el( 'span', { className: 'bdr-detail-text' }, withCode( issue.detail ) ) )
				: null
		);
	}

	/**
	 * A post's issues, grouped by kind: "Outdated: 12 blocks (List ×8, Heading ×4)".
	 *
	 * @param {Object}   props        Props.
	 * @param {Object[]} props.issues Issues.
	 */
	function IssueList( { issues } ) {
		const groups = [];
		issues.forEach( ( issue ) => {
			let group = groups.find( ( entry ) => entry.kind === issue.kind );
			if ( ! group ) {
				group = { kind: issue.kind, issues: [] };
				groups.push( group );
			}
			group.issues.push( issue );
		} );

		return el(
			'ul',
			{ className: 'bdr-issues' },
			groups.map( ( group ) => {
				const blocks = group.issues.filter( ( issue ) => issue.name && issue.kind !== 'classic' );
				const titles = {};
				blocks.forEach( ( issue ) => {
					const title = blockTitle( issue.name );
					titles[ title ] = ( titles[ title ] || 0 ) + 1;
				} );
				const messages = Array.from( new Set( group.issues.map( ( issue ) => issue.message ) ) );
				const shared = group.issues.length > 1 && messages.length === 1;
				const list = el(
					'ul',
					{ className: 'bdr-group-items' },
					group.issues.map( ( issue, index ) => el( IssueItem, { key: index, issue, shared } ) )
				);
				return el(
					'li',
					{ key: group.kind, className: 'bdr-group', 'data-kind': group.kind },
					el( KindBadge, { kind: group.kind } ),
					blocks.length > 1
						? el(
								'span',
								{ className: 'bdr-group-summary' },
								sprintf(
									/* translators: 1: number of blocks, 2: block titles with counts, such as List ×8, Heading ×4. */
									_n( '%1$s block: %2$s', '%1$s blocks: %2$s', blocks.length, 'block-doctor' ),
									blocks.length,
									Object.keys( titles )
										.map( ( title ) => ( titles[ title ] > 1 ? title + ' ×' + titles[ title ] : title ) )
										.join( ', ' )
								)
						  )
						: null,
					shared ? el( 'span', { className: 'bdr-message' }, messages[ 0 ] ) : null,
					group.issues.length > 3
						? el(
								'details',
								{ className: 'bdr-group-more' },
								el(
									'summary',
									null,
									sprintf(
										/* translators: %s: number of blocks. */
										__( 'Show all %s', 'block-doctor' ),
										group.issues.length
									)
								),
								list
						  )
						: list
				);
			} )
		);
	}

	function Report( { summary, version, onFix, setNotice, reload } ) {
		const [ tab, setTab ] = useState( '' );
		const [ page, setPage ] = useState( 1 );
		const [ report, setReport ] = useState( null );
		const [ selected, setSelected ] = useState( [] );
		const [ kinds, setKinds ] = useState( { invalid: true, deprecated: true, classic: false, missing: 'keep' } );

		// A scan, fix or undo changes what is listed, so start the selection again.
		useEffect( () => setSelected( [] ), [ version ] );

		useEffect( () => {
			let cancelled = false;
			request( 'GET', addQueryArgs( '/issues', { kind: tab, page, per_page: 20 } ) )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setReport( response );
					}
				} )
				.catch( ( error ) => setNotice( { status: 'error', text: errorMessage( error ) } ) );
			return () => {
				cancelled = true;
			};
		}, [ tab, page, version ] ); // eslint-disable-line react-hooks/exhaustive-deps

		const counts = summary.counts || {};
		const tabs = data.kinds.filter( ( kind ) => counts[ kind.key ] && counts[ kind.key ].posts );
		const tabCounts = tab && counts[ tab ] ? counts[ tab ] : null;
		const items = report ? report.items : [];
		const pageIds = items.map( ( item ) => item.id );
		const allOnPage = pageIds.length && pageIds.every( ( id ) => selected.includes( id ) );
		const tabFixable = tab && FIXABLE.includes( tab );

		async function fixAll() {
			try {
				const response = await request( 'GET', addQueryArgs( '/issues', { kind: tab, ids_only: true } ) );
				const only = { invalid: false, deprecated: false, classic: false, missing: 'keep' };
				only[ tab ] = tab === 'missing' ? 'html' : true;
				onFix( response.ids, only );
			} catch ( error ) {
				setNotice( { status: 'error', text: errorMessage( error ) } );
			}
		}

		async function clear() {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Delete every stored result? Fixed posts and fix batches stay.', 'block-doctor' ) ) ) {
				return;
			}
			try {
				await request( 'DELETE', '/results' );
				setSelected( [] );
				reload();
			} catch ( error ) {
				setNotice( { status: 'error', text: errorMessage( error ) } );
			}
		}

		if ( ! summary.posts && summary.browser.status === 'idle' && summary.server.status === 'idle' ) {
			return null;
		}

		return el(
			'div',
			{ className: 'bdr-panel bdr-report' },
			el( 'h2', null, __( 'Report', 'block-doctor' ) ),
			el(
				'div',
				{ className: 'bdr-tabs', role: 'group', 'aria-label': __( 'Show the posts with one kind of issue', 'block-doctor' ) },
				[ { key: '', label: __( 'All', 'block-doctor' ) } ].concat( tabs ).map( ( kind ) =>
					el(
						'button',
						{
							key: kind.key,
							type: 'button',
							className: 'bdr-tab' + ( tab === kind.key ? ' is-active' : '' ),
							'aria-pressed': tab === kind.key,
							'data-kind': kind.key || 'all',
							title: kind.description || '',
							onClick: () => {
								setTab( kind.key );
								setPage( 1 );
							},
						},
						kind.label,
						' ',
						// Every pill counts posts, like the table below it.
						el( 'span', { className: 'bdr-count' }, kind.key ? counts[ kind.key ].posts : summary.posts )
					)
				)
			),
			! items.length
				? el( 'p', { className: 'bdr-empty' }, summary.posts ? __( 'Nothing of this kind.', 'block-doctor' ) : __( 'No issues found. Every block is healthy.', 'block-doctor' ) )
				: el(
						Fragment,
						null,
						tab
							? el(
									'p',
									{ className: 'description bdr-kind-description' },
									kindInfo( tab ).description,
									tabCounts
										? ' ' +
												sprintf(
													/* translators: 1: number of blocks, such as "381 blocks", 2: number of posts, such as "75 posts". */
													__( '%1$s in %2$s.', 'block-doctor' ),
													sprintf( /* translators: %s: number of blocks. */ _n( '%s block', '%s blocks', tabCounts.issues, 'block-doctor' ), tabCounts.issues ),
													sprintf( /* translators: %s: number of posts. */ _n( '%s post', '%s posts', tabCounts.posts, 'block-doctor' ), tabCounts.posts )
												)
										: ''
							  )
							: null,
						el( FixOptions, { kinds, setKinds } ),
						el(
							'div',
							{ className: 'bdr-toolbar' },
							el(
								Button,
								{ variant: 'primary', className: 'bdr-fix-selected', disabled: ! selected.length, onClick: () => onFix( selected, kinds ) },
								sprintf(
									/* translators: %s: number of posts. */
									_n( 'Preview fixes for %s selected post', 'Preview fixes for %s selected posts', selected.length, 'block-doctor' ),
									selected.length
								)
							),
							tabFixable
								? el(
										Button,
										{ variant: 'secondary', className: 'bdr-fix-kind', onClick: fixAll },
										sprintf(
											/* translators: 1: issue kind, such as Invalid, 2: number of posts. */
											_n( 'Preview fixes for every "%1$s" issue (%2$s post)', 'Preview fixes for every "%1$s" issue (%2$s posts)', counts[ tab ].posts, 'block-doctor' ),
											kindInfo( tab ).label,
											counts[ tab ].posts
										)
								  )
								: null,
							el( 'a', { className: 'button bdr-export', href: addQueryArgs( data.exportUrl, tab ? { kind: tab } : {} ) }, __( 'Download CSV', 'block-doctor' ) ),
							el( Button, { variant: 'tertiary', isDestructive: true, className: 'bdr-clear', onClick: clear }, __( 'Clear results', 'block-doctor' ) )
						),
						el(
							'table',
							{ className: 'widefat striped bdr-table' },
							el(
								'thead',
								null,
								el(
									'tr',
									null,
									el(
										'td',
										{ className: 'check-column' },
										el( 'input', {
											type: 'checkbox',
											'aria-label': __( 'Select every post on this page', 'block-doctor' ),
											checked: !! allOnPage,
											onChange: ( event ) => setSelected( event.target.checked ? Array.from( new Set( selected.concat( pageIds ) ) ) : selected.filter( ( id ) => ! pageIds.includes( id ) ) ),
										} )
									),
									el( 'th', { scope: 'col' }, __( 'Post', 'block-doctor' ) ),
									el( 'th', { scope: 'col' }, __( 'Issues', 'block-doctor' ) )
								)
							),
							el(
								'tbody',
								null,
								items.map( ( item ) =>
									el(
										'tr',
										{ key: item.id, 'data-post': item.id },
										el(
											'th',
											{ scope: 'row', className: 'check-column' },
											el( 'input', {
												type: 'checkbox',
												'aria-label': sprintf( /* translators: %s: post title. */ __( 'Select %s', 'block-doctor' ), item.title ),
												checked: selected.includes( item.id ),
												onChange: ( event ) => setSelected( event.target.checked ? selected.concat( item.id ) : selected.filter( ( id ) => id !== item.id ) ),
											} )
										),
										el(
											'td',
											{ className: 'bdr-post' },
											el( 'a', { href: item.edit_link, className: 'row-title' }, item.title || sprintf( /* translators: %d: post ID. */ __( 'Post #%d', 'block-doctor' ), item.id ) ),
											el( 'div', { className: 'bdr-meta' }, item.type_label, ' · ', item.status_label || item.status ),
											item.stale ? el( 'div', { className: 'bdr-stale' }, __( 'Changed since the scan', 'block-doctor' ) ) : null
										),
										el( 'td', null, el( IssueList, { issues: item.issues } ) )
									)
								)
							)
						),
						report && report.pages > 1
							? el(
									'div',
									{ className: 'bdr-pages' },
									el( Button, { variant: 'secondary', disabled: page <= 1, onClick: () => setPage( page - 1 ) }, __( 'Previous', 'block-doctor' ) ),
									el(
										'span',
										null,
										sprintf(
											/* translators: 1: page, 2: pages. */
											__( 'Page %1$s of %2$s', 'block-doctor' ),
											page,
											report.pages
										)
									),
									el( Button, { variant: 'secondary', disabled: page >= report.pages, onClick: () => setPage( page + 1 ) }, __( 'Next', 'block-doctor' ) )
							  )
							: null
				  )
		);
	}

	function Batches( { version, onUndo } ) {
		const [ batches, setBatches ] = useState( [] );

		useEffect( () => {
			request( 'GET', '/batches' )
				.then( setBatches )
				.catch( () => setBatches( [] ) );
		}, [ version ] );

		if ( ! batches.length ) {
			return null;
		}

		return el(
			'div',
			{ className: 'bdr-panel bdr-batches' },
			el( 'h2', null, __( 'Fix batches', 'block-doctor' ) ),
			el(
				'p',
				{ className: 'description' },
				sprintf(
					/* translators: %s: number of days. */
					_n( 'Undo batch puts every post back to the revision saved before its fix. Posts edited since are skipped. Batches can be undone for %s day.', 'Undo batch puts every post back to the revision saved before its fix. Posts edited since are skipped. Batches can be undone for %s days.', data.undoDays, 'block-doctor' ),
					data.undoDays
				)
			),
			el(
				'table',
				{ className: 'widefat striped' },
				el(
					'thead',
					null,
					el( 'tr', null, el( 'th', null, __( 'Batch', 'block-doctor' ) ), el( 'th', null, __( 'Date', 'block-doctor' ) ), el( 'th', null, __( 'Fixed', 'block-doctor' ) ), el( 'th', null, __( 'Posts', 'block-doctor' ) ), el( 'th', null, '' ) )
				),
				el(
					'tbody',
					null,
					batches.map( ( batch ) =>
						el(
							'tr',
							{ key: batch.id, 'data-batch': batch.id },
							el( 'td', null, '#' + batch.id ),
							el( 'td', null, formatDate( batch.time ) ),
							el( 'td', null, batch.kinds.map( ( kind ) => el( KindBadge, { key: kind, kind } ) ) ),
							el( 'td', null, batch.posts.length ),
							el(
								'td',
								null,
								batch.undone
									? el(
											'span',
											{ className: 'bdr-undone' },
											sprintf(
												/* translators: 1: date, 2: posts restored, 3: posts skipped. */
												__( 'Undone %1$s: %2$s restored, %3$s skipped', 'block-doctor' ),
												formatDate( batch.undone ),
												batch.restored.length,
												Object.keys( batch.skipped ).length
											)
									  )
									: el( Button, { variant: 'secondary', className: 'bdr-undo', disabled: ! batch.posts.length, onClick: () => onUndo( batch.id ) }, __( 'Undo batch', 'block-doctor' ) )
							)
						)
					)
				)
			)
		);
	}

	function App() {
		const [ summary, setSummary ] = useState( data.summary );
		const [ version, setVersion ] = useState( 0 );
		const [ notice, setNotice ] = useState( null );
		const [ preview, setPreview ] = useState( null );
		const reload = async () => {
			try {
				setSummary( await request( 'GET', '/scan' ) );
			} catch ( error ) {
				setNotice( { status: 'error', text: errorMessage( error ) } );
			}
			setVersion( ( n ) => n + 1 );
		};

		async function undo( batchId, skipConfirm ) {
			// eslint-disable-next-line no-alert
			if ( ! skipConfirm && ! window.confirm( sprintf( /* translators: %s: batch number. */ __( 'Undo batch #%s? Every post goes back to its content before the fix.', 'block-doctor' ), batchId ) ) ) {
				return;
			}
			try {
				let result;
				do {
					result = await request( 'POST', '/batches/' + batchId + '/undo' );
				} while ( result.remaining > 0 );
				const skipped = Object.keys( result.batch.skipped );
				let text = sprintf(
					/* translators: 1: batch number, 2: posts restored. */
					_n( 'Batch #%1$s undone: %2$s post restored.', 'Batch #%1$s undone: %2$s posts restored.', result.batch.restored.length, 'block-doctor' ),
					batchId,
					result.batch.restored.length
				);
				if ( skipped.length ) {
					text +=
						' ' +
						sprintf(
							/* translators: %s: list of posts with the reason each was skipped. */
							__( 'Skipped: %s.', 'block-doctor' ),
							skipped.map( ( id ) => '#' + id + ' (' + ( SKIP_REASONS[ result.batch.skipped[ id ] ] || result.batch.skipped[ id ] ) + ')' ).join( ', ' )
						);
				}
				setNotice( { status: skipped.length ? 'warning' : 'success', text } );
			} catch ( error ) {
				setNotice( { status: 'error', text: errorMessage( error ) } );
			}
			reload();
		}

		return el(
			Fragment,
			null,
			notice ? el( Notice, { status: notice.status, className: 'bdr-notice', onRemove: () => setNotice( null ) }, notice.text ) : null,
			el( ScanPanel, { summary, setSummary, setNotice, onFinished: reload } ),
			el( Report, {
				summary,
				version,
				setNotice,
				reload,
				onFix: ( ids, kinds ) => setPreview( { ids, kinds } ),
			} ),
			el( Batches, { version, onUndo: ( id ) => undo( id, false ) } ),
			preview
				? el( Preview, {
						ids: preview.ids,
						kinds: preview.kinds,
						onClose: () => setPreview( null ),
						onDone: ( batchId, undoNow ) => {
							setPreview( null );
							if ( undoNow ) {
								undo( batchId, true );
							} else {
								reload();
							}
						},
				  } )
				: null
		);
	}

	wp.domReady( () => {
		bootBlocks();
		const root = document.getElementById( 'bdr-app' );
		if ( ! root ) {
			return;
		}
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( el( App ) );
		} else {
			wp.element.render( el( App ), root );
		}
	} );
} )( window.wp, window.blockDoctorCore, window.blockDoctorData );
