<?php
if (!defined('ABSPATH')) exit;
class AIWC_REST_Tasks {
    public static function init(){add_action('rest_api_init',array(__CLASS__,'register'));}
    public static function register(){
        register_rest_route('aiwc/v1','/tasks',array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'list'),'permission_callback'=>array(__CLASS__,'can_view')),
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array(__CLASS__,'create'),'permission_callback'=>array(__CLASS__,'can_create'))
        ));
        register_rest_route('aiwc/v1','/tasks/(?P<id>\\d+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'get'),'permission_callback'=>array(__CLASS__,'can_view')));
        foreach(array('approve','reject','execute') as $action)register_rest_route('aiwc/v1','/tasks/(?P<id>\\d+)/'.$action,array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array(__CLASS__,$action),'permission_callback'=>array(__CLASS__,'can_'.$action)));
    }
    public static function can_view(){return AIWC_Capabilities::can_view();}
    public static function can_create(){return AIWC_Capabilities::can_create();}
    public static function can_approve(){return AIWC_Capabilities::can_approve();}
    public static function can_reject(){return AIWC_Capabilities::can_approve();}
    public static function can_execute(){return AIWC_Capabilities::can_execute();}
    private static function body($r){$b=$r->get_json_params();return is_array($b)?$b:array();}
    public static function list($r){$s=sanitize_key((string)$r->get_param('status'));$rows=AIWC_Task_Repository::list($s?:null,(int)$r->get_param('limit')?:50);foreach($rows as &$row)unset($row['plan_json']);return rest_ensure_response($rows);}
    public static function get($r){$row=AIWC_Task_Repository::get((int)$r['id']);if(!$row)return new WP_Error('not_found','Task not found.',array('status'=>404));$row['plan']=AIWC_Task_Manager::decoded_plan($row);unset($row['plan_json']);return rest_ensure_response($row);}
    public static function create($r){$result=AIWC_Task_Manager::create(self::body($r),'ai');if(is_wp_error($result))return $result;return new WP_REST_Response(array('success'=>true,'task_id'=>(int)$result['id'],'task_uuid'=>$result['task_uuid'],'status'=>$result['status']),201);}
    public static function approve($r){$result=AIWC_Task_Approval::approve((int)$r['id']);if(is_wp_error($result))return $result;return rest_ensure_response(array('success'=>true,'status'=>'approved','task_id'=>(int)$r['id']));}
    public static function reject($r){$b=self::body($r);$result=AIWC_Task_Approval::reject((int)$r['id'],isset($b['reason'])?$b['reason']:'');if(is_wp_error($result))return $result;return rest_ensure_response(array('success'=>true,'status'=>'rejected','task_id'=>(int)$r['id']));}
    public static function execute($r){$result=AIWC_Task_Executor::execute((int)$r['id']);if(is_wp_error($result))return $result;return rest_ensure_response(array('success'=>true,'task_id'=>(int)$r['id'])+$result);}
}