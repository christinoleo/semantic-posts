/* SemanticPosts admin settings — vanilla JS (AR-14: no jQuery). */
( function () {
	'use strict';

	if ( typeof window.SemanticPostsAdmin === 'undefined' ) {
		return;
	}
	var cfg = window.SemanticPostsAdmin;

	function byId( id ) {
		return document.getElementById( id );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				return { ok: r.ok && j && j.success, json: j };
			} );
		} );
	}

	function fmtUsd( value ) {
		return ( Math.round( value * 10000 ) / 10000 ).toFixed( 4 );
	}

	function refreshCost() {
		var modelEl = byId( 'semantic-posts-model' );
		var costEl  = byId( 'semantic-posts-cost' );
		if ( ! modelEl || ! costEl ) {
			return;
		}
		costEl.textContent = cfg.i18n.estimating;
		post( cfg.actions.costPreview, { model: modelEl.value } ).then( function ( res ) {
			if ( ! res.ok || ! res.json || ! res.json.data ) {
				costEl.textContent = '—';
				return;
			}
			var d = res.json.data;
			costEl.textContent = '~ $' + fmtUsd( d.estimated_usd ) +
				' · ' + d.posts + ' posts · ' + d.total_tokens + ' tokens (' + d.model + ')';
		} );
	}

	function validateApiKey() {
		var keyEl    = byId( 'semantic-posts-api-key' );
		var statusEl = byId( 'semantic-posts-key-status' );
		if ( ! keyEl || ! statusEl ) {
			return;
		}
		var key = keyEl.value.trim();
		if ( ! key ) {
			statusEl.textContent = '';
			return;
		}
		statusEl.textContent = cfg.i18n.validating;
		statusEl.className   = 'semantic-posts-key-status is-pending';
		post( cfg.actions.validateKey, { api_key: key } ).then( function ( res ) {
			if ( res.ok ) {
				statusEl.textContent = cfg.i18n.keySaved + ' (' + ( res.json.data.masked || '' ) + ')';
				statusEl.className   = 'semantic-posts-key-status is-success';
				keyEl.value          = '';
			} else {
				var msg = ( res.json && res.json.data && res.json.data.message ) || 'Error';
				statusEl.textContent = msg;
				statusEl.className   = 'semantic-posts-key-status is-error';
			}
		} );
	}

	function renderProgress( progress ) {
		var label = byId( 'semantic-posts-progress-label' );
		var fill  = document.querySelector( '.semantic-posts-progress-fill' );
		if ( ! label || ! fill ) {
			return;
		}
		var total = progress.indexed_count + progress.pending_count;
		var pct   = total > 0 ? Math.round( ( progress.indexed_count / total ) * 100 ) : 0;
		fill.style.width = pct + '%';
		if ( progress.phase === 'idle' && progress.completed_at ) {
			label.textContent = cfg.i18n.completeLabel;
		} else if ( progress.phase === 'idle' ) {
			label.textContent = cfg.i18n.idleLabel;
		} else {
			label.textContent = cfg.i18n.progressLabel
				.replace( '%1$d', progress.indexed_count )
				.replace( '%2$d', total );
		}
	}

	var pollHandle = null;
	function pollProgress() {
		post( cfg.actions.progress, {} ).then( function ( res ) {
			if ( res.ok && res.json && res.json.data ) {
				renderProgress( res.json.data );
				if ( res.json.data.phase === 'idle' && pollHandle ) {
					clearInterval( pollHandle );
					pollHandle = null;
				}
			}
		} );
	}

	function startPolling() {
		if ( pollHandle ) {
			return;
		}
		pollProgress();
		pollHandle = setInterval( pollProgress, 5000 );
	}

	function startIndexing() {
		var status = byId( 'semantic-posts-bulk-status' );
		if ( status ) {
			status.textContent = cfg.i18n.startingLabel;
		}
		post( cfg.actions.startIndexing, {} ).then( function ( res ) {
			if ( ! res.ok ) {
				var msg = ( res.json && res.json.data && res.json.data.message ) || 'Error';
				if ( status ) {
					status.textContent = msg;
				}
				return;
			}
			if ( status ) {
				status.textContent = ( res.json.data && res.json.data.message ) || '';
			}
			if ( res.json.data && res.json.data.progress ) {
				renderProgress( res.json.data.progress );
			}
			startPolling();
		} );
	}

	function wipeAndReindex() {
		if ( ! window.confirm( cfg.i18n.confirmWipe.replace( '%s', '?' ) ) ) {
			return;
		}
		var status = byId( 'semantic-posts-bulk-status' );
		if ( status ) {
			status.textContent = cfg.i18n.startingLabel;
		}
		post( cfg.actions.wipeReindex, {} ).then( function ( res ) {
			if ( ! res.ok ) {
				var msg = ( res.json && res.json.data && res.json.data.message ) || 'Error';
				if ( status ) {
					status.textContent = msg;
				}
				return;
			}
			if ( status ) {
				status.textContent = ( res.json.data && res.json.data.message ) || '';
			}
			if ( res.json.data && res.json.data.progress ) {
				renderProgress( res.json.data.progress );
			}
			startPolling();
		} );
	}

	function setupQualityToggle() {
		var checkbox = byId( 'semantic-posts-quality-bounded' );
		if ( ! checkbox ) {
			return;
		}
		var fields = document.querySelector( '.semantic-posts-quality-fields' );
		checkbox.addEventListener( 'change', function () {
			if ( ! fields ) {
				return;
			}
			if ( checkbox.checked ) {
				fields.removeAttribute( 'hidden' );
			} else {
				fields.setAttribute( 'hidden', '' );
			}
		} );
	}

	function setupNoticeDismiss() {
		document.querySelectorAll( '[data-sp-notice="floor"]' ).forEach( function ( notice ) {
			notice.addEventListener( 'click', function ( ev ) {
				if ( ! ev.target.classList || ! ev.target.classList.contains( 'notice-dismiss' ) ) {
					return;
				}
				post( cfg.actions.dismissFloor, {} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var modelEl    = byId( 'semantic-posts-model' );
		var countEl    = byId( 'semantic-posts-count' );
		var validateBn = byId( 'semantic-posts-validate-key' );
		var startBn    = byId( 'semantic-posts-start' );
		var wipeBn     = byId( 'semantic-posts-wipe-reindex' );

		if ( modelEl ) {
			modelEl.addEventListener( 'change', refreshCost );
		}
		if ( countEl ) {
			countEl.addEventListener( 'change', refreshCost );
		}
		if ( validateBn ) {
			validateBn.addEventListener( 'click', validateApiKey );
		}
		if ( startBn ) {
			startBn.addEventListener( 'click', startIndexing );
		}
		if ( wipeBn ) {
			wipeBn.addEventListener( 'click', wipeAndReindex );
		}

		setupQualityToggle();
		setupNoticeDismiss();
		refreshCost();
		pollProgress();
	} );
}() );
