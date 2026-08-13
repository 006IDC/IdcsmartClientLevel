<?php

/**
 * Installed-system dual-axis financial integration test.
 *
 * All fixtures and setting changes live inside one transaction and are rolled
 * back. Run only after the plugin's upgrade() migration has completed.
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
use addon\idcsmart_client_level\model\ClientLevelService;
use addon\idcsmart_client_level\model\ProductRebateService;
use addon\idcsmart_client_level\model\ReferralService;
use addon\idcsmart_client_level\model\WithdrawService;
use think\facade\Db;

function dualAssert($condition, $message)
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function dualClient($username, $now)
{
    return (int) Db::name('client')->insertGetId([
        'username' => $username,
        'status' => 1,
        'email' => $username . '@example.com',
        'phone_code' => 86,
        'phone' => '',
        'password' => '',
        'create_time' => $now,
        'update_time' => $now,
    ]);
}

$plugin = new IdcsmartClientLevel();
$result = [];

Db::startTrans();
try {
    $now = time();
    $pluginRow = Db::name('plugin')->where('name', 'IdcsmartClientLevel')->where('module', 'addon')->find();
    dualAssert(!empty($pluginRow) && (int) $pluginRow['status'] === 1, 'plugin is not installed and enabled');
    dualAssert(version_compare((string) $pluginRow['version'], '1.1.0', '>='), 'database plugin version is below 1.1.0');

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

    $maxLevelAmount = Money::normalize(Db::name('addon_idcsmart_client_level')->max('amount'));
    $threshold = Money::add($maxLevelAmount, '137.00');
    $levelSave = ClientLevelService::saveLevel([
        'name' => 'Dual Axis Runtime ' . $now,
        'amount' => $threshold,
        'referral_level_amount' => $threshold,
        'discount_percent' => '17.00',
        'discount_status' => 1,
        'status' => 1,
        'background_color' => '#7C3AED',
        'notes' => 'transactional dual-axis integration fixture',
        'sort' => 999,
        'reward_rate_override' => 0,
        'reward_rate' => '100.00',
        'contribution_rate_override' => 0,
        'contribution_rate' => '100.00',
        'min_withdraw_override' => 0,
        'min_withdraw' => '1.00',
    ]);
    dualAssert((int) $levelSave['status'] === 200, 'atomic level and referral policy save failed');
    $levelId = (int) ($levelSave['data']['id'] ?? 0);
    dualAssert($levelId > 0, 'atomic level save did not return level id');
    dualAssert(Money::compare(BenefitLedgerService::levelPolicy($levelId)['referral_level_amount'], $threshold) === 0, 'atomic referral threshold save failed');

    $referrerId = dualClient('dual_referrer_' . $now, $now);
    $inviteeId = dualClient('dual_invitee_' . $now, $now);
    $plugin->afterClientRegister(['id' => $referrerId]);
    $plugin->afterClientRegister(['id' => $inviteeId]);

    $bind = ReferralService::bind($referrerId, $inviteeId, true, 'runtime_test', 0);
    dualAssert((int) $bind['status'] === 200, 'referral bind failed');
    $cycle = ReferralService::bind($inviteeId, $referrerId, false, 'runtime_test', 0);
    dualAssert((int) $cycle['status'] === 400, 'referral cycle was not rejected');

    $eligibleProductId = (int) Db::name('product')->insertGetId([
        'name' => 'Dual Axis Rebate Product ' . $now,
        'product_group_id' => 0,
        'pay_type' => 'recurring_prepayment',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    dualAssert((int) ProductRebateService::saveProductEligibility($eligibleProductId, 1)['status'] === 200, 'eligible product switch save failed');

    $orderAmount = Money::add($threshold, '100.00');
    $paidAt = time() - (20 * 86400);
    $orderId = (int) Db::name('order')->insertGetId([
        'client_id' => $inviteeId,
        'type' => 'new',
        'status' => 'Paid',
        'amount' => $orderAmount,
        'refund_amount' => '0.00',
        'pay_time' => $paidAt,
        'create_time' => $paidAt,
        'update_time' => $paidAt,
    ]);
    Db::name('order_item')->insert([
        'order_id' => $orderId,
        'client_id' => $inviteeId,
        'product_id' => $eligibleProductId,
        'type' => 'host',
        'amount' => $orderAmount,
        'create_time' => $paidAt,
        'update_time' => $paidAt,
    ]);

    $plugin->orderPaid(['id' => $orderId]);
    $plugin->orderPaid(['id' => $orderId]);
    $accrual = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $orderId)->find();
    dualAssert(!empty($accrual), 'referral accrual was not created');
    dualAssert(Money::compare($accrual['net_entitlement'], $orderAmount) === 0, 'referral entitlement is incorrect');
    dualAssert((int) $accrual['revision'] === 1, 'repeated paid callback changed accrual revision');
    dualAssert(Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $orderId)->count() === 1, 'order accrual is not unique');
    dualAssert(Money::compare($accrual['eligible_paid_amount'], $orderAmount) === 0, 'eligible product payment snapshot is incorrect');

    $excludedProductId = (int) Db::name('product')->insertGetId([
        'name' => 'Dual Axis Activity Product ' . $now,
        'product_group_id' => 0,
        'pay_type' => 'onetime',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    dualAssert((int) ProductRebateService::saveProductEligibility($excludedProductId, 0)['status'] === 200, 'excluded product switch save failed');
    $excludedOrderId = (int) Db::name('order')->insertGetId([
        'client_id' => $inviteeId,
        'type' => 'new',
        'status' => 'Paid',
        'amount' => '50.00',
        'refund_amount' => '0.00',
        'pay_time' => $paidAt,
        'create_time' => $paidAt,
        'update_time' => $paidAt,
    ]);
    Db::name('order_item')->insert([
        'order_id' => $excludedOrderId,
        'client_id' => $inviteeId,
        'product_id' => $excludedProductId,
        'type' => 'host',
        'amount' => '50.00',
        'create_time' => $paidAt,
        'update_time' => $paidAt,
    ]);
    $plugin->orderPaid(['id' => $excludedOrderId]);
    dualAssert(Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $excludedOrderId)->count() === 0, 'excluded activity product generated referral accrual');

    $halfThreshold = bcdiv($threshold, '2', 2);
    $ownOrderId = (int) Db::name('order')->insertGetId([
        'client_id' => $referrerId,
        'type' => 'new',
        'status' => 'Paid',
        'amount' => $halfThreshold,
        'refund_amount' => '0.00',
        'pay_time' => $paidAt,
        'create_time' => $paidAt,
        'update_time' => $paidAt,
    ]);
    $plugin->orderPaid(['id' => $ownOrderId]);
    $splitBusiness = 'dual_split_' . bin2hex(random_bytes(8));
    $splitContribution = BenefitLedgerService::allocate($referrerId, $halfThreshold, 'contribution', $splitBusiness);
    dualAssert((int) $splitContribution['status'] === 200, 'split contribution allocation failed');
    $splitLink = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $referrerId)->find();
    dualAssert((int) $splitLink['addon_idcsmart_client_level_id'] !== $levelId, 'own spend and referral contribution were incorrectly added together');

    $contributionBusiness = 'dual_contribution_' . bin2hex(random_bytes(8));
    $remainingContribution = Money::subtract($threshold, $halfThreshold);
    $contribution = BenefitLedgerService::allocate($referrerId, $remainingContribution, 'contribution', $contributionBusiness);
    dualAssert((int) $contribution['status'] === 200, 'contribution allocation failed');
    $contributionAgain = BenefitLedgerService::allocate($referrerId, $remainingContribution, 'contribution', $contributionBusiness);
    dualAssert((int) $contributionAgain['status'] === 200, 'contribution allocation is not idempotent');
    dualAssert(Db::name('addon_idcsmart_client_level_benefit_allocation')->where('business_no', $contributionBusiness)->count() === 1, 'duplicate contribution allocation was inserted');

    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $referrerId)->find();
    dualAssert((int) $link['addon_idcsmart_client_level_id'] === $levelId, 'contribution did not upgrade the official level');
    dualAssert(Money::compare($link['cumulative_amount'], $threshold) === 0, 'official cumulative compatibility projection is incorrect');

    $axisSwitch = ClientLevelService::saveSettings(['referral_contribution_level_enabled' => 0]);
    dualAssert((int) $axisSwitch['status'] === 200, 'referral qualification switch save failed');
    dualAssert((int) ClientLevelService::settings()['referral_contribution_level_enabled'] === 0, 'referral qualification switch was not persisted');
    ClientLevelService::recalculateClient($referrerId, 'runtime_axis_disabled');
    $disabledLink = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $referrerId)->find();
    dualAssert((int) $disabledLink['addon_idcsmart_client_level_id'] === $levelId, 'disabling referral qualification unexpectedly downgraded the retained level');

    $cashBusiness = 'dual_withdrawable_' . bin2hex(random_bytes(8));
    $cash = BenefitLedgerService::allocate($referrerId, '100.00', 'withdrawable', $cashBusiness);
    dualAssert((int) $cash['status'] === 200, 'withdrawable allocation failed');

    $method = WithdrawService::saveMethod($referrerId, [
        'type' => 'bank',
        'account' => '6222020202020202020',
        'name' => 'Runtime Tester',
        'is_default' => 1,
    ]);
    dualAssert((int) $method['status'] === 200, 'encrypted payout method save failed');
    $methodId = (int) $method['data']['id'];
    $methodRow = Db::name('addon_idcsmart_client_level_withdraw_method')->where('id', $methodId)->find();
    dualAssert((string) $methodRow['account_cipher'] !== '6222020202020202020', 'payout account was stored in plaintext');
    dualAssert(strpos((string) $methodRow['account_mask'], '*') !== false, 'payout account mask is missing');

    $withdraw = WithdrawService::create($referrerId, '40.00', $methodId, 'dual_withdraw_req_' . bin2hex(random_bytes(8)));
    dualAssert((int) $withdraw['status'] === 200, 'withdrawal create failed');
    $withdrawId = (int) $withdraw['data']['id'];
    $lockedReviewTime = (int) Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->value('eligible_review_time');
    Db::name('addon_idcsmart_client_level_setting')->where('setting_key', 'withdrawal_review_days')->update([
        'setting_value' => '30',
        'update_time' => time(),
    ]);
    $listedWithdrawal = WithdrawService::listRows($referrerId, ['limit' => 20]);
    $listedReviewTime = 0;
    foreach ($listedWithdrawal['list'] as $listedRow) {
        if ((int) $listedRow['id'] === $withdrawId) {
            $listedReviewTime = (int) $listedRow['eligible_review_time'];
            break;
        }
    }
    dualAssert($lockedReviewTime > time() && $listedReviewTime === $lockedReviewTime, 'submitted withdrawal review deadline changed with current policy');
    Db::name('addon_idcsmart_client_level_setting')->where('setting_key', 'withdrawal_review_days')->update([
        'setting_value' => '7',
        'update_time' => time(),
    ]);
    $publishEarly = WithdrawService::publishEligibleToOfficial(20, $referrerId);
    dualAssert((int) $publishEarly['data']['published'] === 0, 'withdrawal bypassed mandatory review hold');
    dualAssert((int) Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->value('official_withdraw_id') === 0, 'early withdrawal reached official manager');
    Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->update([
        'create_time' => time() - (8 * 86400),
        'eligible_review_time' => time() - 1,
        'update_time' => time(),
    ]);
    $publish = WithdrawService::publishEligibleToOfficial(20, $referrerId);
    dualAssert((int) $publish['data']['published'] === 1, 'eligible withdrawal was not published to official manager');
    $officialId = (int) Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->value('official_withdraw_id');
    $official = Db::name('addon_idcsmart_withdraw')->where('id', $officialId)->find();
    dualAssert($officialId > 0 && (string) $official['source'] === 'IdcsmartClientLevel' && (int) $official['status'] === 0, 'official withdrawal source/link is incorrect');
    dualAssert((string) $official['account'] === '6222020202020202020', 'official manager did not receive the decrypted payout account');
    $cancelPublished = WithdrawService::cancel($referrerId, $withdrawId);
    dualAssert((int) $cancelPublished['status'] !== 200, 'published official withdrawal was cancellable outside official manager');
    Db::name('addon_idcsmart_withdraw')->where('id', $officialId)->update(['status' => 1, 'update_time' => time()]);
    hook('after_idcsmart_withdraw_pass', ['id' => $officialId, 'source' => 'IdcsmartClientLevel', 'amount' => '40.00']);
    dualAssert((string) Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->value('status') === WithdrawService::APPROVED, 'official approval hook did not update frozen ledger');
    Db::name('addon_idcsmart_withdraw')->where('id', $officialId)->update(['status' => 3, 'update_time' => time()]);
    WithdrawService::syncOfficialStatuses(20, $referrerId);
    dualAssert((string) Db::name('addon_idcsmart_client_level_withdraw')->where('id', $withdrawId)->value('status') === WithdrawService::PAID, 'official remittance reconciliation failed');
    WithdrawService::syncOfficialStatuses(20, $referrerId);
    dualAssert((int) Db::name('addon_idcsmart_client_level_benefit_flow')->where('idempotency_key', 'withdraw:paid:' . $withdraw['data']['business_no'])->count() === 1, 'official paid reconciliation was not idempotent');
    Db::name('order')->where('id', $orderId)->update([
        'status' => 'Refunded',
        'refund_amount' => '110.00',
        'update_time' => time(),
    ]);
    $plugin->afterOrderRefund(['id' => $orderId]);
    $account = BenefitLedgerService::accountSummary($referrerId, false);
    dualAssert(Money::compare($account['withdrawable'], '0.00') === 0, 'refund did not consume remaining withdrawable balance');
    dualAssert(Money::compare($account['debt'], '40.00') === 0, 'paid cash refund shortfall did not become debt');
    dualAssert(Money::compare($account['contribution_effective'], Money::subtract($threshold, '10.00')) === 0, 'refund did not reverse contribution proportionally');
    $afterRefundAccrual = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $orderId)->find();
    $accounted = Money::add(
        Money::add($afterRefundAccrual['pending_amount'], $afterRefundAccrual['unallocated_amount']),
        Money::add(
            Money::add($afterRefundAccrual['cash_allocated_amount'], $afterRefundAccrual['contribution_source_amount']),
            $afterRefundAccrual['debt_offset_amount']
        )
    );
    dualAssert(Money::compare($accounted, $afterRefundAccrual['net_entitlement']) === 0, 'accrual source conservation failed after refund');

    $blocked = BenefitLedgerService::allocate($referrerId, '1.00', 'withdrawable', 'dual_blocked_' . bin2hex(random_bytes(8)));
    dualAssert((int) $blocked['status'] !== 200, 'allocation was allowed while refund debt exists');
    $link = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $referrerId)->find();
    dualAssert((int) $link['addon_idcsmart_client_level_id'] !== $levelId, 'refund contribution reversal did not downgrade official level');

    $manual = ClientLevelService::assignClient($referrerId, $levelId, true, 'runtime_expiring_manual', time() - 1, 'runtime expiry test');
    dualAssert((int) $manual['status'] === 200, 'manual level lock failed');
    ClientLevelService::recalculateClient($referrerId, 'runtime_expiry_check');
    $expiredLink = Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $referrerId)->find();
    dualAssert((int) $expiredLink['manual_lock'] === 0, 'expired manual lock was not released');
    dualAssert((int) $expiredLink['addon_idcsmart_client_level_id'] !== $levelId, 'expired manual lock did not restore automatic official level');

    $result = [
        'status' => 'OK',
        'plugin_version' => (string) $pluginRow['version'],
        'assertions' => [
            'single_parent_and_cycle_guard',
            'paid_order_referral_accrual',
            'paid_callback_idempotency',
            'official_product_rebate_switch_and_payment_snapshot',
            'excluded_activity_product_has_no_rebate',
            'manual_benefit_allocation_idempotency',
            'independent_axis_thresholds_do_not_sum',
            'disabled_axis_retains_existing_level',
            'official_level_dual_axis_higher_result',
            'encrypted_payout_method',
            'official_withdraw_freeze_approve_paid_state_machine',
            'mandatory_withdrawal_review_hold',
            'submitted_withdrawal_review_deadline_snapshot',
            'official_paid_reconciliation_idempotency',
            'official_manager_is_the_only_review_entry',
            'refund_cash_debt_and_contribution_reversal',
            'accrual_source_conservation',
            'debt_blocks_new_allocation',
            'expired_manual_lock_restores_auto_level',
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
