/*
 * Timevault admin dashboard.
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

	/* ── i18n ────────────────────────────────────────────────── */
	var STR = {
		'pt-BR': {
			backupDb: 'Só banco de dados', backupFull: 'Criar backup completo',
			tabBackups: 'Backups', tabExport: 'Exportar', tabImport: 'Importar',
			theme: 'Alternar tema', lang: 'Idioma',
			keyMissing1: 'A chave de criptografia não está configurada. Defina a constante ',
			keyMissing2: ' no wp-config.php antes de criar backups. A chave nunca fica no banco.',
			cardLast: 'Último backup', cardCount: 'Backups guardados', cardCountMeta: 'Concluídos e íntegros',
			cardSpace: 'Espaço usado', nextClean: 'Próxima limpeza: ', retentionOff: 'Retenção desligada',
			cardHealth: 'Saúde do ambiente', hEncryption: 'Criptografia', hQueue: 'Fila', hDir: 'Diretório',
			noneYet: 'Nenhum ainda', inProgress: 'Em andamento', jobRestore: 'Restauração',
			cancelJobs: 'Cancelar jobs presos', cancelJobsDone: 'Jobs cancelados', cancelJobsMsg: 'Você já pode iniciar uma nova importação.',
			queued: 'Na fila', processing: 'Processando',
			spineTitle: 'Espinha temporal', sortNewest: 'Mais recentes primeiro', sortOldest: 'Mais antigos primeiro',
			encrypted: 'Cifrado', dbType: 'Banco', download: 'Baixar', restore: 'Restaurar', del: 'Excluir',
			backupName: 'Nome do backup', saveName: 'Salvar nome',
			prev: 'Anteriores', next: 'Próximos', pageOf: 'de',
			stCompleted: 'Íntegro', stPending: 'Na fila', stRunning: 'Em andamento', stFailed: 'Falhou', stExpired: 'Expirado',
			emptyTitle: 'Nenhum backup ainda.', emptyText: 'Crie o primeiro para preservar o estado atual do site: banco de dados e arquivos, cifrados em repouso.', emptyCta: 'Criar backup agora',
			historyTitle: 'Histórico', fAll: 'Todos', fFull: 'Completo', fDb: 'Banco', fExport: 'Export',
			thDate: 'Data', thType: 'Tipo', thSize: 'Tamanho', thDest: 'Destino', thStatus: 'Status', emptyFilter: 'Nenhum backup neste filtro.',
			exportTitle: 'Exportação', exportDesc: 'Gere um pacote portátil para migrar ou levar uma cópia a staging. Ao gerar, o download começa automaticamente quando o pacote fica pronto.',
			scope: 'Escopo', scopeAll: 'Tudo (banco + arquivos)', scopeSel: 'Selecionar tabelas',
			tables: 'Tabelas', selectAll: 'Selecionar todas', clear: 'Limpar',
			includeUploads: 'Incluir a pasta de uploads (mídia).', anonymize: 'Anonimizar dados pessoais', anonymizeHint: '(staging/dev: mascara e-mail, nome, telefone; determinístico)',
			genExport: 'Gerar exportação', generating: 'Gerando…', preparingDl: 'Preparando download…',
			importTitle: 'Importar backup (migração)', importDesc: 'Envie pacotes do Timevault, All-in-One WP Migration (.wpress) ou WPvivid em ZIP único. O arquivo é validado e convertido para o formato seguro do Timevault antes de aparecer na lista.',
			importSupport: 'Marque "substituir o site" para aplicar a importação imediatamente. Sem marcar, o pacote só é adicionado à lista.',
			importWarn1: 'Atenção: ', importWarn2: 'pacotes cifrados do Timevault só podem ser lidos com a MESMA ', importWarn3: ' definida no site de origem. Chaves diferentes = pacote ilegível.',
			importFile: 'Pacote (.zip, .zip.enc ou .wpress)', doImport: 'Importar pacote',
			applyLabel: 'Substituir este site agora (aplicar a importação)', applyWarn: 'Isto sobrescreve o banco e os arquivos do site atual.',
			safetyLabel: 'Criar backup de segurança antes de substituir', safetyWarn: 'Mais seguro, mas pode demorar ou travar em hospedagens com limite baixo.',
			uploading: 'Enviando pacote…', processingImport: 'Upload concluído. Processando o pacote; arquivos grandes podem levar alguns minutos.', applying: 'Aplicando migração…', tMigrated: 'Migração concluída', tMigratedMsg: 'O site foi substituído pelo conteúdo importado.', tApplyFail: 'A importação foi salva, mas a aplicação falhou',
			restoreTitle: 'Restaurar este backup vai substituir o site atual.',
			restoreP2: 'O conteúdo atual do banco será sobrescrito pelo conteúdo deste backup. Esta ação não pode ser desfeita manualmente.',
			safeNote: 'Um backup de segurança completo do estado atual é criado automaticamente antes de qualquer alteração.',
			restoreFiles: 'Também restaurar os arquivos (uploads e wp-content) deste pacote.',
			typeToConfirm1: 'Para confirmar, digite ', typeToConfirm2: ' abaixo.',
			cancel: 'Cancelar', restoreNow: 'Restaurar agora',
			delTitle: 'Excluir este backup?', delBody: 'O arquivo do backup e o registro serão removidos permanentemente. Esta ação não pode ser desfeita.', delNow: 'Excluir backup',
			tBackupQueued: 'Backup agendado', tBackupQueuedMsg: 'O backup entrou na fila.',
			tExportDone: 'Exportação pronta', tExportDoneMsg: 'O download vai começar.',
			tExportFail: 'A exportação falhou', tImported: 'Pacote importado', tImportedMsg: 'Ele aparece em Backups e já pode ser restaurado.',
			tRestoreStart: 'Restauração iniciada', tRestoreStartMsg: 'Um backup de segurança está sendo criado antes de sobrescrever.',
			tDeleted: 'Backup excluído', tDeletedMsg: 'O arquivo e o registro foram removidos.',
			tRenamed: 'Nome salvo',
			errNoSelection: 'Selecione ao menos uma tabela ou inclua os uploads.', errNoSelectionT: 'Selecione algo para exportar',
			errBackup: 'Não foi possível criar o backup', errDownload: 'Download indisponível', errExport: 'Não foi possível exportar',
			errPrepare: 'Não foi possível preparar a restauração', errRestore: 'Não foi possível restaurar', errDelete: 'Não foi possível excluir', errImport: 'Não foi possível importar', errRename: 'Não foi possível salvar o nome',
			chooseFile: 'Escolha um arquivo', chooseFileMsg: 'Selecione um pacote .zip, .zip.enc ou .wpress.', loadFail: 'Não foi possível carregar o Timevault: ', close: 'Fechar', loading: 'Carregando…',
			schedTitle: 'Backup automático', schedDesc: 'Roda sozinho na frequência escolhida e mantém apenas os mais recentes.',
			schedFreq: 'Frequência', schedOff: 'Desligado', schedDaily: 'Diário', schedWeekly: 'Semanal', schedMonthly: 'Mensal',
			schedKeep: 'Manter', schedKeepUnit: 'backups automáticos', schedNote: 'Ao passar do limite, os automáticos mais antigos são excluídos com rotatividade; os manuais nunca são tocados.',
			tSchedSaved: 'Agendamento salvo', errSched: 'Não foi possível salvar o agendamento',
		},
		en: {
			backupDb: 'Database only', backupFull: 'Create full backup',
			tabBackups: 'Backups', tabExport: 'Export', tabImport: 'Import',
			theme: 'Toggle theme', lang: 'Language',
			keyMissing1: 'The encryption key is not configured. Define the ', keyMissing2: ' constant in wp-config.php before creating backups. The key never lives in the database.',
			cardLast: 'Last backup', cardCount: 'Stored backups', cardCountMeta: 'Completed and verified',
			cardSpace: 'Space used', nextClean: 'Next cleanup: ', retentionOff: 'Retention off',
			cardHealth: 'Environment health', hEncryption: 'Encryption', hQueue: 'Queue', hDir: 'Directory',
			noneYet: 'None yet', inProgress: 'In progress', jobRestore: 'Restore',
			cancelJobs: 'Cancel stuck jobs', cancelJobsDone: 'Jobs cancelled', cancelJobsMsg: 'You can start a new import now.',
			queued: 'Queued', processing: 'Processing',
			spineTitle: 'Temporal spine', sortNewest: 'Newest first', sortOldest: 'Oldest first',
			encrypted: 'Encrypted', dbType: 'Database', download: 'Download', restore: 'Restore', del: 'Delete',
			backupName: 'Backup name', saveName: 'Save name',
			prev: 'Previous', next: 'Next', pageOf: 'of',
			stCompleted: 'Verified', stPending: 'Queued', stRunning: 'Running', stFailed: 'Failed', stExpired: 'Expired',
			emptyTitle: 'No backups yet.', emptyText: 'Create the first one to preserve the current state of the site: database and files, encrypted at rest.', emptyCta: 'Create backup now',
			historyTitle: 'History', fAll: 'All', fFull: 'Full', fDb: 'Database', fExport: 'Export',
			thDate: 'Date', thType: 'Type', thSize: 'Size', thDest: 'Destination', thStatus: 'Status', emptyFilter: 'No backups in this filter.',
			exportTitle: 'Export', exportDesc: 'Generate a portable package to migrate or take a copy to staging. When you generate it, the download starts automatically once the package is ready.',
			scope: 'Scope', scopeAll: 'Everything (database + files)', scopeSel: 'Select tables',
			tables: 'Tables', selectAll: 'Select all', clear: 'Clear',
			includeUploads: 'Include the uploads folder (media).', anonymize: 'Anonymize personal data', anonymizeHint: '(staging/dev: masks email, name, phone; deterministic)',
			genExport: 'Generate export', generating: 'Generating…', preparingDl: 'Preparing download…',
			importTitle: 'Import backup (migration)', importDesc: 'Upload Timevault packages, All-in-One WP Migration (.wpress), or single-file WPvivid ZIP backups. The file is validated and converted to Timevault’s safe format before it appears in the list.',
			importSupport: 'Tick "replace this site" to apply the import immediately. Left unticked, the package is only added to the list.',
			importWarn1: 'Note: ', importWarn2: 'encrypted Timevault packages can only be read with the SAME ', importWarn3: ' defined on the source site. Different keys = unreadable package.',
			importFile: 'Package (.zip, .zip.enc or .wpress)', doImport: 'Import package',
			applyLabel: 'Replace this site now (apply the import)', applyWarn: 'This overwrites the current site’s database and files.',
			safetyLabel: 'Create a safety backup before replacing', safetyWarn: 'Safer, but it can be slow or get stuck on hosts with low limits.',
			uploading: 'Uploading package…', processingImport: 'Upload complete. Processing the package; large files can take several minutes.', applying: 'Applying migration…', tMigrated: 'Migration complete', tMigratedMsg: 'The site was replaced with the imported content.', tApplyFail: 'The import was saved, but applying it failed',
			restoreTitle: 'Restoring this backup will replace the current site.',
			restoreP2: 'The current database content will be overwritten by this backup. This action cannot be undone manually.',
			safeNote: 'A full safety backup of the current state is created automatically before anything changes.',
			restoreFiles: 'Also restore the files (uploads and wp-content) from this package.',
			typeToConfirm1: 'To confirm, type ', typeToConfirm2: ' below.',
			cancel: 'Cancel', restoreNow: 'Restore now',
			delTitle: 'Delete this backup?', delBody: 'The backup file and its record will be permanently removed. This action cannot be undone.', delNow: 'Delete backup',
			tBackupQueued: 'Backup queued', tBackupQueuedMsg: 'The backup was added to the queue.',
			tExportDone: 'Export ready', tExportDoneMsg: 'The download will start.',
			tExportFail: 'The export failed', tImported: 'Package imported', tImportedMsg: 'It appears under Backups and can be restored.',
			tRestoreStart: 'Restore started', tRestoreStartMsg: 'A safety backup is being created before overwriting.',
			tDeleted: 'Backup deleted', tDeletedMsg: 'The file and record were removed.',
			tRenamed: 'Name saved',
			errNoSelection: 'Select at least one table or include the uploads.', errNoSelectionT: 'Select something to export',
			errBackup: 'Could not create the backup', errDownload: 'Download unavailable', errExport: 'Could not export',
			errPrepare: 'Could not prepare the restore', errRestore: 'Could not restore', errDelete: 'Could not delete', errImport: 'Could not import', errRename: 'Could not save the name',
			chooseFile: 'Choose a file', chooseFileMsg: 'Select a .zip, .zip.enc or .wpress package.', loadFail: 'Could not load Timevault: ', close: 'Close', loading: 'Loading…',
			schedTitle: 'Automatic backup', schedDesc: 'Runs on its own at the chosen frequency and keeps only the most recent ones.',
			schedFreq: 'Frequency', schedOff: 'Off', schedDaily: 'Daily', schedWeekly: 'Weekly', schedMonthly: 'Monthly',
			schedKeep: 'Keep', schedKeepUnit: 'automatic backups', schedNote: 'Past the limit, the oldest automatic backups are rotated out; manual backups are never touched.',
			tSchedSaved: 'Schedule saved', errSched: 'Could not save the schedule',
		},
		es: {
			backupDb: 'Solo base de datos', backupFull: 'Crear copia completa',
			tabBackups: 'Copias', tabExport: 'Exportar', tabImport: 'Importar',
			theme: 'Cambiar tema', lang: 'Idioma',
			keyMissing1: 'La clave de cifrado no está configurada. Define la constante ', keyMissing2: ' en wp-config.php antes de crear copias. La clave nunca queda en la base de datos.',
			cardLast: 'Última copia', cardCount: 'Copias guardadas', cardCountMeta: 'Completas e íntegras',
			cardSpace: 'Espacio usado', nextClean: 'Próxima limpieza: ', retentionOff: 'Retención desactivada',
			cardHealth: 'Salud del entorno', hEncryption: 'Cifrado', hQueue: 'Cola', hDir: 'Directorio',
			noneYet: 'Ninguna aún', inProgress: 'En curso', jobRestore: 'Restauración',
			cancelJobs: 'Cancelar trabajos atascados', cancelJobsDone: 'Trabajos cancelados', cancelJobsMsg: 'Ya puedes iniciar una nueva importación.',
			queued: 'En cola', processing: 'Procesando',
			spineTitle: 'Espina temporal', sortNewest: 'Más recientes primero', sortOldest: 'Más antiguas primero',
			encrypted: 'Cifrado', dbType: 'Base de datos', download: 'Descargar', restore: 'Restaurar', del: 'Eliminar',
			backupName: 'Nombre de la copia', saveName: 'Guardar nombre',
			prev: 'Anteriores', next: 'Siguientes', pageOf: 'de',
			stCompleted: 'Íntegra', stPending: 'En cola', stRunning: 'En curso', stFailed: 'Falló', stExpired: 'Expirada',
			emptyTitle: 'Aún no hay copias.', emptyText: 'Crea la primera para preservar el estado actual del sitio: base de datos y archivos, cifrados en reposo.', emptyCta: 'Crear copia ahora',
			historyTitle: 'Historial', fAll: 'Todas', fFull: 'Completa', fDb: 'Base de datos', fExport: 'Export',
			thDate: 'Fecha', thType: 'Tipo', thSize: 'Tamaño', thDest: 'Destino', thStatus: 'Estado', emptyFilter: 'No hay copias en este filtro.',
			exportTitle: 'Exportación', exportDesc: 'Genera un paquete portátil para migrar o llevar una copia a staging. Al generarlo, la descarga empieza automáticamente cuando el paquete está listo.',
			scope: 'Alcance', scopeAll: 'Todo (base de datos + archivos)', scopeSel: 'Seleccionar tablas',
			tables: 'Tablas', selectAll: 'Seleccionar todas', clear: 'Limpiar',
			includeUploads: 'Incluir la carpeta de uploads (medios).', anonymize: 'Anonimizar datos personales', anonymizeHint: '(staging/dev: enmascara correo, nombre, teléfono; determinista)',
			genExport: 'Generar exportación', generating: 'Generando…', preparingDl: 'Preparando descarga…',
			importTitle: 'Importar copia (migración)', importDesc: 'Sube paquetes de Timevault, All-in-One WP Migration (.wpress) o copias WPvivid en ZIP único. El archivo se valida y se convierte al formato seguro de Timevault antes de aparecer en la lista.',
			importSupport: 'Marca "reemplazar el sitio" para aplicar la importación de inmediato. Sin marcar, el paquete solo se añade a la lista.',
			importWarn1: 'Atención: ', importWarn2: 'los paquetes cifrados de Timevault solo se leen con la MISMA ', importWarn3: ' definida en el sitio de origen. Claves distintas = paquete ilegible.',
			importFile: 'Paquete (.zip, .zip.enc o .wpress)', doImport: 'Importar paquete',
			applyLabel: 'Reemplazar este sitio ahora (aplicar la importación)', applyWarn: 'Esto sobrescribe la base de datos y los archivos del sitio actual.',
			safetyLabel: 'Crear copia de seguridad antes de reemplazar', safetyWarn: 'Más seguro, pero puede tardar o atascarse en alojamientos con límites bajos.',
			uploading: 'Subiendo paquete…', processingImport: 'Subida completada. Procesando el paquete; los archivos grandes pueden tardar varios minutos.', applying: 'Aplicando migración…', tMigrated: 'Migración completada', tMigratedMsg: 'El sitio fue reemplazado por el contenido importado.', tApplyFail: 'La importación se guardó, pero la aplicación falló',
			restoreTitle: 'Restaurar esta copia reemplazará el sitio actual.',
			restoreP2: 'El contenido actual de la base de datos se sobrescribirá con esta copia. Esta acción no se puede deshacer manualmente.',
			safeNote: 'Se crea automáticamente una copia de seguridad completa del estado actual antes de cualquier cambio.',
			restoreFiles: 'Restaurar también los archivos (uploads y wp-content) de este paquete.',
			typeToConfirm1: 'Para confirmar, escribe ', typeToConfirm2: ' abajo.',
			cancel: 'Cancelar', restoreNow: 'Restaurar ahora',
			delTitle: '¿Eliminar esta copia?', delBody: 'El archivo de la copia y su registro se eliminarán permanentemente. Esta acción no se puede deshacer.', delNow: 'Eliminar copia',
			tBackupQueued: 'Copia en cola', tBackupQueuedMsg: 'La copia se añadió a la cola.',
			tExportDone: 'Exportación lista', tExportDoneMsg: 'La descarga comenzará.',
			tExportFail: 'La exportación falló', tImported: 'Paquete importado', tImportedMsg: 'Aparece en Copias y puede restaurarse.',
			tRestoreStart: 'Restauración iniciada', tRestoreStartMsg: 'Se está creando una copia de seguridad antes de sobrescribir.',
			tDeleted: 'Copia eliminada', tDeletedMsg: 'El archivo y el registro se eliminaron.',
			tRenamed: 'Nombre guardado',
			errNoSelection: 'Selecciona al menos una tabla o incluye los uploads.', errNoSelectionT: 'Selecciona algo para exportar',
			errBackup: 'No se pudo crear la copia', errDownload: 'Descarga no disponible', errExport: 'No se pudo exportar',
			errPrepare: 'No se pudo preparar la restauración', errRestore: 'No se pudo restaurar', errDelete: 'No se pudo eliminar', errImport: 'No se pudo importar', errRename: 'No se pudo guardar el nombre',
			chooseFile: 'Elige un archivo', chooseFileMsg: 'Selecciona un paquete .zip, .zip.enc o .wpress.', loadFail: 'No se pudo cargar Timevault: ', close: 'Cerrar', loading: 'Cargando…',
			schedTitle: 'Copia automática', schedDesc: 'Se ejecuta sola en la frecuencia elegida y conserva solo las más recientes.',
			schedFreq: 'Frecuencia', schedOff: 'Desactivado', schedDaily: 'Diaria', schedWeekly: 'Semanal', schedMonthly: 'Mensual',
			schedKeep: 'Conservar', schedKeepUnit: 'copias automáticas', schedNote: 'Al pasar el límite, las copias automáticas más antiguas se eliminan por rotación; las manuales nunca se tocan.',
			tSchedSaved: 'Programación guardada', errSched: 'No se pudo guardar la programación',
		},
	};

	function t( k ) {
		var d = STR[ state.lang ] || STR['pt-BR'];
		return d[ k ] !== undefined ? d[ k ] : ( STR['pt-BR'][ k ] || k );
	}

	var state = {
		overview: null,
		backups: [],
		restores: [],
		filterType: 'all',
		loading: true,
		polling: null,
		tab: 'backups',
		tables: null,
		exportSel: {},
		exportScope: 'all',
		spinePage: 0,
		spineSort: 'newest',
		lang: localStorage.getItem( 'tv-lang' ) || 'pt-BR',
		theme: localStorage.getItem( 'tv-theme' ) || 'light',
	};
	if ( ! STR[ state.lang ] ) {
		state.lang = 'pt-BR';
	}

	var SPINE_PER_PAGE = 4;

	function applyTheme() {
		app.setAttribute( 'data-theme', state.theme );
	}

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
		sun: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
		moon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
	};

	/* ── REST ────────────────────────────────────────────────── */
	function apiUrl( root, path ) {
		var url = new URL( root, window.location.href );
		var parts = String( path || '' ).split( '?' );
		var endpoint = parts[ 0 ].charAt( 0 ) === '/' ? parts[ 0 ] : '/' + parts[ 0 ];
		var query = new URLSearchParams( parts[ 1 ] || '' );
		var restRoute = url.searchParams.get( 'rest_route' );

		if ( restRoute !== null ) {
			url.searchParams.set( 'rest_route', restRoute.replace( /\/+$/, '' ) + endpoint );
		} else {
			url.pathname = url.pathname.replace( /\/+$/, '' ) + endpoint;
		}

		query.forEach( function ( value, key ) {
			url.searchParams.set( key, value );
		} );

		return url.toString();
	}

	function plainErrorMessage( status, text ) {
		var clean = String( text || '' )
			.replace( /<script[\s\S]*?<\/script>/gi, ' ' )
			.replace( /<style[\s\S]*?<\/style>/gi, ' ' )
			.replace( /<[^>]+>/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();

		if ( clean.length > 180 ) {
			clean = clean.substring( 0, 180 ) + '...';
		}

		return clean || 'The server returned an empty response.';
	}

	function parseApiResponse( res ) {
		return res.text().then( function ( text ) {
			var data = null;

			if ( text ) {
				try {
					data = JSON.parse( text );
				} catch ( err ) {
					var parseError = new Error( 'The server returned HTML instead of JSON (HTTP ' + res.status + '): ' + plainErrorMessage( res.status, text ) );
					parseError.status = res.status;
					parseError.raw = text;
					throw parseError;
				}
			}

			if ( ! res.ok ) {
				var apiError = new Error( ( data && data.message ) || 'HTTP ' + res.status );
				apiError.code = data && data.code;
				apiError.status = res.status;
				throw apiError;
			}

			return data || {};
		} );
	}

	function apiFetch( root, path, method, body ) {
		return fetch( apiUrl( root, path ), {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( parseApiResponse );
	}

	function api( path, method, body ) {
		return apiFetch( cfg.root, path, method, body ).catch( function ( err ) {
			if ( cfg.rootFallback && cfg.rootFallback !== cfg.root && ( err.code === 'rest_no_route' || err.status === 404 ) ) {
				return apiFetch( cfg.rootFallback, path, method, body ).catch( function ( fallbackErr ) {
					throw fallbackErr;
				} );
			}

			throw err;
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

	function localeCode() {
		return state.lang === 'en' ? 'en-US' : ( state.lang === 'es' ? 'es-ES' : 'pt-BR' );
	}

	function fmtDate( iso ) {
		if ( ! iso ) {
			return '-';
		}
		var d = new Date( iso.replace( ' ', 'T' ) + ( /Z|[+-]\d\d:?\d\d$/.test( iso ) ? '' : 'Z' ) );
		if ( isNaN( d.getTime() ) ) {
			return iso;
		}
		return d.toLocaleString( localeCode(), { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } );
	}

	function statusBadge( status ) {
		var map = {
			completed: [ 'ok', '✓ ' + t( 'stCompleted' ) ],
			pending: [ 'info', '• ' + t( 'stPending' ) ],
			running: [ 'info', '• ' + t( 'stRunning' ) ],
			failed: [ 'danger', '✕ ' + t( 'stFailed' ) ],
			expired: [ 'dest', '· ' + t( 'stExpired' ) ],
		};
		var m = map[ status ] || [ 'dest', status ];
		return h( 'span', { class: 'tv-badge tv-badge--' + m[ 0 ] }, [ m[ 1 ] ] );
	}

	/* ── Toasts ──────────────────────────────────────────────── */
	function toast( kind, title, msg ) {
		var host = document.getElementById( 'tv-toasts' );
		if ( ! host ) {
			// Themed wrapper so the CSS custom properties resolve (the toasts
			// live on <body>, outside the main .timevault-app element). The
			// inline styles neutralize the .timevault-app layout.
			host = h( 'div', { class: 'tv-toasts timevault-app', id: 'tv-toasts', 'data-theme': state.theme, aria: { live: 'polite' }, style: 'min-height:0;padding:0;margin:0;background:none' }, [] );
			document.body.appendChild( host );
		}
		host.setAttribute( 'data-theme', state.theme );
		var tt = h( 'div', { class: 'tv-toast tv-glass tv-toast--' + kind }, [
			h( 'div', { class: 'tv-toast__title', text: title } ),
			msg ? h( 'div', { text: msg } ) : null,
		] );
		host.appendChild( tt );
		if ( kind !== 'error' ) {
			setTimeout( function () {
				tt.remove();
			}, 5000 );
		} else {
			tt.appendChild( h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', style: 'margin-top:8px', text: t( 'close' ), onclick: function () {
				tt.remove();
			} }, [] ) );
		}
	}

	/* ── Data load ───────────────────────────────────────────── */
	function overviewFromStatus( status ) {
		return {
			health: {
				encryption_configured: !! status.encryption_configured,
				key_install_status: status.key_install_status || null,
				queue_available: !! status.queue_available,
				backup_dir_protected: !! status.backup_dir_protected,
			},
			backups_completed: 0,
			total_size_bytes: 0,
			running_jobs: 0,
			last_backup: null,
			next_maintenance: null,
			retention: null,
			schedule: { enabled: false, frequency: 'weekly', keep: 6 },
		};
	}

	function load() {
		var overviewReq = api( '/overview' ).catch( function ( err ) {
			if ( err.code === 'rest_no_route' || err.status === 404 ) {
				return api( '/status' ).then( overviewFromStatus );
			}
			throw err;
		} );
		var restoresReq = api( '/restores' ).catch( function ( err ) {
			if ( err.code === 'rest_no_route' || err.status === 404 ) {
				return [];
			}
			throw err;
		} );

		return Promise.all( [ overviewReq, api( '/backups?per_page=50' ), restoresReq ] ).then( function ( r ) {
			state.overview = r[ 0 ];
			state.backups = r[ 1 ];
			state.restores = r[ 2 ];
			state.loading = false;
			render();
			managePolling();
		} ).catch( function ( e ) {
			state.loading = false;
			app.innerHTML = '';
			app.appendChild( h( 'div', { class: 'tv-notice', text: t( 'loadFail' ) + e.message } ) );
		} );
	}

	function hasActiveJobs() {
		var active = function ( x ) {
			return x.status === 'pending' || x.status === 'running';
		};
		return state.backups.some( active ) || state.restores.some( active );
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
		app.appendChild( tabbar() );
		if ( state.tab === 'export' ) {
			app.appendChild( exportTab() );
		} else if ( state.tab === 'import' ) {
			app.appendChild( importTab() );
		} else {
			app.appendChild( backupsTab() );
		}
	}

	function header() {
		var langSel = h( 'select', { class: 'tv-select', aria: { label: t( 'lang' ) }, onchange: function () {
			state.lang = langSel.value;
			localStorage.setItem( 'tv-lang', state.lang );
			render();
		} }, [
			h( 'option', { value: 'pt-BR', text: 'PT-BR', selected: state.lang === 'pt-BR' ? 'selected' : null } ),
			h( 'option', { value: 'en', text: 'EN', selected: state.lang === 'en' ? 'selected' : null } ),
			h( 'option', { value: 'es', text: 'ES', selected: state.lang === 'es' ? 'selected' : null } ),
		] );

		var themeBtn = h( 'button', { class: 'tv-iconbtn', title: t( 'theme' ), aria: { label: t( 'theme' ) }, html: state.theme === 'dark' ? ICONS.sun : ICONS.moon, onclick: function () {
			state.theme = state.theme === 'dark' ? 'light' : 'dark';
			localStorage.setItem( 'tv-theme', state.theme );
			applyTheme();
			render();
		} } );

		return h( 'div', { class: 'tv-header' }, [
			h( 'div', { class: 'tv-header__brand' }, [
				h( 'div', { class: 'tv-header__logoFrame' }, [
					cfg.logo ? h( 'img', { class: 'tv-header__logo', src: cfg.logo, alt: 'Timevault' } ) : h( 'span', { class: 'tv-header__logo tv-header__logo--icon', html: ICONS.vault } ),
				] ),
			] ),
			h( 'div', { class: 'tv-header__actions' }, [
				h( 'div', { class: 'tv-controls' }, [ themeBtn, langSel ] ),
				h( 'button', { class: 'tv-btn tv-btn--ghost', text: t( 'backupDb' ), onclick: function () {
					createBackup( 'db' );
				} }, [] ),
				h( 'button', { class: 'tv-btn tv-btn--primary', text: t( 'backupFull' ), onclick: function () {
					createBackup( 'full' );
				} }, [] ),
			] ),
		] );
	}

	function tabbar() {
		var tabs = [ [ 'backups', t( 'tabBackups' ) ], [ 'export', t( 'tabExport' ) ], [ 'import', t( 'tabImport' ) ] ];
		return h( 'nav', { class: 'tv-tabs', role: 'tablist' }, tabs.map( function ( tb ) {
			return h( 'button', { class: 'tv-tab', role: 'tab', aria: { selected: String( state.tab === tb[ 0 ] ) }, text: tb[ 1 ], onclick: function () {
				state.tab = tb[ 0 ];
				render();
			} }, [] );
		} ) );
	}

	function backupsTab() {
		var wrap = h( 'div', {}, [] );
		var ov = state.overview || {};
		var health = ov.health || {};
		if ( ! health.encryption_configured ) {
			wrap.appendChild( h( 'div', { class: 'tv-notice' }, [ t( 'keyMissing1' ), h( 'code', { text: cfg.encryptConst || 'TIMEVAULT_ENCRYPTION_KEY' } ), t( 'keyMissing2' ) ] ) );
		}
		wrap.appendChild( cards() );
		wrap.appendChild( schedulePanel() );
		wrap.appendChild( activeJobsBanner() );
		wrap.appendChild( h( 'div', { class: 'tv-columns' }, [ spinePanel(), historyPanel() ] ) );
		return wrap;
	}

	function schedulePanel() {
		var sc = ( state.overview && state.overview.schedule ) || { enabled: false, frequency: 'weekly', keep: 6 };
		var freqVal = sc.enabled ? sc.frequency : 'off';

		var freqSel = h( 'select', { class: 'tv-select', aria: { label: t( 'schedFreq' ) } }, [
			h( 'option', { value: 'off', text: t( 'schedOff' ), selected: freqVal === 'off' ? 'selected' : null } ),
			h( 'option', { value: 'daily', text: t( 'schedDaily' ), selected: freqVal === 'daily' ? 'selected' : null } ),
			h( 'option', { value: 'weekly', text: t( 'schedWeekly' ), selected: freqVal === 'weekly' ? 'selected' : null } ),
			h( 'option', { value: 'monthly', text: t( 'schedMonthly' ), selected: freqVal === 'monthly' ? 'selected' : null } ),
		] );
		var keepInput = h( 'input', { class: 'tv-input tv-input--num', type: 'number', min: '1', max: '60', value: String( sc.keep || 6 ) } );

		function save() {
			var freq = freqSel.value;
			saveSchedule( { enabled: freq !== 'off', frequency: freq === 'off' ? 'weekly' : freq, keep: parseInt( keepInput.value, 10 ) || 6 } );
		}
		freqSel.addEventListener( 'change', save );
		keepInput.addEventListener( 'change', save );

		return h( 'div', { class: 'tv-panel tv-glass tv-sched-panel' }, [
			h( 'div', { class: 'tv-sched' }, [
				h( 'div', { class: 'tv-sched__copy' }, [
					h( 'div', { class: 'tv-eyebrow', style: 'margin-bottom:4px', text: t( 'schedTitle' ) } ),
					h( 'p', { style: 'color:var(--tv-text-muted);font-size:13px', text: t( 'schedDesc' ) } ),
				] ),
				h( 'label', { class: 'tv-sched__field' }, [ h( 'span', { class: 'tv-sched__lbl', text: t( 'schedFreq' ) } ), freqSel ] ),
				h( 'label', { class: 'tv-sched__field' }, [ h( 'span', { class: 'tv-sched__lbl', text: t( 'schedKeep' ) } ), h( 'span', { class: 'tv-sched__keep' }, [ keepInput, h( 'span', { class: 'tv-sched__unit', text: t( 'schedKeepUnit' ) } ) ] ) ] ),
			] ),
			h( 'p', { class: 'tv-sched__note', text: t( 'schedNote' ) } ),
		] );
	}

	function saveSchedule( data ) {
		api( '/schedule', 'POST', data ).then( function ( sc ) {
			if ( state.overview ) {
				state.overview.schedule = sc;
			}
			toast( 'ok', t( 'tSchedSaved' ), '' );
		} ).catch( function ( e ) {
			toast( 'error', t( 'errSched' ), e.message );
		} );
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
		var sizeParts = fmtBytes( ov.total_size_bytes || 0 ).split( ' ' );

		var healthItems = [ [ health.encryption_configured, t( 'hEncryption' ) ], [ health.queue_available, t( 'hQueue' ) ], [ health.backup_dir_protected, t( 'hDir' ) ] ].map( function ( it ) {
			return h( 'span', { class: 'tv-badge tv-badge--' + ( it[ 0 ] ? 'ok' : 'warn' ), text: ( it[ 0 ] ? '✓ ' : '⚠ ' ) + it[ 1 ] } );
		} );

		return h( 'div', { class: 'tv-cards' }, [
			card( t( 'cardLast' ), last ? fmtBytes( last.size_bytes ) : '-', null, last ? [ h( 'span', { class: 'tv-data', text: fmtDate( last.created_at ) } ) ] : [ h( 'span', { text: t( 'noneYet' ) } ) ] ),
			card( t( 'cardCount' ), ov.backups_completed || 0, null, [ h( 'span', { text: t( 'cardCountMeta' ) } ) ] ),
			card( t( 'cardSpace' ), sizeParts[ 0 ], sizeParts[ 1 ], [ h( 'span', { text: ov.next_maintenance ? t( 'nextClean' ) : t( 'retentionOff' ) } ), ov.next_maintenance ? h( 'span', { class: 'tv-data', text: fmtDate( ov.next_maintenance ) } ) : null ] ),
			h( 'div', { class: 'tv-card tv-glass' }, [
				h( 'div', { class: 'tv-card__label', text: t( 'cardHealth' ) } ),
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
			rows.push( jobRow( t( 'jobRestore' ), r.status, r.step ) );
		} );
		var cancelBtn = h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: t( 'cancelJobs' ), onclick: cancelActiveJobs }, [] );
		return h( 'div', { class: 'tv-panel tv-glass tv-glass--active', style: 'margin-bottom:32px' }, [
			h( 'div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap' }, [
				h( 'div', { class: 'tv-eyebrow', text: t( 'inProgress' ) } ),
				cancelBtn,
			] ),
			h( 'div', { style: 'margin-top:12px;display:flex;flex-direction:column;gap:16px' }, rows ),
		] );
	}

	function cancelActiveJobs() {
		api( '/jobs/cancel-active', 'POST' ).then( function () {
			toast( 'ok', t( 'cancelJobsDone' ), t( 'cancelJobsMsg' ) );
			load();
		} ).catch( function ( e ) {
			toast( 'error', t( 'errBackup' ), e.message );
		} );
	}

	function jobRow( label, status, step ) {
		var stepLabels = {
			safety_backup: { 'pt-BR': 'criando backup de segurança', en: 'creating safety backup', es: 'creando copia de seguridad' },
			validate: { 'pt-BR': 'validando pacote', en: 'validating package', es: 'validando paquete' },
			extract: { 'pt-BR': 'extraindo', en: 'extracting', es: 'extrayendo' },
			restore_db: { 'pt-BR': 'restaurando banco', en: 'restoring database', es: 'restaurando base de datos' },
			restore_files: { 'pt-BR': 'restaurando arquivos', en: 'restoring files', es: 'restaurando archivos' },
			finalize: { 'pt-BR': 'finalizando', en: 'finishing', es: 'finalizando' },
			dump_db: { 'pt-BR': 'exportando banco', en: 'dumping database', es: 'exportando base de datos' },
			package: { 'pt-BR': 'empacotando', en: 'packaging', es: 'empaquetando' },
		};
		var caption = step && stepLabels[ step ] ? ( stepLabels[ step ][ state.lang ] || stepLabels[ step ]['pt-BR'] ) : ( status === 'pending' ? t( 'queued' ) : t( 'processing' ) );
		return h( 'div', {}, [
			h( 'div', { style: 'display:flex;justify-content:space-between;align-items:center' }, [
				h( 'span', { style: 'color:var(--tv-text-strong);font-weight:600', text: label } ),
				h( 'span', { class: 'tv-data', style: 'color:var(--tv-text-muted)', text: caption } ),
			] ),
			h( 'div', { class: 'tv-progress tv-progress--indeterminate', aria: { label: caption } }, [ h( 'div', { class: 'tv-progress__fill' } ) ] ),
		] );
	}

	/* ── Temporal Spine (paginated + sortable) ───────────────── */
	function spinePanel() {
		var done = state.backups.filter( function ( b ) {
			return b.status === 'completed';
		} );
		if ( state.spineSort === 'oldest' ) {
			done = done.slice().reverse();
		}

		var sortBtn = h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: state.spineSort === 'newest' ? t( 'sortNewest' ) : t( 'sortOldest' ), onclick: function () {
			state.spineSort = state.spineSort === 'newest' ? 'oldest' : 'newest';
			state.spinePage = 0;
			render();
		} } );

		var head = h( 'div', { class: 'tv-panel__head' }, [ h( 'h2', { text: t( 'spineTitle' ) } ), h( 'div', { class: 'tv-spine-controls' }, [ done.length ? sortBtn : null ] ) ] );

		if ( ! done.length ) {
			return h( 'section', { class: 'tv-panel tv-glass', aria: { label: t( 'spineTitle' ) } }, [ head, emptyState() ] );
		}

		var pages = Math.ceil( done.length / SPINE_PER_PAGE );
		state.spinePage = Math.min( state.spinePage, pages - 1 );
		var start = state.spinePage * SPINE_PER_PAGE;
		var slice = done.slice( start, start + SPINE_PER_PAGE );

		var list = h( 'ol', { class: 'tv-spine' }, slice.map( function ( b, i ) {
			return spineItem( b, state.spineSort === 'newest' && state.spinePage === 0 && i === 0 );
		} ) );

		var children = [ head, list ];
		if ( pages > 1 ) {
			children.push( h( 'div', { class: 'tv-pager' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', disabled: state.spinePage === 0 ? 'disabled' : null, text: '‹ ' + t( 'prev' ), onclick: function () {
					state.spinePage--;
					render();
				} }, [] ),
				h( 'span', { class: 'tv-data', text: ( start + 1 ) + '–' + ( start + slice.length ) + ' ' + t( 'pageOf' ) + ' ' + done.length } ),
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', disabled: state.spinePage >= pages - 1 ? 'disabled' : null, text: t( 'next' ) + ' ›', onclick: function () {
					state.spinePage++;
					render();
				} }, [] ),
			] ) );
		}
		return h( 'section', { class: 'tv-panel tv-glass', aria: { label: t( 'spineTitle' ) } }, children );
	}

	function spineItem( b, isNow ) {
		return h( 'li', { class: 'tv-spine__item' + ( isNow ? ' tv-spine__item--now' : '' ) }, [
			h( 'span', { class: 'tv-spine__node', aria: { hidden: 'true' } } ),
			h( 'div', { class: 'tv-spine__main' }, [
				h( 'div', { class: 'tv-spine__name', text: backupName( b ) } ),
				h( 'div', { class: 'tv-spine__date', text: fmtDate( b.created_at ) } ),
				editableBackupName( b ),
			] ),
			h( 'div', { class: 'tv-spine__facts' }, [
				h( 'span', { class: 'tv-data', text: fmtBytes( b.size_bytes ) } ),
				h( 'span', { class: 'tv-badge tv-badge--dest', text: b.storage } ),
				b.is_encrypted ? h( 'span', { class: 'tv-badge tv-badge--dest', text: '🔒 ' + t( 'encrypted' ) } ) : null,
				statusBadge( b.status ),
			] ),
			h( 'div', { class: 'tv-spine__actions' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: t( 'download' ), onclick: function () {
					downloadBackup( b.uuid );
				} }, [] ),
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: t( 'restore' ), onclick: function () {
					openRestore( b );
				} }, [] ),
				h( 'button', { class: 'tv-btn tv-btn--danger tv-btn--sm', text: t( 'del' ), onclick: function () {
					openDelete( b );
				} }, [] ),
			] ),
		] );
	}

	function backupName( backup ) {
		return backup.display_name || backup.file_name || backup.uuid;
	}

	function editableBackupName( backup ) {
		var input = h( 'input', {
			class: 'tv-input tv-backup-name__input',
			type: 'text',
			value: backup.display_name || '',
			placeholder: backup.file_name || backup.uuid,
			maxlength: '120',
			aria: { label: t( 'backupName' ) },
		} );
		var btn = h( 'button', {
			class: 'tv-btn tv-btn--ghost tv-btn--sm',
			text: t( 'saveName' ),
			onclick: function () {
				saveBackupName( backup, input, btn );
			},
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				saveBackupName( backup, input, btn );
			}
		} );

		return h( 'div', { class: 'tv-backup-name' }, [ input, btn ] );
	}

	function saveBackupName( backup, input, btn ) {
		var displayName = input.value.trim();
		btn.disabled = true;
		api( '/backups/' + backup.uuid, 'PATCH', { display_name: displayName } ).then( function ( updated ) {
			state.backups = state.backups.map( function ( item ) {
				return item.uuid === updated.uuid ? updated : item;
			} );
			toast( 'ok', t( 'tRenamed' ), backupName( updated ) );
			render();
		} ).catch( function ( e ) {
			btn.disabled = false;
			toast( 'error', t( 'errRename' ), e.message );
		} );
	}

	function emptyState() {
		return h( 'div', { class: 'tv-empty' }, [
			h( 'div', { class: 'tv-empty__icon', html: ICONS.empty } ),
			h( 'h3', { text: t( 'emptyTitle' ) } ),
			h( 'p', { text: t( 'emptyText' ) } ),
			h( 'button', { class: 'tv-btn tv-btn--primary', text: t( 'emptyCta' ), onclick: function () {
				createBackup( 'full' );
			} }, [] ),
		] );
	}

	/* ── History ─────────────────────────────────────────────── */
	function historyPanel() {
		var types = [ [ 'all', t( 'fAll' ) ], [ 'full', t( 'fFull' ) ], [ 'db', t( 'fDb' ) ], [ 'export', t( 'fExport' ) ] ];
		var filters = h( 'div', { class: 'tv-filters', role: 'group' }, types.map( function ( ty ) {
			return h( 'button', { class: 'tv-chip', aria: { pressed: String( state.filterType === ty[ 0 ] ) }, text: ty[ 1 ], onclick: function () {
				state.filterType = ty[ 0 ];
				render();
			} }, [] );
		} ) );

		var rows = state.backups.filter( function ( b ) {
			return state.filterType === 'all' || b.type === state.filterType;
		} );

		var history;
		if ( ! rows.length ) {
			history = h( 'p', { class: 'tv-history-empty', text: t( 'emptyFilter' ) } );
		} else {
			history = h( 'div', { style: 'overflow-x:auto' }, [
				h( 'table', { class: 'tv-table' }, [
					h( 'thead', {}, [ h( 'tr', {}, [ h( 'th', { text: t( 'backupName' ) } ), h( 'th', { text: t( 'thDate' ) } ), h( 'th', { text: t( 'thType' ) } ), h( 'th', { text: t( 'thSize' ), class: 'tv-num' } ), h( 'th', { text: t( 'thDest' ) } ), h( 'th', { text: t( 'thStatus' ) } ) ] ) ] ),
					h( 'tbody', {}, rows.map( function ( b ) {
						return h( 'tr', {}, [
							h( 'td', { class: 'tv-table__name' }, [ editableBackupName( b ) ] ),
							h( 'td', { class: 'tv-data', text: fmtDate( b.created_at ) } ),
							h( 'td', { text: b.type } ),
							h( 'td', { class: 'tv-data tv-num', text: b.size_bytes ? fmtBytes( b.size_bytes ) : '-' } ),
							h( 'td', {}, [ h( 'span', { class: 'tv-badge tv-badge--dest', text: b.storage } ) ] ),
							h( 'td', {}, [ b.error ? h( 'span', { class: 'tv-badge tv-badge--danger', title: b.error, text: '✕ ' + t( 'stFailed' ) } ) : statusBadge( b.status ) ] ),
						] );
					} ) ),
				] ),
			] );
		}
		return h( 'section', { class: 'tv-panel tv-glass', aria: { label: t( 'historyTitle' ) } }, [ h( 'div', { class: 'tv-panel__head' }, [ h( 'h2', { text: t( 'historyTitle' ) } ) ] ), filters, history ] );
	}

	/* ── Export tab (scope + auto-download) ──────────────────── */
	function exportTab() {
		if ( state.tables === null ) {
			api( '/exports/tables' ).then( function ( r ) {
				state.tables = r.tables || [];
				if ( state.tab === 'export' ) {
					render();
				}
			} ).catch( function () {
				state.tables = [];
			} );
			return h( 'div', { class: 'tv-panel tv-glass' }, [ h( 'div', { class: 'tv-boot' }, [ h( 'div', { class: 'tv-boot__spinner' } ), h( 'p', { text: t( 'loading' ) } ) ] ) ] );
		}

		var uploadsCb = h( 'input', { type: 'checkbox', checked: 'checked' } );
		var anonCb = h( 'input', { type: 'checkbox' } );

		var tableList = h( 'div', { class: 'tv-checklist' }, state.tables.map( function ( tb ) {
			var cb = h( 'input', { type: 'checkbox', checked: state.exportSel[ tb ] ? 'checked' : null, onchange: function () {
				state.exportSel[ tb ] = cb.checked;
			} } );
			return h( 'label', { class: 'tv-checkitem' }, [ cb, h( 'span', { class: 'tv-data', text: tb } ) ] );
		} ) );

		var selectiveBox = h( 'div', { style: state.exportScope === 'all' ? 'display:none' : '' }, [
			h( 'div', { class: 'tv-eyebrow', style: 'margin-bottom:8px', text: t( 'tables' ) } ),
			h( 'div', { style: 'display:flex;gap:8px;margin-bottom:10px' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: t( 'selectAll' ), onclick: function () {
					state.tables.forEach( function ( x ) {
						state.exportSel[ x ] = true;
					} );
					render();
				} }, [] ),
				h( 'button', { class: 'tv-btn tv-btn--ghost tv-btn--sm', text: t( 'clear' ), onclick: function () {
					state.tables.forEach( function ( x ) {
						state.exportSel[ x ] = false;
					} );
					render();
				} }, [] ),
			] ),
			tableList,
		] );

		var scopeSel = h( 'select', { class: 'tv-select tv-export-scope', onchange: function () {
			state.exportScope = scopeSel.value;
			render();
		} }, [
			h( 'option', { value: 'all', text: t( 'scopeAll' ), selected: state.exportScope === 'all' ? 'selected' : null } ),
			h( 'option', { value: 'selective', text: t( 'scopeSel' ), selected: state.exportScope === 'selective' ? 'selected' : null } ),
		] );

		var btn = h( 'button', { class: 'tv-btn tv-btn--primary', text: t( 'genExport' ) }, [] );
		btn.addEventListener( 'click', function () {
			var tables, includeUploads;
			if ( state.exportScope === 'all' ) {
				tables = state.tables.slice();
				includeUploads = true;
			} else {
				tables = state.tables.filter( function ( x ) {
					return state.exportSel[ x ];
				} );
				includeUploads = uploadsCb.checked;
				if ( ! tables.length && ! includeUploads ) {
					toast( 'error', t( 'errNoSelectionT' ), t( 'errNoSelection' ) );
					return;
				}
			}
			runExport( tables, includeUploads, anonCb.checked, btn );
		} );

		return h( 'section', { class: 'tv-panel tv-glass tv-export-panel' }, [
			h( 'div', { class: 'tv-panel__head' }, [ h( 'h2', { text: t( 'exportTitle' ) } ) ] ),
			h( 'p', { style: 'color:var(--tv-text-muted);margin-bottom:20px', text: t( 'exportDesc' ) } ),
			h( 'label', { class: 'tv-field' }, [ h( 'span', { class: 'tv-field__label', text: t( 'scope' ) } ), scopeSel ] ),
			selectiveBox,
			h( 'label', { class: 'tv-checkbox', style: 'margin-top:20px' + ( state.exportScope === 'all' ? ';display:none' : '' ) }, [ uploadsCb, h( 'span', { text: t( 'includeUploads' ) } ) ] ),
			h( 'label', { class: 'tv-checkbox' }, [ anonCb, h( 'span', {}, [ t( 'anonymize' ) + ' ', h( 'span', { style: 'color:var(--tv-text-faint)', text: t( 'anonymizeHint' ) } ) ] ) ] ),
			h( 'div', { class: 'tv-export-actions' }, [ btn ] ),
		] );
	}

	function runExport( tables, includeUploads, anonymize, btn ) {
		btn.disabled = true;
		btn.textContent = t( 'generating' );
		api( '/exports', 'POST', { tables: tables, include_uploads: includeUploads, anonymize: anonymize } ).then( function ( r ) {
			pollUntilComplete( r.uuid, btn );
		} ).catch( function ( e ) {
			btn.disabled = false;
			btn.textContent = t( 'genExport' );
			toast( 'error', t( 'errExport' ), e.message );
		} );
	}

	function pollUntilComplete( uuid, btn ) {
		var tries = 0;
		var iv = setInterval( function () {
			tries++;
			api( '/backups/' + uuid ).then( function ( b ) {
				if ( b.status === 'completed' ) {
					clearInterval( iv );
					btn.textContent = t( 'preparingDl' );
					toast( 'ok', t( 'tExportDone' ), t( 'tExportDoneMsg' ) );
					downloadBackup( uuid, function () {
						btn.disabled = false;
						btn.textContent = t( 'genExport' );
						state.tab = 'backups';
						load();
					} );
				} else if ( b.status === 'failed' ) {
					clearInterval( iv );
					btn.disabled = false;
					btn.textContent = t( 'genExport' );
					toast( 'error', t( 'tExportFail' ), b.error || '' );
				}
			} );
			if ( tries > 200 ) {
				clearInterval( iv );
				btn.disabled = false;
				btn.textContent = t( 'genExport' );
			}
		}, 3000 );
	}

	/* ── Import tab ──────────────────────────────────────────── */
	function importTab() {
		var fileInput = h( 'input', { type: 'file', accept: '.zip,.enc,.wpress', class: 'tv-file' } );
		var applyCb = h( 'input', { type: 'checkbox', checked: 'checked' } );
		var safetyCb = h( 'input', { type: 'checkbox' } );
		var progress = h( 'div', { class: 'tv-progress', style: 'display:none' }, [ h( 'div', { class: 'tv-progress__fill', style: 'width:0%' } ) ] );
		var status = h( 'p', { class: 'tv-import-status', style: 'display:none;color:var(--tv-text-muted);margin-top:10px' } );
		var btn;

		function submit() {
			var file = fileInput.files && fileInput.files[ 0 ];
			if ( ! file ) {
				toast( 'error', t( 'chooseFile' ), t( 'chooseFileMsg' ) );
				return;
			}
			var apply = applyCb.checked;
			var safetyBackup = apply && safetyCb.checked;
			btn.disabled = true;
			progress.style.display = 'block';
			progress.className = 'tv-progress';
			var fill = progress.firstChild;
			status.style.display = 'block';
			status.textContent = t( 'uploading' );

			uploadPackage( file, apply, safetyBackup, function ( pct ) {
				fill.style.width = pct + '%';
				if ( pct >= 100 ) {
					status.textContent = t( 'processingImport' );
					progress.className = 'tv-progress tv-progress--indeterminate';
				}
			}, function () {
				status.textContent = t( 'processingImport' );
				progress.className = 'tv-progress tv-progress--indeterminate';
			} ).then( function ( data ) {
				if ( apply && data && data.restore_uuid ) {
					// The upload is done; now show the restore pipeline running.
					progress.className = 'tv-progress tv-progress--indeterminate';
					status.textContent = t( 'applying' );
					pollRestore( data.restore_uuid, function ( ok, err ) {
						btn.disabled = false;
						if ( ok ) {
							toast( 'ok', t( 'tMigrated' ), t( 'tMigratedMsg' ) );
						} else {
							toast( 'error', t( 'errRestore' ), err || '' );
						}
						state.tab = 'backups';
						load();
					} );
				} else {
					toast( 'ok', t( 'tImported' ), t( 'tImportedMsg' ) );
					state.tab = 'backups';
					load();
				}
			} ).catch( function ( e ) {
				btn.disabled = false;
				progress.style.display = 'none';
				status.style.display = 'none';
				toast( 'error', t( 'errImport' ), e.message );
			} );
		}
		btn = h( 'button', { class: 'tv-btn tv-btn--primary', text: t( 'doImport' ), onclick: submit }, [] );

		return h( 'section', { class: 'tv-panel tv-glass tv-import-panel' }, [
			h( 'div', { class: 'tv-panel__head' }, [ h( 'h2', { text: t( 'importTitle' ) } ) ] ),
			h( 'div', { class: 'tv-import-copy' }, [
				h( 'p', { text: t( 'importDesc' ) } ),
				h( 'p', { text: t( 'importSupport' ) } ),
			] ),
			h( 'div', { class: 'tv-notice', style: 'margin-bottom:20px' }, [ h( 'strong', { style: 'color:var(--tv-amber-text)', text: t( 'importWarn1' ) } ), t( 'importWarn2' ), h( 'code', { text: cfg.encryptConst || 'TIMEVAULT_ENCRYPTION_KEY' } ), t( 'importWarn3' ) ] ),
			h( 'label', { class: 'tv-field' }, [ h( 'span', { style: 'display:block;color:var(--tv-text-muted);font-size:13px;margin-bottom:8px', text: t( 'importFile' ) } ), fileInput ] ),
			h( 'label', { class: 'tv-checkbox tv-import-apply' }, [ applyCb, h( 'span', {}, [ h( 'strong', { text: t( 'applyLabel' ) } ), h( 'br', {} ), h( 'span', { style: 'color:var(--tv-text-muted);font-size:13px', text: t( 'applyWarn' ) } ) ] ) ] ),
			h( 'label', { class: 'tv-checkbox tv-import-apply' }, [ safetyCb, h( 'span', {}, [ h( 'strong', { text: t( 'safetyLabel' ) } ), h( 'br', {} ), h( 'span', { style: 'color:var(--tv-text-muted);font-size:13px', text: t( 'safetyWarn' ) } ) ] ) ] ),
			progress,
			status,
			h( 'div', { style: 'margin-top:16px' }, [ btn ] ),
		] );
	}

	function pollRestore( uuid, done ) {
		var tries = 0;
		function finishOrContinue( r ) {
			if ( 'completed' === r.status ) {
				done( true );
				return;
			}
			if ( 'failed' === r.status ) {
				done( false, r.error || '' );
				return;
			}
			if ( 'pending' === r.status ) {
				setTimeout( startStep, 600 );
				return;
			}
			if ( tries > 0 && 0 === tries % 45 ) {
				setTimeout( startStep, 600 );
				return;
			}
			setTimeout( checkStep, 1600 );
		}
		function startStep() {
			tries++;
			api( '/restores/' + uuid, 'POST' ).then( function ( r ) {
				setTimeout( function () {
					finishOrContinue( r );
				}, 1200 );
			} ).catch( function ( e ) {
				if ( 504 === e.status ) {
					setTimeout( checkStep, 2500 );
					return;
				}
				done( false, e.message );
			} );
		}
		function checkStep() {
			tries++;
			api( '/restores/' + uuid ).then( finishOrContinue ).catch( function ( e ) {
				if ( 504 === e.status ) {
					setTimeout( checkStep, 2500 );
					return;
				}
				done( false, e.message );
			} );
		}
		startStep();
	}

	function uploadPackage( file, apply, safetyBackup, onProgress, onProcessing ) {
		return new Promise( function ( resolve, reject ) {
			var fd = new FormData();
			fd.append( 'package', file );
			fd.append( 'apply', apply ? '1' : '0' );
			fd.append( 'safety_backup', safetyBackup ? '1' : '0' );
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', apiUrl( cfg.root, '/import' ) );
			xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
			xhr.upload.onprogress = function ( e ) {
				if ( e.lengthComputable && onProgress ) {
					onProgress( Math.round( ( e.loaded / e.total ) * 100 ) );
				}
			};
			xhr.upload.onload = function () {
				if ( onProcessing ) {
					onProcessing();
				}
			};
			xhr.onload = function () {
				var data;
				try {
					data = JSON.parse( xhr.responseText );
				} catch ( err ) {
					reject( new Error( 'The server returned HTML instead of JSON (HTTP ' + xhr.status + '): ' + plainErrorMessage( xhr.status, xhr.responseText ) ) );
					return;
				}
				if ( xhr.status >= 200 && xhr.status < 300 ) {
					resolve( data );
				} else {
					reject( new Error( ( data && data.message ) || 'HTTP ' + xhr.status ) );
				}
			};
			xhr.onerror = function () {
				reject( new Error( 'HTTP error' ) );
			};
			xhr.send( fd );
		} );
	}

	/* ── Actions ─────────────────────────────────────────────── */
	function createBackup( type ) {
		api( '/backups', 'POST', { type: type, files_scope: 'wp-content', storage: 'local' } ).then( function () {
			toast( 'ok', t( 'tBackupQueued' ), t( 'tBackupQueuedMsg' ) );
			state.tab = 'backups';
			load();
		} ).catch( function ( e ) {
			toast( 'error', t( 'errBackup' ), e.message );
		} );
	}

	function downloadBackup( uuid, done ) {
		api( '/backups/' + uuid + '/download-token', 'POST' ).then( function ( r ) {
			window.location.assign( r.url );
			if ( done ) {
				done();
			}
		} ).catch( function ( e ) {
			toast( 'error', t( 'errDownload' ), e.message );
			if ( done ) {
				done();
			}
		} );
	}

	/* ── Modals ──────────────────────────────────────────────── */
	function modal( content ) {
		var overlay = h( 'div', { class: 'tv-overlay' }, [ h( 'div', { class: 'timevault-app', 'data-theme': state.theme, style: 'min-height:0;padding:0;background:none' }, [ content ] ) ] );
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
		document.body.appendChild( overlay );
		overlay._close = close;
		return overlay;
	}

	function openRestore( backup ) {
		api( '/restore/prepare', 'POST', { backup_uuid: backup.uuid } ).then( function ( prep ) {
			showRestoreModal( backup, prep );
		} ).catch( function ( e ) {
			toast( 'error', t( 'errPrepare' ), e.message );
		} );
	}

	function showRestoreModal( backup, prep ) {
		var phrase = prep.confirm_phrase || 'RESTORE';
		var input = h( 'input', { class: 'tv-input', type: 'text', autocomplete: 'off' } );
		var filesCb = h( 'input', { type: 'checkbox' } );
		var confirmBtn = h( 'button', { class: 'tv-btn tv-btn--danger', disabled: 'disabled', text: t( 'restoreNow' ) }, [] );
		input.addEventListener( 'input', function () {
			confirmBtn.disabled = input.value.trim() !== phrase;
		} );
		var manifest = ( prep.summary && prep.summary.manifest ) || {};
		var dbInfo = manifest.database ? ', ' + manifest.database.tables + ' / ' + manifest.database.rows : '';

		var m = h( 'div', { class: 'tv-modal tv-glass tv-glass--active', role: 'dialog', aria: { modal: 'true' } }, [
			h( 'h2', { class: 'tv-modal__title', text: t( 'restoreTitle' ) } ),
			h( 'div', { class: 'tv-modal__body' }, [
				h( 'p', { text: fmtDate( backup.created_at ) + ' (' + fmtBytes( backup.size_bytes ) + ')' + dbInfo } ),
				h( 'p', { text: t( 'restoreP2' ) } ),
			] ),
			h( 'div', { class: 'tv-safenote' }, [ h( 'span', { html: ICONS.shield } ), h( 'span', { text: t( 'safeNote' ) } ) ] ),
			h( 'label', { class: 'tv-checkbox' }, [ filesCb, h( 'span', { text: t( 'restoreFiles' ) } ) ] ),
			h( 'div', { class: 'tv-field' }, [ h( 'label', {}, [ t( 'typeToConfirm1' ), h( 'code', { text: phrase } ), t( 'typeToConfirm2' ) ] ), input ] ),
			h( 'div', { class: 'tv-modal__actions' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost', text: t( 'cancel' ), onclick: function () {
					overlay._close();
				} }, [] ),
				confirmBtn,
			] ),
		] );
		var overlay = modal( m );
		confirmBtn.addEventListener( 'click', function () {
			api( '/restore/confirm', 'POST', { token: prep.confirm_token, confirm: phrase, restore_files: filesCb.checked } ).then( function () {
				overlay._close();
				toast( 'ok', t( 'tRestoreStart' ), t( 'tRestoreStartMsg' ) );
				load();
			} ).catch( function ( e ) {
				toast( 'error', t( 'errRestore' ), e.message );
			} );
		} );
		input.focus();
	}

	function openDelete( backup ) {
		var confirmBtn = h( 'button', { class: 'tv-btn tv-btn--danger', text: t( 'delNow' ) }, [] );
		var m = h( 'div', { class: 'tv-modal tv-glass tv-glass--active', role: 'dialog', aria: { modal: 'true' } }, [
			h( 'h2', { class: 'tv-modal__title', text: t( 'delTitle' ) } ),
			h( 'div', { class: 'tv-modal__body' }, [
				h( 'p', { text: fmtDate( backup.created_at ) + ' · ' + fmtBytes( backup.size_bytes ) + ' · ' + backup.storage } ),
				h( 'p', { text: t( 'delBody' ) } ),
			] ),
			h( 'div', { class: 'tv-modal__actions' }, [
				h( 'button', { class: 'tv-btn tv-btn--ghost', text: t( 'cancel' ), onclick: function () {
					overlay._close();
				} }, [] ),
				confirmBtn,
			] ),
		] );
		var overlay = modal( m );
		confirmBtn.addEventListener( 'click', function () {
			api( '/backups/' + backup.uuid, 'DELETE' ).then( function () {
				overlay._close();
				toast( 'ok', t( 'tDeleted' ), t( 'tDeletedMsg' ) );
				load();
			} ).catch( function ( e ) {
				toast( 'error', t( 'errDelete' ), e.message );
			} );
		} );
	}

	/* ── Boot ────────────────────────────────────────────────── */
	applyTheme();
	load();
}() );
