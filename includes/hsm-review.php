<?php
defined('ABSPATH') || exit;

function hsm_review_register() {
	register_post_type('hsm_review',array('public'=>false,'show_ui'=>false,'rewrite'=>false,'query_var'=>false,'supports'=>array('title','author')));
}
add_action('init','hsm_review_register',5);

function hsm_review_canonical($ability,$input) {
	unset($input['confirm_token']);
	return hash('sha256',$ability.'|'.wp_json_encode(hsm_canonical($input)));
}
function hsm_review_extract_ids($value,$ability='',$path=array()) {
	$ids=array();
	if(!is_array($value))return $ids;
	foreach($value as $k=>$v) {
		$next=array_merge($path,array((string)$k));
		$is_product_id=in_array($k,array('product_id','variation_id'),true);
		$is_root_product_id=empty($path)&&$k==='id'&&preg_match('/product-upsert$/',$ability);
		$is_bulk_item_id=$k==='id'&&in_array('items',$path,true)&&preg_match('/products-bulk-update$/',$ability);
		$is_filter_id=in_array('ids',$path,true)&&preg_match('/products-bulk-update$/',$ability)&&is_numeric($v);
		if(($is_product_id||$is_root_product_id||$is_bulk_item_id||$is_filter_id)&&is_numeric($v)&&(int)$v>0)$ids[]=(int)$v;
		if($k==='sku'&&is_string($v)&&$v!==''&&function_exists('wc_get_product_id_by_sku')){$sku_id=(int)wc_get_product_id_by_sku($v);if($sku_id>0)$ids[]=$sku_id;}
		$ids=array_merge($ids,hsm_review_extract_ids($v,$ability,$next));
	}
	return array_values(array_unique($ids));
}
function hsm_review_collect_skus($value) {
	$out=array();if(!is_array($value))return $out;
	foreach($value as $key=>$item){if($key==='sku'&&is_string($item)&&trim($item)!=='')$out[]=trim($item);$out=array_merge($out,hsm_review_collect_skus($item));}
	return array_values(array_unique($out));
}
function hsm_review_bulk_target_ids($input) {
	if(!function_exists('wc_get_products'))return array();
	$ids=array();foreach((array)($input['items']??array()) as $item){$id=function_exists('hsm_product_id')?hsm_product_id((array)$item):0;if($id)$ids[]=(int)$id;}
	if(!empty($input['filter'])||!empty($input['changes'])){
		$f=(array)($input['filter']??array());$args=array('limit'=>-1,'status'=>array('publish','draft','private','pending'));
		if(!empty($f['category'])){$term=get_term_by('name',$f['category'],'product_cat')?:get_term_by('slug',$f['category'],'product_cat');if($term)$args['category']=array($term->slug);else return array();}
		!empty($f['tag'])&&$args['tag']=array($f['tag']);!empty($f['search'])&&$args['s']=$f['search'];!empty($f['stock_status'])&&$args['stock_status']=$f['stock_status'];!empty($f['ids'])&&$args['include']=array_map('intval',(array)$f['ids']);
		foreach(wc_get_products($args) as $product){$id=(int)$product->get_id();$ids[]=$id;if($product->is_type('variable'))$ids=array_merge($ids,array_map('intval',$product->get_children()));}
	}
	$ids=array_values(array_unique(array_filter(array_map('absint',$ids))));sort($ids);return $ids;
}
function hsm_review_server_snapshot($input,$preview=array()) {
	$ability=$GLOBALS['hsm_current_ability']??'';
	$ids=hsm_review_extract_ids($input,$ability);
	if(!empty($preview['target_ids'])&&is_array($preview['target_ids']))$ids=array_merge($ids,array_map('absint',$preview['target_ids']));
	if(!empty($preview['candidate_ids'])&&is_array($preview['candidate_ids']))$ids=array_merge($ids,array_map('absint',$preview['candidate_ids']));
	if(!empty($preview['first_changes'])&&is_array($preview['first_changes']))foreach($preview['first_changes'] as $item)if(!empty($item['id']))$ids[]=(int)$item['id'];
	$ids=array_values(array_unique(array_filter($ids)));$products=array();
	foreach($ids as $id) {
		if(!function_exists('wc_get_product'))break;
		$p=wc_get_product($id);if(!$p)continue;
		$children=method_exists($p,'get_children')?$p->get_children():array();sort($children);
		$products[]=array('id'=>$id,'name'=>$p->get_name(),'sku'=>$p->get_sku(),'type'=>$p->get_type(),'status'=>$p->get_status(),'manage_stock'=>$p->get_manage_stock(),'child_ids'=>array_values(array_map('intval',$children)),'url'=>get_permalink($id),'edit_url'=>get_edit_post_link($id,''),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'stock_status'=>$p->get_stock_status(),'stock_quantity'=>$p->get_stock_quantity());
	}
	$changes=array();
	if(is_array($preview)){
		if(!empty($preview['changes'])&&is_array($preview['changes'])){
			foreach($preview['changes'] as $key=>$change){
				if(is_array($change)&&array_key_exists('before',$change)||is_array($change)&&array_key_exists('after',$change))$changes[]=$change;
				elseif(is_array($change)&&array_key_exists('from',$change)||is_array($change)&&array_key_exists('to',$change))$changes[]=array('field'=>(string)$key,'before'=>$change['from']??null,'after'=>$change['to']??null);
				else $changes[]=$change;
			}
		}elseif(!empty($preview['first_changes'])&&is_array($preview['first_changes'])){
			foreach($preview['first_changes'] as $item){foreach((array)($item['changes']??array()) as $field=>$change){if(is_array($change))$changes[]=array('id'=>$item['id']??'','field'=>($item['item']??'').': '.$field,'before'=>$change['from']??null,'after'=>$change['to']??null);}}
		}else{
			foreach($preview as $key=>$change){if(is_array($change)&&(array_key_exists('from',$change)||array_key_exists('to',$change)))$changes[]=array('field'=>(string)$key,'before'=>$change['from']??null,'after'=>$change['to']??null);}
		}
	}
	$sku_checks=array();if(function_exists('wc_get_product_id_by_sku'))foreach(hsm_review_collect_skus($input) as $sku)$sku_checks[$sku]=(int)wc_get_product_id_by_sku($sku);
	return array('server_verified_at'=>current_time('mysql',true),'products'=>$products,'sku_checks'=>$sku_checks,'target_ids'=>!empty($preview['target_ids'])?array_values(array_unique(array_map('absint',$preview['target_ids']))):array(),'candidate_ids'=>!empty($preview['candidate_ids'])?array_values(array_unique(array_map('absint',$preview['candidate_ids']))):array(),'changes'=>$changes,'raw_preview'=>$preview,'validation'=>array('plan_hash_valid'=>true,'server_snapshot'=>!empty($products),'live_recheck'=>'performed again before execution'));
}
function hsm_review_create($ability,$input,$reason,$preview,$token='') {
	$hash=hsm_review_canonical($ability,$input);
	$existing=get_posts(array('post_type'=>'hsm_review','post_status'=>'private','posts_per_page'=>20,'meta_key'=>'_hsm_plan_hash','meta_value'=>$hash,'fields'=>'ids','orderby'=>'date','order'=>'DESC'));
	foreach($existing as $existing_id) {
		$existing_plan=hsm_review_read($existing_id);
		// Reuse only an actionable proposal. A rejected, expired or executed task must not
		// suppress a fresh proposal for a later, intentionally repeated operation.
		if($existing_plan && in_array($existing_plan['status']??'',array('pending','approved'),true)
			&& (empty($existing_plan['expires_at']) || strtotime($existing_plan['expires_at'].' UTC')>=time())) {
			return (int)$existing_id;
		}
	}
	$plan=array('version'=>2,'status'=>'pending','ability'=>$ability,'reason'=>sanitize_text_field($reason),'input'=>$input,'preview'=>hsm_review_server_snapshot($input,$preview),'input_hash'=>$hash,'created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)hsm_guard('token_ttl')));
	$id=wp_insert_post(array('post_type'=>'hsm_review','post_status'=>'private','post_title'=>sanitize_text_field('Pending AI operation: '.$ability),'post_author'=>get_current_user_id(),'post_content'=>wp_slash(wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))),true);
	if(is_wp_error($id))return 0;
	update_post_meta($id,'_hsm_plan_hash',$hash);hsm_review_audit($id,'created',array('ability'=>$ability,'risk'=>$preview['risk']??'confirmation'));return (int)$id;
}
function hsm_review_read($id) {
	$p=get_post((int)$id);if(!$p||'hsm_review'!==$p->post_type)return null;
	$d=json_decode($p->post_content,true);if(!is_array($d))return null;
	$d['id']=(int)$p->ID;$d['user']=get_the_author_meta('user_login',$p->post_author)?:'unknown';return $d;
}
function hsm_review_update($id,$data) {
	$d=hsm_review_read($id);if(!$d)return false;foreach($data as $k=>$v)$d[$k]=$v;
	return wp_update_post(array('ID'=>(int)$id,'post_content'=>wp_slash(wp_json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))),true);
}
function hsm_review_audit($id,$event,$details=array()) {
	$d=hsm_review_read($id);if(!$d)return false;
	$log=$d['audit']??array();$log[]=array('event'=>sanitize_key($event),'user'=>get_current_user_id(),'time'=>current_time('mysql',true),'details'=>$details);
	return hsm_review_update($id,array('audit'=>$log));
}
function hsm_review_can_approve(){return current_user_can('manage_woocommerce')||current_user_can('manage_options');}

function hsm_review_validate($d) {
	if(!$d)return new WP_Error('not_found','Review task not found.');
	if(!in_array($d['status']??'',array('pending','approved'),true))return new WP_Error('invalid_state','This review is no longer actionable.');
	if(!empty($d['expires_at'])&&strtotime($d['expires_at'].' UTC')<time())return new WP_Error('expired','Review task expired. Create a new proposal.');
	if(hsm_review_canonical($d['ability'],$d['input'])!==($d['input_hash']??''))return new WP_Error('tampered','Operation plan changed after it was created.');
	if(empty(HSM_Registry::$abilities[$d['ability']]))return new WP_Error('ability_missing','The original AI ability is no longer available.');
	$stored_products=$d['preview']['products']??array();
	if(function_exists('wc_get_product_id_by_sku'))foreach((array)($d['preview']['sku_checks']??array()) as $sku=>$expected_id){$actual_id=(int)wc_get_product_id_by_sku((string)$sku);if($actual_id!==(int)$expected_id)return new WP_Error('stale',"SKU {$sku} now resolves to a different product. Refresh the proposal before executing.");}
	$check_ids=array_merge(hsm_review_extract_ids($d['input'],$d['ability']??''),array_map('absint',(array)($d['preview']['target_ids']??array())),array_map(function($p){return (int)($p['id']??0);},$stored_products));$check_ids=array_values(array_unique(array_filter($check_ids)));
	if(preg_match('/products-bulk-update$/',$d['ability']??'')){$current_targets=hsm_review_bulk_target_ids($d['input']);$expected_targets=array_values(array_unique(array_map('absint',(array)($d['preview']['candidate_ids']??array()))));sort($expected_targets);if($current_targets!==$expected_targets)return new WP_Error('stale','The set of products matching this bulk operation changed after review. Create a fresh proposal.');}
	foreach($check_ids as $id){
		if(!function_exists('wc_get_product'))continue;
		$p=wc_get_product($id);if(!$p)return new WP_Error('stale','A referenced product no longer exists.');
		$old=null;foreach($stored_products as $sp)if((int)($sp['id']??0)===$id){$old=$sp;break;}
		if($old){
			$children=method_exists($p,'get_children')?$p->get_children():array();sort($children);
			$live=array('name'=>$p->get_name(),'sku'=>$p->get_sku(),'type'=>$p->get_type(),'status'=>$p->get_status(),'manage_stock'=>$p->get_manage_stock(),'child_ids'=>array_values(array_map('intval',$children)),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'stock_status'=>$p->get_stock_status(),'stock_quantity'=>$p->get_stock_quantity());
			foreach($live as $key=>$value)if(array_key_exists($key,$old)&&wp_json_encode($value)!==wp_json_encode($old[$key]))return new WP_Error('stale',"Product #$id changed since this proposal was created ($key). Refresh the proposal before executing.");
		}
	}
	$raw_preview=$d['preview']['raw_preview']??array();
	// SEO proposals must not overwrite metadata changed by a person/plugin after approval.
	if ( preg_match( '/seo-update$/', $d['ability'] ?? '' ) ) {
		foreach ( (array) ( $raw_preview['seo_snapshot'] ?? array() ) as $snapshot ) {
			if ( ! empty( $snapshot['missing'] ) ) {
				if ( 'term' === ( $snapshot['kind'] ?? '' ) ) {
					$tax = $snapshot['taxonomy'] ?? 'product_cat';
					$term = is_numeric( $snapshot['ref'] ?? null ) ? get_term( (int) $snapshot['ref'], $tax ) : get_term_by( 'name', (string) ( $snapshot['ref'] ?? '' ), $tax );
					if ( $term && ! is_wp_error( $term ) ) return new WP_Error( 'stale', 'A SEO category target now exists or resolves differently. Refresh the proposal before executing.' );
				} else {
					$id = ! empty( $snapshot['ref'] ) && is_numeric( $snapshot['ref'] ) ? (int) $snapshot['ref'] : ( ! empty( $snapshot['ref'] ) && function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( (string) $snapshot['ref'] ) : 0 );
					if ( $id && get_post( $id ) ) return new WP_Error( 'stale', 'A SEO content target now exists or resolves differently. Refresh the proposal before executing.' );
				}
				continue;
			}
			if ( 'term' === ( $snapshot['kind'] ?? '' ) ) {
				$term = get_term( (int) ( $snapshot['id'] ?? 0 ), $snapshot['taxonomy'] ?? 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'stale', 'The SEO category no longer exists. Refresh the proposal.' );
				foreach ( (array) ( $snapshot['meta'] ?? array() ) as $field => $expected ) if ( wp_json_encode( get_term_meta( (int) $snapshot['id'], '_hsm_seo_' . $field, true ) ) !== wp_json_encode( $expected ) ) return new WP_Error( 'stale', 'SEO category metadata changed after review. Refresh the proposal.' );
			} else {
				$id = (int) ( $snapshot['id'] ?? 0 );
				if ( ! $id || ! get_post( $id ) ) return new WP_Error( 'stale', 'The SEO content target no longer exists. Refresh the proposal.' );
				foreach ( (array) ( $snapshot['meta'] ?? array() ) as $meta_key => $expected ) if ( wp_json_encode( get_post_meta( $id, $meta_key, true ) ) !== wp_json_encode( $expected ) ) return new WP_Error( 'stale', 'SEO metadata changed after review. Refresh the proposal to avoid overwriting newer values.' );
				if ( array_key_exists( 'global_unique_id', $snapshot ) ) {
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
					if ( ! $product || ! method_exists( $product, 'get_global_unique_id' ) || wp_json_encode( $product->get_global_unique_id() ) !== wp_json_encode( $snapshot['global_unique_id'] ) ) return new WP_Error( 'stale', 'The product GTIN changed after review. Refresh the proposal.' );
				}
			}
		}
	}
	// Revalidate high-impact non-product targets too; their state can change while a review waits for approval.
	$settings_option_map=array('/seo-settings$/' =>'hsm_seo','/conversion$/' =>'hsm_conversion','/marketing$/' =>'hsm_marketing','/shipping-rules$/' =>'hsm_shipping_rules','/shipments$/' =>'hsm_shipments');
	foreach($settings_option_map as $pattern=>$option){if(preg_match($pattern,$d['ability']??'')&&array_key_exists('before',$raw_preview)){if(wp_json_encode(get_option($option,array()))!==wp_json_encode($raw_preview['before']))return new WP_Error('stale','The settings changed after this proposal was created. Refresh and review the latest values before executing.');}}
	if(preg_match('/support$/',$d['ability']??'')&&in_array($d['input']['action']??'',array('set_settings','templates_set'),true)&&array_key_exists('before',$raw_preview)){if(wp_json_encode(get_option('hsm_support',array()))!==wp_json_encode($raw_preview['before']))return new WP_Error('stale','Customer support settings changed after this proposal was created. Refresh the proposal.');}
	if(preg_match('/content$/',$d['ability']??'')&&'set_settings'===($raw_preview['action']??'')&&array_key_exists('settings_snapshot',$raw_preview)){if(wp_json_encode(get_option('hsm_content',array()))!==wp_json_encode($raw_preview['settings_snapshot']))return new WP_Error('stale','Content settings changed after this proposal was created. Refresh the proposal.');}
	if(preg_match('/store-settings$/',$d['ability']??'')){
		$store_option_map=array('currency'=>'woocommerce_currency','base_country'=>'woocommerce_default_country','allowed_countries'=>'woocommerce_allowed_countries','specific_allowed_countries'=>'woocommerce_specific_allowed_countries','ship_to_countries'=>'woocommerce_ship_to_countries','calc_taxes'=>'woocommerce_calc_taxes','prices_include_tax'=>'woocommerce_prices_include_tax','tax_display_shop'=>'woocommerce_tax_display_shop','tax_display_cart'=>'woocommerce_tax_display_cart','enable_coupons'=>'woocommerce_enable_coupons','weight_unit'=>'woocommerce_weight_unit','dimension_unit'=>'woocommerce_dimension_unit','price_decimals'=>'woocommerce_price_num_decimals','store_address'=>'woocommerce_store_address','store_city'=>'woocommerce_store_city','store_postcode'=>'woocommerce_store_postcode','enable_reviews'=>'woocommerce_enable_reviews','review_rating_verification_required'=>'woocommerce_review_rating_verification_required','manage_stock'=>'woocommerce_manage_stock','notify_low_stock_amount'=>'woocommerce_notify_low_stock_amount','hold_stock_minutes'=>'woocommerce_hold_stock_minutes','enable_guest_checkout'=>'woocommerce_enable_guest_checkout','blogname'=>'blogname','blogdescription'=>'blogdescription','timezone_string'=>'timezone_string','blog_public'=>'blog_public');
		foreach($raw_preview as $key=>$change){if(isset($store_option_map[$key])&&is_array($change)&&array_key_exists('from',$change)&&wp_json_encode(get_option($store_option_map[$key],null))!==wp_json_encode($change['from']))return new WP_Error('stale','Store setting "'.$key.'" changed after this proposal was created. Refresh the proposal.');}
	}
	// Shipping zones affect checkout availability; reject proposals if the zone or its methods changed while awaiting approval.
	if ( preg_match( '/shipping-manage$/', $d['ability'] ?? '' ) && in_array( $d['input']['action'] ?? '', array( 'upsert_zone', 'delete_zone' ), true ) ) {
		$snapshot = $raw_preview['zone_snapshot'] ?? null;
		$zone_id = (int) ( $raw_preview['zone_id'] ?? 0 );
		if ( $zone_id > 0 ) {
			$zone = class_exists( 'WC_Shipping_Zone' ) ? new WC_Shipping_Zone( $zone_id ) : false;
			if ( ! $zone || (int) $zone->get_id() !== $zone_id ) return new WP_Error( 'stale', 'The shipping zone no longer exists. Refresh the proposal.' );
			$methods = array();
			foreach ( $zone->get_shipping_methods() as $method ) $methods[] = array( 'instance_id' => $method->instance_id, 'type' => $method->id, 'title' => $method->get_title(), 'enabled' => 'yes' === $method->enabled, 'cost' => $method->get_option( 'cost' ), 'min_amount' => $method->get_option( 'min_amount' ), 'requires' => $method->get_option( 'requires' ) );
			$live = array( 'id' => $zone->get_id(), 'name' => $zone->get_zone_name(), 'regions' => array_map( function ( $location ) { return $location->type . ':' . $location->code; }, $zone->get_zone_locations() ), 'methods' => $methods );
			if ( ! is_array( $snapshot ) || wp_json_encode( $live ) !== wp_json_encode( $snapshot ) ) return new WP_Error( 'stale', 'The shipping zone, regions, or methods changed after review. Refresh the proposal before executing.' );
		} elseif ( 'upsert_zone' === ( $d['input']['action'] ?? '' ) && ! empty( $d['input']['zone_name'] ) ) {
			foreach ( WC_Shipping_Zones::get_zones() as $candidate ) if ( 0 === strcasecmp( (string) $candidate['zone_name'], (string) $d['input']['zone_name'] ) ) return new WP_Error( 'stale', 'A shipping zone with this name appeared after review. Refresh the proposal.' );
		}
	}
	// Tax changes must be based on the same rate rows the owner reviewed.
	if ( preg_match( '/tax-manage$/', $d['ability'] ?? '' ) && function_exists( 'wc_tax_enabled' ) && isset( $raw_preview['action'] ) ) {
		global $wpdb;
		if ( 'delete' === $raw_preview['action'] ) {
			$current = WC_Tax::_get_tax_rate( (int) ( $raw_preview['rate_id'] ?? 0 ) );
			if ( ! $current || wp_json_encode( $current ) !== wp_json_encode( $raw_preview['tax_snapshot'] ?? null ) ) return new WP_Error( 'stale', 'The tax rate changed or was deleted after review. Refresh the proposal.' );
		} elseif ( 'clear_class' === $raw_preview['action'] ) {
			$class = (string) ( $raw_preview['class'] ?? '' );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class=%s ORDER BY tax_rate_id", $class ) );
			$current = array();
			foreach ( $ids as $tax_id ) { $row = WC_Tax::_get_tax_rate( (int) $tax_id ); if ( $row ) $current[] = $row; }
			if ( wp_json_encode( $current ) !== wp_json_encode( $raw_preview['tax_snapshot'] ?? null ) ) return new WP_Error( 'stale', 'Tax rates in this class changed after review. Refresh the proposal before deleting them.' );
		} elseif ( 'add' === $raw_preview['action'] ) {
			$class = (string) ( $raw_preview['class'] ?? '' );
			$current = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class=%s ORDER BY tax_rate_id", $class ), ARRAY_A );
			if ( wp_json_encode( $current ) !== wp_json_encode( $raw_preview['tax_class_snapshot'] ?? null ) ) return new WP_Error( 'stale', 'Tax rates in this class changed after review. Refresh the proposal before adding more rates.' );
		}
	}
	if(!empty($raw_preview['post_snapshot']['id'])){
		$post=get_post((int)$raw_preview['post_snapshot']['id']);$snap=$raw_preview['post_snapshot'];
		if(!$post)return new WP_Error('stale','The content item no longer exists. Refresh the proposal.');
		if(isset($snap['status'])&&$post->post_status!==$snap['status'])return new WP_Error('stale','The content status changed after this proposal was created. Refresh the proposal.');
		if(isset($snap['content_hash'])&&hash('sha256',(string)$post->post_content)!==$snap['content_hash'])return new WP_Error('stale','The content body changed after this proposal was created. Review the latest content before executing.');
		if(isset($snap['title'])&&$post->post_title!==$snap['title'])return new WP_Error('stale','The content title changed after this proposal was created. Refresh the proposal.');
	}
	if(preg_match('/order-update$/',$d['ability']??'')&&!empty($raw_preview['order'])){
		$order=function_exists('wc_get_order')?wc_get_order((int)$raw_preview['order']):false;
		if(!$order)return new WP_Error('stale','The order no longer exists. Refresh the proposal before executing.');
		if(isset($raw_preview['current_status'])&&$order->get_status()!==$raw_preview['current_status'])return new WP_Error('stale','The order status changed after this proposal was created. Refresh the proposal before executing.');
		$snapshot=$raw_preview['order_snapshot']??array();
		if(isset($snapshot['status'])&&$order->get_status()!==$snapshot['status'])return new WP_Error('stale','The order status changed after this proposal was created. Refresh the proposal.');
		foreach(array('billing','shipping') as $address_type){
			$current_address=$order->get_address($address_type);
			foreach((array)($snapshot[$address_type]??array()) as $field=>$expected){
				if(wp_json_encode($current_address[$field]??'')!==wp_json_encode($expected))return new WP_Error('stale','The order '.$address_type.' address changed after review. Refresh the proposal to avoid overwriting newer details.');
			}
		}
		foreach((array)($snapshot['meta']??array()) as $meta_key=>$expected){
			if(wp_json_encode($order->get_meta($meta_key,true))!==wp_json_encode($expected))return new WP_Error('stale','Order metadata changed after review. Refresh the proposal before executing.');
		}
		if(array_key_exists('tracking',$snapshot)&&wp_json_encode($order->get_meta('_hsm_tracking',true))!==wp_json_encode($snapshot['tracking']))return new WP_Error('stale','Shipment tracking changed after review. Refresh the proposal before adding another tracking entry.');
	}
	if(preg_match('/order-refund$/',$d['ability']??'')&&!empty($raw_preview['order'])){
		$order=function_exists('wc_get_order')?wc_get_order((int)$raw_preview['order']):false;
		if(!$order)return new WP_Error('stale','The order no longer exists. Refresh the refund proposal.');
		if(isset($raw_preview['current_status'])&&$order->get_status()!==$raw_preview['current_status'])return new WP_Error('stale','The order status changed after this refund proposal was created. Refresh the proposal.');
		if(isset($raw_preview['already_refunded'])&&abs((float)$order->get_total_refunded()-(float)$raw_preview['already_refunded'])>0.00001)return new WP_Error('stale','The order refund total changed after this proposal was created. Recalculate the remaining refundable amount.');
		if(isset($raw_preview['order_total'])&&abs((float)$order->get_total()-(float)$raw_preview['order_total'])>0.00001)return new WP_Error('stale','The order total changed after this refund proposal was created. Refresh the proposal.');
		foreach((array)($raw_preview['refund_items_snapshot']??array()) as $item_id=>$expected){
			$item=$order->get_item((int)$item_id);
			if(!$item)return new WP_Error('stale','An order line item changed or disappeared after refund review. Recalculate the refund.');
			$product=$item->get_product();
			$live=array('product_id'=>(int)$item->get_product_id(),'variation_id'=>(int)$item->get_variation_id(),'sku'=>($product?(string)$product->get_sku():''),'quantity'=>(int)$item->get_quantity(),'total'=>(string)$item->get_total(),'total_tax'=>(string)$item->get_total_tax(),'qty_refunded'=>(int)absint($order->get_qty_refunded_for_item((int)$item_id)));
			if(wp_json_encode($live)!==wp_json_encode($expected))return new WP_Error('stale','An order line item or refunded quantity changed after review. Recalculate the refund before executing.');
		}
		if(count((array)($raw_preview['refund_items_snapshot']??array()))!==count($order->get_items()))return new WP_Error('stale','The order line items changed after refund review. Recalculate the refund.');
	}
	if(preg_match('/coupon-manage$/',$d['ability']??'')&&in_array($d['input']['action']??'',array('upsert','delete'),true)){
		if(!array_key_exists('coupon_snapshot',$raw_preview))return new WP_Error('stale','This coupon proposal lacks a live-state snapshot. Create a fresh review before changing coupons.');
		$expected=$raw_preview['coupon_snapshot'];
		$code=function_exists('wc_format_coupon_code')?wc_format_coupon_code((string)($d['input']['code']??'')):(string)($d['input']['code']??'');
		$live_id=function_exists('wc_get_coupon_id_by_code')?(int)wc_get_coupon_id_by_code($code):0;
		if(!$expected){
			if($live_id)return new WP_Error('stale','A coupon with this code was created after review. Refresh the proposal instead of modifying it implicitly.');
		}else{
			if(!$live_id||(int)$expected['id']!==$live_id)return new WP_Error('stale','The coupon code now resolves to a different or missing coupon. Refresh the proposal.');
			$coupon=class_exists('WC_Coupon')?new WC_Coupon($live_id):false;
			if(!$coupon||wp_json_encode(hsm_coupon_state($coupon))!==wp_json_encode($expected['state']??array()))return new WP_Error('stale','Coupon terms changed after review. Refresh the proposal before updating or deleting it.');
			if((int)$coupon->get_usage_count()!==(int)($expected['usage_count']??-1))return new WP_Error('stale','Coupon usage changed after review. Refresh the proposal before updating or deleting it.');
		}
	}
	if(preg_match('/taxonomy-manage$/',$d['ability']??'')){
		$tax=sanitize_key((string)($raw_preview['taxonomy']??$d['input']['taxonomy']??'product_cat'));
		$action=$d['input']['action']??'';
		if('delete'===$action){
			$term=get_term((int)($raw_preview['id']??0),$tax);
			if(!$term||is_wp_error($term))return new WP_Error('stale','The taxonomy term no longer exists. Refresh the proposal.');
			$live=array('name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>(int)$term->parent,'count'=>(int)$term->count);
			$expected=array('name'=>$raw_preview['name']??'','slug'=>$raw_preview['slug']??'','description'=>$raw_preview['description']??'','parent'=>(int)($raw_preview['parent']??0),'count'=>(int)($raw_preview['count']??0));
			if(wp_json_encode($live)!==wp_json_encode($expected))return new WP_Error('stale','The taxonomy term changed after review, including its product usage count. Review the latest term state before deleting.');
		}elseif('upsert'===$action){
			$term_id=(int)($raw_preview['term_id']??0);
			if($term_id){
				$term=get_term($term_id,$tax);
				if(!$term||is_wp_error($term))return new WP_Error('stale','The taxonomy term selected for update no longer exists.');
				$live=array('name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>(int)$term->parent);
				$expected=(array)($raw_preview['before']??array());
				if(wp_json_encode($live)!==wp_json_encode($expected))return new WP_Error('stale','The taxonomy term changed after review. Refresh the proposal to avoid overwriting newer changes.');
			}else{
				$name=sanitize_text_field((string)($d['input']['name']??''));
				if($name&&function_exists('get_term_by')){$existing=get_term_by('name',$name,$tax);if($existing&&!is_wp_error($existing))return new WP_Error('stale','A taxonomy term with this name appeared after review. Refresh the proposal instead of updating it implicitly.');}
			}
		}
	}
	if(preg_match('/customers-manage$/',$d['ability']??'')){
		$action=$raw_preview['action']??'';$email=sanitize_email((string)($d['input']['email']??''));
		if('create'===$action&&$email&&function_exists('get_user_by')&&get_user_by('email',$email))return new WP_Error('stale','A customer account with this email was created after the proposal. Refresh the task instead of applying the create plan to an existing account.');
		if('update'===$action){$uid=(int)($raw_preview['customer_id']??0);$user=$uid&&function_exists('get_userdata')?get_userdata($uid):false;if(!$user)return new WP_Error('stale','The customer account no longer exists. Refresh the proposal.');if($email&&strtolower((string)$user->user_email)!==strtolower($email))return new WP_Error('stale','The customer email-to-account mapping changed after review. Refresh the proposal.');$roles=(array)($user->roles??array());if(!array_intersect($roles,array('customer','subscriber')))return new WP_Error('stale','This account is no longer a customer/subscriber and cannot be edited through this proposal.');if(class_exists('WC_Customer')){$customer=new WC_Customer($uid);$snapshot=(array)($raw_preview['customer_snapshot']??array());foreach(array('first_name','last_name') as $field)if(array_key_exists($field,$snapshot)){$live='first_name'===$field?$customer->get_first_name():$customer->get_last_name();if(wp_json_encode($live)!==wp_json_encode($snapshot[$field]))return new WP_Error('stale','Customer name changed after review. Refresh the proposal before updating it.');}foreach(array('billing','shipping') as $address_type)foreach((array)($snapshot[$address_type]??array()) as $field=>$expected){$getter='get_'.$address_type.'_'.$field;if(method_exists($customer,$getter)&&wp_json_encode($customer->$getter())!==wp_json_encode($expected))return new WP_Error('stale','Customer '.$address_type.' details changed after review. Refresh the proposal before updating them.');}}}
	}
	return true;
}
function hsm_review_approve($id) {
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may approve AI operations.');
	$d=hsm_review_read($id);$v=hsm_review_validate($d);if(is_wp_error($v)){if($v->get_error_code()==='expired')hsm_review_update($id,array('status'=>'expired'));elseif($v->get_error_code()==='stale')hsm_review_update($id,array('status'=>'stale','stale_at'=>current_time('mysql',true),'stale_reason'=>$v->get_error_message()));return $v;}
	if($d['status']!=='pending')return new WP_Error('invalid_state','Only pending reviews can be approved.');
	$saved=hsm_review_update($id,array('status'=>'approved','approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true)));
	if(is_wp_error($saved)||!$saved)return new WP_Error('state_persist_failed','Approval was not confirmed because the review state could not be saved. Refresh the task before trying again.');
	hsm_review_audit($id,'approved');return true;
}
function hsm_review_rollback_partial($ability) {
	if(!preg_match('/(?:product-upsert|products-bulk-update)$/',$ability)||empty($GLOBALS['hsm_undo'])||!function_exists('hsm_apply_undo'))return array('attempted'=>false,'ok'=>null,'details'=>array());
	try {
		$entries=(array)$GLOBALS['hsm_undo'];
		$details=hsm_apply_undo($entries);
		$details=is_array($details)?$details:array($details);
		// hsm_apply_undo must return exactly one non-empty success message per undo entry.
		// False, numeric, empty, missing or extra results are not proof that restoration succeeded.
		$ok=count($details)===count($entries);$safe_details=array();
		foreach($details as $detail) {
			if(is_wp_error($detail)) {$ok=false;$safe_details[]=$detail->get_error_message();continue;}
			if(!is_string($detail)) {$ok=false;$safe_details[]=is_scalar($detail)?(string)$detail:wp_json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);continue;}
			$message=trim($detail);
			if($message===''||preg_match('/no longer exists|failed|error|could not|cannot restore/i',$message))$ok=false;
			$safe_details[]=$message;
		}
		return array('attempted'=>true,'ok'=>$ok,'details'=>$safe_details,'verification'=>'best_effort_log_check');
	} catch(Throwable $e) {
		return array('attempted'=>true,'ok'=>false,'error'=>$e->getMessage());
	}
}
function hsm_review_execute($id) {
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may execute approved AI operations.');
	$lock='hsm_review_lock_'.(int)$id;$lock_value=time().':'.get_current_user_id();
	if(!add_option($lock,$lock_value,'',false)){
		$existing=(string)get_option($lock,'');$parts=explode(':',$existing,2);$started=(int)($parts[0]??0);
		if($started>0&&time()-$started>3600){
			// Compare-and-delete the exact stale value. A plain delete_option() can remove a
			// fresh lock written by another request between our read and delete, enabling double execution.
			global $wpdb;
			$deleted=$wpdb->delete($wpdb->options,array('option_name'=>$lock,'option_value'=>$existing),array('%s','%s'));
			if(!$deleted)return new WP_Error('busy','This operation is already being executed.');
			wp_cache_delete($lock,'options');wp_cache_delete('notoptions','options');
			if(!add_option($lock,$lock_value,'',false))return new WP_Error('busy','This operation is already being executed.');
		}else return new WP_Error('busy','This operation is already being executed.');
	}
	try {
		$d=hsm_review_read($id);$v=hsm_review_validate($d);if(is_wp_error($v)){if($v->get_error_code()==='expired')hsm_review_update($id,array('status'=>'expired'));elseif($v->get_error_code()==='stale')hsm_review_update($id,array('status'=>'stale','stale_at'=>current_time('mysql',true),'stale_reason'=>$v->get_error_message()));return $v;}
		if($d['status']!=='approved')return new WP_Error('invalid_state','Approve this review before execution.');
		$ref=HSM_Registry::$abilities[$d['ability']];
		$GLOBALS['hsm_review_execution']=array('id'=>(int)$id,'ability'=>$d['ability'],'input_hash'=>$d['input_hash']);
		$marked=hsm_review_update($id,array('status'=>'executing','executed_by'=>get_current_user_id(),'executing_at'=>current_time('mysql',true)));
		if(is_wp_error($marked)||!$marked)return new WP_Error('state_persist_failed','Could not persist the executing state; the operation was not run. Please retry after checking the review task.');
		$audit_started=hsm_review_audit($id,'execution_started');
		if(is_wp_error($audit_started)||!$audit_started)return new WP_Error('audit_persist_failed','The execution-start audit record could not be saved, so the store operation was not run. Check the review task before retrying.');
		$result=hsm_run_ability($d['ability'],$ref,$d['input']);
		if(is_wp_error($result)){$rollback=hsm_review_rollback_partial($d['ability']);hsm_review_update($id,array('status'=>'execution_error','error'=>$result->get_error_message(),'rollback'=>$rollback));hsm_review_audit($id,'execution_error',array('message'=>$result->get_error_message(),'rollback'=>$rollback));return $result;}
		$finished=hsm_review_update($id,array('status'=>'executed','executed_at'=>current_time('mysql',true),'result'=>$result));
		if(is_wp_error($finished)||!$finished){
			// The ability has already returned success. Do not claim it was undone or invite a blind retry:
			// keep the task non-actionable (normally still 'executing') and tell the operator to verify the store.
			return new WP_Error('result_persist_failed','The store operation returned success, but its review result could not be saved. Do NOT retry this task blindly; verify the actual store state and review record first.');
		}
		hsm_review_audit($id,'executed');return $result;
	} catch(Throwable $e) {
		$failed_plan=isset($d)&&is_array($d)?$d:hsm_review_read($id);$rollback=hsm_review_rollback_partial($failed_plan['ability']??'');hsm_review_update($id,array('status'=>'execution_error','error'=>$e->getMessage(),'rollback'=>$rollback));hsm_review_audit($id,'execution_error',array('message'=>$e->getMessage(),'rollback'=>$rollback));return new WP_Error('execution_error',$e->getMessage().(!empty($rollback['attempted'])?($rollback['ok']?' Product changes were rolled back.':' Automatic rollback failed; inspect the store immediately.'):'' ));
	} finally {unset($GLOBALS['hsm_review_execution']);delete_option($lock);}
}
function hsm_review_reject($id,$reason='') {
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may reject AI operations.');
	$d=hsm_review_read($id);if(!$d||$d['status']!=='pending')return new WP_Error('invalid_state','Only pending reviews can be rejected.');
	$reason=sanitize_textarea_field($reason);$saved=hsm_review_update($id,array('status'=>'rejected','rejected_by'=>get_current_user_id(),'rejected_at'=>current_time('mysql',true),'rejection_reason'=>$reason));
	if(is_wp_error($saved)||!$saved)return new WP_Error('state_persist_failed','Rejection was not confirmed because the review state could not be saved. Refresh the task before trying again.');
	hsm_review_audit($id,'rejected',array('reason'=>$reason));return true;
}
function hsm_review_menu(){if(!hsm_review_can_approve())return;add_submenu_page('woocommerce','AI 操作审核','AI 操作审核','manage_woocommerce','hsm-review','hsm_review_page');}
add_action('admin_menu','hsm_review_menu',30);
function hsm_review_status_label($s){return array('pending'=>'待审核','approved'=>'已批准','executing'=>'执行中','executed'=>'已完成','rejected'=>'已拒绝','expired'=>'已过期','execution_error'=>'执行失败','stale'=>'数据已变化')[$s]??$s;}
function hsm_review_risk($d){$n=is_array($d['preview']['changes']??null)?count($d['preview']['changes']):0;if($n>20)return array('high','高风险 · '.$n.' 项');if(($d['preview']['raw_preview']['risk']??'')==='high')return array('high','高风险');return array('medium',$n?'需审核 · '.$n.' 项':'需人工确认');}
function hsm_review_value($v){return is_array($v)||is_object($v)?wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):($v===null?'—':(string)$v);}
function hsm_review_page() {
	if(!hsm_review_can_approve())wp_die('Forbidden');
	$filter=sanitize_key($_GET['review_status']??'pending');$allowed=array('pending','approved','executing','executed','rejected','expired','execution_error','stale','all');if(!in_array($filter,$allowed,true))$filter='pending';
	$posts=get_posts(array('post_type'=>'hsm_review','post_status'=>'private','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC'));$counts=array_fill_keys(array_slice($allowed,0,-1),0);
	foreach($posts as $p){$x=hsm_review_read($p->ID);if($x&&isset($counts[$x['status']]))$counts[$x['status']]++;}
	$nonce=wp_create_nonce('hsm_review_action');
	echo '<div class="wrap hsm-review-wrap"><h1>AI 操作审核台</h1><p class="description">AI 只能提出操作计划。预览不会修改线上数据；批准与执行前都会重新验证。</p><nav class="nav-tab-wrapper">';
	foreach(array('pending'=>'待审核','approved'=>'已批准','executing'=>'执行中','executed'=>'已完成','rejected'=>'已拒绝','expired'=>'已过期','execution_error'=>'失败','stale'=>'数据已变化') as $k=>$label)echo '<a class="nav-tab '.($filter===$k?'nav-tab-active':'').'" href="'.esc_url(add_query_arg(array('page'=>'hsm-review','review_status'=>$k),admin_url('admin.php'))).'">'.esc_html($label).' <span class="count">('.(int)$counts[$k].')</span></a>';
	echo '</nav><style>
	.hsm-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;margin:16px 0;padding:20px;max-width:1200px}.hsm-card header{display:flex;justify-content:space-between;gap:20px}.hsm-card h2{margin:0 0 5px}.hsm-id{color:#777;font-size:12px}.hsm-badges{display:flex;gap:6px;align-items:flex-start}.hsm-status,.hsm-risk{padding:5px 9px;border-radius:99px;font-size:12px;font-weight:600;background:#f0f0f1}.hsm-risk.high{background:#ffe8e8;color:#b32d2e}.hsm-risk.medium{background:#fff5cc;color:#705000}.hsm-meta{display:flex;flex-wrap:wrap;gap:15px;color:#646970;font-size:12px;border-top:1px solid #eee;border-bottom:1px solid #eee;padding:10px 0;margin:12px 0}.hsm-product-list{overflow:auto}.hsm-product-list table{width:100%;border-collapse:collapse}.hsm-product-list th,.hsm-product-list td{text-align:left;border-bottom:1px solid #eee;padding:9px}.hsm-compare{display:grid;grid-template-columns:1fr 36px 1fr;gap:10px;align-items:center;margin-top:14px}.hsm-pane{border:1px solid #ddd;border-radius:9px;padding:12px}.hsm-pane.before{background:#fff7f7;border-color:#f0caca}.hsm-pane.after{background:#f3fff5;border-color:#b7dfbf}.hsm-pane h3{margin-top:0}.hsm-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #eee}.hsm-row:last-child{border:0}.hsm-row del{color:#a00}.hsm-row ins{color:#087a28;text-decoration:none;font-weight:600}.hsm-arrow{text-align:center;font-size:24px;color:#777}.hsm-details{margin-top:12px}.hsm-details pre{background:#f6f7f7;padding:12px;max-height:260px;overflow:auto}.hsm-live{margin-top:14px}.hsm-live iframe{width:100%;height:520px;border:1px solid #ddd;border-radius:8px;background:#fff}.hsm-actions{display:flex;gap:10px;align-items:center;margin-top:16px}.hsm-actions form{display:flex;gap:7px;align-items:center}.hsm-card small{color:#646970}@media(max-width:800px){.hsm-card header{display:block}.hsm-badges{margin-top:10px}.hsm-compare{grid-template-columns:1fr}.hsm-arrow{transform:rotate(90deg)}} </style>';
	foreach($posts as $p){
		$d=hsm_review_read($p->ID);if(!$d||($filter!=='all'&&$d['status']!==$filter))continue;
		[$risk_class,$risk_text]=hsm_review_risk($d);$products=$d['preview']['products']??array();$changes=$d['preview']['changes']??array();
		echo '<section class="hsm-card"><header><div><h2>'.esc_html($d['ability']).' <span class="hsm-id">#'.(int)$p->ID.'</span></h2><p>'.esc_html($d['reason']).'</p></div><div class="hsm-badges"><span class="hsm-status">'.esc_html(hsm_review_status_label($d['status'])).'</span><span class="hsm-risk '.$risk_class.'">'.esc_html($risk_text).'</span></div></header>';
		echo '<div class="hsm-meta"><span>创建者：'.esc_html($d['user']).'</span><span>创建：'.esc_html($d['created_at']??'').'</span><span>到期：'.esc_html($d['expires_at']??'').'</span><span>计划校验：'.(!empty($d['input_hash'])?'OK':'缺失').'</span><span>线上快照：'.(!empty($d['preview']['validation']['server_snapshot'])?'已读取':'无商品引用').'</span></div>';
		if($products){echo '<div class="hsm-product-list"><table><thead><tr><th>商品</th><th>当前价格</th><th>库存</th><th>状态</th><th>页面</th></tr></thead><tbody>';foreach($products as $pr)echo '<tr><td><strong>'.esc_html($pr['name']).'</strong><br><small>#'.(int)$pr['id'].' · '.esc_html($pr['type']).'</small></td><td>'.esc_html($pr['regular_price']?:'—').($pr['sale_price']!==''?' → 销售 '.esc_html($pr['sale_price']):'').'</td><td>'.esc_html($pr['stock_quantity']===null?'—':$pr['stock_quantity']).' / '.esc_html($pr['stock_status']).'</td><td>'.esc_html($pr['status']).'</td><td>'.($pr['url']?'<a target="_blank" rel="noopener" href="'.esc_url($pr['url']).'">查看</a>':'—').'</td></tr>';echo '</tbody></table></div>';}
		$structured_changes=!empty($changes)&&is_array($changes);if($structured_changes){foreach($changes as $change){if(!is_array($change)||(!array_key_exists('before',$change)&&!array_key_exists('after',$change))){$structured_changes=false;break;}}}
		if($structured_changes){echo '<div class="hsm-compare"><div class="hsm-pane before"><h3>修改前</h3>';foreach($changes as $change)echo '<div class="hsm-row"><span>'.esc_html(($change['id']??'').' · '.($change['field']??$change['key']??'变更')).'</span><del>'.esc_html(hsm_review_value($change['before']??null)).'</del></div>';echo '</div><div class="hsm-arrow">→</div><div class="hsm-pane after"><h3>修改后</h3>';foreach($changes as $change)echo '<div class="hsm-row"><span>'.esc_html(($change['id']??'').' · '.($change['field']??$change['key']??'变更')).'</span><ins>'.esc_html(hsm_review_value($change['after']??null)).'</ins></div>';echo '</div></div>';}
		elseif(!empty($changes)){echo '<div class="hsm-pane"><h3>操作影响 / 风险说明</h3><ul>';foreach($changes as $change){if(is_array($change)){echo '<li>'.esc_html(($change['field']??$change['key']??'变更').'：'.hsm_review_value($change['from']??$change['before']??null).' → '.hsm_review_value($change['to']??$change['after']??null)).'</li>';}else{echo '<li>'.esc_html((string)$change).'</li>';}}echo '</ul></div>';}
		$page_plan=$d['preview']['raw_preview']??$d['preview'];echo '<details class="hsm-details"><summary>查看完整操作计划（核对所有输入）</summary><pre>'.esc_html(wp_json_encode($page_plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></details>';
		$page_url=$d['preview']['raw_preview']['page_url']??'';if($page_url)echo '<div class="hsm-live"><h3>页面预览</h3><iframe loading="lazy" sandbox="allow-same-origin" src="'.esc_url($page_url).'"></iframe></div>';
		if(!empty($d['error']))echo '<div class="notice notice-error inline"><p>'.esc_html($d['error']).'</p></div>';
		if(!empty($d['stale_reason']))echo '<div class="notice notice-warning inline"><p>此计划已失效：'.esc_html($d['stale_reason']).' 请重新提交操作计划。</p></div>';
		if(($d['status']??'')==='executing')echo '<div class="notice notice-warning inline"><p>此任务记录为“执行中”。请先核实店铺实际数据和执行日志，不要直接重复执行；若任务长期停留此状态，应先人工确认操作是否已生效。</p></div>';
		if(!empty($d['rollback']['attempted']))echo '<div class="notice '.(!empty($d['rollback']['ok'])?'notice-success':'notice-error').' inline"><p>'.(!empty($d['rollback']['ok'])?'已尝试回滚商品修改：':'自动回滚未能确认成功：').esc_html(isset($d['rollback']['details'])?implode('; ',array_map('strval',(array)$d['rollback']['details'])):($d['rollback']['error']??'请检查商品状态')).'</p></div>';
		if($d['status']==='pending')echo '<div class="hsm-actions"><form method="post"><input type="hidden" name="hsm_review_nonce" value="'.esc_attr($nonce).'"><input type="hidden" name="hsm_review_id" value="'.(int)$p->ID.'"><input type="hidden" name="hsm_review_action" value="approve"><button class="button button-primary">批准</button></form><form method="post"><input type="hidden" name="hsm_review_nonce" value="'.esc_attr($nonce).'"><input type="hidden" name="hsm_review_id" value="'.(int)$p->ID.'"><input type="hidden" name="hsm_review_action" value="reject"><input type="text" name="hsm_reject_reason" placeholder="拒绝原因（可选）"><button class="button">拒绝</button></form></div>';
		elseif($d['status']==='approved')echo '<div class="hsm-actions"><form method="post"><input type="hidden" name="hsm_review_nonce" value="'.esc_attr($nonce).'"><input type="hidden" name="hsm_review_id" value="'.(int)$p->ID.'"><input type="hidden" name="hsm_review_action" value="execute"><button class="button button-primary">执行已批准操作</button></form></div>';
		if(!empty($d['audit'])){echo '<details class="hsm-details"><summary>审核记录</summary><ul>';foreach($d['audit'] as $a)echo '<li>'.esc_html($a['event']??'').' · '.esc_html($a['time']??'').' · user #'.(int)($a['user']??0).'</li>';echo '</ul></details>';}
		echo '</section>';
	}
	echo '</div>';
}
function hsm_review_handle_post(){
	if(!is_admin()||!hsm_review_can_approve()||empty($_POST['hsm_review_action']))return;
	check_admin_referer('hsm_review_action','hsm_review_nonce');
	$id=absint($_POST['hsm_review_id']??0);$action=sanitize_key($_POST['hsm_review_action']);
	if(!in_array($action,array('approve','execute','reject'),true))return;
	$result=$action==='approve'?hsm_review_approve($id):($action==='execute'?hsm_review_execute($id):hsm_review_reject($id,$_POST['hsm_reject_reason']??''));
	$labels=array('approve'=>'批准','execute'=>'执行','reject'=>'拒绝');
	if(is_wp_error($result)){$notice=array('type'=>'error','message'=>$result->get_error_message());$next='pending';}
	else{$notice=array('type'=>'success','message'=>($labels[$action]??'操作').'成功。');$next=array('approve'=>'approved','execute'=>'executed','reject'=>'rejected')[$action];}
	set_transient('hsm_review_notice_'.get_current_user_id(),$notice,60);
	$url=add_query_arg(array('page'=>'hsm-review','review_status'=>$next),admin_url('admin.php'));
	wp_safe_redirect($url);exit;
}
function hsm_review_admin_notice(){
	if(!is_admin()||!hsm_review_can_approve())return;
	$key='hsm_review_notice_'.get_current_user_id();$notice=get_transient($key);if(!$notice)return;delete_transient($key);
	$class=($notice['type']??'error')==='success'?'notice-success':'notice-error';
	echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html($notice['message']??'审核操作未完成。').'</p></div>';
}
add_action('admin_notices','hsm_review_admin_notice');
add_action('admin_init','hsm_review_handle_post');
