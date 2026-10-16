<?php
if (!defined('ABSPATH')) exit;
class AIWC_Capabilities {
    const VIEW='aiwc_view_tasks'; const CREATE='aiwc_create_tasks'; const APPROVE='aiwc_approve_tasks'; const EXECUTE='aiwc_execute_tasks'; const SETTINGS='aiwc_manage_settings';
    public static function init() {}
    public static function add_caps() { $role=get_role('administrator'); if(!$role)return; foreach(array(self::VIEW,self::CREATE,self::APPROVE,self::EXECUTE,self::SETTINGS) as $cap)$role->add_cap($cap); }
    public static function can_view(){return current_user_can(self::VIEW);}
    public static function can_create(){return current_user_can(self::CREATE);}
    public static function can_approve(){return current_user_can(self::APPROVE);}
    public static function can_execute(){return current_user_can(self::EXECUTE);}
}