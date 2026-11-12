/**
 * Plugins screen: before Deactivate or Delete runs for a plugin that provides
 * blocks, shows an in-place step with how many posts use those blocks.
 *
 * Block Doctor never blocks anything. The step has Continue and Cancel, and
 * when the check fails Continue still works.
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

			const show = ( sentence, extra, tone ) => {
				const verb = info.action === 'delete' ? __( 'Delete anyway', 'block-doctor' ) : __( 'Deactivate anyway', 'block-doctor' );
				const group = node( 'div', { class: 'axle-confirm' + ( tone ? ' axle-confirm--' + tone : '' ), role: 'group', 'aria-labelledby': id, tabindex: '-1' } );
				group.appendChild( node( 'p', { id }, node( 'strong', { text: sentence } ) ) );
				( extra || [] ).forEach( ( part ) => group.appendChild( part ) );
				const actions = node( 'div', { class: 'axle-actions' } );
				const go = node( 'button', { type: 'button', class: 'button button-primary block-doctor-continue', text: verb } );
				const cancel = node( 'button', { type: 'button', class: 'button block-doctor-cancel', text: __( 'Cancel', 'block-doctor' ) } );
				go.addEventListener( 'click', () => {
					row.remove();
					carryOn( link, info );
				} );
				cancel.addEventListener( 'click', close );
				actions.appendChild( go );
				actions.appendChild( cancel );
				group.appendChild( actions );
				group.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Escape' ) {
						close();
					}
				} );
				box.replaceChildren( group );
				row.setAttribute( 'aria-busy', 'false' );
				group.focus();
				speak( sentence );
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
						),
						node(
							'p',
							{
								text:
									info.action === 'delete'
										? __( 'Deleting removes its files, so you would have to install it again to get the blocks back. Until then the editor shows them as unregistered. Block Doctor can fix the posts afterwards, with a preview and Undo.', 'block-doctor' )
										: __( 'If you deactivate it, the editor shows these blocks as unregistered. Turn the plugin back on to get them back, or fix the posts with Block Doctor afterwards, with a preview and Undo.', 'block-doctor' ),
							}
						)
					);
					show( intro, extra );
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

	return { parseLink, boot };
} );
