/* global pressocampusAdmin */
( function () {
	'use strict';

	var cfg = window.pressocampusAdmin || {};

	// Tab switching.
	document.querySelectorAll( '.nav-tab[data-tab]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var tab = this.dataset.tab;
			document.querySelectorAll( '.nav-tab[data-tab]' ).forEach( function ( b ) {
				b.classList.remove( 'nav-tab-active' );
			} );
			document.querySelectorAll( '.pc-tab-panel' ).forEach( function ( p ) {
				p.classList.remove( 'active' );
			} );
			this.classList.add( 'nav-tab-active' );
			var panel = document.getElementById( 'pc-tab-' + tab );
			if ( panel ) {
				panel.classList.add( 'active' );
			}
		} );
	} );

	// Toast.
	var toastTimer;
	window.pcToast = function ( msg, duration ) {
		var el = document.getElementById( 'pc-toast' );
		if ( ! el ) {
			return;
		}
		el.textContent = msg;
		el.style.display = 'block';
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			el.style.display = 'none';
		}, duration || 2500 );
	};

	// Copy to clipboard.
	window.pcCopy = function ( text, btn ) {
		navigator.clipboard.writeText( text ).then( function () {
			pcToast( cfg.i18n.copied );
			if ( btn ) {
				var orig = btn.textContent;
				btn.textContent = cfg.i18n.copiedBtn;
				setTimeout( function () {
					btn.textContent = orig;
				}, 1800 );
			}
		} ).catch( function () {
			pcToast( cfg.i18n.copyFailed );
		} );
	};

	// Share Brain dropdown.
	window.pcToggleDropdown = function ( e ) {
		e.stopPropagation();
		var menu = document.getElementById( 'pc-share-menu' );
		if ( menu ) {
			menu.classList.toggle( 'open' );
		}
	};
	window.pcCloseDropdown = function () {
		var menu = document.getElementById( 'pc-share-menu' );
		if ( menu ) {
			menu.classList.remove( 'open' );
		}
	};
	document.addEventListener( 'click', pcCloseDropdown );

	// Share config snippets.
	window.pcCopyClaudeConfig = function () {
		pcCopy( cfg.claudeConfig, null );
	};
	window.pcCopyCursorConfig = function () {
		pcCopy( cfg.cursorConfig, null );
	};
	window.pcCopyGenericConfig = function () {
		pcCopy( cfg.genericConfig, null );
	};

	// Test connection.
	window.pcTestConnection = function () {
		var btn    = document.getElementById( 'pc-test-btn' );
		var result = document.getElementById( 'pc-test-result' );
		btn.disabled = true;
		btn.textContent = cfg.i18n.testing;
		result.textContent = '';
		result.style.color = '';

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=pressocampus_test_connection&_wpnonce=' + cfg.nonce,
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				btn.disabled = false;
				btn.textContent = cfg.i18n.testConnection;
				if ( data.success ) {
					result.textContent = '\u2713 ' + data.data.message;
					result.style.color = '#00a32a';
				} else {
					result.textContent = '\u2717 ' + ( data.data ? data.data.message : cfg.i18n.unknownError );
					result.style.color = '#d63638';
				}
			} )
			.catch( function ( err ) {
				btn.disabled = false;
				btn.textContent = cfg.i18n.testConnection;
				result.textContent = '\u2717 ' + err.message;
				result.style.color = '#d63638';
			} );
	};

	// Revoke client.
	window.pcRevokeClient = function ( id, name, btn ) {
		if ( ! confirm( cfg.i18n.revokeConfirm + ' "' + name + '"?' ) ) {
			return;
		}
		btn.disabled = true;

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=pressocampus_revoke_client&client_id=' + encodeURIComponent( id ) + '&_wpnonce=' + cfg.nonce,
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					var row = btn.closest( 'tr' );
					if ( row ) {
						row.remove();
					}
					pcToast( cfg.i18n.clientRevoked );
				} else {
					btn.disabled = false;
					pcToast( cfg.i18n.revokeFailed );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				pcToast( cfg.i18n.revokeFailed );
			} );
	};

	// Save settings.
	window.pcSaveSettings = function ( e ) {
		e.preventDefault();
		var form   = document.getElementById( 'pc-settings-form' );
		var result = document.getElementById( 'pc-save-result' );
		var data   = new FormData( form );
		data.append( 'action', 'pressocampus_save_settings' );

		result.textContent = cfg.i18n.saving;
		result.style.color = '#888';

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			body: new URLSearchParams( data ),
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( resp ) {
				if ( resp.success ) {
					result.textContent = '\u2713 ' + cfg.i18n.saved;
					result.style.color = '#00a32a';
				} else {
					result.textContent = '\u2717 ' + cfg.i18n.saveFailed;
					result.style.color = '#d63638';
				}
				setTimeout( function () {
					result.textContent = '';
				}, 3000 );
			} )
			.catch( function () {
				result.textContent = '\u2717 ' + cfg.i18n.saveFailed;
				result.style.color = '#d63638';
			} );
	};
}() );
