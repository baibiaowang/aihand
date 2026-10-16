<?php
if (!defined('ABSPATH')) exit;
class AIWC_Admin_Tasks {
    public static function init(){add_action('admin_menu',array(__CLASS__,'menu'));add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));}
    public static function menu(){add_menu_page('AI 待审核','AI 运营','aiwc_view_tasks','aiwc-review',array(__CLASS__,'page'),'dashicons-visibility',56);}
    public static function assets($hook){if($hook!=='toplevel_page_aiwc-review')return;wp_enqueue_style('aiwc-review',AIWC_REVIEW_URL.'assets/css/review.css',array(),AIWC_REVIEW_VERSION);wp_enqueue_script('aiwc-review',AIWC_REVIEW_URL.'assets/js/review.js',array(),AIWC_REVIEW_VERSION,true);wp_localize_script('aiwc-review','AIWCReview',array('api'=>esc_url_raw(rest_url('aiwc/v1/tasks')),'nonce'=>wp_create_nonce('wp_rest'),'canApprove'=>AIWC_Capabilities::can_approve(),'canExecute'=>AIWC_Capabilities::can_execute()));}
    public static function page(){if(!AIWC_Capabilities::can_view())wp_die('Permission denied.');?>
    <div class="wrap aiwc-wrap"><h1>AI 待审核任务</h1><p class="description">AI 只能提出变更计划；只有人工批准并通过服务器二次校验后，才会修改 WooCommerce。</p><div id="aiwc-app"><div class="aiwc-toolbar"><button class="button" data-filter="">全部</button><button class="button" data-filter="pending_review">待审核</button><button class="button" data-filter="approved">已批准</button><button class="button" data-filter="completed">已完成</button><button class="button" data-filter="failed">失败</button></div><div id="aiwc-task-list"><div class="aiwc-loading">正在加载…</div></div><div id="aiwc-detail" hidden></div></div></div>
    <?php }
}