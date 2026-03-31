/**
 * 360 Tranquillité — JS admin
 * Actions côté client : confirmations, preview URL login, toggles visuels.
 */
(function ($) {
    'use strict';

    var trqStrings = ($ && window.TRQ && window.TRQ.strings) ? window.TRQ.strings : {
        driveBrowserTitle: 'Choisir un dossier Google Drive',
        driveBrowserLoading: 'Chargement des dossiers...',
        driveBrowserRoot: 'Mon Drive',
        driveBrowserUseCurrent: 'Utiliser ce dossier',
        driveBrowserUseRoot: 'Utiliser la racine du Drive',
        driveBrowserClose: 'Fermer',
        driveBrowserBack: 'Retour',
        driveBrowserEmpty: 'Aucun sous-dossier disponible ici.',
        driveBrowserError: 'Impossible de charger les dossiers Google Drive.',
        driveBrowserSelectedRoot: 'Les sauvegardes seront envoyées à la racine du Drive.',
        driveBrowserSelectedFolder: 'Dossier sélectionné : '
    };

    // -------------------------------------------------------------------------
    // Navigation instantanée entre onglets (sans reload)
    // -------------------------------------------------------------------------
    var $tabs = $('.trq-tab[data-trq-tab]');
    var $panels = $('.trq-tab-panel[data-trq-panel]');

    function switchTab(tabSlug, pushState) {
        if (!tabSlug || !$tabs.length || !$panels.length) {
            return;
        }

        var found = false;
        $tabs.each(function () {
            var $tab = $(this);
            if ($tab.data('trq-tab') === tabSlug) {
                found = true;
            }
        });

        if (!found) {
            return;
        }

        $tabs.removeClass('trq-tab-active');
        $tabs.filter('[data-trq-tab="' + tabSlug + '"]').addClass('trq-tab-active');

        $panels.each(function () {
            var $panel = $(this);
            $panel.prop('hidden', $panel.data('trq-panel') !== tabSlug);
        });

        if (pushState && window.history && window.history.pushState) {
            var nextUrl = new URL(window.location.href);
            nextUrl.searchParams.set('page', 'trq-security');
            nextUrl.searchParams.set('tab', tabSlug);
            window.history.pushState({ trqTab: tabSlug }, '', nextUrl.toString());
        }
    }

    $(document).on('click', '.trq-tab[data-trq-tab]', function (e) {
        var tabSlug = String($(this).data('trq-tab') || '');
        if (!tabSlug) {
            return;
        }
        e.preventDefault();
        switchTab(tabSlug, true);
    });

    $(window).on('popstate', function () {
        var params = new URL(window.location.href).searchParams;
        var tabSlug = params.get('tab') || 'dashboard';
        switchTab(tabSlug, false);
    });

    // -------------------------------------------------------------------------
    // Confirmation avant actions destructives
    // -------------------------------------------------------------------------
    $(document).on('submit', 'form[data-confirm]', function (e) {
        var msg = $(this).data('confirm') || 'Êtes-vous sûr ?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });

    // Ajouter confirmation sur les boutons "Débloquer" et "Bloquer"
    $(document).on('click', '.button-link-delete', function (e) {
        if (!window.confirm('Confirmer cette action ?')) {
            e.preventDefault();
        }
    });

    // -------------------------------------------------------------------------
    // Preview en temps réel de l'URL de connexion
    // -------------------------------------------------------------------------
    var $slugInput = $('#login_slug');
    var $preview   = $('#trq-slug-preview');

    if ($slugInput.length && $preview.length) {
        $slugInput.on('input', function () {
            var val = $(this).val().toLowerCase().replace(/[^a-z0-9-]/g, '-');
            $(this).val(val);
            $preview.text(val);
        });
    }

    // -------------------------------------------------------------------------
    // Auto-hide des notices après 5 secondes
    // -------------------------------------------------------------------------
    setTimeout(function () {
        $('.trq-notice').fadeOut(600);
    }, 5000);

    // -------------------------------------------------------------------------
    // Copier la clé secrète 2FA dans le presse-papier
    // -------------------------------------------------------------------------
    $(document).on('click', '.trq-copy-secret', function (e) {
        e.preventDefault();
        var secret = $(this).data('secret');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(secret).then(function () {
                alert('Clé copiée dans le presse-papier !');
            });
        }
    });

    // -------------------------------------------------------------------------
    // Indicateur de force du slug de connexion
    // -------------------------------------------------------------------------
    if ($slugInput.length) {
        $slugInput.on('input', function () {
            var val = $(this).val();
            var $hint = $('#trq-slug-hint');
            if (!$hint.length) {
                $hint = $('<span id="trq-slug-hint" style="margin-left:8px;font-size:12px;"></span>');
                $slugInput.after($hint);
            }
            if (val.length < 6) {
                $hint.css('color', '#d63638').text('⚠️ Slug trop court (min. 6 caractères)');
            } else if (/^(login|wp-login|admin|connect|connexion)$/.test(val)) {
                $hint.css('color', '#d63638').text('⚠️ Slug trop prévisible');
            } else {
                $hint.css('color', '#46b450').text('✅ Bon slug');
            }
        }).trigger('input');
    }

    // -------------------------------------------------------------------------
    // Navigateur visuel Google Drive
    // -------------------------------------------------------------------------
    var $folderInput = $('input[name="backup_google_drive_folder_id"]');
    var $browser = $('.trq-drive-browser');
    var $backupsForm = $('form[data-trq-tab="backups"]').first();
    var $manualBackupForm = $('.trq-manual-backup-form').first();
    var $runBackupButton = $('#trq-run-backup-now');
    var $cancelBackupButton = $('#trq-cancel-backup');
    var $backupProgress = $('.trq-backup-progress');
    var $backupProgressFill = $backupProgress.find('.trq-backup-progress-fill');
    var $backupProgressPercent = $backupProgress.find('.trq-backup-progress-percent');
    var $backupProgressText = $backupProgress.find('.trq-backup-progress-text');
    var backupPollTimer = null;
    var manualBackupRequestInFlight = false;
    var backupCancelRequestInFlight = false;

    function syncBackupButtons(progress) {
        var running = !!(progress && progress.in_progress);

        if ($runBackupButton.length) {
            $runBackupButton.prop('disabled', running || manualBackupRequestInFlight);
        }

        if ($cancelBackupButton.length) {
            $cancelBackupButton.prop('hidden', !running);
            $cancelBackupButton.prop('disabled', !running || backupCancelRequestInFlight);
        }
    }

    function updateBackupProgressUi(progress) {
        if (!$backupProgress.length || !progress) {
            return;
        }

        var percent = parseInt(progress.percent, 10);
        if (isNaN(percent)) {
            percent = 0;
        }

        $backupProgress.attr('data-running', progress.in_progress ? '1' : '0');

        if (progress.in_progress) {
            $backupProgress.prop('hidden', false);
        }

        $backupProgressFill.css('width', percent + '%');
        $backupProgressPercent.text(percent + '%');
        $backupProgressText.text(progress.message || '');
        syncBackupButtons(progress);

        if (!progress.in_progress && !progress.message) {
            $backupProgress.prop('hidden', true);
        }
    }

    function stopBackupPolling() {
        if (backupPollTimer) {
            window.clearInterval(backupPollTimer);
            backupPollTimer = null;
        }
    }

    function pollBackupProgress() {
        $.post(TRQ.ajaxurl, {
            action: 'trq_get_backup_progress',
            nonce: TRQ.nonce
        }).done(function (response) {
            if (!response || !response.success) {
                return;
            }

            updateBackupProgressUi(response.data);
            if (!response.data.in_progress) {
                stopBackupPolling();
                window.setTimeout(function () {
                    window.location.reload();
                }, 1200);
            }
        });
    }

    function startBackupPolling() {
        stopBackupPolling();
        pollBackupProgress();
        backupPollTimer = window.setInterval(pollBackupProgress, 1500);
    }

    function saveBackupsSettings(extraData) {
        var payload = $backupsForm.serializeArray();
        payload.push({ name: 'action', value: 'trq_save_backup_settings' });
        payload.push({ name: 'nonce', value: TRQ.nonce });

        if (extraData && extraData.length) {
            extraData.forEach(function (item) {
                payload.push(item);
            });
        }

        return $.post(TRQ.ajaxurl, payload);
    }

    if ($folderInput.length && $browser.length) {
        var $feedback = $browser.find('.trq-drive-browser-feedback');
        var $list = $browser.find('.trq-drive-browser-list');
        var $breadcrumbs = $browser.find('.trq-drive-browser-breadcrumbs');
        var $back = $browser.find('.trq-drive-browser-back');
        var pathStack = [{ id: 'root', name: trqStrings.driveBrowserRoot }];

        function escapeHtml(value) {
            return $('<div>').text(value).html();
        }

        function renderBreadcrumbs() {
            var html = '';
            pathStack.forEach(function (item, index) {
                if (index > 0) {
                    html += '<span class="trq-drive-browser-sep">/</span>';
                }
                html += '<button type="button" class="button-link trq-drive-browser-crumb" data-index="' + index + '">' + escapeHtml(item.name) + '</button>';
            });
            $breadcrumbs.html(html);
            $back.prop('disabled', pathStack.length <= 1);
        }

        function setFolderValue(id, name) {
            $folderInput.val(id === 'root' ? '' : id);
            $feedback.text(id === 'root' ? trqStrings.driveBrowserSelectedRoot : (trqStrings.driveBrowserSelectedFolder + name));

            if ($backupsForm.length) {
                saveBackupsSettings([]);
            }
        }

        function renderFolderList(folders) {
            if (!folders.length) {
                $list.html('<p class="description">' + escapeHtml(trqStrings.driveBrowserEmpty) + '</p>');
                return;
            }

            var html = '<ul class="trq-drive-browser-items">';
            folders.forEach(function (folder) {
                html += '<li class="trq-drive-browser-item">';
                html += '<button type="button" class="button-link trq-drive-browser-open" data-folder-id="' + escapeHtml(folder.id) + '" data-folder-name="' + escapeHtml(folder.name) + '">' + escapeHtml(folder.name) + '</button>';
                html += '<button type="button" class="button button-secondary trq-drive-browser-choose" data-folder-id="' + escapeHtml(folder.id) + '" data-folder-name="' + escapeHtml(folder.name) + '">Choisir</button>';
                html += '</li>';
            });
            html += '</ul>';
            $list.html(html);
        }

        function loadFolders(parentId) {
            $feedback.text(trqStrings.driveBrowserLoading);
            $list.empty();

            $.post(TRQ.ajaxurl, {
                action: 'trq_google_drive_list_folders',
                nonce: TRQ.nonce,
                parent_id: parentId
            }).done(function (response) {
                if (!response || !response.success) {
                    $feedback.text((response && response.data && response.data.message) ? response.data.message : trqStrings.driveBrowserError);
                    return;
                }

                $feedback.text('');
                renderFolderList(response.data.folders || []);
                renderBreadcrumbs();
            }).fail(function () {
                $feedback.text(trqStrings.driveBrowserError);
            });
        }

        $(document).on('click', '.trq-drive-folder-picker', function () {
            $browser.prop('hidden', false);
            pathStack = [{ id: 'root', name: trqStrings.driveBrowserRoot }];
            renderBreadcrumbs();
            loadFolders('root');
        });

        $(document).on('click', '.trq-drive-browser-close', function () {
            $browser.prop('hidden', true);
        });

        $(document).on('click', '.trq-drive-folder-root', function () {
            setFolderValue('root', trqStrings.driveBrowserRoot);
        });

        $(document).on('click', '.trq-drive-browser-select-current', function () {
            var current = pathStack[pathStack.length - 1];
            setFolderValue(current.id, current.name);
            $browser.prop('hidden', true);
        });

        $(document).on('click', '.trq-drive-browser-open', function () {
            var folderId = String($(this).data('folder-id') || '');
            var folderName = String($(this).data('folder-name') || '');
            pathStack.push({ id: folderId, name: folderName });
            loadFolders(folderId);
        });

        $(document).on('click', '.trq-drive-browser-choose', function () {
            var folderId = String($(this).data('folder-id') || '');
            var folderName = String($(this).data('folder-name') || '');
            setFolderValue(folderId, folderName);
            $browser.prop('hidden', true);
        });

        $(document).on('click', '.trq-drive-browser-back', function () {
            if (pathStack.length <= 1) {
                return;
            }
            pathStack.pop();
            loadFolders(pathStack[pathStack.length - 1].id);
        });

        $(document).on('click', '.trq-drive-browser-crumb', function () {
            var index = parseInt($(this).data('index'), 10);
            if (isNaN(index)) {
                return;
            }
            pathStack = pathStack.slice(0, index + 1);
            loadFolders(pathStack[pathStack.length - 1].id);
        });
    }

    // -------------------------------------------------------------------------
    // Sauvegarde manuelle avec progression
    // -------------------------------------------------------------------------
    function triggerManualBackup($button) {
        if (manualBackupRequestInFlight) {
            return;
        }

        if (typeof TRQ === 'undefined' || !TRQ.ajaxurl || !$backupsForm.length) {
            return;
        }

        manualBackupRequestInFlight = true;
        $button.prop('disabled', true);
        updateBackupProgressUi({ in_progress: true, percent: 3, message: trqStrings.backupSaving });

        saveBackupsSettings([]).done(function (saveResponse) {
            if (!saveResponse || !saveResponse.success) {
                updateBackupProgressUi({ in_progress: false, percent: 100, message: trqStrings.backupSaveError });
                $button.prop('disabled', false);
                manualBackupRequestInFlight = false;
                syncBackupButtons({ in_progress: false });
                return;
            }

            updateBackupProgressUi({ in_progress: true, percent: 6, message: trqStrings.backupStarting });
            startBackupPolling();

            $.post(TRQ.ajaxurl, {
                action: 'trq_run_backup_now',
                nonce: TRQ.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    stopBackupPolling();
                    updateBackupProgressUi({ in_progress: false, percent: 100, message: (response && response.data && response.data.message) ? response.data.message : trqStrings.backupRunning });
                    $button.prop('disabled', false);
                    manualBackupRequestInFlight = false;
                    syncBackupButtons({ in_progress: false });
                }
            }).fail(function () {
                stopBackupPolling();
                updateBackupProgressUi({ in_progress: false, percent: 100, message: trqStrings.backupRunning });
                $button.prop('disabled', false);
                manualBackupRequestInFlight = false;
                syncBackupButtons({ in_progress: false });
            });
        }).fail(function () {
            updateBackupProgressUi({ in_progress: false, percent: 100, message: trqStrings.backupSaveError });
            $button.prop('disabled', false);
            manualBackupRequestInFlight = false;
            syncBackupButtons({ in_progress: false });
        });
    }

    $(document).on('click', '#trq-cancel-backup', function (e) {
        if (backupCancelRequestInFlight || typeof TRQ === 'undefined' || !TRQ.ajaxurl) {
            return;
        }

        e.preventDefault();
        backupCancelRequestInFlight = true;
        $cancelBackupButton.prop('disabled', true);

        updateBackupProgressUi({
            in_progress: true,
            percent: parseInt($backupProgressPercent.text(), 10) || 0,
            message: trqStrings.backupCancelling
        });

        $.post(TRQ.ajaxurl, {
            action: 'trq_cancel_backup',
            nonce: TRQ.nonce
        }).done(function (response) {
            if (!response || !response.success) {
                updateBackupProgressUi({
                    in_progress: true,
                    percent: parseInt($backupProgressPercent.text(), 10) || 0,
                    message: (response && response.data && response.data.message) ? response.data.message : trqStrings.backupCancelError
                });
                return;
            }

            if (response.data && response.data.progress) {
                updateBackupProgressUi(response.data.progress);
            } else {
                updateBackupProgressUi({
                    in_progress: true,
                    percent: parseInt($backupProgressPercent.text(), 10) || 0,
                    message: trqStrings.backupCancelRequested
                });
            }

            startBackupPolling();
        }).fail(function () {
            updateBackupProgressUi({
                in_progress: true,
                percent: parseInt($backupProgressPercent.text(), 10) || 0,
                message: trqStrings.backupCancelError
            });
        }).always(function () {
            backupCancelRequestInFlight = false;
            syncBackupButtons({ in_progress: $backupProgress.attr('data-running') === '1' });
        });
    });

    $(document).on('click', '#trq-run-backup-now', function (e) {
        if (typeof TRQ === 'undefined' || !TRQ.ajaxurl || !$backupsForm.length) {
            return;
        }

        e.preventDefault();
        triggerManualBackup($(this));
    });

    $(document).on('submit', '.trq-manual-backup-form', function (e) {
        var $form = $(this);

        if (typeof TRQ === 'undefined' || !TRQ.ajaxurl || !$backupsForm.length) {
            return;
        }

        e.preventDefault();
        triggerManualBackup($form.find('#trq-run-backup-now'));
    });

    syncBackupButtons({ in_progress: $backupProgress.attr('data-running') === '1' });

    if ($backupProgress.length && $backupProgress.attr('data-running') === '1') {
        startBackupPolling();
    }

}(jQuery));
