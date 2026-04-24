/**
 * AcrossAI MCP Manager — Admin JavaScript
 *
 * Handles password generation, config loading, and clipboard actions on
 * the per-server edit page. Tab navigation is URL-based (PHP renders
 * the active tab server-side), so no JS tab switching is needed here.
 */
( function () {
	'use strict';

	/**
	 * Data injected by wp_localize_script:
	 *   acrossaiMcpManagerData.nonce       — wp_rest nonce
	 *   acrossaiMcpManagerData.rest_url    — REST API base URL (trailing slash)
	 *   acrossaiMcpManagerData.server_id   — DB ID of the server being edited (0 on list page)
	 *   acrossaiMcpManagerData.clients     — array of client ID strings
	 */

	const MCPAdmin = {

		/**
		 * Run on DOMContentLoaded. Only loads configs and passwords when a
		 * specific server is being edited (server_id > 0).
		 */
		init() {
			this.setupClipboardButtons();
			this.setupGeneratePassword();

			const serverId = parseInt( acrossaiMcpManagerData.server_id, 10 ) || 0;
			if ( serverId > 0 ) {
				this.loadExistingPasswords();
				this.loadClientConfigurations();
			}
		},

		// ---------------------------------------------------------------------
		// REST helpers
		// ---------------------------------------------------------------------

		/**
		 * Build a full REST URL, appending the nonce as a query param.
		 *
		 * @param {string} path  Path relative to the REST base URL.
		 * @param {Object} extra Additional query params.
		 * @return {string}
		 */
		restUrl( path, extra = {} ) {
			const base = acrossaiMcpManagerData.rest_url.replace( /\/$/, '' );
			const url  = new URL( base + '/' + path, window.location.origin );
			url.searchParams.set( '_wpnonce', acrossaiMcpManagerData.nonce );
			Object.entries( extra ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
			return url.toString();
		},

		/**
		 * Simple GET fetch that returns a JSON-parsed response promise.
		 *
		 * @param {string} url
		 * @return {Promise<Object>}
		 */
		get( url ) {
			return fetch( url, { method: 'GET', credentials: 'same-origin' } )
				.then( r => r.json() );
		},

		/**
		 * Simple POST fetch using URL-encoded body.
		 *
		 * @param {string} url
		 * @param {Object} params Key/value pairs to send as form data.
		 * @return {Promise<Object>}
		 */
		post( url, params ) {
			const body = new URLSearchParams( params );
			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} ).then( r => r.json() );
		},

		// ---------------------------------------------------------------------
		// Init: load existing passwords
		// ---------------------------------------------------------------------

		/**
		 * Mark "Generate" buttons for clients that already have a password.
		 * Called only on the edit page (server_id > 0).
		 */
		loadExistingPasswords() {
			this.get( this.restUrl( 'list-app-passwords' ) )
				.then( data => {
					if ( ! data.success || ! Array.isArray( data.passwords ) ) {
						return;
					}
					data.passwords.forEach( pwd => {
						const name = pwd.name || '';
						const client = this.clientIdFromPasswordName( name );
						if ( client ) {
							this.markPasswordExists( client );
						}
					} );
				} )
				.catch( err => console.log( 'AcrossAI MCP: could not load existing passwords', err ) );
		},

		/**
		 * Map a password name back to a client ID.
		 * E.g. "AcrossAI MCP Manager - Claude (Default MCP Server)" → "claude"
		 *
		 * @param {string} name Application Password name.
		 * @return {string|null} Client ID or null.
		 */
		clientIdFromPasswordName( name ) {
			const map = {
				'VS Code'            : 'vscode',
				'Claude Code'        : 'claude-code',
				'Claude'             : 'claude',
				'GitHub Copilot'     : 'copilot',
				'GitHub Codex'       : 'codex',
				'OpenAI ChatGPT Codex': 'chatgpt',
				'Custom Client'      : 'custom',
			};
			for ( const [ label, id ] of Object.entries( map ) ) {
				if ( name.includes( label ) ) {
					return id;
				}
			}
			return null;
		},

		// ---------------------------------------------------------------------
		// Init: load client configs
		// ---------------------------------------------------------------------

		/**
		 * Load JSON configurations for every client in parallel.
		 * Client list comes from PHP via acrossaiMcpManagerData.clients.
		 */
		loadClientConfigurations() {
			const clients  = acrossaiMcpManagerData.clients || [];
			const serverId = parseInt( acrossaiMcpManagerData.server_id, 10 ) || 0;
			clients.forEach( clientId => this.loadClientConfiguration( clientId, serverId ) );
		},

		/**
		 * Fetch and render the config block for one client.
		 *
		 * @param {string} clientId
		 * @param {number} serverId
		 */
		loadClientConfiguration( clientId, serverId ) {
			const url = this.restUrl( 'get-client-config/' + clientId, { server_id: serverId } );

			this.get( url )
				.then( data => {
					if ( ! data.success || ! data.full_config ) {
						return;
					}

					const configJson = document.getElementById( 'config_json_' + clientId );
					if ( configJson ) {
						configJson.value = JSON.stringify( data.full_config, null, 2 );
					}

					const configPath = document.getElementById( 'config_path_' + clientId );
					if ( configPath ) {
						configPath.textContent = data.config_file_path;
					}

					const topLevelKey = document.getElementById( 'top_level_key_' + clientId );
					if ( topLevelKey ) {
						topLevelKey.textContent = '"' + data.top_level_key + '"';
					}
				} )
				.catch( err => console.log( 'AcrossAI MCP: could not load config for ' + clientId, err ) );
		},

		// ---------------------------------------------------------------------
		// Password generation
		// ---------------------------------------------------------------------

		/**
		 * Wire up all "Generate New Application Password" buttons.
		 */
		setupGeneratePassword() {
			document.querySelectorAll( '.generate-app-password' ).forEach( button => {
				button.addEventListener( 'click', e => {
					e.preventDefault();
					this.generatePassword(
						button.dataset.client,
						parseInt( button.dataset.server, 10 ) || 0
					);
				} );
			} );
		},

		/**
		 * Generate a new Application Password for the given client + server.
		 *
		 * On success the password is injected into the config JSON textarea
		 * so the user can copy a complete, ready-to-paste config block.
		 *
		 * @param {string} clientId
		 * @param {number} serverId
		 */
		generatePassword( clientId, serverId ) {
			const button = document.querySelector(
				`.generate-app-password[data-client="${ clientId }"]`
			);
			if ( button ) {
				button.disabled    = true;
				button.textContent = acrossaiMcpManagerData.generating || 'Generating…';
			}

			this.post( this.restUrl( 'generate-app-password' ), {
				client   : clientId,
				server_id: serverId,
			} )
				.then( result => {
					if ( result.success && result.password ) {
						this.updateConfig( clientId, result.password );
						this.markPasswordExists( clientId );
						this.showNotice( clientId, result.message, 'success' );
					} else {
						const msg = ( result.message || result.data || 'Failed to generate password.' );
						this.showNotice( clientId, msg, 'error' );
					}
				} )
				.catch( err => {
					this.showNotice( clientId, 'An error occurred: ' + err.message, 'error' );
				} )
				.finally( () => {
					if ( button ) {
						button.disabled = false;
					}
				} );
		},

		/**
		 * Inject the real password into the config JSON textarea.
		 *
		 * Replaces the WP_API_PASSWORD placeholder in the nested structure
		 * regardless of which top-level key (mcpServers / servers) is used.
		 *
		 * @param {string} clientId
		 * @param {string} password
		 */
		updateConfig( clientId, password ) {
			const configField = document.getElementById( 'config_json_' + clientId );
			if ( ! configField || ! configField.value ) {
				return;
			}
			try {
				const config = JSON.parse( configField.value );

				// Walk every top-level key to find the server block.
				Object.values( config ).forEach( servers => {
					if ( servers && typeof servers === 'object' ) {
						Object.values( servers ).forEach( serverBlock => {
							if ( serverBlock && serverBlock.env ) {
								serverBlock.env.WP_API_PASSWORD = password;
							}
						} );
					}
				} );

				configField.value = JSON.stringify( config, null, 2 );
			} catch ( e ) {
				console.error( 'AcrossAI MCP: could not update config JSON', e );
			}
		},

		/**
		 * Update the "Generate" button label to signal that a password exists.
		 *
		 * @param {string} clientId
		 */
		markPasswordExists( clientId ) {
			const button = document.querySelector(
				`.generate-app-password[data-client="${ clientId }"]`
			);
			if ( button ) {
				button.textContent = 'Regenerate Application Password';
				button.classList.add( 'has-password' );
			}
		},

		// ---------------------------------------------------------------------
		// Notices
		// ---------------------------------------------------------------------

		/**
		 * Display an inline success or error notice below the password button.
		 *
		 * Reuses the existing WP notice markup so it inherits admin styles.
		 * The notice auto-removes after 5 seconds.
		 *
		 * @param {string} clientId
		 * @param {string} message
		 * @param {'success'|'error'} type
		 */
		showNotice( clientId, message, type ) {
			// Remove any previous notice for this client.
			const existingId = 'acrossai-notice-' + clientId;
			const existing   = document.getElementById( existingId );
			if ( existing ) {
				existing.remove();
			}

			const notice = document.createElement( 'div' );
			notice.id        = existingId;
			notice.className = `notice notice-${ type } inline is-dismissible`;
			notice.style.marginTop = '10px';
			notice.innerHTML = `<p>${ message }</p>`;

			const button = document.querySelector(
				`.generate-app-password[data-client="${ clientId }"]`
			);
			if ( button && button.parentNode ) {
				button.parentNode.insertBefore( notice, button.nextSibling );
			}

			setTimeout( () => notice.remove(), 5000 );
		},

		// ---------------------------------------------------------------------
		// Clipboard
		// ---------------------------------------------------------------------

		/**
		 * Wire up all "Copy …" buttons.
		 * Each button must have a data-field attribute pointing to a textarea ID.
		 */
		setupClipboardButtons() {
			document.querySelectorAll( '.copy-to-clipboard' ).forEach( button => {
				button.addEventListener( 'click', e => {
					e.preventDefault();
					const target = document.getElementById( button.dataset.field );
					if ( ! target || ! target.value ) {
						return;
					}
					navigator.clipboard.writeText( target.value ).then( () => {
						const original = button.textContent;
						button.textContent = '✓ Copied!';
						setTimeout( () => { button.textContent = original; }, 2000 );
					} );
				} );
			} );
		},
	};

	// Boot when the DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => MCPAdmin.init() );
	} else {
		MCPAdmin.init();
	}
} )();
