<?php
if (!defined('ABSPATH')) exit;

class AIWC_Installer {
    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'aiwc_tasks';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_uuid varchar(64) NOT NULL,
            schema_version int unsigned NOT NULL DEFAULT 1,
            task_type varchar(64) NOT NULL,
            title text NOT NULL,
            description longtext NULL,
            status varchar(32) NOT NULL DEFAULT 'pending_review',
            risk_level varchar(16) NOT NULL DEFAULT 'low',
            created_by varchar(32) NOT NULL DEFAULT 'ai',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            expires_at datetime NULL,
            approved_by bigint(20) unsigned NULL,
            approved_at datetime NULL,
            rejected_by bigint(20) unsigned NULL,
            rejected_at datetime NULL,
            rejection_reason text NULL,
            executed_at datetime NULL,
            completed_at datetime NULL,
            error_message text NULL,
            plan_json longtext NOT NULL,
            plan_hash char(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY task_uuid (task_uuid),
            KEY status (status),
            KEY created_at (created_at),
            KEY plan_hash (plan_hash)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        AIWC_Capabilities::add_caps();
        update_option('aiwc_review_db_version', AIWC_REVIEW_VERSION, false);
    }
}
