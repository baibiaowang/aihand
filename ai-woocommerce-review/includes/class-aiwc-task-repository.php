<?php
if (!defined('ABSPATH')) exit;
class AIWC_Task_Repository {
    private static function table(){global $wpdb;return $wpdb->prefix.'aiwc_tasks';}
    public static function insert($data){global $wpdb;$ok=$wpdb->insert(self::table(),$data);if(!$ok)return new WP_Error('db_insert_failed',$wpdb->last_error?:'Unable to create task.');return (int)$wpdb->insert_id;}
    public static function get($id){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table()." WHERE id=%d",$id),ARRAY_A);}
    public static function list($status=null,$limit=50){global $wpdb;$limit=max(1,min(100,(int)$limit));if($status)return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table()." WHERE status=%s ORDER BY id DESC LIMIT %d",$status,$limit),ARRAY_A);return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table()." ORDER BY id DESC LIMIT %d",$limit),ARRAY_A);}
    public static function update($id,$data,$where=array()){global $wpdb;if(!$where)$where=array('id'=>(int)$id);$ok=$wpdb->update(self::table(),$data,$where);if($ok===false)return new WP_Error('db_update_failed',$wpdb->last_error?:'Unable to update task.');return $ok;}
}