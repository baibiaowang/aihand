<?php
if (!defined('ABSPATH')) exit;
class AIWC_Installer {
    public static function activate(){
        global $wpdb; $charset=$wpdb->get_charset_collate();
        $tasks=$wpdb->prefix.'aiwc_tasks'; $audit=$wpdb->prefix.'aiwc_task_audit';
        $sql1="CREATE TABLE {$tasks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_uuid varchar(64) NOT NULL, schema_version int unsigned NOT NULL DEFAULT 1,
            task_type varchar(64) NOT NULL, title text NOT NULL, description longtext NULL,
            status varchar(32) NOT NULL DEFAULT 'pending_review', risk_level varchar(16) NOT NULL DEFAULT 'low',
            created_by varchar(32) NOT NULL DEFAULT 'ai', created_at datetime NOT NULL, updated_at datetime NOT NULL,
            expires_at datetime NULL, approved_by bigint(20) unsigned NULL, approved_at datetime NULL,
            rejected_by bigint(20) unsigned NULL, rejected_at datetime NULL, rejection_reason text NULL,
            executed_at datetime NULL, completed_at datetime NULL, error_message text NULL,
            plan_json longtext NOT NULL, plan_hash char(64) NOT NULL, PRIMARY KEY(id),
            UNIQUE KEY task_uuid(task_uuid), KEY status(status), KEY created_at(created_at), KEY plan_hash(plan_hash)
        ) {$charset};";
        $sql2="CREATE TABLE {$audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT, task_id bigint(20) unsigned NOT NULL,
            actor_type varchar(32) NOT NULL, actor_id bigint(20) unsigned NULL, action varchar(64) NOT NULL,
            from_status varchar(32) NULL, to_status varchar(32) NULL, plan_hash char(64) NULL,
            details longtext NULL, created_at datetime NOT NULL, PRIMARY KEY(id),
            KEY task_id(task_id), KEY action(action), KEY created_at(created_at)
        ) {$charset};";
        require_once ABSPATH.'wp-admin/includes/upgrade.php'; dbDelta($sql1); dbDelta($sql2);
        AIWC_Capabilities::add_caps(); update_option('aiwc_review_db_version',AIWC_REVIEW_VERSION,false);
    }
}
