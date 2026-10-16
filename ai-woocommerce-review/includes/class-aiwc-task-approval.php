<?php
if (!defined('ABSPATH')) exit;
class AIWC_Task_Approval {
    public static function init(){}
    public static function reject($id,$reason){
        if(!AIWC_Capabilities::can_approve())return new WP_Error('forbidden','You cannot reject tasks.');
        $task=AIWC_Task_Repository::get($id);if(!$task)return new WP_Error('not_found','Task not found.');
        if($task['status']!=='pending_review')return new WP_Error('invalid_state','Only pending tasks can be rejected.');
        $reason=sanitize_textarea_field($reason);if($reason==='')return new WP_Error('missing_reason','A rejection reason is required.');
        return AIWC_Task_Repository::update($id,array('status'=>'rejected','rejected_by'=>get_current_user_id(),'rejected_at'=>current_time('mysql',true),'rejection_reason'=>$reason,'updated_at'=>current_time('mysql',true)),array('id'=>(int)$id,'status'=>'pending_review'));
    }
    public static function approve($id){
        if(!AIWC_Capabilities::can_approve())return new WP_Error('forbidden','You cannot approve tasks.');
        $task=AIWC_Task_Repository::get($id);if(!$task)return new WP_Error('not_found','Task not found.');
        if($task['status']!=='pending_review')return new WP_Error('invalid_state','Only pending tasks can be approved.');
        if($task['expires_at']&&strtotime($task['expires_at'].' UTC')<time()){AIWC_Task_Repository::update($id,array('status'=>'expired','updated_at'=>current_time('mysql',true)),array('id'=>(int)$id,'status'=>'pending_review'));return new WP_Error('expired','Task has expired.');}
        $plan=AIWC_Task_Manager::decoded_plan($task);if(AIWC_Task_Validator::hash($plan)!==$task['plan_hash'])return new WP_Error('plan_tampered','Task plan hash mismatch.');
        foreach($plan['changes'] as $change){$check=AIWC_Task_Validator::validate_change($change);if(is_wp_error($check)){AIWC_Task_Repository::update($id,array('status'=>'stale','updated_at'=>current_time('mysql',true)),array('id'=>(int)$id,'status'=>'pending_review'));return $check;}}
        $ok=AIWC_Task_Repository::update($id,array('status'=>'approved','approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)),array('id'=>(int)$id,'status'=>'pending_review'));
        return is_wp_error($ok)?$ok:true;
    }
}