<?php
/**
 * Plugin Name: AI WooCommerce Review
 * Description: V1 human approval gate for AI-proposed WooCommerce product changes. AI creates plans; a WordPress user reviews and approves them before execution.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AI WooCommerce
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIWC_REVIEW_VERSION', '1.0.0');
define('AIWC_REVIEW_FILE', __FILE__);
define('AIWC_REVIEW_DIR', plugin_dir_path(__FILE__));
define('AIWC_REVIEW_URL', plugin_dir_url(__FILE__));

require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-installer.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-capabilities.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-task-repository.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-task-validator.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-task-manager.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-task-approval.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-task-executor.php';
require_once AIWC_REVIEW_DIR . 'includes/class-aiwc-rest-tasks.php';
require_once AIWC_REVIEW_DIR . 'admin/class-aiwc-admin-tasks.php';

register_activation_hook(__FILE__, array('AIWC_Installer', 'activate'));

add_action('plugins_loaded', function () {
    AIWC_Capabilities::init();
    AIWC_Task_Manager::init();
    AIWC_Task_Approval::init();
    AIWC_Task_Executor::init();
    AIWC_REST_Tasks::init();
    AIWC_Admin_Tasks::init();
});
