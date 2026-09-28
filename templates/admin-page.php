<?php
/**
 * Admin Page Template
 * WordPress Migrator UI
 */

use WordpressMigrator\Helpers\Security;
use WordpressMigrator\Helpers\Translation;

$pageTitle = 'WordPress Migrator';

// Helper function for translation - use WP if available, otherwise our helper
function __t($text, $domain = 'wordpress-migrator') {
    if (function_exists('__')) {
        return __($text, $domain);
    }
    return Translation::__($text, $domain);
}

function _et($text, $domain = 'wordpress-migrator') {
    if (function_exists('_e')) {
        _e($text, $domain);
    } else {
        echo Translation::__($text, $domain);
    }
}

function esc($text) {
    if (function_exists('esc_html')) {
        return esc_html($text);
    }
    return Security::escHtml($text);
}
?>
<div class="wrap wp-migrator-admin">
    <h1><?php echo esc($pageTitle); ?></h1>

    <!-- Mode Selection Section -->
    <div class="wp-migrator-section" id="mode-selection-section">
        <div class="mode-selection-inline">
            <label class="mode-icon-option" title="<?php _et('Source - Send data to destination', 'wordpress-migrator'); ?>">
                <input type="radio" name="migration-mode" value="source" id="mode-source" checked>
                <span class="dashicons dashicons-upload"></span>
            </label>
            <label class="mode-icon-option" title="<?php _et('Destination - Receive data from source', 'wordpress-migrator'); ?>">
                <input type="radio" name="migration-mode" value="destination" id="mode-destination">
                <span class="dashicons dashicons-download"></span>
            </label>
        </div>
    </div>

    <!-- Destination Mode Section -->
    <div class="wp-migrator-section" id="destination-mode-section" style="display: none;">
        <h2><?php _et('Destination Configuration', 'wordpress-migrator'); ?></h2>
        <div class="wp-migrator-card">
            <p class="description">
                <?php _et('Copy the URL below and paste it into the Source WordPress to start the migration.', 'wordpress-migrator'); ?>
            </p>
            <div class="destination-url-display">
                <div class="form-group">
                    <label><?php _et('Your Destination URL with Token:', 'wordpress-migrator'); ?></label>
                    <div class="url-copy-container">
                        <input type="text" id="destination-url-display" class="large-text" readonly />
                        <button type="button" id="copy-destination-url" class="button button-primary">
                            <span class="dashicons dashicons-clipboard"></span>
                            <?php _et('Copy', 'wordpress-migrator'); ?>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <button type="button" id="generate-new-token" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        <?php _et('Generate New Token', 'wordpress-migrator'); ?>
                    </button>
                    <p class="description">
                        <?php _et('Generate a new security token. Note: This will invalidate the current URL.', 'wordpress-migrator'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="wp-migrator-container" id="source-mode-container">
        <!-- Migration Configuration Section -->
        <div class="wp-migrator-section" id="migration-config-section">
            <div class="section-header">
                <h2><?php _et('Migration Configuration', 'wordpress-migrator'); ?></h2>
                <button type="button" id="toggle-advanced-options" class="button-icon" title="<?php _et('Advanced Options', 'wordpress-migrator'); ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                </button>
            </div>

            <div class="wp-migrator-card">
                <div class="wp-migrator-form">
                    <div class="form-group">
                        <label for="destination-url-with-token">
                            <?php _et('Destination URL (paste from destination)', 'wordpress-migrator'); ?>
                            <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="destination-url-with-token"
                            class="regular-text"
                            placeholder="<?php _et('Paste the complete URL with token from destination WordPress...', 'wordpress-migrator'); ?>"
                        />
                        <p class="description">
                            <?php _et('Paste the complete URL with token copied from destination WordPress.', 'wordpress-migrator'); ?>
                        </p>
                    </div>

                    <!-- Advanced Options (Hidden by Default) -->
                    <div id="advanced-options" style="display: none;">
                        <div class="advanced-options-divider"></div>

                        <div class="form-group">
                            <label for="destination-ip">
                                <?php _et('Destination IP Address (Optional)', 'wordpress-migrator'); ?>
                            </label>
                            <input
                                type="text"
                                id="destination-ip"
                                class="regular-text"
                                placeholder="<?php _et('e.g., 192.168.1.100', 'wordpress-migrator'); ?>"
                            />
                            <p class="description">
                                <?php _et('If the destination domain is not yet configured, enter the server IP address. The plugin will use this IP for connections while preserving the domain in requests.', 'wordpress-migrator'); ?>
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="exclusions">
                                <?php _et('Additional Exclusions (Optional)', 'wordpress-migrator'); ?>
                            </label>
                            <textarea
                                id="exclusions"
                                class="large-text"
                                rows="3"
                                placeholder="*.log, node_modules, *.tmp"
                            ></textarea>
                            <p class="description">
                                <?php _et('Enter additional items to exclude (comma-separated). Examples: *.log, node_modules, *.tmp', 'wordpress-migrator'); ?><br>
                                <strong><?php _et('Always excluded:', 'wordpress-migrator'); ?></strong> .htaccess, wp-config.php, wordpress-migrator
                            </p>
                        </div>

                        <div class="form-group">
                            <label>
                                <?php _et('Backup Content', 'wordpress-migrator'); ?>
                                <span class="required">*</span>
                            </label>
                            <div class="backup-options">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="backup-database" checked>
                                    <span class="dashicons dashicons-database"></span>
                                    <?php _et('Database', 'wordpress-migrator'); ?>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="backup-files" checked>
                                    <span class="dashicons dashicons-media-document"></span>
                                    <?php _et('Files', 'wordpress-migrator'); ?>
                                </label>
                            </div>
                            <p class="description">
                                <?php _et('Select what you want to backup. At least one option must be selected.', 'wordpress-migrator'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" id="start-scan" class="button button-primary button-large">
                            <span class="dashicons dashicons-backup"></span>
                            <?php _et('Start Migration', 'wordpress-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    

        <!-- Progress Section -->
        <div class="wp-migrator-section" id="progress-section" style="display: none;">
            <h2><?php _et('Scan Progress', 'wordpress-migrator'); ?></h2>

            <div class="wp-migrator-card">
                <div class="wp-migrator-progress">
                    <div class="progress-status">
                        <span class="dashicons dashicons-update spin"></span>
                        <span id="progress-message"><?php _et('Initializing...', 'wordpress-migrator'); ?></span>
                    </div>

                    <div class="progress-bar-container">
                        <div class="progress-bar">
                            <div class="progress-bar-fill" id="progress-bar-fill" style="width: 0%"></div>
                        </div>
                        <div class="progress-percentage" id="progress-percentage">0%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unified Backup Progress Section -->
        <div class="wp-migrator-section" id="backup-progress-section" style="display: none;">
            <h2><?php _et('Backup Progress', 'wordpress-migrator'); ?></h2>

            <div class="wp-migrator-card">
                <div class="wp-migrator-progress">
                    <div class="progress-status">
                        <span class="dashicons dashicons-backup" id="backup-progress-icon"></span>
                        <span id="backup-progress-message"><?php _et('Starting backup...', 'wordpress-migrator'); ?></span>
                    </div>

                    <div class="progress-bar-container">
                        <div class="progress-bar">
                            <div class="progress-bar-fill" id="backup-progress-bar-fill" style="width: 0%"></div>
                        </div>
                        <div class="progress-percentage" id="backup-progress-percentage">0%</div>
                    </div>

                    <div class="progress-details" id="backup-progress-details" style="margin-top: 10px; font-size: 13px; color: #666;">
                    </div>

                    <div class="progress-actions" style="margin-top: 15px;">
                        <button type="button" id="stop-backup" class="button button-secondary">
                            <span class="dashicons dashicons-controls-pause"></span>
                            <?php _et('Stop Backup', 'wordpress-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>


        <!-- Interrupted Migration Section -->
        <div class="wp-migrator-section" id="interrupted-migration-section" style="display: none;">
            <h2><?php _et('Interrupted Migration Detected', 'wordpress-migrator'); ?></h2>

            <div class="wp-migrator-card">
                <div class="interrupted-migration-info">
                
                    <div class="interrupted-details">
                        <div class="detail-row">
                            <span class="detail-label"><?php _et('Migration ID:', 'wordpress-migrator'); ?></span>
                            <span class="detail-value" id="interrupted-migration-id">-</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><?php _et('Last Activity:', 'wordpress-migrator'); ?></span>
                            <span class="detail-value" id="interrupted-last-activity">-</span>
                        </div>
                    </div>

                    <!-- Database Progress -->
                    <div class="interrupted-progress-section" id="interrupted-db-section" style="display: none;">
                        <h3><?php _et('Database Backup Progress', 'wordpress-migrator'); ?></h3>
                        <div class="interrupted-progress-info">
                            <div class="progress-text" id="interrupted-db-text">-</div>
                            <div class="progress-bar-container">
                                <div class="progress-bar">
                                    <div class="progress-bar-fill" id="interrupted-db-progress" style="width: 0%"></div>
                                </div>
                                <div class="progress-percentage" id="interrupted-db-percentage">0%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Files Progress -->
                    <div class="interrupted-progress-section" id="interrupted-files-section" style="display: none;">
                        <h3><?php _et('Files Backup Progress', 'wordpress-migrator'); ?></h3>
                        <div class="interrupted-progress-info">
                            <div class="progress-text" id="interrupted-files-text">-</div>
                            <div class="progress-bar-container">
                                <div class="progress-bar">
                                    <div class="progress-bar-fill" id="interrupted-files-progress" style="width: 0%"></div>
                                </div>
                                <div class="progress-percentage" id="interrupted-files-percentage">0%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Unified Resume Button -->
                    <div class="progress-actions" style="margin-top: 15px;">
                        <button type="button" id="resume-backup" class="button button-primary">
                            <span class="dashicons dashicons-controls-play"></span>
                            <?php _et('Resume Backup', 'wordpress-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Migration Complete Section -->
        <div class="wp-migrator-section" id="migration-complete-section" style="display: none;">
            <div class="wp-migrator-card migration-complete-card">
                <div class="migration-complete-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h2><?php _et('Migration Completed Successfully!', 'wordpress-migrator'); ?></h2>

                <div class="migration-summary">
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="dashicons dashicons-media-document"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php _et('Files Migrated', 'wordpress-migrator'); ?></div>
                                <div class="summary-value" id="complete-files-count">-</div>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span class="dashicons dashicons-database"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php _et('Tables Migrated', 'wordpress-migrator'); ?></div>
                                <div class="summary-value" id="complete-tables-count">-</div>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span class="dashicons dashicons-chart-area"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php _et('Total Data', 'wordpress-migrator'); ?></div>
                                <div class="summary-value" id="complete-total-size">-</div>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span class="dashicons dashicons-clock"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php _et('Duration', 'wordpress-migrator'); ?></div>
                                <div class="summary-value" id="complete-duration">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="migration-complete-actions">
                    <button type="button" id="start-new-migration" class="button button-primary button-hero">
                        <span class="dashicons dashicons-update"></span>
                        <?php _et('Start New Migration', 'wordpress-migrator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Error Section (dynamically added when needed) -->
        <div id="error-section"></div>
    </div>
</div>
