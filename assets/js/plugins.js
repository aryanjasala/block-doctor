/**
 * Plugins screen: before Deactivate or Delete runs for a plugin that provides
 * blocks, shows an in-place step with how many posts use those blocks.
 *
 * Block Doctor never blocks anything. The step has Cancel (focused), a button
 * that keeps what the plugin's dynamic blocks show first, and a continue
 * button. When the check fails Continue still works.
 *
 * JavaScript hooks (wp.hooks):
 * - action blockDoctor.deactivationContinue ( plugin, action ): someone chose to carry on after the warning. `action` is deactivate or delete.
 */
( function ( root, factory ) {
	'use strict';

	const api = factory();
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	} else {
		api.boot( root );
	}
} )( typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	/**
	 * Reads a Deactivate or Delete link.
	 *
	 * @param {string} href Link address.
	 * @return {{action: string, plugin: string}|null} The action and the plugin file, or null for any other link.
	 */
	function parseLink( href ) {
		let url;
		try {
			url = new URL( href, 'https://example.com/wp-admin/' );
		} catch ( error ) {
			return null;
		}
		if ( ! /plugins\.php$/.test( url.pathname ) ) {
			return null;
		}
		const action = url.searchParams.get( 'action' );
		if ( action === 'deactivate' ) {
			const plugin = url.searchParams.get( 'plugin' );
			return plugin ? { action: 'deactivate', plugin } : null;
		}
		if ( action === 'delete-selected' ) {
			// The link has checked[0]=plugin/file.php, and a bulk form has checked[]. One plugin only: a bulk delete is not held back.
			const checked = [];
			url.searchParams.forEach( ( value, key ) => {
				if ( /^checked\[\d*\]$/.test( key ) ) {
					checked.push( value );
				}
			} );
			return checked.length === 1 && checked[ 0 ] ? { action: 'delete', plugin: checked[ 0 ] } : null;
		}
		return null;
	}

	/**
	 * What happens to the blocks, for the step. Dynamic blocks have no saved HTML, so nothing can convert them later.
	 *
	 * @param {string} action  `deactivate` or `delete`.
	 * @param {Object} dynamic `blocks` (dynamic block types in use), `posts` (posts that use them), `can_keep`.
	 * @param {Object} i18n    wp.i18n functions.
	 * @return {string} The sentence.
	 */
	function consequence( action, dynamic, i18n ) {
		const { __, _n, sprintf } = i18n || ( typeof window !== 'undefined' && window.wp ? window.wp.i18n : {} );
		if ( dynamic && dynamic.blocks > 0 ) {
			const drawn = sprintf(
				/* translators: 1: number of dynamic block types, 2: number of posts. */
				_n( '%1$s of its block types is dynamic, in %2$s posts: the plugin draws it each time the page loads, so no HTML is saved.', '%1$s of its block types are dynamic, in %2$s posts: the plugin draws them each time the page loads, so no HTML is saved.', dynamic.blocks, 'block-doctor' ),
				dynamic.blocks,
				dynamic.posts
			);
			const after =
				action === 'delete'
					? __( 'Once its files are deleted they show nothing, and Block Doctor cannot convert them afterwards. You would have to install the plugin again to get them back.', 'block-doctor' )
					: __( 'Once it is off they show nothing, and Block Doctor cannot convert them afterwards. Turn the plugin back on to get them back.', 'block-doctor' );
			const keep = dynamic.can_keep ? ' ' + __( 'Keep what they show saves their output as HTML first, with Undo, and refuses blocks that show different things to different visitors.', 'block-doctor' ) : '';
			return drawn + ' ' + after + keep;
		}
		return action === 'delete'
			? __( 'Deleting removes its files, so you would have to install it again to get the blocks back. Until then the editor shows them as unregistered. Block Doctor can fix the posts afterwards, with a preview and Undo.', 'block-doctor' )
			: __( 'If you deactivate it, the editor shows these blocks as unregistered. Turn the plugin back on to get them back, or fix the posts with Block Doctor afterwards, with a preview and Undo.', 'block-doctor' );
	}

	function boot( win ) {
		const data = win.blockDoctorPlugins;
		const wp = win.wp;
		if ( ! data || ! data.plugins || ! wp || ! wp.apiFetch || ! wp.i18n ) {
			return;
		}

		const { __, _n, sprintf } = wp.i18n;
		const doc = win.document;
		let bypass = false;

		const node = ( tag, props, ...children ) => {
			const element = doc.createElement( tag );
			Object.keys( props || {} ).forEach( ( key ) => {
				if ( key === 'text' ) {
					element.textContent = props[ key ];
				} else {
					element.setAttribute( key, props[ key ] );
				}
			} );
			children.forEach( ( child ) => child && element.appendChild( typeof child === 'string' ? doc.createTextNode( child ) : child ) );
			return element;
		};

		const speak = ( text ) => wp.a11y && wp.a11y.speak && wp.a11y.speak( text );

		/** Runs the link as if the step had not been there. */
		const carryOn = ( link, info ) => {
			wp.hooks.doAction( 'blockDoctor.deactivationContinue', info.plugin, info.action );
			bypass = true;
			link.click();
			bypass = false;
		};

		function openStep( link, info ) {
			doc.querySelectorAll( '.block-doctor-warning-row' ).forEach( ( row ) => row.remove() );
			const plugin = link.closest( 'tr' );
			if ( ! plugin ) {
				carryOn( link, info );
				return;
			}

			const id = 'block-doctor-warning-' + info.plugin.replace( /[^a-z0-9]/gi, '-' );
			const cell = node( 'td', { colspan: String( plugin.children.length || 3 ) } );
			const row = node( 'tr', { class: 'block-doctor-warning-row', 'aria-busy': 'true' }, cell );
			const box = node( 'div', { class: 'axle-ui' } );
			cell.appendChild( box );
			plugin.after( row );

			const close = () => {
				row.remove();
				link.focus();
			};

			const checking = node( 'div', { class: 'axle-confirm', role: 'status' }, node( 'p', {}, node( 'span', { class: 'spinner is-active' } ), __( 'Checking where its blocks are used...', 'block-doctor' ) ) );
			box.appendChild( checking );

			/**
			 * Shows the step.
			 *
			 * @param {string}   sentence What is at stake, in bold.
			 * @param {Node[]}   extra    More paragraphs and lists.
			 * @param {Object}   options  `keep` (a warning with dynamic blocks that can be kept), `plain` (no list of posts, just the choice).
			 */
			const show = ( sentence, extra, options ) => {
				const settings = options || {};
				const verb = info.action === 'delete' ? __( 'Delete anyway', 'block-doctor' ) : __( 'Deactivate anyway', 'block-doctor' );
				const group = node( 'div', { class: 'axle-confirm axle-confirm--danger', role: 'group', 'aria-labelledby': id } );
				group.appendChild( node( 'p', { id }, node( 'strong', { text: sentence } ) ) );
				( extra || [] ).forEach( ( part ) => group.appendChild( part ) );
				const actions = node( 'div', { class: 'axle-actions' } );
				const cancel = node( 'button', { type: 'button', class: 'button button-primary block-doctor-cancel', text: __( 'Cancel', 'block-doctor' ) } );
				cancel.addEventListener( 'click', close );
				actions.appendChild( cancel );
				if ( settings.keep ) {
					const keep = node( 'button', {
						type: 'button',
						class: 'button block-doctor-keep',
						text: info.action === 'delete' ? __( 'Keep what they show, then delete', 'block-doctor' ) : __( 'Keep what they show, then deactivate', 'block-doctor' ),
					} );
					keep.addEventListener( 'click', () => keepThenContinue( group, settings.keep ) );
					actions.appendChild( keep );
				}
				const go = node( 'button', { type: 'button', class: 'button axle-button--danger block-doctor-continue', text: settings.goLabel || verb } );
				go.addEventListener( 'click', () => {
					row.remove();
					carryOn( link, info );
				} );
				actions.appendChild( go );
				group.appendChild( actions );
				group.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Escape' ) {
						close();
					}
				} );
				box.replaceChildren( group );
				row.setAttribute( 'aria-busy', 'false' );
				cancel.focus();
				speak( sentence );
			};

			/** Keeps what the plugin's dynamic blocks show, a chunk of posts at a time, then carries on. */
			const keepThenContinue = ( group, summary ) => {
				const provider = 'plugin:' + info.plugin;
				const progress = node( 'p', { class: 'block-doctor-progress', role: 'status' }, node( 'span', { class: 'spinner is-active' } ), __( 'Keeping what the blocks show...', 'block-doctor' ) );
				group.querySelectorAll( 'button' ).forEach( ( button ) => ( button.disabled = true ) );
				group.querySelector( '.axle-actions' ).before( progress );

				const fail = ( error ) => {
					progress.remove();
					const message = error && error.message ? error.message : __( 'Something went wrong.', 'block-doctor' );
					group.querySelector( '.axle-actions' ).before( node( 'p', { class: 'block-doctor-error', role: 'alert', text: message } ) );
					group.querySelectorAll( 'button' ).forEach( ( button ) => ( button.disabled = false ) );
					group.querySelector( '.block-doctor-keep' ).remove();
					group.querySelector( '.block-doctor-cancel' ).focus();
				};

				const run = ( restart ) =>
					wp.apiFetch( { path: '/block-doctor/v1/keep', method: 'POST', data: { provider, restart } } ).then( ( result ) => {
						const text = sprintf(
							/* translators: 1: posts checked so far, 2: all posts. */
							__( 'Keeping what the blocks show: %1$s of %2$s posts checked.', 'block-doctor' ),
							result.done,
							result.total || summary.posts
						);
						progress.lastChild.textContent = text;
						if ( result.status === 'running' ) {
							return run( false );
						}
						return result;
					} );

				run( true )
					.then( ( result ) => finished( result, group ) )
					.catch( fail );
			};

			/** After a keep run: carry on when nothing was refused, else list what stays as it is and let the person choose. */
			const finished = ( result, group ) => {
				const kept = sprintf(
					/* translators: 1: number of blocks, 2: number of posts. */
					_n( 'Kept %1$s block in %2$s posts.', 'Kept %1$s blocks in %2$s posts.', result.kept, 'block-doctor' ),
					result.kept,
					result.kept_posts
				);
				if ( ! result.refused_n && ! result.not_yours ) {
					speak( kept );
					row.remove();
					carryOn( link, info );
					return;
				}

				const extra = [];
				extra.push(
					node(
						'p',
						{},
						result.kept ? kept + ' ' : '',
						result.batch ? node( 'a', { href: data.undoUrl, text: sprintf( /* translators: %s: batch number. */ __( 'Undo batch #%s', 'block-doctor' ), result.batch ) } ) : ''
					)
				);
				if ( result.refused.length ) {
					extra.push( node( 'p', { text: sprintf( /* translators: %s: number of blocks. */ _n( '%s block was left as it is, because it cannot be kept as HTML:', '%s blocks were left as they are, because they cannot be kept as HTML:', result.refused_n, 'block-doctor' ), result.refused_n ) } ) );
					const list = node( 'ul', { class: 'block-doctor-refused' } );
					result.refused.forEach( ( post ) => {
						post.blocks.forEach( ( block ) => {
							list.appendChild( node( 'li', {}, node( 'a', { href: post.edit_link, text: post.title } ), ' ' + ( block.name ? block.name + ': ' : '' ) + block.message ) );
						} );
					} );
					extra.push( list );
				}
				if ( result.not_yours ) {
					extra.push( node( 'p', { text: sprintf( /* translators: %s: number of posts. */ _n( '%s post you cannot edit was not touched.', '%s posts you cannot edit were not touched.', result.not_yours, 'block-doctor' ), result.not_yours ) } ) );
				}
				const sentence = __( 'Some blocks stay dynamic.', 'block-doctor' );
				show( sentence, extra, { goLabel: info.action === 'delete' ? __( 'Delete', 'block-doctor' ) : __( 'Deactivate', 'block-doctor' ) } );
			};

			wp.apiFetch( { path: '/block-doctor/v1/usage/warning?provider=' + encodeURIComponent( 'plugin:' + info.plugin ) } )
				.then( ( response ) => {
					const warning = response && response.warning;
					if ( ! warning || ! warning.posts ) {
						row.remove();
						carryOn( link, info );
						return;
					}

					const extra = [];
					const intro = sprintf(
						/* translators: 1: plugin name, 2: sentence such as "Its 3 block types are used in 212 posts." */
						__( '%1$s provides blocks that posts use. %2$s', 'block-doctor' ),
						warning.name,
						warning.message
					);
					if ( warning.top && warning.top.length ) {
						const list = node( 'ul', { class: 'block-doctor-top-posts' } );
						warning.top.forEach( ( post ) => {
							list.appendChild(
								node(
									'li',
									{},
									node( 'a', { href: post.edit_link, text: post.title || sprintf( /* translators: %d: post ID. */ __( 'Post #%d', 'block-doctor' ), post.id ) } ),
									' ' + sprintf( /* translators: %s: number of uses. */ _n( '(%s use)', '(%s uses)', post.uses, 'block-doctor' ), post.uses )
								)
							);
						} );
						extra.push( node( 'p', { text: __( 'Most affected posts:', 'block-doctor' ) } ), list );
					}
					extra.push(
						node(
							'p',
							{},
							node( 'a', { href: warning.url, class: 'block-doctor-all-posts', text: sprintf( /* translators: %s: number of posts. */ _n( 'See the %s post', 'See all %s posts', warning.posts, 'block-doctor' ), warning.posts ) } )
						)
					);

					const dynamic = warning.dynamic || { blocks: 0, posts: 0, can_keep: false };
					extra.push( node( 'p', { class: 'block-doctor-consequence', text: consequence( info.action, dynamic, wp.i18n ) } ) );
					show( intro, extra, { keep: dynamic.can_keep && data.keep !== false ? dynamic : null } );
				} )
				.catch( () => {
					show( __( 'Block Doctor could not check where this plugin\'s blocks are used.', 'block-doctor' ), [ node( 'p', { text: __( 'Nothing is blocked. You can carry on, or cancel and try again in a moment.', 'block-doctor' ) } ) ] );
				} );
		}

		doc.addEventListener(
			'click',
			( event ) => {
				if ( bypass || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
					return;
				}
				const link = event.target.closest ? event.target.closest( 'a[href]' ) : null;
				const info = link ? parseLink( link.getAttribute( 'href' ) ) : null;
				if ( ! info || ! Object.prototype.hasOwnProperty.call( data.plugins, info.plugin ) ) {
					return;
				}
				event.preventDefault();
				event.stopImmediatePropagation();
				openStep( link, info );
			},
			true
		);
	}

	return { parseLink, consequence, boot };
} );
