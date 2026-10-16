<?php
if (!defined('ABSPATH')) exit;
class AIWC_Task_Manager {
    public static function init(){}
    public static function create($plan,$created_by='ai'){
        $valid=AIWC_Task_Validator::validate_plan($plan);if(is_wp_error($valid))return $valid;
        if(!function_exists('wc_get_product'))return new WP_Error('woocommerce_missing','WooCommerce is required.');
        foreach($plan['changes'] as &$change){
            $product=wc_get_product((int)$change['object_id']);if(!$product)return new WP_Error('product_missing','Product does not exist: '.$change['object_id']);
            $actual=AIWC_Task_Validator::current_product_value($product,$change['field']);if(is_wp_error($actual))return $actual;
            if((string)$actual!==(string)$change['before'])return new WP_Error('before_mismatch','AI before value does not match current server value.',array('product_id'=>(int)$change['object_id'],'server_value'=>$actual));
            $change['server_snapshot']=array('field'=>$change['field'],'before'=>$actual);
        } unset($change);
        $plan['changes']=array_values($plan['changes']);$uuid=wp_generate_uuid4();$hash=AIWC_Task_Validator::hash($plan);$now=current_time('mysql',true);
        $data=array('task_uuid'=>$uuid,'schema_version'=>1,'task_type'=>sanitize_key($plan['task_type']),'title'=>sanitize_text_field($plan['title']),'description'=>isset($plan['description'])?wp_kses_post($plan['description']):'','status'=>'pending_review','risk_level'=>self::risk_level($plan),'created_by'=>'ai','created_at'=>$now,'updated_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS),'plan_json'=>wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'plan_hash'=>$hash);
        $id=AIWC_Task_Repository::insert($data);if(is_wp_error($id))return $id;return AIWC_Task_Repository::get($id);
    }
    public static function risk_level($plan){$n=count($plan['changes']);if($n>=100)return'high';if($plan['task_type']==='product_price_update'&&$n>=20)return'high';return in_array($plan['task_type'],array('product_price_update','product_stock_update'),true)?'medium':'low';}
    public static function decoded_plan($task){$p=json_decode($task['plan_json'],true);return is_array($p)?$p:array();}
}