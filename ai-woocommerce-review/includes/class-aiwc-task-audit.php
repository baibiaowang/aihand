<?php
if (!defined('ABSPATH')) exit;
class AIWC_Task_Audit {
    public static function log($task_id,$action,$from=null,$to=null,$actor_type='system',$actor_id=null,$details=array(),$plan_hash=null){
        global $wpdb;$table=$wpdb->prefix.'aiwc_task_audit';
        $wpdb->insert($table,array(
            'task_id'=>(int)$task_id,'actor_type'=>sanitize_key($actor_type),'actor_id'=>$actor_id?(int)$actor_id:null,
            'action'=>sanitize_key($action),'from_status'=>$from?sanitize_key($from):null,'to_status'=>$to?sanitize_key($to):null,
            'plan_hash'=>$plan_hash?sanitize_text_field($plan_hash):null,
            'details'=>$details?wp_json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
            'created_at'=>current_time('mysql',true)
        ));
    }
    public static function list($task_id){
        global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."aiwc_task_audit WHERE task_id=%d ORDER BY id ASC",(int)$task_id),ARRAY_A);
    }
}