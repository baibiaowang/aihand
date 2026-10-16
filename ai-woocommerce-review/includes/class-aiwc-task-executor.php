<?php
if (!defined('ABSPATH')) exit;
class AIWC_Task_Executor {
    public static function init(){}
    public static function execute($id){
        if(!AIWC_Capabilities::can_execute())return new WP_Error('forbidden','You cannot execute tasks.');
        $task=AIWC_Task_Repository::get($id);if(!$task)return new WP_Error('not_found','Task not found.');
        if($task['status']!=='approved')return new WP_Error('invalid_state','Only approved tasks can execute.');
        $plan=AIWC_Task_Manager::decoded_plan($task);if(AIWC_Task_Validator::hash($plan)!==$task['plan_hash'])return new WP_Error('plan_tampered','Task plan hash mismatch.');
        $now=current_time('mysql',true);
        $claimed=AIWC_Task_Repository::update($id,array('status'=>'executing','updated_at'=>$now),array('id'=>(int)$id,'status'=>'approved'));
        if(is_wp_error($claimed)||$claimed!==1)return new WP_Error('already_claimed','Task was already claimed or could not be claimed.');
        $success=0;$failed=0;$errors=array();
        foreach($plan['changes'] as $change){
            $check=AIWC_Task_Validator::validate_change($change);
            if(is_wp_error($check)){$failed++;$errors[]=array('product_id'=>(int)$change['object_id'],'message'=>$check->get_error_message());continue;}
            $product=wc_get_product((int)$change['object_id']);
            try{
                switch($change['field']){
                    case'regular_price':$product->set_regular_price((string)$change['after']);break;
                    case'sale_price':$product->set_sale_price((string)$change['after']);break;
                    case'stock_quantity':$product->set_manage_stock(true);$product->set_stock_quantity((int)$change['after']);break;
                    case'name':$product->set_name(wp_strip_all_tags((string)$change['after']));break;
                    case'description':$product->set_description(wp_kses_post((string)$change['after']));break;
                    case'short_description':$product->set_short_description(wp_kses_post((string)$change['after']));break;
                    default:throw new Exception('Unsupported field.');
                }
                $product->save();$success++;
            }catch(Throwable $e){$failed++;$errors[]=array('product_id'=>(int)$change['object_id'],'message'=>$e->getMessage());}
        }
        $status=$failed===0?'completed':($success>0?'partial_failed':'failed');
        AIWC_Task_Repository::update($id,array('status'=>$status,'executed_at'=>$now,'completed_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true),'error_message'=>$failed?wp_json_encode($errors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null),array('id'=>(int)$id,'status'=>'executing'));
        return array('status'=>$status,'success'=>$success,'failed'=>$failed,'errors'=>$errors);
    }
}