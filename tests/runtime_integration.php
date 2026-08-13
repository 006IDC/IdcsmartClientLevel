<?php

/**
 * Installed-system integration test.
 *
 * Run from a configured ZJMF-CBAP installation. All fixtures are created in a
 * transaction and rolled back, so the test does not leave clients or orders.
 */

$root = dirname(__DIR__, 5);
require $root . '/config.php';
require $root . '/vendor/autoload.php';

defined('IDCSMART_ROOT') || define('IDCSMART_ROOT', $root . '/');
defined('WEB_ROOT') || define('WEB_ROOT', $root . '/public/');

$app = new \think\App();
$app->debug(APP_DEBUG);
$app->initialize();

use addon\idcsmart_client_level\IdcsmartClientLevel;
use addon\idcsmart_client_level\model\ClientLevelService;
use think\facade\Db;

function runtimeAssert($condition, $message)
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function runtimeMoney($value)
{
    return number_format((float) $value, 2, '.', '');
}

$plugin = new IdcsmartClientLevel();
$result = [];

Db::startTrans();
try {
    $now = time();
    $pluginRow = Db::name('plugin')
        ->where('name', 'IdcsmartClientLevel')
        ->where('module', 'addon')
        ->find();
    runtimeAssert(!empty($pluginRow) && (int) $pluginRow['status'] === 1, 'plugin is not installed and enabled');

    foreach ([
        'auto_upgrade' => '1',
        'own_spend_level_enabled' => '1',
        'referral_contribution_level_enabled' => '1',
    ] as $key => $value) {
        Db::name('addon_idcsmart_client_level_setting')
            ->where('setting_key', $key)
            ->update(['setting_value' => $value, 'update_time' => $now]);
    }

    $silverId = (int) Db::name('addon_idcsmart_client_level')->insertGetId([
        'name' => 'Runtime Silver',
        'amount' => '9137.00',
        'discount_percent' => '10.00',
        'discount_status' => 1,
        'status' => 1,
        'background_color' => '#94A3B8',
        'notes' => 'transactional integration fixture',
        'sort' => 901,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $goldId = (int) Db::name('addon_idcsmart_client_level')->insertGetId([
        'name' => 'Runtime Gold',
        'amount' => '18274.00',
        'discount_percent' => '20.00',
        'discount_status' => 1,
        'status' => 1,
        'background_color' => '#F59E0B',
        'notes' => 'transactional integration fixture',
        'sort' => 902,
        'create_time' => $now,
        'update_time' => $now,
    ]);

    $clientId = (int) Db::name('client')->insertGetId([
        'username' => 'level_runtime_' . $now,
        'status' => 1,
        'email' => 'level-runtime-' . $now . '@example.com',
        'phone_code' => 86,
        'phone' => '',
        'password' => '',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $productId = (int) Db::name('product')->insertGetId([
        'name' => 'Level Runtime Product',
        'product_group_id' => 0,
        'hidden' => 1,
        'pay_type' => 'onetime',
        'type' => 'server_group',
        'create_time' => $now,
        'update_time' => $now,
    ]);

    hook('after_client_register', ['id' => $clientId]);
    hook('after_product_create', ['id' => $productId]);
    runtimeAssert(
        Db::name('addon_idcsmart_client_level_product_link')
            ->where('product_id', $productId)
            ->whereIn('addon_idcsmart_client_level_id', [$silverId, $goldId])
            ->count() === 2,
        'after_product_create did not create level/product links'
    );

    $orderOneId = (int) Db::name('order')->insertGetId([
        'client_id' => $clientId,
        'type' => 'new',
        'status' => 'Paid',
        'amount' => '10000.00',
        'pay_time' => $now,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('order_item')->insert([
        'order_id' => $orderOneId,
        'client_id' => $clientId,
        'product_id' => $productId,
        'type' => 'host',
        'amount' => '10000.00',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    hook('order_paid', ['id' => $orderOneId]);

    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert((int) $link['addon_idcsmart_client_level_id'] === $silverId, 'first paid order did not upgrade to Silver');
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '10000.00', 'first paid order amount is incorrect');

    $silverDiscount = $plugin->clientDiscountByAmount([
        'client_id' => $clientId,
        'product_id' => $productId,
        'amount' => '200.00',
    ]);
    runtimeAssert(runtimeMoney($silverDiscount['data']['discount']) === '20.00', 'Silver discount should reduce 20.00 from 200.00');

    hook('order_paid', ['id' => $orderOneId]);
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '10000.00', 'repeated order_paid counted the order twice');
    runtimeAssert(
        Db::name('addon_idcsmart_client_level_order')->where('order_id', $orderOneId)->count() === 1,
        'order ledger is not idempotent'
    );

    $orderTwoId = (int) Db::name('order')->insertGetId([
        'client_id' => $clientId,
        'type' => 'renew',
        'status' => 'Paid',
        'amount' => '9000.00',
        'pay_time' => $now,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('order_item')->insert([
        'order_id' => $orderTwoId,
        'client_id' => $clientId,
        'product_id' => $productId,
        'type' => 'host',
        'amount' => '9000.00',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    hook('order_paid', ['id' => $orderTwoId]);

    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert((int) $link['addon_idcsmart_client_level_id'] === $goldId, 'second paid order did not upgrade to Gold');
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '19000.00', 'second paid order total is incorrect');

    $goldDiscount = $plugin->clientDiscountByAmount([
        'client_id' => $clientId,
        'product_id' => $productId,
        'amount' => '200.00',
    ]);
    runtimeAssert(runtimeMoney($goldDiscount['data']['discount']) === '40.00', 'Gold discount should reduce 40.00 from 200.00');

    Db::name('order')->where('id', $orderTwoId)->update([
        'status' => 'Refunded',
        'refund_amount' => '4000.00',
        'update_time' => time(),
    ]);
    hook('after_order_refund', ['id' => $orderTwoId]);
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '15000.00', 'refund was not deducted from cumulative spend');
    runtimeAssert((int) $link['addon_idcsmart_client_level_id'] === $silverId, 'refund did not immediately restore the earned level');

    $rechargeId = (int) Db::name('order')->insertGetId([
        'client_id' => $clientId,
        'type' => 'recharge',
        'status' => 'Paid',
        'amount' => '50000.00',
        'pay_time' => $now,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('order_item')->insert([
        'order_id' => $rechargeId,
        'client_id' => $clientId,
        'product_id' => 0,
        'type' => 'recharge',
        'amount' => '50000.00',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    hook('order_paid', ['id' => $rechargeId]);
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '15000.00', 'recharge should be excluded by default');

    $assignment = ClientLevelService::assignClient($clientId, $goldId, true, 'runtime_manual');
    runtimeAssert((int) $assignment['status'] === 200, 'manual level assignment failed');
    Db::name('order')->where('id', $orderTwoId)->update([
        'refund_amount' => '9000.00',
        'update_time' => time(),
    ]);
    hook('after_order_refund', ['id' => $orderTwoId]);
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '10000.00', 'manual-lock scenario cumulative spend is incorrect');
    runtimeAssert((int) $link['addon_idcsmart_client_level_id'] === $goldId, 'manual lock did not preserve assigned level');
    runtimeAssert((int) $link['manual_lock'] === 1, 'manual lock flag was not persisted');

    $mixedOrderId = (int) Db::name('order')->insertGetId([
        'client_id' => $clientId,
        'type' => 'new',
        'status' => 'Paid',
        'amount' => '250.00',
        'pay_time' => $now,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('order_item')->insert([
        'order_id' => $mixedOrderId,
        'client_id' => $clientId,
        'product_id' => $productId,
        'type' => 'recharge',
        'amount' => '250.00',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    hook('order_paid', ['id' => $mixedOrderId]);
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert(runtimeMoney($link['cumulative_amount']) === '10250.00', 'non-recharge order was excluded by an item type');
    runtimeAssert(
        (int) Db::name('addon_idcsmart_client_level_order')->where('order_id', $mixedOrderId)->value('is_consumption') === 1,
        'order type must be authoritative for recharge exclusion'
    );

    $logCountBeforeUnlock = (int) Db::name('addon_idcsmart_client_level_log')
        ->where('client_id', $clientId)
        ->count();
    $unlock = ClientLevelService::assignClient($clientId, 0, false, 'runtime_unlock');
    runtimeAssert((int) $unlock['status'] === 200, 'restoring automatic level failed');
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->find();
    runtimeAssert((int) $link['addon_idcsmart_client_level_id'] === $silverId, 'automatic restore did not match current spend');
    runtimeAssert((int) $link['manual_lock'] === 0, 'automatic restore did not clear manual lock');
    runtimeAssert(
        (int) Db::name('addon_idcsmart_client_level_log')->where('client_id', $clientId)->count() === $logCountBeforeUnlock + 1,
        'automatic restore must write one final transition instead of two'
    );

    $disableGold = ClientLevelService::saveLevel([
        'id' => $goldId,
        'name' => 'Runtime Gold',
        'amount' => '18274.00',
        'discount_percent' => '20.00',
        'discount_status' => 1,
        'status' => 0,
        'background_color' => '#F59E0B',
        'notes' => 'transactional integration fixture',
        'sort' => 902,
    ]);
    runtimeAssert((int) $disableGold['status'] === 200, 'disabling level failed');
    runtimeAssert(
        (int) Db::name('addon_idcsmart_client_level')->where('id', $goldId)->value('discount_status') === 0,
        'disabled level kept direct Server discount enabled'
    );

    $levelMap = $plugin->getClientLevelList(['client_id' => [$clientId]]);
    runtimeAssert(isset($levelMap[$clientId]) && (int) $levelMap[$clientId]['id'] === $silverId, 'get_client_level_list contract failed');
    $adminField = $plugin->adminClientIndex(['id' => $clientId]);
    runtimeAssert(
        (int) $adminField['data']['idcsmart_client_level']['id'] === $silverId
        && count($adminField['data']['idcsmart_client_level']['list']) >= 2,
        'admin_client_index contract failed'
    );

    $result = [
        'status' => 'OK',
        'plugin_version' => (string) $pluginRow['version'],
        'assertions' => [
            'hook_registration',
            'paid_order_upgrade',
            'discount_calculation',
            'order_idempotency',
            'refund_net_spend',
            'mandatory_refund_level_rollback',
            'recharge_exclusion',
            'order_type_recharge_authority',
            'manual_assignment_lock',
            'single_transition_auto_restore',
            'disabled_level_direct_discount_guard',
            'core_level_contracts',
        ],
        'final_transactional_state' => [
            'cumulative_amount' => runtimeMoney($link['cumulative_amount']),
            'level_id' => (int) $link['addon_idcsmart_client_level_id'],
            'manual_lock' => (int) $link['manual_lock'],
        ],
        'fixtures_rolled_back' => true,
    ];
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
