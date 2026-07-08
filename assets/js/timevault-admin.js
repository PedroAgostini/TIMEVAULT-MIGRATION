/*
 * Timevault — admin dashboard.
 * Vanilla JS, no build step, no external requests (privacy by design).
 * User-supplied strings are always set via textContent (never innerHTML).
 */
( function () {
	'use strict';

	var cfg = window.TimevaultConfig || {};
	var app = document.getElementById( 'timevault-app' );
	if ( ! app ) {
		return;
	}

	var state = {
		overview: null,
		backups: [],
		restores: [],
		filterType: 'all',
		loading: true,
		polling: null,
	};

	/* ── DOM helper ──────────────────────────────────────────── */
	function h( tag, attrs, children ) {
		var el = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) {
				el.className = attrs[ k ];
			} else if ( k === 'text' ) {
				el.textContent = attrs[ k ];
			} else if ( k === 'html' ) {
				el.innerHTML = attrs[ k ]; // Only ever used with our own trusted SVG markup.
			} else if ( k.indexOf( 'on' ) === 0 ) {
				el.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( k === 'aria' ) {
				Object.keys( attrs[ k ] ).forEach( function ( a ) {
					el.setAttribute( 'aria-' + a, attrs[ k ][ a ] );
				} );
			} else if ( attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				el.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			el.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return el;
	}

	var ICONS = {
		vault: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="12" cy="12" r="3.2"/><path d="M12 8.8V6.5M12 17.5v-2.3M15.2 12h2.3M6.5 12h2.3"/></svg>',
		shield: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>',
		empty: '<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="12" cy="12" r="3.5"/><path d="M12 8V6M12 18v-2M16 12h2M6 12h2"/></svg>',
	};

	/* ── REST ────────────────────────────────────────────────── */
	function api( path, method, body ) {
		return fetch( cfg.root + path, {
			method: method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || 'HTTP ' + res.status );
				}
				return data;
			} );
		} );
	}

	/* ── Formatting ──────────────────────────────────────────── */
	function fmtBytes( n ) {
		n = Number( n ) || 0;
		if ( n === 0 ) {
			return '0 B';
		}
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = Math.floor( Math.log( n ) / Math.log( 1024 ) );
		return ( n / Math.pow( 1024, i ) ).toFixed( i ? 1 : 0 ) + ' ' + u[ i ];
	}

	function fmtDate( iso ) {
		if ( ! iso ) {
			return '—';
		}
		var d = new Date( iso.replace( ' ', 'T' ) + ( /Z|[+-]\d\d:?\d\d$/.test( iso ) ? '' : 'Z' ) );
		if ( isNaN( d.getTime() ) ) {
			return iso;
		}
		return d.toLocaleString( 'pt-BR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } );
	}

	function statusBadge( status ) {
		var map = {
			completed: [ 'ok', '✓ íntegro' ],
			pending: [ 'info', '• na fila' ],
			running: [ 'info', '• em andamento' ],
			failed: [ 'danger', '✕ falhou' ],
			expired: [ 'dest', '· expirado' ],
		};
		var m = map[ status ] || [ 'dest', status ];
		return h( 'span', { class: 'tv-badge tv-badge--' + m[ 0 ] }, [ m[ 1 ] ] );
	}

	/* ── Toasts ──────────────────────────────────────────────── */
	function toast( kind, title, msg ) {
		var host = document.getElementById( 'tv-toasts' );
		if ( ! host ) {
			host = h( 'div', { class: 'tv-toasts', id: 'tv-toasts', aria: { live: 'polite' } }, [] );
			document.body.appendChild( host );
		}
		var t = h( 'div', { class: 'tv-toast tv-glass tv-toast--' + kind }, [
			h( 'div', { class: 'tv-toast__title', text: title } ),
			msg ? h( 'div', { text: msg } ) : null,
		] );
		host.appendChild( t );
		if ( kind !== 'error' ) {
			setTimeout( function () {
				t.remove();
			}, 5000 );
		} else {
			t.appendChild( h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', style: 'margin-top:8px', text: 'Fechar', onclick: function () {
				t.remove();
			} }, [] ) );
		}
	}

	/* ── Data load ───────────────────────────────────────────── */
	function load() {
		return Promise.all( [
			api( '/overview' ),
			api( '/backups?per_page=50' ),
			api( '/restores' ),
		] ).then( function ( r ) {
			state.overview = r[ 0 ];
			state.backups = r[ 1 ];
			state.restores = r[ 2 ];
			state.loading = false;
			render();
			managePolling();
		} ).catch( function ( e ) {
			state.loading = false;
			app.innerHTML = '';
			app.appendChild( h( 'div', { class: 'tv-notice', text: 'Não foi possível carregar o Timevault: ' + e.message } ) );
		} );
	}

	function hasActiveJobs() {
		var b = state.backups.some( function ( x ) {
			return x.status === 'pending' || x.status === 'running';
		} );
		var r = state.restores.some( function ( x ) {
			return x.status === 'pending' || x.status === 'running';
		} );
		return b || r;
	}

	function managePolling() {
		if ( hasActiveJobs() && ! state.polling ) {
			state.polling = setInterval( function () {
				api( '/overview' ).then( function ( o ) {
					state.overview = o;
				} );
				Promise.all( [ api( '/backups?per_page=50' ), api( '/restores' ) ] ).then( function ( r ) {
					state.backups = r[ 0 ];
					state.restores = r[ 1 ];
					render();
					if ( ! hasActiveJobs() ) {
						clearInterval( state.polling );
						state.polling = null;
					}
				} );
			}, 3000 );
		}
	}

	/* ── Render ──────────────────────────────────────────────── */
	function render() {
		app.innerHTML = '';
		app.appendChild( header() );

		var ov = state.overview || {};
		var health = ov.health || {};
		if ( ! health.encryption_configured ) {
			app.appendChild( h( 'div', { class: 'tv-notice' }, [
				'A chave de criptografia não está configurada. Defina a constante ',
				h( 'code', { text: cfg.encryptConst || 'TIMEVAULT_ENCRYPTION_KEY' } ),
				' no wp-config.php antes de criar backups — a chave nunca fica no banco.',
			] ) );
		}

		app.appendChild( cards() );
		app.appendChild( activeJobsBanner() );

		var cols = h( 'div', { class: 'tv-columns' }, [ spinePanel(), historyPanel() ] );
		app.appendChild( cols );
	}

	function header() {
		return h( 'div', { class: 'tv-header' }, [
			cfg.logo ? h( 'img', { class: 'tv-header__logo', src: cfg.logo, alt: '' } ) : h( 'span', { class: 'tv-header__logo', html: ICONS.vault } ),
			h( 'div', { class: 'tv-header__titles' }, [
				h( 'h1', { text: 'Timevault' } ),
				h( 'p', { text: 'Backup, exportação e migração — preservação do estado do site.' } ),
			] ),
			h( 'div', { class: 'tv-header__actions' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost', text: 'Só banco de dados', onclick: function () {
					createBackup( 'db' );
				} }, [] ),
				h( 'button', { class: 'tv-btn tv-btn--primary', text: 'Criar backup completo', onclick: function () {
					createBackup( 'full' );
				} }, [] ),
			] ),
		] );
	}

	function card( label, hero, unit, meta ) {
		return h( 'div', { class: 'tv-card tv-glass' }, [
			h( 'div', { class: 'tv-card__label', text: label } ),
			h( 'div', { class: 'tv-card__hero' }, [ h( 'span', { text: String( hero ) } ), unit ? h( 'span', { class: 'tv-unit', text: unit } ) : null ] ),
			meta ? h( 'div', { class: 'tv-card__meta' }, meta ) : null,
		] );
	}

	function cards() {
		var ov = state.overview || {};
		var last = ov.last_backup;
		var health = ov.health || {};

		var lastMeta = last
			? [ h( 'span', { class: 'tv-data', text: fmtDate( last.created_at ) } ) ]
			: [ h( 'span', { text: 'nenhum ainda' } ) ];

		var healthItems = [
			[ health.encryption_configured, 'Criptografia' ],
			[ health.queue_available, 'Fila' ],
			[ health.backup_dir_protected, 'Diretório' ],
		].map( function ( it ) {
			return h( 'span', { class: 'tv-badge tv-badge--' + ( it[ 0 ] ? 'ok' : 'warn' ), text: ( it[ 0 ] ? '✓ ' : '⚠ ' ) + it[ 1 ] } );
		} );

		return h( 'div', { class: 'tv-cards' }, [
			card( 'Último backup', last ? fmtBytes( last.size_bytes ) : '—', null, lastMeta ),
			card( 'Backups guardados', ov.backups_completed || 0, null, [ h( 'span', { text: 'concluídos e íntegros' } ) ] ),
			card( 'Espaço usado', fmtBytes( ov.total_size_bytes || 0 ).split( ' ' )[ 0 ], fmtBytes( ov.total_size_bytes || 0 ).split( ' ' )[ 1 ], [
				h( 'span', { text: ov.next_maintenance ? 'próxima limpeza: ' : 'retenção desligada' } ),
				ov.next_maintenance ? h( 'span', { class: 'tv-data', text: fmtDate( ov.next_maintenance ) } ) : null,
			] ),
			h( 'div', { class: 'tv-card tv-glass' }, [
				h( 'div', { class: 'tv-card__label', text: 'Saúde do ambiente' } ),
				h( 'div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin-top:6px' }, healthItems ),
			] ),
		] );
	}

	function activeJobsBanner() {
		var running = state.backups.filter( function ( b ) {
			return b.status === 'pending' || b.status === 'running';
		} );
		var runningR = state.restores.filter( function ( r ) {
			return r.status === 'pending' || r.status === 'running';
		} );
		if ( ! running.length && ! runningR.length ) {
			return document.createComment( 'no-jobs' );
		}

		var rows = [];
		running.forEach( function ( b ) {
			rows.push( jobRow( 'Backup ' + b.type, b.status, null ) );
		} );
		runningR.forEach( function ( r ) {
			rows.push( jobRow( 'Restauração', r.status, r.step ) );
		} );

		return h( 'div', { class: 'tv-panel tv-glass tv-glass--active', style: 'margin-bottom:32px' }, [
			h( 'div', { class: 'tv-eyebrow', text: 'Em andamento' } ),
			h( 'div', { style: 'margin-top:12px;display:flex;flex-direction:column;gap:16px' }, rows ),
		] );
	}

	function jobRow( label, status, step ) {
		var stepLabels = {
			safety_backup: 'criando backup de segurança',
			validate: 'validando pacote',
			extract: 'extraindo',
			restore_db: 'restaurando banco',
			restore_files: 'restaurando arquivos',
			finalize: 'finalizando',
			dump_db: 'exportando banco',
			package: 'empacotando',
		};
		var caption = step ? stepLabels[ step ] || step : ( status === 'pending' ? 'na fila' : 'processando' );
		return h( 'div', {}, [
			h( 'div', { style: 'display:flex;justify-content:space-between;align-items:center' }, [
				h( 'span', { style: 'color:var(--tv-text-strong);font-weight:600', text: label } ),
				h( 'span', { class: 'tv-data', style: 'color:var(--tv-text-muted)', text: caption } ),
			] ),
			h( 'div', { class: 'tv-progress tv-progress--indeterminate', aria: { label: caption } }, [ h( 'div', { class: 'tv-progress__fill' } ) ] ),
		] );
	}

	/* ── Temporal Spine ──────────────────────────────────────── */
	function spinePanel() {
		var done = state.backups.filter( function ( b ) {
			return b.status === 'completed';
		} );

		var body;
		if ( ! done.length ) {
			body = emptyState();
		} else {
			body = h( 'ol', { class: 'tv-spine' }, done.slice( 0, 8 ).map( function ( b, i ) {
				return spineItem( b, i === 0 );
			} ) );
		}

		return h( 'section', { class: 'tv-panel tv-glass', 'aria-label': 'Linha do tempo de backups' }, [
			h( 'div', { class: 'tv-panel__head' }, [ h( 'h2', { text: 'Espinha temporal' } ) ] ),
			body,
		] );
	}

	function spineItem( b, isNow ) {
		var cls = 'tv-spine__item' + ( isNow ? ' tv-spine__item--now' : '' );
		return h( 'li', { class: cls }, [
			h( 'span', { class: 'tv-spine__node', aria: { hidden: 'true' } } ),
			h( 'div', { class: 'tv-spine__date', text: fmtDate( b.created_at ) } ),
			h( 'div', { class: 'tv-spine__facts' }, [
				h( 'span', { class: 'tv-data', text: fmtBytes( b.size_bytes ) } ),
				h( 'span', { class: 'tv-badge tv-badge--dest', text: b.storage } ),
				b.is_encrypted ? h( 'span', { class: 'tv-badge tv-badge--dest', text: '🔒 cifrado' } ) : null,
				statusBadge( b.status ),
			] ),
			h( 'div', { class: 'tv-spine__actions' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: 'Baixar', onclick: function () {
					downloadBackup( b.uuid );
				} }, [] ),
				h( 'button', { class: 'tv-btn tv-btn--danger tv-btn--sm', text: 'Restaurar', onclick: function () {
					openRestore( b );
				} }, [] ),
			] ),
		] );
	}

	function emptyState() {
		return h( 'div', { class: 'tv-empty' }, [
			h( 'div', { class: 'tv-empty__icon', html: ICONS.empty } ),
			h( 'h3', { text: 'Nenhum backup ainda.' } ),
			h( 'p', { text: 'Crie o primeiro para preservar o estado atual do site — banco de dados e arquivos, cifrados em repouso.' } ),
			h( 'button', { class: 'tv-btn tv-btn--primary', text: 'Criar backup agora', onclick: function () {
				createBackup( 'full' );
			} }, [] ),
		] );
	}

	/* ── History ─────────────────────────────────────────────── */
	function historyPanel() {
		var types = [ [ 'all', 'Todos' ], [ 'full', 'Completo' ], [ 'db', 'Banco' ], [ 'export', 'Export' ] ];
		var filters = h( 'div', { class: 'tv-filters', role: 'group', 'aria-label': 'Filtrar por tipo' }, types.map( function ( t ) {
			return h( 'button', {
				class: 'tv-chip',
				aria: { pressed: String( state.filterType === t[ 0 ] ) },
				text: t[ 1 ],
				onclick: function () {
					state.filterType = t[ 0 ];
					render();
				},
			}, [] );
		} ) );

		var rows = state.backups.filter( function ( b ) {
			return state.filterType === 'all' || b.type === state.filterType;
		} );

		var table;
		if ( ! rows.length ) {
			table = h( 'p', { style: 'color:var(--tv-text-muted);padding:16px 4px', text: 'Nenhum backup neste filtro.' } );
		} else {
			table = h( 'div', { style: 'overflow-x:auto' }, [
				h( 'table', { class: 'tv-table' }, [
					h( 'thead', {}, [ h( 'tr', {}, [
						h( 'th', { text: 'Data' } ),
						h( 'th', { text: 'Tipo' } ),
						h( 'th', { text: 'Tamanho', class: 'tv-num' } ),
						h( 'th', { text: 'Destino' } ),
						h( 'th', { text: 'Status' } ),
					] ) ] ),
					h( 'tbody', {}, rows.map( function ( b ) {
						return h( 'tr', {}, [
							h( 'td', { class: 'tv-data', text: fmtDate( b.created_at ) } ),
							h( 'td', { text: b.type } ),
							h( 'td', { class: 'tv-data tv-num', text: b.size_bytes ? fmtBytes( b.size_bytes ) : '—' } ),
							h( 'td', {}, [ h( 'span', { class: 'tv-badge tv-badge--dest', text: b.storage } ) ] ),
							h( 'td', {}, [ b.error ? h( 'span', { class: 'tv-badge tv-badge--danger', title: b.error, text: '✕ falhou' } ) : statusBadge( b.status ) ] ),
						] );
					} ) ),
				] ),
			] );
		}

		return h( 'section', { class: 'tv-panel tv-glass', 'aria-label': 'Histórico de backups' }, [
			h( 'div', { class: 'tv-panel__head' }, [ h( 'h2', { text: 'Histórico' } ) ] ),
			filters,
			table,
		] );
	}

	/* ── Actions ─────────────────────────────────────────────── */
	function createBackup( type ) {
		api( '/backups', 'POST', { type: type, files_scope: 'wp-content', storage: 'local' } ).then( function ( r ) {
			toast( 'ok', 'Backup agendado', 'O ' + ( type === 'db' ? 'backup do banco' : 'backup completo' ) + ' entrou na fila.' );
			load();
		} ).catch( function ( e ) {
			toast( 'error', 'Não foi possível criar o backup', e.message );
		} );
	}

	function downloadBackup( uuid ) {
		api( '/backups/' + uuid + '/download-token', 'POST' ).then( function ( r ) {
			window.location.assign( r.url );
		} ).catch( function ( e ) {
			toast( 'error', 'Download indisponível', e.message );
		} );
	}

	/* ── Restore double confirmation ─────────────────────────── */
	function openRestore( backup ) {
		api( '/restore/prepare', 'POST', { backup_uuid: backup.uuid } ).then( function ( prep ) {
			showRestoreModal( backup, prep );
		} ).catch( function ( e ) {
			toast( 'error', 'Não foi possível preparar a restauração', e.message );
		} );
	}

	function showRestoreModal( backup, prep ) {
		var phrase = prep.confirm_phrase || 'RESTORE';
		var input, filesCb, confirmBtn;

		function validate() {
			confirmBtn.disabled = input.value.trim() !== phrase;
		}

		input = h( 'input', { class: 'tv-input', type: 'text', autocomplete: 'off', 'aria-label': 'Frase de confirmação', oninput: validate } );
		filesCb = h( 'input', { type: 'checkbox' } );
		confirmBtn = h( 'button', { class: 'tv-btn tv-btn--danger', disabled: 'disabled', text: 'Restaurar agora', onclick: function () {
			doRestore( prep.confirm_token, phrase, filesCb.checked, overlay );
		} }, [] );

		var manifest = ( prep.summary && prep.summary.manifest ) || {};
		var dbInfo = manifest.database ? manifest.database.tables + ' tabelas · ' + manifest.database.rows + ' registros' : '';

		var overlay = h( 'div', { class: 'tv-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'tv-modal-title' }, [
			h( 'div', { class: 'tv-modal tv-glass tv-glass--active' }, [
				h( 'h2', { class: 'tv-modal__title', id: 'tv-modal-title', text: 'Restaurar este backup vai substituir o site atual.' } ),
				h( 'div', { class: 'tv-modal__body' }, [
					h( 'p', { text: 'Backup de ' + fmtDate( backup.created_at ) + ' (' + fmtBytes( backup.size_bytes ) + ')' + ( dbInfo ? ' — ' + dbInfo + '.' : '.' ) } ),
					h( 'p', { text: 'O conteúdo atual do banco será sobrescrito pelo conteúdo deste backup. Esta ação não pode ser desfeita manualmente.' } ),
				] ),
				h( 'div', { class: 'tv-safenote' }, [
					h( 'span', { html: ICONS.shield } ),
					h( 'span', { text: 'Um backup de segurança completo do estado atual é criado automaticamente antes de qualquer alteração.' } ),
				] ),
				h( 'label', { class: 'tv-checkbox' }, [ filesCb, h( 'span', { text: 'Também restaurar os arquivos (uploads e wp-content) deste pacote.' } ) ] ),
				h( 'div', { class: 'tv-field' }, [
					h( 'label', {}, [ 'Para confirmar, digite ', h( 'code', { text: phrase } ), ' abaixo.' ] ),
					input,
				] ),
				h( 'div', { class: 'tv-modal__actions' }, [
					h( 'button', { class: 'tv-btn tv-btn--ghost', text: 'Cancelar', onclick: function () {
						close();
					} }, [] ),
					confirmBtn,
				] ),
			] ),
		] );

		function close() {
			document.removeEventListener( 'keydown', onKey );
			overlay.remove();
		}
		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		}
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', onKey );
		overlay._close = close;

		document.body.appendChild( overlay );
		input.focus();
	}

	function doRestore( token, phrase, restoreFiles, overlay ) {
		api( '/restore/confirm', 'POST', { token: token, confirm: phrase, restore_files: !! restoreFiles } ).then( function ( r ) {
			if ( overlay._close ) {
				overlay._close();
			}
			toast( 'ok', 'Restauração iniciada', 'Um backup de segurança está sendo criado antes de sobrescrever.' );
			load();
		} ).catch( function ( e ) {
			toast( 'error', 'Não foi possível restaurar', e.message );
		} );
	}

	/* ── Boot ────────────────────────────────────────────────── */
	load();
}() );
