<?php
/**
 * Plugin Name: AI Hand (Store Operations)
 * Description: MCP tools that let an AI agent run a WooCommerce store with guard rails, two-step human review, undo and audit.
 * Version: 1.3.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Hark
 * License: GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes/hsm-core.php';
require_once __DIR__ . '/includes/hsm-review.php';
HSM_Registry::$ns='ai-hand/'; HSM_Registry::$cat='ai-hand'; HSM_Registry::$version='1.3.0';
$hsm_ns=HSM_Registry::$ns;$hsm_pfx=str_replace('/','-',$hsm_ns);
foreach(array('hsm-woo.php','abilities-core.php','seo-frontend.php','redirects.php','feed.php','media.php','abilities-store.php','abilities-orders.php','shipments.php','order-export.php','invoice.php','order-numbers.php','content.php','support.php','conversion.php','post-purchase.php','marketing.php','followups.php','shipping-rules.php','abilities-seo.php','abilities-site.php','abilities-reviews.php') as $f)require_once __DIR__.'/includes/'.$f;
add_action('wp_abilities_api_categories_init',function(){wp_register_ability_category('ai-hand',array('label'=>'AI Hand','description'=>'WooCommerce store operations for AI agents.'));});
add_action('mcp_adapter_init',function($adapter)use($hsm_ns){$tools=array_values(array_filter(array_keys(HSM_Registry::$abilities),function($n)use($hsm_ns){return 0===strpos($n,$hsm_ns);}));$adapter->create_server('ai-hand','ai-hand','mcp','AI Hand','Run a WooCommerce store safely with human review.','1.2.0',array(\WP\MCP\Transport\HttpTransport::class),\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,null,$tools,array(),array(),'hsm_transport_permission');});
if(!function_exists('hsm_transport_permission')) { function hsm_transport_permission(){if(!is_user_logged_in())return false;foreach(array('manage_woocommerce','manage_options','edit_posts','upload_files') as $cap)if(current_user_can($cap))return true;return false;} }
add_filter('mcp_adapter_tools_list',function($tools)use($hsm_ns,$hsm_pfx){if(!function_exists('wp_get_ability'))return $tools;return array_values(array_filter((array)$tools,function($t)use($hsm_ns,$hsm_pfx){$name=is_object($t)&&method_exists($t,'getName')?$t->getName():(is_array($t)?($t['name']??''):'');if(0!==strpos($name,$hsm_pfx))return true;$ability=wp_get_ability($hsm_ns.substr($name,strlen($hsm_pfx)));return !$ability||true===$ability->check_permissions(array());}));});
register_activation_hook(__FILE__,function(){hsm_redirects_install();hsm_feed_rewrite();flush_rewrite_rules();});
$hsm_ver=HSM_Registry::$version;add_action('init',function()use($hsm_ver){if(get_option('ai_hand_version')!==$hsm_ver){flush_rewrite_rules(false);update_option('ai_hand_version',$hsm_ver);}},99);
