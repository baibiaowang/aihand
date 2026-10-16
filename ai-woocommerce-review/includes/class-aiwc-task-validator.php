<?php
if (!defined('ABSPATH')) exit;
class AIWC_Task_Validator {
    const TYPES=array('product_price_update','product_stock_update','product_text_update');
    public static function validate_plan($plan){
        if(!is_array($plan))return new WP_Error('invalid_plan','Plan must be a JSON object.');
        foreach(array('schema_version','task_type','title','changes') as $key)if(!array_key_exists($key,$plan))return new WP_Error('invalid_plan',"Missing field: {$key}");
        if((int)$plan['schema_version']!==1)return new WP_Error('invalid_schema','Only schema_version=1 is supported.');
        if(!in_array($plan['task_type'],self::TYPES,true))return new WP_Error('invalid_task_type','Unsupported task type.');
        if(!is_string($plan['title'])||$plan['title']===''||strlen($plan['title'])>500)return new WP_Error('invalid_title','Invalid title.');
        if(!is_array($plan['changes'])||count($plan['changes'])<1||count($plan['changes'])>500)return new WP_Error('invalid_changes','Changes must contain 1-500 items.');
        foreach($plan['changes'] as $i=>$change){
            if(!is_array($change))return new WP_Error('invalid_change','Change #'.($i+1).' is invalid.');
            foreach(array('object_type','object_id','field','before','after') as $key)if(!array_key_exists($key,$change))return new WP_Error('invalid_change','Change #'.($i+1).' missing '.$key.'.');
            if($change['object_type']!=='product'||(int)$change['object_id']<1)return new WP_Error('invalid_object','Only valid WooCommerce product IDs are supported.');
            $allowed=array('regular_price','sale_price','stock_quantity','name','description','short_description');
            if(!in_array($change['field'],$allowed,true))return new WP_Error('invalid_field','Unsupported product field: '.$change['field']);
        }
        return true;
    }
    public static function canonical_json($plan){$copy=$plan;unset($copy['task_id'],$copy['status'],$copy['created_at'],$copy['expires_at'],$copy['plan_hash']);self::sort_recursive($copy);return wp_json_encode($copy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private static function sort_recursive(&$v){if(!is_array($v))return;if(self::is_assoc($v))ksort($v);foreach($v as &$x)self::sort_recursive($x);}
    private static function is_assoc($a){return array_keys($a)!==range(0,count($a)-1);}
    public static function hash($plan){return hash('sha256',self::canonical_json($plan));}
    public static function current_product_value($product,$field){
        switch($field){
            case'regular_price':return $product->get_regular_price('edit');
            case'sale_price':return $product->get_sale_price('edit');
            case'stock_quantity':return $product->get_stock_quantity('edit');
            case'name':return $product->get_name('edit');
            case'description':return $product->get_description('edit');
            case'short_description':return $product->get_short_description('edit');
        }
        return new WP_Error('unsupported_field','Unsupported product field: '.$field);
    }
    public static function validate_change($change){
        if(!function_exists('wc_get_product'))return new WP_Error('woocommerce_missing','WooCommerce is required.');
        $product=wc_get_product((int)$change['object_id']);if(!$product)return new WP_Error('product_missing','Product does not exist.');
        $actual=self::current_product_value($product,$change['field']);if(is_wp_error($actual))return $actual;
        if((string)$actual!==(string)$change['before'])return new WP_Error('stale_change','Product data changed since this plan was created.',array('actual'=>$actual,'expected'=>$change['before']));
        return true;
    }
}
