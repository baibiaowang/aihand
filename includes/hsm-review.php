<?php
defined('ABSPATH') || exit;

function hsm_review_register() {
	register_post_type('hsm_review',array(
		'public'=>false,'show_ui'=>false,'rewrite'=>false,'query_var'=>false,
		'supports'=>array('title','author')
	));
}
add_action('init','hsm_review_register',5);

function hsm_review_canonical($ability,$input){ unset($input['confirm_token']); return hash('sha256',$ability.'|'.wp_json_encode(hsm_canonical($input))); }

function hsm_review_create($ability,$input,$reason,$preview,$token){
	$post_id=wp_insert_post(array(
		'post_type'=>'hsm_review','post_status'=>'private',
		'post_title'=>sanitize_text_field('Pending AI operation: '.$ability),
		'post_author'=>get_current_user_id(),
		'post_content'=>wp_slash(wp_json_encode(array(
			'version'=>1,'status'=>'pending','ability'=>$ability,'reason'=>$reason,
			'input'=>$input,'preview'=>$preview,'token'=>$token,
			'input_hash'=>hsm_review_canonical($ability,$input),'created_at'=>current_time('mysql',true),
			'expires_at'=>gmdate('Y-m-d H:i:s',time()+(int)hsm_guard('token_ttl'))
		),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
	),true);
	return is_wp_error($post_id)?0:$post_id;
}
function hsm_review_read($id){
	$p=get_post((int)$id);if(!$p||'hsm_review'!==$p->post_type)return null;
	$d=json_decode($p->post_content,true);if(!is_array($d))return null;
	$d['id']=(int)$p->ID;$d['user']=get_userdata($p->post_author);$d['user']=$d['user']?$d['user']->user_login:'unknown';return $d;
}
function hsm_review_update($id,$data){
	$d=hsm_review_read($id);if(!$d)return false;foreach($data as $k=>$v)$d[$k]=$v;
	return wp_update_post(array('ID'=>(int)$id,'post_content'=>wp_slash(wp_json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))),true);
}
function hsm_review_can_approve(){return current_user_can('manage_woocommerce')||current_user_can('manage_options');}

function hsm_review_execute($id){
	if(!hsm_review_can_approve())return new WP_Error('forbidden','Only store managers may approve AI operations.');
	$d=hsm_review_read($id);if(!$d)return new WP_Error('not_found','Review task not found.');
	if('pending'!==$d['status'])return new WP_Error('invalid_state','This review is no longer pending.');
	if(!empty($d['expires_at'])&&strtotime($d['expires_at'].' UTC')<time()){hsm_review_update($id,array('status'=>'expired'));return new WP_Error('expired','Review task expired.');}
	if(hsm_review_canonical($d['ability'],$d['input'])!==$d['input_hash'])return new WP_Error('tampered','Operation plan changed.');
	$ability=wp_get_ability($d['ability']);
	if(!$ability)return new WP_Error('ability_missing','The original AI ability is no longer available.');
	$ref=HSM_Registry::$abilities[$d['ability']]??null;
	if(!$ref)return new WP_Error('ability_missing','The original AI ability definition is unavailable.');
	// Consume the original AI confirmation token before executing. The human approval is now the gate.
	delete_transient('hsm_tok_'.preg_replace('/[^A-Za-z0-9]/','',(string)$d['token']));
	$input=$d['input'];$input['confirm_token']=$d['token'];
	// The original confirmation token is bound to the AI caller. Approval is the human gate;
	// this scoped flag lets the already-approved exact plan pass without rebinding it to the approver.
	$GLOBALS['hsm_review_execution']=array('id'=>(int)$id,'ability'=>$d['ability'],'input_hash'=>$d['input_hash']);
	try{
		$result=hsm_run_ability($d['ability'],$ref,$input);
		if(is_wp_error($result)){hsm_review_update($id,array('status'=>'execution_error','error'=>$result->get_error_message()));return $result;}
		hsm_review_update($id,array('status'=>'executed','approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true),'executed_at'=>current_time('mysql',true),'result'=>$result));
		return $result;
	}finally{unset($GLOBALS['hsm_review_execution']);}
}

function hsm_review_menu(){
	if(!hsm_review_can_approve())return;
	add_submenu_page('woocommerce','AI 操作审核','AI 操作审核','manage_woocommerce','hsm-review','hsm_review_page');
}
add_action('admin_menu','hsm_review_menu',30);

function hsm_review_page(){
	if(!hsm_review_can_approve())wp_die('Forbidden');
	$posts=get_posts(array('post_type'=>'hsm_review','post_status'=>'private','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC'));
	$nonce=wp_create_nonce('hsm_review_action');
	echo '<div class="wrap"><h1>AI 操作审核台</h1><p>AI 的写操作先进入这里；打开预览不会修改线上数据。</p>';
	echo '<style>
	.hsm-review{background:#fff;border:1px solid #dcdcde;border-radius:10px;margin:16px 0;padding:18px;max-width:1100px}
	.hsm-review-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.hsm-before{background:#fff5f5}.hsm-after{background:#f0fff4}
	.hsm-box{padding:12px;border-radius:8px;border:1px solid #ddd}.hsm-risk{font-weight:700;color:#b32d2e}.hsm-actions{display:flex;gap:8px;margin-top:14px}
	.hsm-json{white-space:pre-wrap;background:#f6f7f7;padding:10px;max-height:280px;overflow:auto;font-size:12px}
	@media(max-width:800px){.hsm-review-grid{grid-template-columns:1fr}}
	</style>';
	foreach($posts as $p){
		$d=hsm_review_read($p->ID);if(!$d)continue;
		$status=esc_html($d['status']);$risk=isset($d['preview']['risk'])?$d['preview']['risk']:'confirmation';
		echo '<section class="hsm-review"><h2>'.esc_html($d['ability']).' <small>#'.(int)$p->ID.'</small></h2>';
		echo '<p><strong>状态：</strong>'.$status.'　<strong>风险：</strong><span class="hsm-risk">'.esc_html($risk).'</span></p>';
		echo '<p>'.esc_html($d['reason']).'</p>';
		if(!empty($d['preview']['changes'])&&is_array($d['preview']['changes'])){
			echo '<div class="hsm-review-grid"><div class="hsm-box hsm-before"><strong>修改前</strong><div class="hsm-json">'.esc_html(wp_json_encode(array_map(function($x){return array('id'=>$x['id']??null,'field'=>$x['field']??null,'value'=>$x['before']??null);},$d['preview']['changes']),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</div></div>';
			echo '<div class="hsm-box hsm-after"><strong>修改后</strong><div class="hsm-json">'.esc_html(wp_json_encode(array_map(function($x){return array('id'=>$x['id']??null,'field'=>$x['field']??null,'value'=>$x['after']??null);},$d['preview']['changes']),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</div></div></div>';
		}else{echo '<div class="hsm-box"><strong>操作预览</strong><div class="hsm-json">'.esc_html(wp_json_encode($d['preview'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</div></div>';}
		if('pending'===$d['status']){
			echo '<div class="hsm-actions"><form method="post"><input type="hidden" name="hsm_review_nonce" value="'.esc_attr($nonce).'"><input type="hidden" name="hsm_review_id" value="'.(int)$p->ID.'"><input type="hidden" name="hsm_review_action" value="approve"><button class="button button-primary">批准并执行</button></form>';
			echo '<form method="post"><input type="hidden" name="hsm_review_nonce" value="'.esc_attr($nonce).'"><input type="hidden" name="hsm_review_id" value="'.(int)$p->ID.'"><input type="hidden" name="hsm_review_action" value="reject"><button class="button">拒绝</button></form></div>';
		}
		echo '</section>';
	}
	echo '</div>';
}
function hsm_review_handle_post(){
	if(!is_admin()||!hsm_review_can_approve()||empty($_POST['hsm_review_action']))return;
	check_admin_referer('hsm_review_action','hsm_review_nonce');
	$id=absint($_POST['hsm_review_id']??0);$action=sanitize_key($_POST['hsm_review_action']);
	if('approve'===$action)hsm_review_execute($id);elseif('reject'===$action){$d=hsm_review_read($id);if($d&&'pending'===$d['status'])hsm_review_update($id,array('status'=>'rejected','rejected_by'=>get_current_user_id(),'rejected_at'=>current_time('mysql',true)));}
}
add_action('admin_init','hsm_review_handle_post');
