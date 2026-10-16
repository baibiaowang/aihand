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
function hsm_review_extract_ids($value,$ability='') {
	$ids=array();
	if(is_array($value)) foreach($value as $k=>$v) {
		if((in_array($k,array('product_id','variation_id'),true)||($k==='id'&&preg_match('/product|products-bulk/i',$ability)))&&is_numeric($v)&&(int)$v>0)$ids[]=(int)$v;
		$ids=array_merge($ids,hsm_review_extract_ids($v,$ability));
	}
	return array_values(array_unique($ids));
}
function hsm_review_server_snapshot($input,$preview=array()) {
	$ids=hsm_review_extract_ids($input,$GLOBALS['hsm_current_ability']??'');$products=array();
	foreach($ids as $id) {
		if(!function_exists('wc_get_product'))break;
		$p=wc_get_product($id);if(!$p)continue;
		$products[]=array('id'=>$id,'name'=>$p->get_name(),'type'=>$p->get_type(),'status'=>$p->get_status(),'url'=>get_permalink($id),'edit_url'=>get_edit_post_link($id,''),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'stock_status'=>$p->get_stock_status(),'stock_quantity'=>$p->get_stock_quantity());
	}
	return array('server_verified_at'=>current_time('mysql',true),'products'=>$products,'changes'=>is_array($preview)&&!empty($preview['changes'])?$preview['changes']:array(),'raw_preview'=>$preview,'validation'=>array('plan_hash_valid'=>true,'server_snapshot'=>!empty($products),'live_recheck'=>'performed again before execution'));
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
	$d=hsm_review_read($id);if(!$d)return;
	$log=$d['audit']??array();$log[]=array('event'=>sanitize_key($event),'user'=>get_current_user_id(),'time'=>current_time('mysql',true),'details'=>$details);
	hsm_review_update($id,array('audit'=>$log));
}
function hsm_review_can_approve(){return current_user_can('manage_woocommerce')||current_user_can('manage_options');}

function hsm_review_validate($d) {
	if(!$d)return new WP_Error('not_found','Review task not found.');
	if(!in_array($d['status']??'',array('pending','approved'),true))return new WP_Error('invalid_state','This review is no longer actionable.');
	if(!empty($d['expires_at'])&&strtotime($d['expires_at'].' UTC')<time())return new WP_Error('expired','Review task expired. Create a new proposal.');
	if(hsm_review_canonical($d['ability'],$d['input'])!==($d['input_hash']??''))return new WP_Error('tampered','Operation plan changed after it was created.');
	if(empty(HSM_Registry::$abilities[$d['ability']]))return new WP_Error('ability_missing','The original AI ability is no longer available.');
	$stored_products=$d['preview']['products']??array();
	foreach(hsm_review_extract_ids($d['input'],$d['ability']??'') as $id){
		if(!function_exists('wc_get_product'))continue;
		$p=wc_get_product($id);if(!$p)return new WP_Error('stale','A referenced product no longer exists.');
		$old=null;foreach($stored_products as $sp)if((int)($sp['id']??0)===$id){$old=$sp;break;}
		if($old){
			$live=array('name'=>$p->get_name(),'status'=>$p->get_status(),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'stock_status'=>$p->get_stock_status(),'stock_quantity'=>$p->get_stock_quantity());
			foreach($live as $key=>$value)if((string)$value!==(string)($old[$key]??''))return new WP_Error('stale',"Product #$id changed since this proposal was created ($key). Refresh the proposal before executing.");
		}
	}
	return true;
}
function hsm_review_approve($id) {
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may approve AI operations.');
	$d=hsm_review_read($id);$v=hsm_review_validate($d);if(is_wp_error($v)){if($v->get_error_code()==='expired')hsm_review_update($id,array('status'=>'expired'));return $v;}
	if($d['status']!=='pending')return new WP_Error('invalid_state','Only pending reviews can be approved.');
	hsm_review_update($id,array('status'=>'approved','approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true)));hsm_review_audit($id,'approved');return true;
}
function hsm_review_execute($id) {
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may execute approved AI operations.');
	$lock='hsm_review_lock_'.(int)$id;if(!add_option($lock,(string)get_current_user_id(),'',false))return new WP_Error('busy','This operation is already being executed.');
	try {
		$d=hsm_review_read($id);$v=hsm_review_validate($d);if(is_wp_error($v))return $v;
		if($d['status']!=='approved')return new WP_Error('invalid_state','Approve this review before execution.');
		$ref=HSM_Registry::$abilities[$d['ability']];
		$GLOBALS['hsm_review_execution']=array('id'=>(int)$id,'ability'=>$d['ability'],'input_hash'=>$d['input_hash']);
		hsm_review_update($id,array('status'=>'executing','executed_by'=>get_current_user_id(),'executing_at'=>current_time('mysql',true)));hsm_review_audit($id,'execution_started');
		$result=hsm_run_ability($d['ability'],$ref,$d['input']);
		if(is_wp_error($result)){hsm_review_update($id,array('status'=>'execution_error','error'=>$result->get_error_message()));hsm_review_audit($id,'execution_error',array('message'=>$result->get_error_message()));return $result;}
		hsm_review_update($id,array('status'=>'executed','executed_at'=>current_time('mysql',true),'result'=>$result));hsm_review_audit($id,'executed');return $result;
	} catch(Throwable $e) {
		hsm_review_update($id,array('status'=>'execution_error','error'=>$e->getMessage()));hsm_review_audit($id,'execution_error',array('message'=>$e->getMessage()));return new WP_Error('execution_error',$e->getMessage());
	} finally {unset($GLOBALS['hsm_review_execution']);delete_option($lock);}
}
function hsm_review_reject($id,$reason='') {
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may reject AI operations.');
	$d=hsm_review_read($id);if(!$d||$d['status']!=='pending')return new WP_Error('invalid_state','Only pending reviews can be rejected.');
	$reason=sanitize_textarea_field($reason);hsm_review_update($id,array('status'=>'rejected','rejected_by'=>get_current_user_id(),'rejected_at'=>current_time('mysql',true),'rejection_reason'=>$reason));hsm_review_audit($id,'rejected',array('reason'=>$reason));return true;
}
function hsm_review_menu(){if(!hsm_review_can_approve())return;add_submenu_page('woocommerce','AI 操作审核','AI 操作审核','manage_woocommerce','hsm-review','hsm_review_page');}
add_action('admin_menu','hsm_review_menu',30);
function hsm_review_status_label($s){return array('pending'=>'待审核','approved'=>'已批准','executing'=>'执行中','executed'=>'已完成','rejected'=>'已拒绝','expired'=>'已过期','execution_error'=>'执行失败')[$s]??$s;}
function hsm_review_risk($d){$n=is_array($d['preview']['changes']??null)?count($d['preview']['changes']):0;if($n>20)return array('high','高风险 · '.$n.' 项');if(($d['preview']['raw_preview']['risk']??'')==='high')return array('high','高风险');return array('medium',$n?'需审核 · '.$n.' 项':'需人工确认');}
function hsm_review_value($v){return is_array($v)||is_object($v)?wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):($v===null?'—':(string)$v);}
function hsm_review_page() {
	if(!hsm_review_can_approve())wp_die('Forbidden');
	$filter=sanitize_key($_GET['review_status']??'pending');$allowed=array('pending','approved','executing','executed','rejected','expired','execution_error','all');if(!in_array($filter,$allowed,true))$filter='pending';
	$posts=get_posts(array('post_type'=>'hsm_review','post_status'=>'private','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC'));$counts=array_fill_keys(array_slice($allowed,0,-1),0);
	foreach($posts as $p){$x=hsm_review_read($p->ID);if($x&&isset($counts[$x['status']]))$counts[$x['status']]++;}
	$nonce=wp_create_nonce('hsm_review_action');
	echo '<div class="wrap hsm-review-wrap"><h1>AI 操作审核台</h1><p class="description">AI 只能提出操作计划。预览不会修改线上数据；批准与执行前都会重新验证。</p><nav class="nav-tab-wrapper">';
	foreach(array('pending'=>'待审核','approved'=>'已批准','executing'=>'执行中','executed'=>'已完成','rejected'=>'已拒绝','expired'=>'已过期','execution_error'=>'失败') as $k=>$label)echo '<a class="nav-tab '.($filter===$k?'nav-tab-active':'').'" href="'.esc_url(add_query_arg(array('page'=>'hsm-review','review_status'=>$k),admin_url('admin.php'))).'">'.esc_html($label).' <span class="count">('.(int)$counts[$k].')</span></a>';
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
	if($action==='approve')hsm_review_approve($id);elseif($action==='execute')hsm_review_execute($id);elseif($action==='reject')hsm_review_reject($id,$_POST['hsm_reject_reason']??'');
}
add_action('admin_init','hsm_review_handle_post');
