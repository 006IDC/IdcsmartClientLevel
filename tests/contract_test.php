<?php

$root = dirname(__DIR__);

function failTest($message)
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function requireContains($haystack, $needle, $label)
{
    if (strpos($haystack, $needle) === false) {
        failTest($label . ' 缺少: ' . $needle);
    }
}

function requireNotContains($haystack, $needle, $label)
{
    if (strpos($haystack, $needle) !== false) {
        failTest($label . ' 仍包含: ' . $needle);
    }
}

$entry = file_get_contents($root . '/IdcsmartClientLevel.php');
$routes = file_get_contents($root . '/route.php');
$model = file_get_contents($root . '/model/IdcsmartClientLevelModel.php');
$service = file_get_contents($root . '/model/ClientLevelService.php');

if (preg_match('/\bDROP\s+TABLE\b/i', $entry)) {
    failTest('安装或卸载逻辑不得删除业务表');
}

$requiredHooks = [
    'clientDiscountByAmount',
    'getClientLevelList',
    'adminClientIndex',
    'orderPaid',
    'afterOrderRefund',
    'afterClientRegister',
    'beforeClientEdit',
    'afterClientEdit',
    'afterClientDelete',
    'afterProductCreate',
    'afterProductCopy',
    'afterProductDelete',
    'afterIdcsmartWithdrawPass',
    'afterIdcsmartWithdrawReject',
    'afterIdcsmartWithdrawRejectPass',
    'afterIdcsmartWithdrawRejectPending',
    'minuteCron',
];
foreach ($requiredHooks as $method) {
    requireContains($entry, 'public function ' . $method . '(', 'V10 Hook');
}

$requiredTables = [
    'addon_idcsmart_client_level',
    'addon_idcsmart_client_level_client_link',
    'addon_idcsmart_client_level_product_link',
    'addon_idcsmart_client_level_product_group',
    'addon_idcsmart_client_level_order',
    'addon_idcsmart_client_level_setting',
    'addon_idcsmart_client_level_log',
    'addon_idcsmart_client_level_referrer',
    'addon_idcsmart_client_level_referral_bind',
    'addon_idcsmart_client_level_referral_accrual',
    'addon_idcsmart_client_level_benefit_account',
    'addon_idcsmart_client_level_benefit_allocation',
    'addon_idcsmart_client_level_benefit_allocation_item',
    'addon_idcsmart_client_level_benefit_flow',
    'addon_idcsmart_client_level_withdraw_method',
    'addon_idcsmart_client_level_withdraw',
    'addon_idcsmart_client_level_level_policy',
    'addon_idcsmart_client_level_manual_override',
    'addon_idcsmart_client_level_metric',
    'addon_idcsmart_client_level_audit',
];
foreach ($requiredTables as $table) {
    requireContains($entry, $table, '数据表契约');
}

$requiredRoutes = [
    "Route::get('',",
    "Route::get('product/:id/amount'",
    "Route::get('client/:id'",
    "Route::put('client'",
    "Route::get('all'",
    "Route::post('rebuild'",
    "Route::get('client-level/invite/:code'",
    "Route::post('benefit/allocate'",
    "Route::post('withdrawal'",
    "Route::post('half_agent/import'",
    "Route::get('products'",
    "Route::put('product/:id/rebate'",
];
foreach ($requiredRoutes as $route) {
    requireContains($routes, $route, '路由契约');
}
foreach (['_plugin', '_controller', '_action'] as $routeParam) {
    requireContains($routes, $routeParam, '插件路由默认参数');
}

requireContains($model, 'class IdcsmartClientLevelModel', '核心直连模型');
requireContains($model, 'public function productDiscount(', '核心直连折扣方法');
requireContains($model, 'public function clientDiscount(', 'Server 模块折扣方法');
requireContains($model, "'discount' => \$discount", 'Hook 折扣返回字段');
requireContains($entry, "'version' => '1.6.3'", '发布版本');
requireContains($entry, '`referral_level_amount`', '既有等级策略表推广贡献门槛');
requireContains($entry, '$this->ensureClientareaNavigation();', '覆盖升级前台导航同步');
requireContains($entry, '$this->ensureHookRegistrations();', '覆盖升级 Hook 同步');
requireContains($entry, '`official_withdraw_id`', '官方提现幂等关联字段');
requireContains($entry, '`eligible_review_time`', '提交时冻结审核时间快照');
requireContains($entry, 'removeLegacyWithdrawalAdminAuth', '旧插件提现权限清理迁移');
requireContains($entry, "Db::name('nav')->insertGetId", '前台 nav 幂等补齐');
requireContains($entry, "Db::name('menu')->insert", '前台 menu 幂等补齐');
requireContains($entry, '$url = \'plugin/\' . $pluginId . \'/index.htm\';', '前台插件 ID 路由');
requireContains($service, "'discount_status' => \$status === 1", '停用等级直连折扣保护');
requireContains($service, "(string) (\$order['type'] ?? '') === 'recharge'", '充值订单核心类型判断');
requireContains($service, "->lock(true)", '用户等级并发锁');
requireContains($service, "where('order_id', \$orderId)->update(\$data)", '订单账本并发更新优先');
requireContains($service, "'effective_amount'", '官方模板兼容进度字段');
requireContains($service, "'own_spend_level_enabled'", '本人消费升级通道开关');
requireContains($service, "'referral_contribution_level_enabled'", '推广贡献升级通道开关');
requireContains($service, 'dualAxisEvaluation', '双线独立等级评定');
requireContains($service, 'officialCompatibilityAmount', '官方模板单累计字段兼容投影');
requireContains($service, 'BenefitLedgerService::saveLevelPolicy($id, $param)', '等级与推广策略原子保存');
requireContains($service, "ReferralService::audit(\$isCreate ? 'level_create' : 'level_update'", '等级保存审计与主写同事务');
requireContains($service, "ReferralService::audit('level_delete'", '等级删除审计与主写同事务');
requireContains($service, "ReferralService::audit('client_level_assign'", '人工调级审计与主写同事务');
requireNotContains($service, 'Money::add($spend, $contribution)', '两项等级指标不得直接相加');
requireContains($service, 'MIN_REFERRAL_OBSERVATION_DAYS = 14', '强制退款观察期');
requireContains($service, 'MIN_WITHDRAWAL_REVIEW_DAYS = 7', '强制提现冻结审核期');
requireContains($service, "'refund_level_rollback_required' => 1", '退款强制等级回退标识');
requireContains($service, "strpos((string) \$source, 'refund') !== false", '退款 Hook 强制匹配应得等级');
requireContains($service, "'invite_default_path' => '/regist.htm'", '站内邀请默认落地页');
requireContains($service, 'normalizeInvitePath', '邀请落地页同源校验');

foreach (['ReferralService.php', 'BenefitLedgerService.php', 'WithdrawService.php'] as $serviceFile) {
    if (!is_file($root . '/model/' . $serviceFile)) {
        failTest('缺少双轴服务: ' . $serviceFile);
    }
}

$productRebate = file_get_contents($root . '/model/ProductRebateService.php');
requireContains($productRebate, "Db::name('product')", '官方商品事实源');
requireContains($productRebate, "Db::name('order_item')", '官方订单商品事实源');
requireContains($productRebate, "SETTING_PREFIX = 'referral_product_'", '逐商品返利开关');
requireContains($productRebate, "'scope' => 'payment_snapshot'", '支付时商品资格快照');

$benefit = file_get_contents($root . '/model/BenefitLedgerService.php');
$withdraw = file_get_contents($root . '/model/WithdrawService.php');
$clientScript = file_get_contents($root . '/public/client-level-client.js');
$clientTemplate = file_get_contents($root . '/template/clientarea/index.html');
requireContains($benefit, 'idempotency_key', '权益流水幂等键');
requireContains($benefit, 'eligible_paid_amount', '可返利商品金额快照');
requireContains($benefit, "->lock(true)", '权益账本并发锁');
requireContains($withdraw, "->lock(true)", '提现状态并发锁');
requireNotContains($withdraw, 'public static function review(', '不应保留插件内部审核入口');
requireNotContains($withdraw, 'public static function adminDetail(', '不应保留插件内部收款资料审核入口');
requireContains($withdraw, 'aes_password_encode', '收款账号加密存储');
requireContains($withdraw, 'eligibleReviewTime', '提现冻结期服务端校验');
requireContains($withdraw, "OFFICIAL_SOURCE = 'IdcsmartClientLevel'", '官方提现来源标识');
requireContains($withdraw, 'publishEligibleToOfficial', '冻结期后发布官方提现');
requireContains($withdraw, 'syncOfficialStatuses', '官方提现状态对账');
requireContains($withdraw, 'reconcileRefundExposure', '退款撤销在途提现');
requireContains($withdraw, 'withdraw_refund_release', '退款释放冻结财务流水');
requireContains($withdraw, "ReferralService::audit('withdraw_method_delete'", '收款方式删除审计');
requireContains($withdraw, "'withdraw_publish_official'", '官方提现发布审计');
requireContains($service, 'WithdrawService::reconcileRefundExposure', '退款 Hook 联动提现对账');

$referral = file_get_contents($root . '/model/ReferralService.php');
requireContains($referral, 'orderMetricsForClients', 'HalfAgent 客户经营数据融合');
requireContains($referral, "'same_login_ip'", '同登录 IP 人工风险提示');
requireContains($referral, "'high_refund_ratio'", '高退款比人工风险提示');
requireContains($referral, 'referralDashboard', '推广经营看板');
requireContains($referral, 'daily_net_spend', '近七日客户净消费');
requireContains($referral, "->whereIn('id', [\$referrerClientId, \$inviteeClientId])", '绑定并发固定顺序锁');
requireContains($referral, "'failures' => \$failures", 'HalfAgent 导入部分失败可见');

$adminTemplate = file_get_contents($root . '/template/admin/index.html');
$adminStyle = file_get_contents($root . '/public/client-level-admin.css');
$adminScript = file_get_contents($root . '/public/client-level-admin.js');
requireContains($adminTemplate, 'id="icl-official-withdrawals"', '官方提现管理入口');
requireContains($adminTemplate, '固定为管理员指定等级', '人工等级语义说明');
requireContains($adminStyle, 'grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto auto', '推广绑定输入防溢出');
requireContains($adminStyle, 'position:fixed', '全局可见操作结果通知');
requireContains($clientScript, 'icl:client-shell-mounted', '前台业务脚本等待官方壳层');
requireContains($clientTemplate, 'this.$nextTick(startClientLevelFeature)', '前台业务脚本挂载顺序');
requireContains($clientTemplate, 'id="icl-referral-conversion"', '用户推广转化概览');
requireContains($clientTemplate, 'id="icl-referral-month-reward"', '销售看板式月度权益概览');
requireContains($clientTemplate, 'id="icl-order-mix-ring"', '新购续费结构概览');
requireContains($clientScript, 'legacyCopyInvite', '剪贴板失败回退');
requireContains($clientScript, '复制失败，请长按或选中链接手动复制', '复制失败明确反馈');
requireContains($clientTemplate, 'id="icl-withdraw-form" class="icl-surface icl-client-form" novalidate', '提现使用业务校验而非浏览器原生冲突提示');
requireContains($clientTemplate, 'id="icl-withdraw-feedback"', '提现表单内操作反馈');
requireContains($clientTemplate, 'id="icl-withdraw-submit"', '提现按钮状态机挂载点');
requireContains($clientTemplate, 'aria-live="polite"', '提现反馈辅助技术可感知');
requireContains($clientScript, 'renderWithdrawalEligibility', '提现资格预判');
requireContains($clientScript, '当前可提现余额为 0.00 元', '零余额明确说明');
requireContains($clientScript, '距离最低提现金额还差', '不足最低金额差额说明');
requireContains($clientScript, "button.textContent = withdrawSubmitting ? '正在提交…'", '提现提交中状态');
requireContains($clientScript, '申请已提交，但余额和记录刷新未完成', '提现主写成功与刷新失败分离');
requireContains($clientScript, '网络连接中断，未能确认操作结果，请刷新页面核对', '写请求结果未知语义');
requireContains($adminTemplate, '风险提示只用于人工复核', '风险标签不自动封禁说明');
requireContains($adminTemplate, 'id="icl-invite-default-path"', '邀请默认落地页配置');
requireContains($adminTemplate, 'id="icl-own-spend-level-enabled"', '本人消费通道开关界面');
requireContains($adminTemplate, 'id="icl-referral-contribution-level-enabled"', '推广贡献通道开关界面');
requireContains($adminTemplate, 'id="icl-level-referral-amount"', '推广贡献独立等级门槛界面');
requireContains($adminTemplate, 'id="icl-level-feedback"', '等级弹窗内保存反馈');
requireContains($adminScript, '正在保存等级', '等级保存中反馈');
requireContains($adminScript, '等级已保存；列表刷新未完成', '保存成功与刷新失败分离');
requireContains($service, '$changes = [];', '设置先完整校验再写入');
requireContains($service, "ReferralService::audit('settings_update'", '设置保存操作记录');
requireContains($productRebate, "ReferralService::audit('product_rebate_switch'", '商品返利开关操作记录');
requireContains($productRebate, 'Db::startTrans()', '商品返利开关事务');
requireContains($clientTemplate, 'id="icl-allocation-form" class="icl-surface icl-client-form" novalidate', '权益分配使用业务校验');
requireContains($clientTemplate, 'id="icl-allocation-feedback"', '权益分配表单内反馈');
requireContains($clientTemplate, 'id="icl-allocation-submit"', '权益分配按钮状态机挂载点');
requireContains($clientScript, 'renderAllocationEligibility', '权益分配资格预判');
requireContains($clientScript, '当前待分配权益为 0.00 元', '权益为零明确说明');
requireNotContains($adminScript, '服务器未返回 JSON', '管理员可见错误实现术语');
requireNotContains($adminScript, 'HTTP ', '管理员可见错误实现术语');
requireNotContains($adminScript, 'PHP 日志', '管理员可见错误实现术语');
requireContains($adminScript, 'if (!success)', '成功通知持续显示');
requireContains($adminScript, "setAttribute('title', '点击关闭')", '通知可手工关闭');
requireNotContains($adminScript, "return request('/level/' + levelId + '/policy'", '前端不应分两次保存等级');
requireContains($clientTemplate, 'id="icl-level-formula"', '前台动态等级评定说明');

foreach ([
    '核心用户表', '官方折扣等级', '官方等级匹配金额', '官方等级权益',
    '权益账本净额', '计提规则', '权益计提记录', '进入官方审核', '业务号',
] as $internalPhrase) {
    requireNotContains($clientTemplate, $internalPhrase, '普通用户模板内部术语');
}
foreach ([
    '服务器响应格式异常', '官方待审核', '进入官方审核', '进入官方提现管理',
    '收款方式已加密保存', '暂无推广权益计提记录', '管理员指定等级', '自动计算等级',
] as $internalPhrase) {
    requireNotContains($clientScript, $internalPhrase, '普通用户脚本内部术语');
}
foreach (['业务幂等号格式错误', '业务幂等号已被其他请求使用'] as $internalPhrase) {
    requireNotContains($benefit, $internalPhrase, '用户权益错误提示内部术语');
}
foreach ([
    '系统加密能力不可用', '请求幂等号格式错误', '请求幂等号已被其他提现使用',
    '官方提现插件未启用或未配置收款方式，暂时无法提交推广提现',
    '提现申请已提交，冻结期结束后将自动进入官方提现管理',
    '该申请已进入官方审核流程，请在官方提现管理中处理',
] as $internalPhrase) {
    requireNotContains($withdraw, $internalPhrase, '用户提现错误提示内部术语');
}

if (substr_count($entry, 'CREATE TABLE IF NOT EXISTS') !== 20) {
    failTest('1.6.3 不应新增业务表');
}

echo "contract_test: OK\n";
