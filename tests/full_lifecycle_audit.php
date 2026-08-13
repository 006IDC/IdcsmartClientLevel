<?php

/**
 * Read-only-style financial lifecycle audit for an installed V10 system.
 *
 * Fixtures and setting changes are wrapped in one database transaction and
 * rolled back. The script reports contract failures instead of stopping at the
 * first one so withdrawal/refund timing windows can be compared in one run.
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
use addon\idcsmart_client_level\lib\Money;
use addon\idcsmart_client_level\model\BenefitLedgerService;
use addon\idcsmart_client_level\model\ProductRebateService;
use addon\idcsmart_client_level\model\ReferralService;
use addon\idcsmart_client_level\model\WithdrawService;
use think\facade\Db;

$lifeChecks = [];
$lifeSequence = 0;
$lifePlugin = new IdcsmartClientLevel();

function lifeCheck($name, $condition, $actual = null, $severity = 'P1')
{
    global $lifeChecks;
    $lifeChecks[] = [
        'name' => (string) $name,
        'result' => $condition ? 'PASS' : 'FAIL',
        'severity' => $condition ? '' : (string) $severity,
        'actual' => $actual,
    ];
}

function lifeRequire($condition, $message)
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function lifeClient($label, $now)
{
    global $lifeSequence;
    $lifeSequence++;
    $suffix = $now . '_' . $lifeSequence . '_' . bin2hex(random_bytes(3));
    return (int) Db::name('client')->insertGetId([
        'username' => 'life_' . $label . '_' . $suffix,
        'status' => 1,
        'email' => 'life-' . $label . '-' . $suffix . '@example.com',
        'phone_code' => 86,
        'phone' => '',
        'password' => '',
        'create_time' => $now,
        'update_time' => $now,
    ]);
}

function lifePaidOrder($referrerId, $label, $productId, $paidAt, $amount = '100.00', $items = null)
{
    global $lifePlugin;
    $now = time();
    $inviteeId = lifeClient($label . '_invitee', $now);
    $lifePlugin->afterClientRegister(['id' => $inviteeId]);
    $bind = ReferralService::bind($referrerId, $inviteeId, true, 'lifecycle_audit', 0);
    lifeRequire((int) ($bind['status'] ?? 0) === 200, $label . ': referral bind failed');

    $orderId = (int) Db::name('order')->insertGetId([
        'client_id' => $inviteeId,
        'type' => 'new',
        'status' => 'Paid',
        'amount' => Money::normalize($amount),
        'refund_amount' => '0.00',
        'pay_time' => (int) $paidAt,
        'create_time' => (int) $paidAt,
        'update_time' => (int) $paidAt,
    ]);
    $items = is_array($items) ? $items : [[$productId, $amount]];
    foreach ($items as $item) {
        Db::name('order_item')->insert([
            'order_id' => $orderId,
            'client_id' => $inviteeId,
            'product_id' => (int) $item[0],
            'type' => 'host',
            'amount' => Money::normalize($item[1]),
            'create_time' => (int) $paidAt,
            'update_time' => (int) $paidAt,
        ]);
    }
    $lifePlugin->orderPaid(['id' => $orderId]);
    return ['order_id' => $orderId, 'invitee_id' => $inviteeId];
}

function lifeRefund($orderId, $cumulativeAmount)
{
    global $lifePlugin;
    Db::name('order')->where('id', (int) $orderId)->update([
        'status' => 'Refunded',
        'refund_amount' => Money::normalize($cumulativeAmount),
        'update_time' => time(),
    ]);
    $lifePlugin->afterOrderRefund(['id' => (int) $orderId]);
}

function lifeMethod($clientId, $label)
{
    $result = WithdrawService::saveMethod($clientId, [
        'type' => 'bank',
        'account' => '62220202020202' . str_pad((string) ($clientId % 10000), 4, '0', STR_PAD_LEFT),
        'name' => 'Lifecycle ' . $label,
        'is_default' => 1,
    ]);
    lifeRequire((int) ($result['status'] ?? 0) === 200, $label . ': payout method failed');
    return (int) $result['data']['id'];
}

function lifeCashReady($label, $productId, $paidAt, $amount = '100.00')
{
    global $lifePlugin;
    $now = time();
    $referrerId = lifeClient($label . '_referrer', $now);
    $lifePlugin->afterClientRegister(['id' => $referrerId]);
    $order = lifePaidOrder($referrerId, $label, $productId, $paidAt, $amount);
    $allocated = BenefitLedgerService::allocate(
        $referrerId,
        $amount,
        'withdrawable',
        'life_alloc_' . bin2hex(random_bytes(10))
    );
    lifeRequire((int) ($allocated['status'] ?? 0) === 200, $label . ': cash allocation failed');
    return [$referrerId, $order['order_id']];
}

function lifeWithdraw($clientId, $methodId, $amount = '80.00')
{
    $result = WithdrawService::create(
        $clientId,
        $amount,
        $methodId,
        'life_withdraw_' . bin2hex(random_bytes(10))
    );
    lifeRequire((int) ($result['status'] ?? 0) === 200, 'withdrawal creation failed');
    return (int) $result['data']['id'];
}

function lifeAgeAndPublish($clientId, $withdrawId)
{
    Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->update([
        'create_time' => time() - (8 * 86400),
        'eligible_review_time' => time() - 1,
        'update_time' => time(),
    ]);
    $published = WithdrawService::publishEligibleToOfficial(20, $clientId);
    $row = Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->find();
    return [$published, $row, (int) ($row['official_withdraw_id'] ?? 0)];
}

$result = [];
Db::startTrans();
try {
    $now = time();
    $pluginRow = Db::name('plugin')->where('name', 'IdcsmartClientLevel')->where('module', 'addon')->find();
    lifeRequire(!empty($pluginRow) && (int) $pluginRow['status'] === 1, 'plugin is not installed and enabled');

    foreach ([
        'auto_upgrade' => '1',
        'own_spend_level_enabled' => '1',
        'referral_contribution_level_enabled' => '1',
        'referral_enabled' => '1',
        'referral_reward_rate' => '100.00',
        'contribution_exchange_rate' => '100.00',
        'min_withdraw_amount' => '1.00',
        'referral_observation_days' => '14',
        'withdrawal_review_days' => '7',
        'default_allocation' => 'manual',
    ] as $key => $value) {
        Db::name('addon_idcsmart_client_level_setting')->where('setting_key', $key)->update([
            'setting_value' => $value,
            'update_time' => $now,
        ]);
    }

    $productId = (int) Db::name('product')->insertGetId([
        'name' => 'Lifecycle Eligible ' . $now,
        'product_group_id' => 0,
        'pay_type' => 'onetime',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $excludedProductId = (int) Db::name('product')->insertGetId([
        'name' => 'Lifecycle Excluded ' . $now,
        'product_group_id' => 0,
        'pay_type' => 'onetime',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    ProductRebateService::saveProductEligibility($productId, 1);
    ProductRebateService::saveProductEligibility($excludedProductId, 0);

    // Refund while reward is still in the observation period.
    $pendingReferrer = lifeClient('pending_referrer', $now);
    $lifePlugin->afterClientRegister(['id' => $pendingReferrer]);
    $pendingOrder = lifePaidOrder($pendingReferrer, 'pending', $productId, $now, '100.00');
    lifeRefund($pendingOrder['order_id'], '30.00');
    $pendingAccount = BenefitLedgerService::accountSummary($pendingReferrer, false);
    $pendingAccrual = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $pendingOrder['order_id'])->find();
    lifeCheck('refund_pending_observation', Money::compare($pendingAccount['pending'], '70.00') === 0 && Money::compare($pendingAccount['debt'], '0.00') === 0, $pendingAccount);
    $pendingRevision = (int) $pendingAccrual['revision'];
    lifeRefund($pendingOrder['order_id'], '30.00');
    $pendingRepeat = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $pendingOrder['order_id'])->find();
    lifeCheck('repeated_cumulative_refund_is_idempotent', (int) $pendingRepeat['revision'] === $pendingRevision && Money::compare($pendingRepeat['net_entitlement'], '70.00') === 0, $pendingRepeat);
    $pendingReferralReport = ReferralService::referrals($pendingReferrer, ['limit' => 20]);
    lifeCheck('official_order_customer_metrics',
        (int) ($pendingReferralReport['summary']['total_clients'] ?? 0) === 1
        && (int) ($pendingReferralReport['summary']['paying_clients'] ?? 0) === 1
        && (int) ($pendingReferralReport['summary']['paid_order_count'] ?? 0) === 1
        && Money::compare($pendingReferralReport['summary']['gross_paid_amount'] ?? 0, '100.00') === 0
        && Money::compare($pendingReferralReport['summary']['refund_amount'] ?? 0, '30.00') === 0
        && Money::compare($pendingReferralReport['summary']['net_amount'] ?? 0, '70.00') === 0,
        $pendingReferralReport['summary'] ?? []);
    $pendingDashboard = $pendingReferralReport['dashboard'] ?? [];
    $pendingDailyTotal = '0.00';
    foreach (($pendingDashboard['daily_net_spend'] ?? []) as $dailyPoint) {
        $pendingDailyTotal = Money::add($pendingDailyTotal, $dailyPoint['net_amount'] ?? 0);
    }
    lifeCheck('referral_dashboard_uses_existing_ledger_and_official_orders',
        Money::compare($pendingDashboard['month_reward'] ?? 0, '70.00') === 0
        && Money::compare($pendingDashboard['today_reward'] ?? 0, '70.00') === 0
        && Money::compare($pendingDashboard['total_reward'] ?? 0, '70.00') === 0
        && (int) ($pendingDashboard['total_clients'] ?? 0) === 1
        && (int) ($pendingDashboard['order_mix']['new'] ?? 0) === 1
        && (int) ($pendingDashboard['order_mix']['renew'] ?? 0) === 0
        && count($pendingDashboard['daily_net_spend'] ?? []) === 7
        && Money::compare($pendingDailyTotal, '70.00') === 0,
        $pendingDashboard);
    Db::name('client')->whereIn('id', [$pendingReferrer, $pendingOrder['invitee_id']])->update(['last_login_ip' => '198.51.100.8']);
    $pendingRiskReport = ReferralService::adminBinds(['client_id' => $pendingOrder['invitee_id'], 'limit' => 20]);
    $pendingRiskFlags = $pendingRiskReport['list'][0]['risk_flags'] ?? [];
    lifeCheck('same_login_ip_is_advisory_flag', in_array('same_login_ip', $pendingRiskFlags, true), $pendingRiskFlags);

    // Refund after maturity but before the user chooses an allocation axis.
    $unallocatedReferrer = lifeClient('unallocated_referrer', $now);
    $lifePlugin->afterClientRegister(['id' => $unallocatedReferrer]);
    $unallocatedOrder = lifePaidOrder($unallocatedReferrer, 'unallocated', $productId, $now - 20 * 86400, '100.00');
    lifeRefund($unallocatedOrder['order_id'], '30.00');
    $unallocatedAccount = BenefitLedgerService::accountSummary($unallocatedReferrer, false);
    lifeCheck('refund_mature_unallocated', Money::compare($unallocatedAccount['unallocated'], '70.00') === 0 && Money::compare($unallocatedAccount['debt'], '0.00') === 0, $unallocatedAccount);

    // Refund after allocation but before withdrawal.
    [$availableReferrer, $availableOrderId] = lifeCashReady('available', $productId, $now - 20 * 86400);
    lifeRefund($availableOrderId, '30.00');
    $availableAccount = BenefitLedgerService::accountSummary($availableReferrer, false);
    lifeCheck('refund_available_cash_before_withdrawal', Money::compare($availableAccount['withdrawable'], '70.00') === 0 && Money::compare($availableAccount['debt'], '0.00') === 0, $availableAccount);

    // Mixed eligible/excluded products use the conservative order-level refund rule.
    $mixedReferrer = lifeClient('mixed_referrer', $now);
    $lifePlugin->afterClientRegister(['id' => $mixedReferrer]);
    $mixedOrder = lifePaidOrder($mixedReferrer, 'mixed', $productId, $now - 20 * 86400, '200.00', [
        [$productId, '100.00'],
        [$excludedProductId, '100.00'],
    ]);
    lifeRefund($mixedOrder['order_id'], '30.00');
    $mixedAccrual = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $mixedOrder['order_id'])->find();
    lifeCheck('mixed_product_refund_conservative_allocation', Money::compare($mixedAccrual['eligible_paid_amount'], '100.00') === 0 && Money::compare($mixedAccrual['net_entitlement'], '70.00') === 0, $mixedAccrual);
    lifeRefund($mixedOrder['order_id'], '250.00');
    lifeRefund($mixedOrder['order_id'], '250.00');
    $mixedFull = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $mixedOrder['order_id'])->find();
    lifeCheck('over_refund_clamped_and_idempotent', Money::compare($mixedFull['net_entitlement'], '0.00') === 0, $mixedFull);
    $mixedRiskReport = ReferralService::adminBinds(['client_id' => $mixedOrder['invitee_id'], 'limit' => 20]);
    $mixedRiskFlags = $mixedRiskReport['list'][0]['risk_flags'] ?? [];
    lifeCheck('high_refund_ratio_is_advisory_flag', in_array('high_refund_ratio', $mixedRiskFlags, true), $mixedRiskFlags);

    // Refund during the private hold, before publication to the official manager.
    [$holdReferrer, $holdOrderId] = lifeCashReady('hold', $productId, $now - 20 * 86400);
    $holdMethodId = lifeMethod($holdReferrer, 'Hold');
    $holdWithdrawId = lifeWithdraw($holdReferrer, $holdMethodId);
    lifeRefund($holdOrderId, '50.00');
    $holdBeforePublish = BenefitLedgerService::accountSummary($holdReferrer, false);
    $holdRejected = Db::name('addon_idcsmart_client_level_withdraw')->where('id', $holdWithdrawId)->find();
    lifeCheck('refund_during_hold_auto_rejects_and_restores_net',
        (string) $holdRejected['status'] === WithdrawService::REJECTED
        && Money::compare($holdBeforePublish['withdraw_frozen'], '0.00') === 0
        && Money::compare($holdBeforePublish['withdrawable'], '50.00') === 0
        && Money::compare($holdBeforePublish['debt'], '0.00') === 0,
        ['withdraw' => $holdRejected, 'account' => $holdBeforePublish], 'P0');
    [$holdPublish, $holdWithdrawRow, $holdOfficialId] = lifeAgeAndPublish($holdReferrer, $holdWithdrawId);
    lifeCheck('refund_debt_blocks_official_publication', (int) ($holdPublish['data']['published'] ?? 0) === 0 && $holdOfficialId === 0, [
        'publish_result' => $holdPublish,
        'withdraw' => $holdWithdrawRow,
        'account' => BenefitLedgerService::accountSummary($holdReferrer, false),
    ], 'P0');
    $cancelled = WithdrawService::cancel($holdReferrer, $holdWithdrawId);
    lifeCheck('refund_rejected_withdrawal_cannot_be_cancelled_again', (int) ($cancelled['status'] ?? 0) !== 200, $cancelled);

    // Refund while the official manager is still waiting for review.
    [$officialPendingReferrer, $officialPendingOrderId] = lifeCashReady('official_pending', $productId, $now - 20 * 86400);
    $officialPendingMethod = lifeMethod($officialPendingReferrer, 'Official Pending');
    $officialPendingWithdraw = lifeWithdraw($officialPendingReferrer, $officialPendingMethod);
    [$officialPendingPublish, $officialPendingRow, $officialPendingId] = lifeAgeAndPublish($officialPendingReferrer, $officialPendingWithdraw);
    lifeRequire((int) ($officialPendingPublish['data']['published'] ?? 0) === 1 && $officialPendingId > 0, 'official pending fixture did not publish');
    lifeRefund($officialPendingOrderId, '50.00');
    WithdrawService::syncOfficialStatuses(20, $officialPendingReferrer);
    $officialPendingState = Db::name('addon_idcsmart_withdraw')->where('id', $officialPendingId)->find();
    $internalPendingState = Db::name('addon_idcsmart_client_level_withdraw')->where('id', $officialPendingWithdraw)->find();
    lifeCheck('refund_while_official_pending_forces_reject', (int) $officialPendingState['status'] === 2 && (string) $internalPendingState['status'] === WithdrawService::REJECTED, [
        'official' => $officialPendingState,
        'internal' => $internalPendingState,
        'account' => BenefitLedgerService::accountSummary($officialPendingReferrer, false),
    ], 'P0');
    // Verify the existing rejection branch restores the correct net amount.
    Db::name('addon_idcsmart_withdraw')->where('id', $officialPendingId)->update(['status' => 2, 'reason' => 'lifecycle audit reject', 'update_time' => time()]);
    WithdrawService::syncOfficialStatuses(20, $officialPendingReferrer);
    $officialRejectedAccount = BenefitLedgerService::accountSummary($officialPendingReferrer, false);
    lifeCheck('official_reject_after_refund_restores_net_only', Money::compare($officialRejectedAccount['withdrawable'], '50.00') === 0 && Money::compare($officialRejectedAccount['withdraw_frozen'], '0.00') === 0 && Money::compare($officialRejectedAccount['debt'], '0.00') === 0, $officialRejectedAccount);

    // Refund after official approval but before remittance.
    [$approvedReferrer, $approvedOrderId] = lifeCashReady('approved', $productId, $now - 20 * 86400);
    $approvedMethod = lifeMethod($approvedReferrer, 'Approved');
    $approvedWithdraw = lifeWithdraw($approvedReferrer, $approvedMethod);
    [$approvedPublish, $approvedRow, $approvedOfficialId] = lifeAgeAndPublish($approvedReferrer, $approvedWithdraw);
    lifeRequire((int) ($approvedPublish['data']['published'] ?? 0) === 1 && $approvedOfficialId > 0, 'approved fixture did not publish');
    Db::name('addon_idcsmart_withdraw')->where('id', $approvedOfficialId)->update(['status' => 1, 'update_time' => time()]);
    WithdrawService::syncOfficialStatuses(20, $approvedReferrer);
    lifeRefund($approvedOrderId, '50.00');
    $approvedAfterRefund = Db::name('addon_idcsmart_client_level_withdraw')->where('id', $approvedWithdraw)->find();
    lifeCheck('refund_after_approval_revokes_payout', (string) $approvedAfterRefund['status'] === WithdrawService::REJECTED, [
        'withdraw' => $approvedAfterRefund,
        'account' => BenefitLedgerService::accountSummary($approvedReferrer, false),
    ], 'P0');
    $approvedOfficial = Db::name('addon_idcsmart_withdraw')->where('id', $approvedOfficialId)->find();
    lifeCheck('refund_after_approval_forces_official_reject', (int) $approvedOfficial['status'] === 2, $approvedOfficial, 'P0');

    // Once money really left the platform, refund shortfall must remain debt and
    // future reward must offset that debt before it becomes available again.
    [$paidReferrer, $paidOrderId] = lifeCashReady('already_paid', $productId, $now - 20 * 86400);
    $paidMethod = lifeMethod($paidReferrer, 'Already Paid');
    $paidWithdraw = lifeWithdraw($paidReferrer, $paidMethod);
    [$paidPublish, $paidRow, $paidOfficialId] = lifeAgeAndPublish($paidReferrer, $paidWithdraw);
    lifeRequire((int) ($paidPublish['data']['published'] ?? 0) === 1 && $paidOfficialId > 0, 'paid fixture did not publish');
    Db::name('addon_idcsmart_withdraw')->where('id', $paidOfficialId)->update(['status' => 3, 'update_time' => time()]);
    WithdrawService::syncOfficialStatuses(20, $paidReferrer);
    lifeRefund($paidOrderId, '50.00');
    $paidRefundAccount = BenefitLedgerService::accountSummary($paidReferrer, false);
    $paidState = Db::name('addon_idcsmart_client_level_withdraw')->where('id', $paidWithdraw)->find();
    lifeCheck('refund_after_real_payment_keeps_truth_and_debt',
        (string) $paidState['status'] === WithdrawService::PAID
        && Money::compare($paidRefundAccount['withdrawable'], '0.00') === 0
        && Money::compare($paidRefundAccount['debt'], '30.00') === 0,
        ['withdraw' => $paidState, 'account' => $paidRefundAccount], 'P0');
    $recoveryOrder = lifePaidOrder($paidReferrer, 'debt_recovery', $productId, $now - 20 * 86400, '40.00');
    $recovered = BenefitLedgerService::accountSummary($paidReferrer, false);
    lifeCheck('future_reward_offsets_post_paid_refund_debt', Money::compare($recovered['debt'], '0.00') === 0 && Money::compare($recovered['unallocated'], '10.00') === 0, [
        'order_id' => $recoveryOrder['order_id'],
        'account' => $recovered,
    ]);

    // A large refund may need to revoke more than one in-flight withdrawal.
    [$multiReferrer, $multiOrderId] = lifeCashReady('multi_withdraw', $productId, $now - 20 * 86400, '200.00');
    $multiMethod = lifeMethod($multiReferrer, 'Multi Withdraw');
    $multiFirst = lifeWithdraw($multiReferrer, $multiMethod, '80.00');
    $multiSecond = lifeWithdraw($multiReferrer, $multiMethod, '80.00');
    lifeRefund($multiOrderId, '130.00');
    $multiAccount = BenefitLedgerService::accountSummary($multiReferrer, false);
    $multiStatuses = Db::name('addon_idcsmart_client_level_withdraw')->whereIn('id', [$multiFirst, $multiSecond])->column('status', 'id');
    lifeCheck('large_refund_revokes_multiple_withdrawals',
        (string) ($multiStatuses[$multiFirst] ?? '') === WithdrawService::REJECTED
        && (string) ($multiStatuses[$multiSecond] ?? '') === WithdrawService::REJECTED
        && Money::compare($multiAccount['withdraw_frozen'], '0.00') === 0
        && Money::compare($multiAccount['debt'], '0.00') === 0
        && Money::compare($multiAccount['withdrawable'], '70.00') === 0,
        ['withdrawals' => $multiStatuses, 'account' => $multiAccount], 'P0');

    $failures = array_values(array_filter($lifeChecks, function ($row) {
        return $row['result'] === 'FAIL';
    }));
    $result = [
        'status' => empty($failures) ? 'OK' : 'AUDIT_FINDINGS',
        'plugin_version' => (string) $pluginRow['version'],
        'checks' => $lifeChecks,
        'failure_count' => count($failures),
        'fixtures_rolled_back' => true,
    ];
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
