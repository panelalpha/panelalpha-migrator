
(function($) {
    'use strict';

    let migrationData = null;
    let backupInProgress = false;
    let interruptedMigrationData = null;
    let backupQueue = [];
    let currentBackupType = null;
    let currentMode = 'source'; // 'source' or 'destination'

    // The destination can still be finishing when it answers finalize. Ask again every few seconds.
    const FINALIZE_RETRY_DELAY = 3000;
    const FINALIZE_MAX_RETRIES = 20;

    // Mode Management
    function loadModeFromStorage() {
        return localStorage.getItem('panelalphaMigrator_mode') || 'source';
    }

    function saveModeToStorage(mode) {
        localStorage.setItem('panelalphaMigrator_mode', mode);
    }

    function loadDestinationUrlFromStorage() {
        return localStorage.getItem('panelalphaMigrator_destinationUrl') || '';
    }

    function saveDestinationUrlToStorage(url) {
        localStorage.setItem('panelalphaMigrator_destinationUrl', url);
    }

    function forgetDestinationUrl() {
        localStorage.removeItem('panelalphaMigrator_destinationUrl');
    }

    function switchMode(mode) {
        currentMode = mode;
        saveModeToStorage(mode);

        if (mode === 'destination') {
            $('#source-mode-container').hide();
            $('#destination-mode-section').fadeIn();
            loadDestinationModeData();
        } else {
            $('#destination-mode-section').hide();
            $('#source-mode-container').fadeIn();

            const savedUrl = loadDestinationUrlFromStorage();
            if (savedUrl) {
                $('#destination-url-with-token').val(savedUrl);
            }
        }
    }

    function applyDestinationToken(token, showSuccess) {
        const urlWithToken = panelalphaMigrator.siteUrl + '?token=' + token;
        $('#destination-url-display').val(urlWithToken);
        panelalphaMigrator.token = token;
        if (showSuccess) {
            showSuccessMessage('New token generated successfully!');
        }
    }

    function generateAndSaveToken(options) {
        const showSuccess = !!(options && options.showSuccess);

        $.ajax({
            url: panelalphaMigrator.restUrl + '/token/generate-and-save',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            contentType: 'application/json',
            success: function(response) {
                if (response.success && response.token) {
                    applyDestinationToken(response.token, showSuccess);
                } else {
                    showError('Failed to generate token');
                }
            },
            error: function(xhr) {
                showError('Failed to generate token: ' + (xhr.responseJSON?.message || xhr.statusText));
            }
        });
    }

    function loadDestinationModeData() {
        $.ajax({
            url: panelalphaMigrator.restUrl + '/token/current',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            success: function(response) {
                if (response.success && response.token) {
                    applyDestinationToken(response.token, false);
                } else {
                    generateAndSaveToken({ showSuccess: false });
                }
            },
            error: function(xhr) {
                if (xhr.status === 404) {
                    generateAndSaveToken({ showSuccess: false });
                } else {
                    showError('Failed to load token: ' + (xhr.responseJSON?.message || xhr.statusText));
                }
            }
        });
    }

    function handleCopyDestinationUrl() {
        const url = $('#destination-url-display').val();
        if (url && !url.includes('No token')) {
            copyToClipboard(url);
            const $button = $('#copy-destination-url');
            const originalText = $button.html();
            $button.html('<span class="dashicons dashicons-yes"></span> Copied!');
            setTimeout(function() {
                $button.html(originalText);
            }, 2000);
        }
    }

    function handleGenerateNewToken() {
        if (!confirm('Are you sure you want to generate a new token? The current token will be invalidated.')) {
            return;
        }

        generateAndSaveToken({ showSuccess: true });
    }

    function handleToggleAdvancedOptions() {
        const advancedOptions = $('#advanced-options');
        const icon = $('#toggle-advanced-options .dashicons');

        if (advancedOptions.is(':visible')) {
            advancedOptions.slideUp(300);
            icon.removeClass('rotate-active');
        } else {
            advancedOptions.slideDown(300);
            icon.addClass('rotate-active');
        }
    }

    function parseDestinationUrl(fullUrl) {
        try {
            const url = new URL(fullUrl);
            const token = url.searchParams.get('token');
            
            url.searchParams.delete('token');
            const destinationUrl = url.toString();
            
            return { destinationUrl, token };
        } catch (e) {
            return { destinationUrl: fullUrl, token: null };
        }
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(text).select();
            document.execCommand('copy');
            tempInput.remove();
        }
    }

    $(document).ready(function() {
        initEventHandlers();

        // Check if mode switcher should be shown
        if (!panelalphaMigrator.showModeSwitcher) {
            $('#mode-selection-section').hide();
            // Use mode from .env, not localStorage
            currentMode = panelalphaMigrator.mode || 'source';
        } else {
            // Load saved mode from localStorage
            currentMode = loadModeFromStorage();
        }

        // Set radio button
        if (currentMode === 'destination') {
            $('#mode-destination').prop('checked', true);
        } else {
            $('#mode-source').prop('checked', true);
        }

        switchMode(currentMode);

        if (currentMode === 'source') {
            checkForInterruptedMigration();
        }
    });

    function initEventHandlers() {
        // Mode selection handlers
        $('input[name="migration-mode"]').on('change', function() {
            switchMode($(this).val());
        });

        $('#copy-destination-url').on('click', handleCopyDestinationUrl);

        $('#generate-new-token').on('click', handleGenerateNewToken);

        // The certificate option only applies with a manual IP
        $('#destination-ip').on('input', function() {
            const hasIp = $(this).val().trim() !== '';
            $('#destination-insecure-tls').prop('disabled', !hasIp);
            if (!hasIp) {
                $('#destination-insecure-tls').prop('checked', false);
            }
        });

        // Source mode handlers
        $('#start-scan').on('click', handleStartScan);

        $('#resume-backup').on('click', handleResumeBackup);

        $('#stop-backup').on('click', handleStopBackup);

        $('#backup-database, #backup-files').on('change', function() {
            validateBackupSelection();
        });

        $('#toggle-advanced-options').on('click', handleToggleAdvancedOptions);

        $('#start-new-migration').on('click', handleStartNewMigration);

        // Save destination URL to localStorage when it changes
        $('#destination-url-with-token').on('change blur', function() {
            const url = $(this).val().trim();
            if (url) {
                saveDestinationUrlToStorage(url);
            }
        });
    }

    function handleStartScan() {
        const fullUrl = $('#destination-url-with-token').val().trim();

        if (!fullUrl) {
            showError('Please paste destination URL with token');
            return;
        }

        // Save to localStorage for persistence
        saveDestinationUrlToStorage(fullUrl);

        const { destinationUrl, token } = parseDestinationUrl(fullUrl);

        if (!destinationUrl) {
            showError('Please paste destination URL with token');
            return;
        }

        if (!token) {
            showError('Token not found in URL. Please paste complete URL from destination.');
            return;
        }

        // Validate token format (should be 64 hex characters)
        if (!/^[a-f0-9]{64}$/i.test(token)) {
            showError('Invalid token format. Please paste the correct URL with token from destination.');
            return;
        }

        if (!isValidUrl(destinationUrl)) {
            showError('Please enter a valid URL (http:// or https://)');
            return;
        }

        panelalphaMigrator.token = token;

        if (backupInProgress && migrationData && migrationData.migration_id) {
            backupInProgress = false;

            $('#backup-progress-section').hide();

            $('#start-backup').prop('disabled', false);

            // The scan is refused while the lock is held, so wait for the stop to release it.
            stopBackupOnServer(migrationData.migration_id, false, function() {
                beginScan(destinationUrl);
            }, function(errorMessage) {
                showError(errorMessage);
            });
            return;
        }

        beginScan(destinationUrl);
    }

    function beginScan(destinationUrl) {
        const exclusions = $('#exclusions').val().trim();
        const ip = $('#destination-ip').val().trim();

        hideError();
        $('#interrupted-migration-section').hide();
        $('#migration-config-section').hide();

        showProgress();

        $('#start-scan').prop('disabled', true);

        performScan(destinationUrl, exclusions, ip);
    }

    function performScan(destinationUrl, exclusions, ip) {
        updateProgress(0, 'Initializing scan...');

        const requestData = {
            destination_url: destinationUrl,
            token: panelalphaMigrator.token,  // Add token from URL
            backup_database: $('#backup-database').is(':checked'),
            backup_files: $('#backup-files').is(':checked')
        };

        if (exclusions) {
            requestData.exclusions = exclusions;
        }

        if (ip) {
            requestData.ip = ip;
            requestData.insecure_tls = $('#destination-insecure-tls').is(':checked');
        }

        $.ajax({
            url: panelalphaMigrator.restUrl + '/scan',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify(requestData),
            contentType: 'application/json',
            success: function(response) {
                updateProgress(100, 'Scan completed!');

                setTimeout(function() {
                    handleScanSuccess(response);
                }, 500);
            },
            error: function(xhr) {
                handleScanError(xhr);
            }
        });
    }

    function handleScanSuccess(response) {
        migrationData = response;

        // Store start time for duration calculation
        window.migrationStartTime = Date.now();
        localStorage.setItem('panelalphaMigrator_startTime', window.migrationStartTime.toString());

        $('#progress-section').hide();

        handleStartBackup();
    }

    function handleScanError(xhr) {
        let errorMessage = 'Unknown error';

        if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
        } else if (xhr.statusText) {
            errorMessage = xhr.statusText;
        }

        $('#progress-section').hide();

        $('#migration-config-section').fadeIn();

        showError(errorMessage);

        $('#start-scan').prop('disabled', false);
    }

    function showProgress() {
        $('#progress-section').fadeIn();
    }

    function updateProgress(percentage, message) {
        $('#progress-bar-fill').css('width', percentage + '%');
        $('#progress-percentage').text(Math.round(percentage) + '%');
        $('#progress-message').text(message);
    }

    function showError(message) {
        const errorSection = $('#error-section');
        errorSection.html(`
            <div class="panelalpha-migrator-section">
                <div class="notice notice-error">
                    <p><strong>${escapeHtml(message)}</strong></p>
                </div>
            </div>
        `);
        errorSection.fadeIn();
    }

    function hideError() {
        $('#error-section').empty();
    }

    function showSuccessMessage(message) {
        const errorSection = $('#error-section');
        errorSection.html(`
            <div class="panelalpha-migrator-section">
                <div class="notice notice-success is-dismissible">
                    <p><strong>${escapeHtml(message)}</strong></p>
                </div>
            </div>
        `);
        errorSection.fadeIn();

        setTimeout(function() {
            errorSection.fadeOut(function() {
                errorSection.empty();
            });
        }, 5000);
    }

    function isValidUrl(url) {
        try {
            const parsed = new URL(url);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';

        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('en-US');
    }

    function formatDuration(seconds) {
        if (seconds < 60) {
            return Math.round(seconds) + 's';
        }
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = Math.round(seconds % 60);
        if (minutes < 60) {
            return minutes + 'm ' + remainingSeconds + 's';
        }
        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;
        return hours + 'h ' + remainingMinutes + 'm';
    }

    function showMigrationComplete() {
        forgetDestinationUrl();
        if (!migrationData || !migrationData.summary) {
            console.error('No migration data available for summary');
            return;
        }

        const summary = migrationData.summary;

        // Check what was actually backed up
        const completedBackupTypes = localStorage.getItem('panelalphaMigrator_completedBackupTypes') || '';
        const backupDatabase = completedBackupTypes.includes('database');
        const backupFiles = completedBackupTypes.includes('files');

        // Calculate duration from localStorage times
        let duration = '-';
        const startTime = localStorage.getItem('panelalphaMigrator_startTime');
        const completionTime = localStorage.getItem('panelalphaMigrator_completionTime');

        if (startTime && completionTime) {
            const durationSeconds = (parseInt(completionTime) - parseInt(startTime)) / 1000;
            duration = formatDuration(durationSeconds);
        } else if (window.migrationStartTime) {
            // Fallback to current session time
            const durationSeconds = (Date.now() - window.migrationStartTime) / 1000;
            duration = formatDuration(durationSeconds);
        }

        // Update summary values based on what was actually backed up
        if (backupFiles) {
            $('#complete-files-count').text(formatNumber(summary.total_files || 0));
        } else {
            $('#complete-files-count').text('0');
        }

        if (backupDatabase) {
            $('#complete-tables-count').text(formatNumber(summary.total_tables || 0));
        } else {
            $('#complete-tables-count').text('0');
        }

        // Calculate total size based on what was backed up
        let totalSize = 0;
        if (backupFiles) {
            totalSize += (summary.total_file_size || 0);
        }
        if (backupDatabase) {
            totalSize += (summary.total_db_size || 0);
        }

        $('#complete-total-size').text(formatBytes(totalSize));
        $('#complete-duration').text(duration);

        // Show complete section
        $('#migration-complete-section').fadeIn();
    }

    function handleStartNewMigration() {
        // Hide complete section
        $('#migration-complete-section').fadeOut(function() {
            // Reset all sections
            $('#progress-section').hide();
            $('#backup-progress-section').hide();
            $('#interrupted-migration-section').hide();
            hideError();

            // Reset migration data
            migrationData = null;
            backupInProgress = false;
            currentBackupType = null;
            backupQueue = [];
            window.migrationStartTime = null;

            // Clear localStorage
            clearBackupStateFromLocalStorage();
            localStorage.removeItem('panelalphaMigrator_migrationComplete');
            localStorage.removeItem('panelalphaMigrator_completionTime');
            localStorage.removeItem('panelalphaMigrator_startTime');
            localStorage.removeItem('panelalphaMigrator_completedBackupTypes');

            // Reset buttons
            $('#start-scan').prop('disabled', false);
            $('#start-backup').prop('disabled', false);

            // Show config section
            $('#migration-config-section').fadeIn();
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function truncate(text, maxLength) {
        if (text.length <= maxLength) {
            return text;
        }
        return text.substring(0, maxLength - 3) + '...';
    }

    function validateBackupSelection() {
        const dbChecked = $('#backup-database').is(':checked');
        const filesChecked = $('#backup-files').is(':checked');

        if (!dbChecked && !filesChecked) {
            $(this).prop('checked', true);
            showError('At least one backup option must be selected.');
        } else {
            hideError();
        }
    }

    function handleStartBackup() {
        if (!migrationData || !migrationData.migration_id) {
            showError('No migration data available. Please run scan first.');
            return;
        }

        if (backupInProgress) {
            return;
        }

        const fullUrl = $('#destination-url-with-token').val().trim();
        const { destinationUrl, token } = parseDestinationUrl(fullUrl);
        
        if (!destinationUrl) {
            showError('Please enter destination WordPress URL.');
            return;
        }

        if (token) {
            panelalphaMigrator.token = token;
        }

        const backupDatabase = $('#backup-database').is(':checked');
        const backupFiles = $('#backup-files').is(':checked');

        if (!backupDatabase && !backupFiles) {
            showError('Please select at least one backup option (Database or Files).');
            return;
        }

        backupQueue = [];
        if (backupDatabase) {
            backupQueue.push('database');
        }
        if (backupFiles) {
            backupQueue.push('files');
        }

        saveBackupStateToLocalStorage(migrationData.migration_id, backupQueue.join(','));

        $('#progress-section').hide();
        $('#interrupted-migration-section').hide();
        $('#migration-config-section').hide();

        backupInProgress = true;
        $('#start-backup').prop('disabled', true);
        $('#backup-progress-section').fadeIn();

        updateBackupProgress(0, 'Starting backup and sending migration info to destination...', 'dashicons-backup');

        startBackupOnServer(migrationData.migration_id, function() {
            processNextBackupInQueue();
        }, function(errorMessage) {
            handleBackupError(errorMessage);
        });
    }

    function processNextBackupInQueue() {

        if (!backupInProgress || backupQueue.length === 0) {
            handleAllBackupsComplete();
            return;
        }

        currentBackupType = backupQueue.shift();

        if (currentBackupType === 'database') {
            updateBackupProgress(0, 'Starting database backup...', 'dashicons-database-export');
            sendNextDatabaseChunk(migrationData.migration_id);
        } else if (currentBackupType === 'files') {
            updateBackupProgress(0, 'Starting files backup...', 'dashicons-upload');
            sendNextFileChunk(migrationData.migration_id);
        }
    }

    function handleAllBackupsComplete() {

        backupInProgress = false;
        currentBackupType = null;
        updateBackupProgress(100, 'All backups completed! Finalizing...', 'dashicons-yes-alt');

        stopBackupOnServer(migrationData.migration_id, true, function() {
            finalizeMigration(migrationData.migration_id, function() {
                const completionTime = Date.now();
                localStorage.setItem('panelalphaMigrator_migrationComplete', 'true');
                localStorage.setItem('panelalphaMigrator_completionTime', completionTime.toString());

                const savedBackupType = localStorage.getItem('panelalphaMigrator_lastBackupType');
                if (savedBackupType) {
                    localStorage.setItem('panelalphaMigrator_completedBackupTypes', savedBackupType);
                }

                $('#backup-progress-section').fadeOut(function() {
                    showMigrationComplete();
                });
            });
        }, function(errorMessage) {
            handleBackupError(errorMessage);
        });
    }

    function updateBackupProgress(percentage, message, icon) {
        $('#backup-progress-bar-fill').css('width', percentage + '%');
        $('#backup-progress-percentage').text(Math.round(percentage) + '%');
        $('#backup-progress-message').text(message);

        if (icon) {
            $('#backup-progress-icon').removeClass().addClass('dashicons ' + icon);
        }
    }

    function handleBackupError(errorMessage) {
        backupInProgress = false;
        currentBackupType = null;
        backupQueue = [];

        releaseBackupOnError();

        $('#backup-progress-section').hide();

        $('#migration-config-section').fadeIn();

        forgetDestinationUrl();
        showError(errorMessage);
        $('#start-backup').prop('disabled', false);
    }

    function handleStartDatabaseBackup() {
        if (!migrationData || !migrationData.migration_id) {
            showError('No migration data available. Please run scan first.');
            return;
        }

        if (backupInProgress) {
            return;
        }

        const fullUrl = $('#destination-url-with-token').val().trim();
        const { destinationUrl, token } = parseDestinationUrl(fullUrl);
        
        if (!destinationUrl) {
            showError('Please enter destination WordPress URL.');
            return;
        }

        if (token) {
            panelalphaMigrator.token = token;
        }

        saveBackupStateToLocalStorage(migrationData.migration_id, 'database');

        backupInProgress = true;
        $('#start-db-backup').prop('disabled', true);
        $('#backup-progress-section').fadeIn();

        $('#backup-progress-bar-fill').css('width', '0%');
        $('#backup-progress-percentage').text('0%');
        $('#backup-progress-message').text('Starting backup and sending migration info to destination...');

        startBackupOnServer(migrationData.migration_id, function() {
            sendNextDatabaseChunk(migrationData.migration_id);
        }, function(errorMessage) {
            handleDatabaseBackupError({ responseJSON: { message: errorMessage } });
        });
    }

    function sendNextDatabaseChunk(migrationId) {
        if (!backupInProgress) {
            return;
        }

        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/database/next-chunks-batch',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationId,
                batch_size: 50
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.completed) {
                    handleDatabaseBackupComplete();
                } else {
                    if (response.progress) {
                        updateDatabaseBackupProgressFromData(response.progress);
                    } else {
                        console.warn('No progress data in response');
                    }

                    // Continue processing next batch
                    sendNextDatabaseChunk(migrationId);
                }
            },
            error: function(xhr) {
                handleDatabaseBackupError(xhr);
            }
        });
    }

    function updateDatabaseBackupProgressFromData(progress) {
        let message = 'Backing up database: ' + progress.completed_tables + '/' + progress.total_tables + ' tables (' + progress.sent_chunks + '/' + progress.total_chunks + ' chunks)';

        let details = '';
        if (progress.total_size && progress.sent_size !== undefined) {
            details += 'Size: ' + formatBytes(progress.sent_size) + ' / ' + formatBytes(progress.total_size);
        }

        if (progress.current_table) {
            if (details) details += '<br>';
            details += 'Current table: ' + escapeHtml(progress.current_table);
        }

        updateBackupProgress(progress.percentage, message, 'dashicons-database-export');
        $('#backup-progress-details').html(details);
    }

    function updateDatabaseBackupProgress(migrationId) {
        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/database/progress',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: {
                migration_id: migrationId
            },
            success: function(response) {
                updateDatabaseBackupProgressFromData(response.progress);
            }
        });
    }

    function handleDatabaseBackupComplete() {

        updateBackupProgress(100, 'Database backup completed!', 'dashicons-yes');
        $('#backup-progress-details').html('');

        if (currentBackupType === 'database') {
            setTimeout(function() {
                processNextBackupInQueue();
            }, 500);
        } else {
            backupInProgress = false;

            clearBackupStateFromLocalStorage('database');

            stopBackupOnServer(migrationData.migration_id, true, function() {
                finalizeMigration(migrationData.migration_id);
            }, function(errorMessage) {
                showError(errorMessage);
            });

            setTimeout(function() {
                $('#backup-progress-section').fadeOut();
                $('#start-backup').prop('disabled', false);
            }, 2000);
        }
    }

    function handleDatabaseBackupError(xhr) {
        backupInProgress = false;
        releaseBackupOnError();
        let errorMessage = 'Database backup failed';

        if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
        }

        $('#backup-progress-section').hide();
        forgetDestinationUrl();
        showError(errorMessage);
        $('#start-db-backup').prop('disabled', false);
    }

    function handleStartFilesBackup() {
        if (!migrationData || !migrationData.migration_id) {
            showError('No migration data available. Please run scan first.');
            return;
        }

        if (backupInProgress) {
            return;
        }

        const fullUrl = $('#destination-url-with-token').val().trim();
        const { destinationUrl, token } = parseDestinationUrl(fullUrl);
        
        if (!destinationUrl) {
            showError('Please enter destination WordPress URL.');
            return;
        }

        if (token) {
            panelalphaMigrator.token = token;
        }

        saveBackupStateToLocalStorage(migrationData.migration_id, 'files');

        backupInProgress = true;
        $('#start-files-backup').prop('disabled', true);
        $('#files-backup-progress-section').fadeIn();

        $('#files-backup-progress-bar-fill').css('width', '0%');
        $('#files-backup-progress-percentage').text('0%');
        $('#files-backup-progress-message').text('Starting backup and sending migration info to destination...');

        startBackupOnServer(migrationData.migration_id, function() {
            sendNextFileChunk(migrationData.migration_id);
        }, function(errorMessage) {
            handleFilesBackupError({ responseJSON: { message: errorMessage } });
        });
    }

    function sendNextFileChunk(migrationId) {
        if (!backupInProgress) {
            return;
        }

        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/files/next-chunks-batch',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationId,
                batch_size: 50
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.completed) {
                    handleFilesBackupComplete();
                } else {
                    if (response.progress) {
                        updateFilesBackupProgressFromData(response.progress);
                    } else {
                        console.warn('No progress data in response');
                    }

                    // Continue processing next batch
                    sendNextFileChunk(migrationId);
                }
            },
            error: function(xhr) {
                handleFilesBackupError(xhr);
            }
        });
    }

    function updateFilesBackupProgressFromData(progress) {
        let message = 'Backing up files: ' + progress.completed_files + '/' + progress.total_files + ' files (' + progress.sent_chunks + '/' + progress.total_chunks + ' chunks)';

        let details = '';
        if (progress.total_size && progress.sent_size !== undefined) {
            details += 'Size: ' + formatBytes(progress.sent_size) + ' / ' + formatBytes(progress.total_size);
        }

        if (progress.current_file) {
            if (details) details += '<br>';
            details += 'Current file: ' + escapeHtml(progress.current_file);
        }

        updateBackupProgress(progress.percentage, message, 'dashicons-upload');
        $('#backup-progress-details').html(details);
    }

    function updateFilesBackupProgress(migrationId) {
        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/files/progress',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: {
                migration_id: migrationId
            },
            success: function(response) {
                updateFilesBackupProgressFromData(response.progress);
            }
        });
    }

    function handleFilesBackupComplete() {
        updateBackupProgress(100, 'Files backup completed!', 'dashicons-yes');
        $('#backup-progress-details').html('');

        if (currentBackupType === 'files') {
            setTimeout(function() {
                processNextBackupInQueue();
            }, 500);
        } else {
            backupInProgress = false;

            clearBackupStateFromLocalStorage('files');

            stopBackupOnServer(migrationData.migration_id, true, function() {
                finalizeMigration(migrationData.migration_id);
            }, function(errorMessage) {
                showError(errorMessage);
            });

            setTimeout(function() {
                $('#backup-progress-section').fadeOut();
                $('#start-backup').prop('disabled', false);
            }, 2000);
        }
    }

    function handleFilesBackupError(xhr) {
        backupInProgress = false;
        releaseBackupOnError();
        let errorMessage = 'Files backup failed';

        if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
        }

        $('#files-backup-progress-section').hide();
        forgetDestinationUrl();
        showError(errorMessage);
        $('#start-files-backup').prop('disabled', false);
    }

    function backupRequestError(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }
        return fallback;
    }

    // Takes the migration lock on the server, which also sends the migration info to the destination.
    // Chunks are sent only after this succeeds.
    function startBackupOnServer(migrationId, onSuccess, onError) {
        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/start',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationId
            }),
            contentType: 'application/json',
            success: function() {
                onSuccess();
            },
            error: function(xhr) {
                onError(backupRequestError(xhr, 'Failed to start the backup'));
            }
        });
    }

    // keepLock is true only right before finalize, which releases the lock itself.
    function stopBackupOnServer(migrationId, keepLock, onSuccess, onError) {
        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/stop',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationId,
                keep_lock: keepLock
            }),
            contentType: 'application/json',
            success: function() {
                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
            },
            error: function(xhr) {
                if (typeof onError === 'function') {
                    onError(backupRequestError(xhr, 'Failed to stop the backup'));
                }
            }
        });
    }

    function releaseBackupOnError() {
        if (migrationData && migrationData.migration_id) {
            stopBackupOnServer(migrationData.migration_id, false);
        }
    }

    function finalizeMigration(migrationId, onSuccess, retries) {
        retries = retries || 0;

        $.ajax({
            url: panelalphaMigrator.restUrl + '/finalize',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationId
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response && response.success && response.finalized === false) {
                    if (retries < FINALIZE_MAX_RETRIES) {
                        setTimeout(function() {
                            finalizeMigration(migrationId, onSuccess, retries + 1);
                        }, FINALIZE_RETRY_DELAY);
                        return;
                    }

                    forgetDestinationUrl();
                    releaseBackupOnError();
                    localStorage.removeItem('panelalphaMigrator_migrationComplete');
                    localStorage.removeItem('panelalphaMigrator_completionTime');
                    showError('Destination is still finishing the migration. Check its status later.');
                    return;
                }

                forgetDestinationUrl();
                if (response && response.success) {
                    if (response.warning && response.skipped_files && response.skipped_files.length) {
                        $('#error-section').html(`
                            <div class="panelalpha-migrator-section">
                                <div class="notice notice-warning is-dismissible">
                                    <p><strong>${escapeHtml(response.warning)} (${response.skipped_files.length} skipped files)</strong></p>
                                </div>
                            </div>
                        `).show();
                    }
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                    return;
                }

                localStorage.removeItem('panelalphaMigrator_migrationComplete');
                localStorage.removeItem('panelalphaMigrator_completionTime');
                showError('Migration finalization failed');
            },
            error: function(xhr) {
                forgetDestinationUrl();
                releaseBackupOnError();
                localStorage.removeItem('panelalphaMigrator_migrationComplete');
                localStorage.removeItem('panelalphaMigrator_completionTime');
                showError(backupRequestError(xhr, 'Migration finalization failed'));
            }
        });
    }


    function checkForInterruptedMigration() {
        const lastMigrationId = localStorage.getItem('panelalphaMigrator_lastMigrationId');
        const migrationComplete = localStorage.getItem('panelalphaMigrator_migrationComplete');

        // Check if migration is completed
        if (migrationComplete === 'true' && lastMigrationId) {
            // Load migration data and show complete screen
            $.ajax({
                url: panelalphaMigrator.restUrl + '/info',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
                },
                data: {
                    migration_id: lastMigrationId
                },
                success: function(data) {
                    migrationData = {
                        migration_id: lastMigrationId,
                        summary: data.summary
                    };
                    $('#migration-config-section').hide();
                    showMigrationComplete();
                },
                error: function() {
                    // If can't load data, clear the complete flag
                    localStorage.removeItem('panelalphaMigrator_migrationComplete');
                    localStorage.removeItem('panelalphaMigrator_completionTime');
                }
            });
            return;
        }

        if (!lastMigrationId) {
            return;
        }

        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/status',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: {
                migration_id: lastMigrationId
            },
            success: function(response) {
                if (response.success && response.status) {
                    handleInterruptedMigrationStatus(response.status);
                }
            },
            error: function() {
            }
        });
    }

    function handleInterruptedMigrationStatus(status) {

        if (status.backup_running) {
            autoResumeBackup(status);
            return;
        }

        if (!status.is_interrupted) {
            return;
        }

        interruptedMigrationData = status;

        displayInterruptedMigration(status);
    }

    function autoResumeBackup(status) {
        migrationData = {
            migration_id: status.migration_id
        };

        interruptedMigrationData = status;

        const savedUrl = loadDestinationUrlFromStorage();
        if (savedUrl) {
            $('#destination-url-with-token').val(savedUrl);
        }

        backupQueue = [];
        const dbInProgress = status.database && status.database.status !== 'completed' && status.database.status !== 'pending';
        const filesInProgress = status.files && status.files.status !== 'completed' && status.files.status !== 'pending';

        if (dbInProgress) {
            backupQueue.push('database');
        }
        if (filesInProgress) {
            backupQueue.push('files');
        }

        if (backupQueue.length === 0) {
            return; // Nothing to resume
        }

        $('#progress-section').hide();
        $('#interrupted-migration-section').hide();
        $('#migration-config-section').hide(); // Migration Configuration section

        backupInProgress = true;
        $('#start-backup').prop('disabled', true);
        $('#backup-progress-section').show();

        updateBackupProgress(0, 'Resuming backup...', 'dashicons-backup');

        processNextBackupInQueue();
    }

    function loadAndShowInterruptedMigration(migrationId) {
        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/status',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: {
                migration_id: migrationId
            },
            success: function(response) {
                if (response.success && response.status) {
                    interruptedMigrationData = response.status;
                    displayInterruptedMigration(response.status);
                }
            },
            error: function() {
                showError('Failed to load migration status');
            }
        });
    }

    function displayInterruptedMigration(status) {
        $('#interrupted-db-section').hide();
        $('#interrupted-files-section').hide();

        $('#interrupted-migration-id').text(status.migration_id);
        $('#interrupted-last-activity').text(formatDate(status.last_activity));

        if (status.database && status.database.status !== 'completed' && status.database.status !== 'pending') {
            const dbText = `${status.database.sent_chunks} / ${status.database.total_chunks} chunks sent (${status.database.completed_tables} / ${status.database.total_tables} tables)`;
            $('#interrupted-db-text').text(dbText);
            $('#interrupted-db-progress').css('width', status.database.percentage + '%');
            $('#interrupted-db-percentage').text(Math.round(status.database.percentage) + '%');
            $('#interrupted-db-section').show();
        }

        if (status.files && status.files.status !== 'completed' && status.files.status !== 'pending') {
            const filesText = `${status.files.sent_chunks} / ${status.files.total_chunks} chunks sent (${status.files.completed_files} / ${status.files.total_files} files)`;
            $('#interrupted-files-text').text(filesText);
            $('#interrupted-files-progress').css('width', status.files.percentage + '%');
            $('#interrupted-files-percentage').text(Math.round(status.files.percentage) + '%');
            $('#interrupted-files-section').show();
        }

        $('#interrupted-migration-section').fadeIn();
        $('#migration-config-section').fadeIn();
    }

    function handleResumeBackup() {
        if (!interruptedMigrationData || !interruptedMigrationData.migration_id) {
            showError('No interrupted migration data available.');
            return;
        }

        let destinationUrl;
        let token;
        
        const fullUrl = $('#destination-url-with-token').val().trim();
        if (fullUrl) {
            const parsed = parseDestinationUrl(fullUrl);
            destinationUrl = parsed.destinationUrl;
            token = parsed.token;
        }
        
        if (!destinationUrl && interruptedMigrationData.destination_url) {
            destinationUrl = interruptedMigrationData.destination_url;
        }

        if (!destinationUrl) {
            showError('Destination URL not found. Please paste the URL with token.');
            return;
        }

        if (!token) {
            showError('Token not found in URL. Please paste complete URL from destination.');
            return;
        }

        panelalphaMigrator.token = token;

        migrationData = {
            migration_id: interruptedMigrationData.migration_id
        };

        $('#interrupted-migration-section').fadeOut();
        $('#progress-section').hide();
        $('#migration-config-section').hide();

        backupQueue = [];
        const status = interruptedMigrationData;

        if (status.database && status.database.status !== 'completed' && status.database.status !== 'pending') {
            backupQueue.push('database');
        }
        if (status.files && status.files.status !== 'completed' && status.files.status !== 'pending') {
            backupQueue.push('files');
        }

        if (backupQueue.length === 0) {
            showError('No interrupted backups to resume.');
            return;
        }

        saveBackupStateToLocalStorage(migrationData.migration_id, backupQueue.join(','));

        backupInProgress = true;
        $('#start-backup').prop('disabled', true);
        $('#backup-progress-section').fadeIn();

        updateBackupProgress(0, 'Resuming backup...', 'dashicons-backup');

        startBackupOnServer(migrationData.migration_id, function() {
            processNextBackupInQueue();
        }, function(errorMessage) {
            handleBackupError(errorMessage);
        });
    }

    function handleResumeDatabaseBackup() {
        if (!interruptedMigrationData || !interruptedMigrationData.migration_id) {
            showError('No interrupted migration data available.');
            return;
        }

        const fullUrl = $('#destination-url-with-token').val().trim();
        const { destinationUrl, token } = parseDestinationUrl(fullUrl);
        
        if (!destinationUrl) {
            showError('Please enter destination WordPress URL.');
            return;
        }

        if (token) {
            panelalphaMigrator.token = token;
        }

        migrationData = {
            migration_id: interruptedMigrationData.migration_id
        };

        $('#interrupted-migration-section').fadeOut();
        saveBackupStateToLocalStorage(migrationData.migration_id, 'database');

        backupInProgress = true;
        $('#start-backup').prop('disabled', true);
        $('#backup-progress-section').fadeIn();

        updateDatabaseBackupProgress(migrationData.migration_id);
        updateBackupProgress(0, 'Resuming database backup...', 'dashicons-database-export');

        startBackupOnServer(migrationData.migration_id, function() {
            sendNextDatabaseChunk(migrationData.migration_id);
        }, function(errorMessage) {
            handleDatabaseBackupError({ responseJSON: { message: errorMessage } });
        });
    }

    function handleResumeFilesBackup() {
        if (!interruptedMigrationData || !interruptedMigrationData.migration_id) {
            showError('No interrupted migration data available.');
            return;
        }

        const fullUrl = $('#destination-url-with-token').val().trim();
        const { destinationUrl, token } = parseDestinationUrl(fullUrl);
        
        if (!destinationUrl) {
            showError('Please enter destination WordPress URL.');
            return;
        }

        if (token) {
            panelalphaMigrator.token = token;
        }

        migrationData = {
            migration_id: interruptedMigrationData.migration_id
        };

        $('#interrupted-migration-section').fadeOut();
        saveBackupStateToLocalStorage(migrationData.migration_id, 'files');

        backupInProgress = true;
        $('#start-backup').prop('disabled', true);
        $('#backup-progress-section').fadeIn();

        updateFilesBackupProgress(migrationData.migration_id);
        updateBackupProgress(0, 'Resuming files backup...', 'dashicons-upload');

        startBackupOnServer(migrationData.migration_id, function() {
            sendNextFileChunk(migrationData.migration_id);
        }, function(errorMessage) {
            handleFilesBackupError({ responseJSON: { message: errorMessage } });
        });
    }

    function handleStopBackup() {
        if (!migrationData || !migrationData.migration_id) {
            return;
        }

        if (!confirm('Are you sure you want to stop the backup? You can resume it later.')) {
            return;
        }

        backupInProgress = false;
        currentBackupType = null;
        backupQueue = [];

        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/stop',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationData.migration_id
            }),
            contentType: 'application/json',
            success: function() {
                $('#backup-progress-section').fadeOut();
                $('#start-backup').prop('disabled', false);

                loadAndShowInterruptedMigration(migrationData.migration_id);
            }
        });
    }

    function handleStopDatabaseBackup() {
        if (!migrationData || !migrationData.migration_id) {
            return;
        }

        if (!confirm('Are you sure you want to stop the database backup? You can resume it later.')) {
            return;
        }

        backupInProgress = false;

        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/stop',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationData.migration_id
            }),
            contentType: 'application/json',
            success: function() {
                $('#backup-progress-section').fadeOut();
                $('#start-db-backup').prop('disabled', false);

                loadAndShowInterruptedMigration(migrationData.migration_id);
            }
        });
    }

    function handleStopFilesBackup() {
        if (!migrationData || !migrationData.migration_id) {
            return;
        }

        if (!confirm('Are you sure you want to stop the files backup? You can resume it later.')) {
            return;
        }

        backupInProgress = false;

        $.ajax({
            url: panelalphaMigrator.restUrl + '/backup/stop',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', panelalphaMigrator.nonce);
            },
            data: JSON.stringify({
                migration_id: migrationData.migration_id
            }),
            contentType: 'application/json',
            success: function() {
                $('#files-backup-progress-section').fadeOut();
                $('#start-files-backup').prop('disabled', false);

                loadAndShowInterruptedMigration(migrationData.migration_id);
            }
        });
    }

    function saveBackupStateToLocalStorage(migrationId, backupType) {
        localStorage.setItem('panelalphaMigrator_lastMigrationId', migrationId);
        localStorage.setItem('panelalphaMigrator_lastBackupType', backupType);
    }

    function clearBackupStateFromLocalStorage(backupType) {
        if (backupType) {
            const savedType = localStorage.getItem('panelalphaMigrator_lastBackupType');
            if (savedType === backupType) {
                localStorage.removeItem('panelalphaMigrator_lastMigrationId');
                localStorage.removeItem('panelalphaMigrator_lastBackupType');
            }
        } else {
            localStorage.removeItem('panelalphaMigrator_lastMigrationId');
            localStorage.removeItem('panelalphaMigrator_lastBackupType');
        }
    }


})(jQuery);
