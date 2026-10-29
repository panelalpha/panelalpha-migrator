<?php
/**
 * Admin Page Template
 * PanelAlpha Migrator UI
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap panelalpha-migrator-admin">
    <h1><?php esc_html_e('PanelAlpha Migrator', 'panelalpha-migrator'); ?></h1>

    <!-- Mode Selection Section -->
    <div class="panelalpha-migrator-section" id="mode-selection-section">
        <div class="mode-selection-inline">
            <label class="mode-icon-option" title="<?php esc_attr_e('Source - Send data to destination', 'panelalpha-migrator'); ?>">
                <input type="radio" name="migration-mode" value="source" id="mode-source" checked>
                <span class="dashicons dashicons-upload"></span>
            </label>
            <label class="mode-icon-option" title="<?php esc_attr_e('Destination - Receive data from source', 'panelalpha-migrator'); ?>">
                <input type="radio" name="migration-mode" value="destination" id="mode-destination">
                <span class="dashicons dashicons-download"></span>
            </label>
        </div>
    </div>

    <!-- Destination Mode Section -->
    <div class="panelalpha-migrator-section" id="destination-mode-section" style="display: none;">
        <h2><?php esc_html_e('Destination Configuration', 'panelalpha-migrator'); ?></h2>
        <div class="panelalpha-migrator-card">
            <p class="description">
                <?php esc_html_e('Copy the URL below and paste it into the Source WordPress to start the migration.', 'panelalpha-migrator'); ?>
            </p>
            <div class="destination-url-display">
                <div class="form-group">
                    <label><?php esc_html_e('Your Destination URL with Token:', 'panelalpha-migrator'); ?></label>
                    <div class="url-copy-container">
                        <input type="text" id="destination-url-display" class="large-text" readonly />
                        <button type="button" id="copy-destination-url" class="button button-primary">
                            <span class="dashicons dashicons-clipboard"></span>
                            <?php esc_html_e('Copy', 'panelalpha-migrator'); ?>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <button type="button" id="generate-new-token" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Generate New Token', 'panelalpha-migrator'); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e('Generate a new security token. Note: This will invalidate the current URL.', 'panelalpha-migrator'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="panelalpha-migrator-container" id="source-mode-container">
        <!-- Migration Configuration Section -->
        <div class="panelalpha-migrator-section" id="migration-config-section">
            <div class="section-header">
                <h2><?php esc_html_e('Migration Configuration', 'panelalpha-migrator'); ?></h2>
                <button type="button" id="toggle-advanced-options" class="button-icon" title="<?php esc_attr_e('Advanced Options', 'panelalpha-migrator'); ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                </button>
            </div>

            <div class="panelalpha-migrator-card">
                <div class="panelalpha-migrator-form">
                    <div class="form-group">
                        <label for="destination-url-with-token">
                            <?php esc_html_e('Destination URL (paste from destination)', 'panelalpha-migrator'); ?>
                            <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="destination-url-with-token"
                            class="regular-text"
                            placeholder="<?php esc_attr_e('Paste the complete URL with token from destination WordPress...', 'panelalpha-migrator'); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e('Paste the complete URL with token copied from destination WordPress.', 'panelalpha-migrator'); ?>
                        </p>
                    </div>

                    <!-- Advanced Options (Hidden by Default) -->
                    <div id="advanced-options" style="display: none;">
                        <div class="advanced-options-divider"></div>

                        <div class="form-group">
                            <label for="destination-ip">
                                <?php esc_html_e('Destination IP Address (Optional)', 'panelalpha-migrator'); ?>
                            </label>
                            <input
                                type="text"
                                id="destination-ip"
                                class="regular-text"
                                placeholder="<?php esc_attr_e('e.g., 192.168.1.100', 'panelalpha-migrator'); ?>"
                            />
                            <p class="description">
                                <?php esc_html_e('If the destination domain is not yet configured, enter the server IP address. The plugin will use this IP for connections while preserving the domain in requests.', 'panelalpha-migrator'); ?>
                            </p>
                            <p>
                                <label for="destination-insecure-tls">
                                    <input type="checkbox" id="destination-insecure-tls" disabled />
                                    <?php esc_html_e('Do not verify the HTTPS certificate (only with a manual IP)', 'panelalpha-migrator'); ?>
                                </label>
                            </p>
                            <p class="description">
                                <?php esc_html_e('The connection is not checked against a certificate. Use it only when the destination has no valid certificate for the domain yet.', 'panelalpha-migrator'); ?>
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="exclusions">
                                <?php esc_html_e('Additional Exclusions (Optional)', 'panelalpha-migrator'); ?>
                            </label>
                            <textarea
                                id="exclusions"
                                class="large-text"
                                rows="3"
                                placeholder="*.log, node_modules, *.tmp"
                            ></textarea>
                            <p class="description">
                                <?php esc_html_e('Enter additional items to exclude (comma-separated). Examples: *.log, node_modules, *.tmp', 'panelalpha-migrator'); ?><br>
                                <strong><?php esc_html_e('Always excluded:', 'panelalpha-migrator'); ?></strong> .htaccess, wp-config.php, panelalpha-migrator
                            </p>
                        </div>

                        <div class="form-group">
                            <label>
                                <?php esc_html_e('Backup Content', 'panelalpha-migrator'); ?>
                                <span class="required">*</span>
                            </label>
                            <div class="backup-options">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="backup-database" checked>
                                    <span class="dashicons dashicons-database"></span>
                                    <?php esc_html_e('Database', 'panelalpha-migrator'); ?>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="backup-files" checked>
                                    <span class="dashicons dashicons-media-document"></span>
                                    <?php esc_html_e('Files', 'panelalpha-migrator'); ?>
                                </label>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Select what you want to backup. At least one option must be selected.', 'panelalpha-migrator'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" id="start-scan" class="button button-primary button-large">
                            <span class="dashicons dashicons-backup"></span>
                            <?php esc_html_e('Start Migration', 'panelalpha-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    

        <!-- Progress Section -->
        <div class="panelalpha-migrator-section" id="progress-section" style="display: none;">
            <h2><?php esc_html_e('Scan Progress', 'panelalpha-migrator'); ?></h2>

            <div class="panelalpha-migrator-card">
                <div class="panelalpha-migrator-progress">
                    <div class="progress-status">
                        <span class="dashicons dashicons-update spin"></span>
                        <span id="progress-message"><?php esc_html_e('Initializing...', 'panelalpha-migrator'); ?></span>
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
        <div class="panelalpha-migrator-section" id="backup-progress-section" style="display: none;">
            <h2><?php esc_html_e('Backup Progress', 'panelalpha-migrator'); ?></h2>

            <div class="panelalpha-migrator-card">
                <div class="panelalpha-migrator-progress">
                    <div class="progress-status">
                        <span class="dashicons dashicons-backup" id="backup-progress-icon"></span>
                        <span id="backup-progress-message"><?php esc_html_e('Starting backup...', 'panelalpha-migrator'); ?></span>
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
                            <?php esc_html_e('Stop Backup', 'panelalpha-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>


        <!-- Interrupted Migration Section -->
        <div class="panelalpha-migrator-section" id="interrupted-migration-section" style="display: none;">
            <h2><?php esc_html_e('Interrupted Migration Detected', 'panelalpha-migrator'); ?></h2>

            <div class="panelalpha-migrator-card">
                <div class="interrupted-migration-info">
                
                    <div class="interrupted-details">
                        <div class="detail-row">
                            <span class="detail-label"><?php esc_html_e('Migration ID:', 'panelalpha-migrator'); ?></span>
                            <span class="detail-value" id="interrupted-migration-id">-</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><?php esc_html_e('Last Activity:', 'panelalpha-migrator'); ?></span>
                            <span class="detail-value" id="interrupted-last-activity">-</span>
                        </div>
                    </div>

                    <!-- Database Progress -->
                    <div class="interrupted-progress-section" id="interrupted-db-section" style="display: none;">
                        <h3><?php esc_html_e('Database Backup Progress', 'panelalpha-migrator'); ?></h3>
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
                        <h3><?php esc_html_e('Files Backup Progress', 'panelalpha-migrator'); ?></h3>
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
                            <?php esc_html_e('Resume Backup', 'panelalpha-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Migration Complete Section -->
        <div class="panelalpha-migrator-section" id="migration-complete-section" style="display: none;">
            <div class="panelalpha-migrator-card migration-complete-card">
                <div class="migration-complete-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h2><?php esc_html_e('Migration Completed Successfully!', 'panelalpha-migrator'); ?></h2>

                <div class="migration-summary">
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="dashicons dashicons-media-document"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php esc_html_e('Files Migrated', 'panelalpha-migrator'); ?></div>
                                <div class="summary-value" id="complete-files-count">-</div>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span class="dashicons dashicons-database"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php esc_html_e('Tables Migrated', 'panelalpha-migrator'); ?></div>
                                <div class="summary-value" id="complete-tables-count">-</div>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span class="dashicons dashicons-chart-area"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php esc_html_e('Total Data', 'panelalpha-migrator'); ?></div>
                                <div class="summary-value" id="complete-total-size">-</div>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span class="dashicons dashicons-clock"></span>
                            <div class="summary-content">
                                <div class="summary-label"><?php esc_html_e('Duration', 'panelalpha-migrator'); ?></div>
                                <div class="summary-value" id="complete-duration">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="migration-complete-actions">
                    <button type="button" id="start-new-migration" class="button button-primary button-hero">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Start New Migration', 'panelalpha-migrator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Error Section (dynamically added when needed) -->
        <div id="error-section"></div>
    </div>
</div>
