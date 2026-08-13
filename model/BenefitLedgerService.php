<?php

namespace addon\idcsmart_client_level\model;

use addon\idcsmart_client_level\lib\Money;
use think\facade\Db;

class BenefitLedgerService
{
    public static function policyForClient($clientId)
    {
        $settings = ClientLevelService::settings();
        $policy = [
            'reward_rate' => Money::normalize($settings['referral_reward_rate'] ?? '0.00'),
            'contribution_rate' => Money::normalize($settings['contribution_exchange_rate'] ?? '100.00'),
            'min_withdraw' => Money::normalize($settings['min_withdraw_amount'] ?? '100.00'),
            'observation_days' => max(ClientLevelService::MIN_REFERRAL_OBSERVATION_DAYS, (int) ($settings['referral_observation_days'] ?? 14)),
            'withdrawal_review_days' => max(ClientLevelService::MIN_WITHDRAWAL_REVIEW_DAYS, (int) ($settings['withdrawal_review_days'] ?? 7)),
            'policy_level_id' => 0,
        ];
        $levelId = (int) Db::name('addon_idcsmart_client_level_client_link')
            ->where('client_id', (int) $clientId)->value('addon_idcsmart_client_level_id');
        if ($levelId > 0) {
            $row = Db::name('addon_idcsmart_client_level_level_policy')->where('level_id', $levelId)->find();
            if ($row) {
                if ((int) ($row['reward_rate_override'] ?? 0) === 1) {
                    $policy['reward_rate'] = Money::normalize($row['reward_rate']);
                }
                if ((int) ($row['contribution_rate_override'] ?? 0) === 1) {
                    $policy['contribution_rate'] = Money::normalize($row['contribution_rate']);
                }
                if ((int) ($row['min_withdraw_override'] ?? 0) === 1) {
                    $policy['min_withdraw'] = Money::normalize($row['min_withdraw']);
                }
                $policy['policy_level_id'] = $levelId;
            }
        }
        return $policy;
    }

    /**
     * 覆盖升级时把旧版过短的观察期提高到强制下限。
     *
     * 已分配或已支付的历史资金不做无记录的回滚；仍未分配的权益会
     * 原子地退回 pending，避免升级后立即被划入可提现余额。
     */
    public static function enforceMinimumObservation($days)
    {
        $days = max(ClientLevelService::MIN_REFERRAL_OBSERVATION_DAYS, (int) $days);
        $seconds = $days * 86400;
        $rows = Db::name('addon_idcsmart_client_level_referral_accrual')
            ->field('id,referrer_client_id,pending_amount,unallocated_amount,mature_time,status,create_time')
            ->order('id', 'asc')->select()->toArray();
        $updated = 0;
        foreach ($rows as $snapshot) {
            $target = (int) ($snapshot['create_time'] ?? 0) + $seconds;
            if ($target <= 0 || (int) ($snapshot['mature_time'] ?? 0) >= $target) {
                continue;
            }
            Db::startTrans();
            try {
                $row = Db::name('addon_idcsmart_client_level_referral_accrual')
                    ->where('id', (int) $snapshot['id'])->lock(true)->find();
                if (!$row || (int) ($row['mature_time'] ?? 0) >= $target) {
                    Db::commit();
                    continue;
                }
                $update = ['mature_time' => $target, 'update_time' => time()];
                if ($target > time()) {
                    $owner = (int) $row['referrer_client_id'];
                    self::ensureAccount($owner);
                    $accountRow = Db::name('addon_idcsmart_client_level_benefit_account')
                        ->where('client_id', $owner)->lock(true)->find();
                    $before = self::accountValues($accountRow);
                    $move = Money::min($row['unallocated_amount'] ?? 0, $before['unallocated']);
                    if (Money::compare($move, '0.00') > 0) {
                        $after = $before;
                        $after['unallocated'] = Money::subtract($after['unallocated'], $move);
                        $after['pending'] = Money::add($after['pending'], $move);
                        $update['unallocated_amount'] = Money::subtract($row['unallocated_amount'], $move);
                        $update['pending_amount'] = Money::add($row['pending_amount'], $move);
                        self::writeAccount($owner, $after);
                        self::flow($owner, 'risk_hold_upgrade', 'accrual', (int) $row['id'], 'risk-hold:' . (int) $row['id'] . ':' . $target, $before, $after, [
                            'moved_to_pending' => $move,
                            'mature_time' => $target,
                        ]);
                    }
                    if (Money::compare($update['pending_amount'] ?? $row['pending_amount'], '0.00') > 0) {
                        $update['status'] = 'pending';
                    }
                }
                Db::name('addon_idcsmart_client_level_referral_accrual')->where('id', (int) $row['id'])->update($update);
                Db::commit();
                $updated++;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        }
        return $updated;
    }

    public static function saveLevelPolicy($levelId, $param)
    {
        $levelId = (int) $levelId;
        if ($levelId <= 0 || Db::name('addon_idcsmart_client_level')->where('id', $levelId)->count() <= 0) {
            return ['status' => 404, 'msg' => '用户等级不存在'];
        }
        try {
            $referralLevelAmount = self::validatedAmount(
                $param['referral_level_amount'] ?? Db::name('addon_idcsmart_client_level')->where('id', $levelId)->value('amount'),
                '推广贡献门槛'
            );
            $rewardRate = self::validatedPercent($param['reward_rate'] ?? '0.00', '推广折算比例');
            $contributionRate = self::validatedPercent($param['contribution_rate'] ?? '100.00', '贡献换算比例', '1000.00');
            $minWithdraw = self::validatedAmount($param['min_withdraw'] ?? '0.00', '最低提现金额');
            self::validateReferralLevelAmount($levelId, $referralLevelAmount);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'msg' => $e->getMessage()];
        }
        $data = [
            'referral_level_amount' => $referralLevelAmount,
            'reward_rate_override' => !empty($param['reward_rate_override']) ? 1 : 0,
            'reward_rate' => $rewardRate,
            'contribution_rate_override' => !empty($param['contribution_rate_override']) ? 1 : 0,
            'contribution_rate' => $contributionRate,
            'min_withdraw_override' => !empty($param['min_withdraw_override']) ? 1 : 0,
            'min_withdraw' => $minWithdraw,
            'update_admin_id' => function_exists('get_admin_id') ? (int) get_admin_id() : 0,
            'update_time' => time(),
        ];
        Db::startTrans();
        try {
            $row = Db::name('addon_idcsmart_client_level_level_policy')->where('level_id', $levelId)->lock(true)->find();
            if ($row) {
                Db::name('addon_idcsmart_client_level_level_policy')->where('id', (int) $row['id'])->update($data);
            } else {
                $data['level_id'] = $levelId;
                $data['create_time'] = time();
                Db::name('addon_idcsmart_client_level_level_policy')->insert($data);
            }
            ReferralService::audit('level_policy_update', 0, 0, 'admin', (int) $data['update_admin_id'], [
                'level_id' => $levelId,
                'referral_level_amount' => $referralLevelAmount,
                'reward_rate_override' => (int) $data['reward_rate_override'],
                'contribution_rate_override' => (int) $data['contribution_rate_override'],
                'min_withdraw_override' => (int) $data['min_withdraw_override'],
            ], true);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 500, 'msg' => '等级推广策略保存失败，请刷新后重试'];
        }
        return ['status' => 200, 'msg' => '等级推广策略已保存'];
    }

    public static function levelPolicy($levelId)
    {
        $row = Db::name('addon_idcsmart_client_level_level_policy')->where('level_id', (int) $levelId)->find();
        if (!is_array($row)) {
            $row = [];
        }
        if (!array_key_exists('referral_level_amount', $row) || $row['referral_level_amount'] === null) {
            $row['referral_level_amount'] = Money::normalize(
                Db::name('addon_idcsmart_client_level')->where('id', (int) $levelId)->value('amount') ?? 0
            );
        } else {
            $row['referral_level_amount'] = Money::normalize($row['referral_level_amount']);
        }
        return $row;
    }

    public static function syncOrder($order)
    {
        if (!is_array($order) || empty($order['id']) || empty($order['client_id'])) {
            return 0;
        }
        $orderId = (int) $order['id'];
        $inviteeId = (int) $order['client_id'];
        $ledger = Db::name('addon_idcsmart_client_level_order')->where('order_id', $orderId)->find();
        $existing = Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', $orderId)->find();
        $orderTime = (int) ($order['pay_time'] ?? 0);
        if ($orderTime <= 0) {
            $orderTime = (int) ($order['create_time'] ?? time());
        }

        if (!$existing) {
            $eligibility = ProductRebateService::snapshotForOrder(
                $orderId,
                $ledger['paid_amount'] ?? ($order['amount'] ?? 0)
            );
            // 没有可返利官方商品时不创建零金额批次。本人消费及官方等级仍由
            // ClientLevelService 独立记账，不受商品返利开关影响。
            if (Money::compare($eligibility['eligible_paid_amount'], '0.00') <= 0) {
                return 0;
            }
            $bind = ReferralService::activeBindForOrder($inviteeId, $orderTime);
            if (!$bind) {
                return 0;
            }
            $referrerId = (int) $bind['referrer_client_id'];
            $policy = self::policyForClient($referrerId);
            $rate = Money::normalize($policy['reward_rate']);
            $matureTime = $orderTime + ((int) $policy['observation_days'] * 86400);
            $existing = [
                'id' => 0,
                'source_order_id' => $orderId,
                'bind_id' => (int) $bind['id'],
                'referrer_client_id' => $referrerId,
                'invitee_client_id' => $inviteeId,
                'eligible_paid_amount' => $eligibility['eligible_paid_amount'],
                'product_policy_snapshot' => $eligibility['product_policy_snapshot'],
                'eligibility_version' => $eligibility['eligibility_version'],
                'rate_percent' => $rate,
                'policy_level_id' => (int) $policy['policy_level_id'],
                'mature_time' => $matureTime,
                'net_entitlement' => '0.00',
                'pending_amount' => '0.00',
                'unallocated_amount' => '0.00',
                'cash_allocated_amount' => '0.00',
                'contribution_source_amount' => '0.00',
                'contribution_effective_amount' => '0.00',
                'debt_offset_amount' => '0.00',
                'revision' => 0,
            ];
        }

        Db::startTrans();
        try {
            $lockedLedger = Db::name('addon_idcsmart_client_level_order')->where('order_id', $orderId)->lock(true)->find();
            $persisted = Db::name('addon_idcsmart_client_level_referral_accrual')
                ->where('source_order_id', $orderId)->lock(true)->find();
            $accrual = $persisted ?: $existing;
            $eligiblePaid = Money::normalize($accrual['eligible_paid_amount'] ?? 0);
            $snapshot = trim((string) ($accrual['product_policy_snapshot'] ?? ''));
            if ($snapshot === '') {
                // 1.1.4 及更早版本的历史批次视为支付时全部商品均参加，避免
                // 升级后因当前商品开关而追溯扣减已经形成的财务权益。
                $eligiblePaid = Money::normalize($lockedLedger['paid_amount'] ?? 0);
                $snapshot = '{"version":"legacy-1.1.4","scope":"all_products"}';
                $accrual['eligible_paid_amount'] = $eligiblePaid;
                $accrual['product_policy_snapshot'] = $snapshot;
                $accrual['eligibility_version'] = 'legacy-1.1.4';
            }
            $baseAmount = $lockedLedger && (int) ($lockedLedger['is_consumption'] ?? 0) === 1
                ? ProductRebateService::eligibleNetAmount($eligiblePaid, $lockedLedger['refund_amount'] ?? 0)
                : '0.00';
            $referrerId = (int) $accrual['referrer_client_id'];
            $newEntitlement = Money::percent($baseAmount, $accrual['rate_percent']);
            $oldEntitlement = Money::normalize($accrual['net_entitlement'] ?? 0);
            $delta = Money::subtract($newEntitlement, $oldEntitlement);
            if (Money::compare($delta, '0.00') === 0 && (int) ($accrual['id'] ?? 0) > 0) {
                Db::commit();
                return $referrerId;
            }
            self::ensureAccount($referrerId);
            $account = Db::name('addon_idcsmart_client_level_benefit_account')
                ->where('client_id', $referrerId)->lock(true)->find();
            $before = self::accountValues($account);
            $after = $before;
            $now = time();

            if (Money::compare($delta, '0.00') > 0) {
                if ((int) $accrual['mature_time'] <= $now) {
                    [$after, $unallocatedAdded, $debtOffset] = self::applyAvailableCredit($after, $delta);
                    $accrual['unallocated_amount'] = Money::add($accrual['unallocated_amount'], $unallocatedAdded);
                    $accrual['debt_offset_amount'] = Money::add($accrual['debt_offset_amount'], $debtOffset);
                } else {
                    $after['pending'] = Money::add($after['pending'], $delta);
                    $accrual['pending_amount'] = Money::add($accrual['pending_amount'], $delta);
                }
            } else {
                $reverse = Money::subtract('0.00', $delta);
                [$accrual, $after] = self::reverseAccrual($accrual, $after, $reverse);
            }

            $accrualData = [
                'bind_id' => (int) $accrual['bind_id'],
                'referrer_client_id' => $referrerId,
                'invitee_client_id' => $inviteeId,
                'base_net_amount' => $baseAmount,
                'eligible_paid_amount' => $eligiblePaid,
                'product_policy_snapshot' => $snapshot,
                'eligibility_version' => (string) ($accrual['eligibility_version'] ?? ProductRebateService::ELIGIBILITY_VERSION),
                'rate_percent' => Money::normalize($accrual['rate_percent']),
                'policy_level_id' => (int) ($accrual['policy_level_id'] ?? 0),
                'net_entitlement' => $newEntitlement,
                'pending_amount' => Money::maxZero($accrual['pending_amount']),
                'unallocated_amount' => Money::maxZero($accrual['unallocated_amount']),
                'cash_allocated_amount' => Money::maxZero($accrual['cash_allocated_amount']),
                'contribution_source_amount' => Money::maxZero($accrual['contribution_source_amount']),
                'contribution_effective_amount' => Money::maxZero($accrual['contribution_effective_amount']),
                'debt_offset_amount' => Money::maxZero($accrual['debt_offset_amount']),
                'mature_time' => (int) $accrual['mature_time'],
                'status' => Money::compare($accrual['pending_amount'], '0.00') > 0 ? 'pending' : 'available',
                'revision' => ((int) ($accrual['revision'] ?? 0)) + 1,
                'update_time' => $now,
            ];
            if ((int) ($accrual['id'] ?? 0) > 0) {
                Db::name('addon_idcsmart_client_level_referral_accrual')->where('id', (int) $accrual['id'])->update($accrualData);
                $accrualId = (int) $accrual['id'];
            } else {
                $accrualData['source_order_id'] = $orderId;
                $accrualData['create_time'] = $now;
                $accrualId = (int) Db::name('addon_idcsmart_client_level_referral_accrual')->insertGetId($accrualData);
            }
            self::writeAccount($referrerId, $after);
            self::flow($referrerId, 'accrual_sync', 'order', $orderId, 'accrual:' . $orderId . ':rev:' . $accrualData['revision'], $before, $after, [
                'accrual_id' => $accrualId,
                'invitee_client_id' => $inviteeId,
                'base_net_amount' => $baseAmount,
                'eligible_paid_amount' => $eligiblePaid,
                'rate_percent' => $accrualData['rate_percent'],
                'net_entitlement' => $newEntitlement,
            ]);
            Db::commit();
            return $referrerId;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public static function processMatured($clientId = 0, $limit = 200)
    {
        $clientId = (int) $clientId;
        $limit = min(1000, max(1, (int) $limit));
        $query = Db::name('addon_idcsmart_client_level_referral_accrual')
            ->where('status', 'pending')->where('mature_time', '<=', time())
            ->where('pending_amount', '>', '0.00');
        if ($clientId > 0) {
            $query->where('referrer_client_id', $clientId);
        }
        $ids = $query->order('id', 'asc')->limit($limit)->column('id');
        $updated = 0;
        foreach ($ids as $id) {
            Db::startTrans();
            try {
                $row = Db::name('addon_idcsmart_client_level_referral_accrual')->where('id', (int) $id)->lock(true)->find();
                if (!$row || (int) $row['mature_time'] > time() || Money::compare($row['pending_amount'], '0.00') <= 0) {
                    Db::commit();
                    continue;
                }
                $owner = (int) $row['referrer_client_id'];
                self::ensureAccount($owner);
                $account = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $owner)->lock(true)->find();
                $before = self::accountValues($account);
                $after = $before;
                $pending = Money::normalize($row['pending_amount']);
                $after['pending'] = Money::maxZero(Money::subtract($after['pending'], $pending));
                [$after, $unallocated, $debtOffset] = self::applyAvailableCredit($after, $pending);
                Db::name('addon_idcsmart_client_level_referral_accrual')->where('id', (int) $id)->update([
                    'pending_amount' => '0.00',
                    'unallocated_amount' => Money::add($row['unallocated_amount'], $unallocated),
                    'debt_offset_amount' => Money::add($row['debt_offset_amount'], $debtOffset),
                    'status' => 'available',
                    'update_time' => time(),
                ]);
                self::writeAccount($owner, $after);
                self::flow($owner, 'accrual_mature', 'accrual', (int) $id, 'mature:' . (int) $id, $before, $after, [
                    'unallocated_amount' => $unallocated,
                    'debt_offset_amount' => $debtOffset,
                ]);
                Db::commit();
                $updated++;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        }
        return ['status' => 200, 'msg' => '到期权益处理完成', 'data' => ['updated' => $updated]];
    }

    public static function allocate($clientId, $amount, $target, $businessNo)
    {
        $clientId = (int) $clientId;
        $target = strtolower(trim((string) $target));
        $businessNo = trim((string) $businessNo);
        try {
            $amount = self::validatedAmount($amount, '分配金额');
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'msg' => $e->getMessage()];
        }
        if ($clientId <= 0 || !in_array($target, ['withdrawable', 'contribution'], true)) {
            return ['status' => 400, 'msg' => '分配参数错误'];
        }
        if (Money::compare($amount, '0.00') <= 0) {
            return ['status' => 400, 'msg' => '分配金额必须大于零'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $businessNo)) {
            return ['status' => 400, 'msg' => '请勿重复提交，请刷新后重试'];
        }
        self::processMatured($clientId, 500);
        $existing = Db::name('addon_idcsmart_client_level_benefit_allocation')->where('business_no', $businessNo)->find();
        if ($existing) {
            if ((int) $existing['client_id'] !== $clientId || (string) $existing['target'] !== $target || Money::compare($existing['source_amount'], $amount) !== 0) {
                return ['status' => 409, 'msg' => '请勿重复提交，请刷新后重试'];
            }
            return ['status' => 200, 'msg' => '权益已分配', 'data' => ['id' => (int) $existing['id']]];
        }

        $policy = self::policyForClient($clientId);
        $rate = $target === 'contribution' ? Money::normalize($policy['contribution_rate']) : '0.00';
        $effective = $target === 'contribution' ? Money::percent($amount, $rate) : '0.00';
        Db::startTrans();
        try {
            self::ensureAccount($clientId);
            $account = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->lock(true)->find();
            $before = self::accountValues($account);
            if (Money::compare($before['debt'], '0.00') > 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '存在退款待抵扣，暂不能分配权益'];
            }
            if (Money::compare($before['unallocated'], $amount) < 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '待分配权益不足'];
            }
            $allocationId = (int) Db::name('addon_idcsmart_client_level_benefit_allocation')->insertGetId([
                'business_no' => $businessNo,
                'client_id' => $clientId,
                'target' => $target,
                'source_amount' => $amount,
                'contribution_rate' => $rate,
                'effective_amount' => $effective,
                'reversed_source_amount' => '0.00',
                'reversed_effective_amount' => '0.00',
                'status' => 'active',
                'create_time' => time(),
                'update_time' => time(),
            ]);

            $remaining = $amount;
            $rows = Db::name('addon_idcsmart_client_level_referral_accrual')
                ->where('referrer_client_id', $clientId)->where('status', 'available')
                ->where('mature_time', '<=', time())->where('unallocated_amount', '>', '0.00')
                ->order('mature_time', 'asc')->order('id', 'asc')->lock(true)->select()->toArray();
            foreach ($rows as $row) {
                if (Money::compare($remaining, '0.00') <= 0) {
                    break;
                }
                $take = Money::min($remaining, $row['unallocated_amount']);
                $itemEffective = $target === 'contribution' ? Money::percent($take, $rate) : '0.00';
                $update = ['unallocated_amount' => Money::subtract($row['unallocated_amount'], $take), 'update_time' => time()];
                if ($target === 'contribution') {
                    $update['contribution_source_amount'] = Money::add($row['contribution_source_amount'], $take);
                    $update['contribution_effective_amount'] = Money::add($row['contribution_effective_amount'], $itemEffective);
                } else {
                    $update['cash_allocated_amount'] = Money::add($row['cash_allocated_amount'], $take);
                }
                Db::name('addon_idcsmart_client_level_referral_accrual')->where('id', (int) $row['id'])->update($update);
                Db::name('addon_idcsmart_client_level_benefit_allocation_item')->insert([
                    'allocation_id' => $allocationId,
                    'accrual_id' => (int) $row['id'],
                    'source_amount' => $take,
                    'effective_amount' => $itemEffective,
                    'reversed_source_amount' => '0.00',
                    'reversed_effective_amount' => '0.00',
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $remaining = Money::subtract($remaining, $take);
            }
            if (Money::compare($remaining, '0.00') > 0) {
                throw new \RuntimeException('allocation_source_mismatch');
            }

            $after = $before;
            $after['unallocated'] = Money::subtract($after['unallocated'], $amount);
            if ($target === 'contribution') {
                $after['contribution_source'] = Money::add($after['contribution_source'], $amount);
                $after['contribution_effective'] = Money::add($after['contribution_effective'], $effective);
            } else {
                $after['withdrawable'] = Money::add($after['withdrawable'], $amount);
            }
            self::writeAccount($clientId, $after);
            self::flow($clientId, 'benefit_allocate_' . $target, 'allocation', $allocationId, 'allocate:' . $businessNo, $before, $after, [
                'source_amount' => $amount,
                'contribution_rate' => $rate,
                'effective_amount' => $effective,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 400, 'msg' => '权益分配失败，请刷新后重试'];
        }
        if ($target === 'contribution') {
            ClientLevelService::recalculateClient($clientId, 'referral_contribution');
        }
        return ['status' => 200, 'msg' => $target === 'contribution' ? '已纳入等级贡献' : '已转入可提现余额', 'data' => [
            'id' => $allocationId,
            'source_amount' => $amount,
            'effective_amount' => $effective,
        ]];
    }

    public static function accountSummary($clientId, $mature = true)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return self::emptyAccount();
        }
        if ($mature) {
            self::processMatured($clientId, 500);
        }
        self::ensureAccount($clientId);
        $row = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->find();
        $values = self::accountValues($row);
        $values['referral_net_amount'] = Money::normalize(Db::name('addon_idcsmart_client_level_referral_accrual')
            ->where('referrer_client_id', $clientId)->sum('base_net_amount'));
        $values['accrued_total'] = Money::normalize(Db::name('addon_idcsmart_client_level_referral_accrual')
            ->where('referrer_client_id', $clientId)->sum('net_entitlement'));
        $values['policy'] = self::policyForClient($clientId);
        return $values;
    }

    public static function accruals($clientId, $param = [])
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 20)));
        $query = Db::name('addon_idcsmart_client_level_referral_accrual')
            ->where('referrer_client_id', (int) $clientId);
        $count = (int) (clone $query)->count();
        $list = $query->field('id,source_order_id,invitee_client_id,base_net_amount,rate_percent,net_entitlement,pending_amount,unallocated_amount,cash_allocated_amount,contribution_source_amount,contribution_effective_amount,mature_time,status,create_time,update_time')
            ->order('id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$row) {
            $row['invitee_display'] = '客户 #' . (int) $row['invitee_client_id'];
            unset($row['invitee_client_id']);
        }
        unset($row);
        return ['list' => $list, 'count' => $count];
    }

    public static function flows($clientId, $param = [], $admin = false)
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 30)));
        $query = Db::name('addon_idcsmart_client_level_benefit_flow');
        if (!$admin || !empty($param['client_id'])) {
            $query->where('client_id', $admin ? (int) $param['client_id'] : (int) $clientId);
        }
        $count = (int) (clone $query)->count();
        $list = $query->field('id,client_id,scene,ref_type,ref_id,pending_delta,unallocated_delta,withdrawable_delta,frozen_delta,contribution_source_delta,contribution_effective_delta,debt_delta,create_time')
            ->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'count' => $count];
    }

    public static function ensureAccount($clientId)
    {
        $clientId = (int) $clientId;
        $row = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->find();
        if ($row) {
            return $row;
        }
        try {
            Db::name('addon_idcsmart_client_level_benefit_account')->insert([
                'client_id' => $clientId,
                'pending' => '0.00', 'unallocated' => '0.00', 'withdrawable' => '0.00',
                'withdraw_frozen' => '0.00', 'contribution_source' => '0.00',
                'contribution_effective' => '0.00', 'debt' => '0.00', 'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
        }
        return Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->find();
    }

    public static function flow($clientId, $scene, $refType, $refId, $idempotencyKey, $before, $after, $extra = [])
    {
        $exists = Db::name('addon_idcsmart_client_level_benefit_flow')->where('idempotency_key', $idempotencyKey)->find();
        if ($exists) {
            return (int) $exists['id'];
        }
        $before = array_merge(self::emptyAccount(), is_array($before) ? $before : []);
        $after = array_merge(self::emptyAccount(), is_array($after) ? $after : []);
        return (int) Db::name('addon_idcsmart_client_level_benefit_flow')->insertGetId([
            'client_id' => (int) $clientId,
            'scene' => substr((string) $scene, 0, 50),
            'ref_type' => substr((string) $refType, 0, 30),
            'ref_id' => (int) $refId,
            'idempotency_key' => substr((string) $idempotencyKey, 0, 100),
            'pending_delta' => Money::subtract($after['pending'], $before['pending']),
            'unallocated_delta' => Money::subtract($after['unallocated'], $before['unallocated']),
            'withdrawable_delta' => Money::subtract($after['withdrawable'], $before['withdrawable']),
            'frozen_delta' => Money::subtract($after['withdraw_frozen'], $before['withdraw_frozen']),
            'contribution_source_delta' => Money::subtract($after['contribution_source'], $before['contribution_source']),
            'contribution_effective_delta' => Money::subtract($after['contribution_effective'], $before['contribution_effective']),
            'debt_delta' => Money::subtract($after['debt'], $before['debt']),
            'balance_before' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'balance_after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'extra' => json_encode(is_array($extra) ? $extra : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'create_time' => time(),
        ]);
    }

    public static function accountValues($row)
    {
        $row = is_array($row) ? $row : [];
        return [
            'pending' => Money::normalize($row['pending'] ?? 0),
            'unallocated' => Money::normalize($row['unallocated'] ?? 0),
            'withdrawable' => Money::normalize($row['withdrawable'] ?? 0),
            'withdraw_frozen' => Money::normalize($row['withdraw_frozen'] ?? 0),
            'contribution_source' => Money::normalize($row['contribution_source'] ?? 0),
            'contribution_effective' => Money::normalize($row['contribution_effective'] ?? 0),
            'debt' => Money::normalize($row['debt'] ?? 0),
        ];
    }

    public static function writeAccount($clientId, $values)
    {
        $values = array_merge(self::emptyAccount(), is_array($values) ? $values : []);
        Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', (int) $clientId)->update([
            'pending' => Money::maxZero($values['pending']),
            'unallocated' => Money::maxZero($values['unallocated']),
            'withdrawable' => Money::maxZero($values['withdrawable']),
            'withdraw_frozen' => Money::maxZero($values['withdraw_frozen']),
            'contribution_source' => Money::maxZero($values['contribution_source']),
            'contribution_effective' => Money::maxZero($values['contribution_effective']),
            'debt' => Money::maxZero($values['debt']),
            'update_time' => time(),
        ]);
    }

    private static function reverseAccrual($accrual, $account, $reverse)
    {
        $remaining = Money::normalize($reverse);
        foreach (['pending_amount' => 'pending', 'unallocated_amount' => 'unallocated'] as $field => $bucket) {
            if (Money::compare($remaining, '0.00') <= 0) {
                break;
            }
            $take = Money::min($remaining, $accrual[$field] ?? 0);
            $accrual[$field] = Money::subtract($accrual[$field] ?? 0, $take);
            $account[$bucket] = Money::maxZero(Money::subtract($account[$bucket], $take));
            $remaining = Money::subtract($remaining, $take);
        }
        if (Money::compare($remaining, '0.00') <= 0 || empty($accrual['id'])) {
            return [$accrual, $account];
        }

        $items = Db::name('addon_idcsmart_client_level_benefit_allocation_item')->alias('ai')
            ->leftJoin('addon_idcsmart_client_level_benefit_allocation a', 'a.id=ai.allocation_id')
            ->where('ai.accrual_id', (int) $accrual['id'])
            ->field('ai.*,a.target,a.contribution_rate')->order('ai.id', 'desc')->lock(true)->select()->toArray();
        foreach ($items as $item) {
            if (Money::compare($remaining, '0.00') <= 0) {
                break;
            }
            $available = Money::maxZero(Money::subtract($item['source_amount'], $item['reversed_source_amount']));
            $take = Money::min($remaining, $available);
            if (Money::compare($take, '0.00') <= 0) {
                continue;
            }
            $newSourceReversed = Money::add($item['reversed_source_amount'], $take);
            $effectiveReverse = '0.00';
            if ((string) $item['target'] === 'contribution') {
                $oldEffectiveRemaining = Money::maxZero(Money::subtract($item['effective_amount'], $item['reversed_effective_amount']));
                $newSourceRemaining = Money::maxZero(Money::subtract($item['source_amount'], $newSourceReversed));
                $newEffectiveRemaining = Money::percent($newSourceRemaining, $item['contribution_rate']);
                $effectiveReverse = Money::maxZero(Money::subtract($oldEffectiveRemaining, $newEffectiveRemaining));
                $account['contribution_source'] = Money::maxZero(Money::subtract($account['contribution_source'], $take));
                $account['contribution_effective'] = Money::maxZero(Money::subtract($account['contribution_effective'], $effectiveReverse));
                $accrual['contribution_source_amount'] = Money::maxZero(Money::subtract($accrual['contribution_source_amount'], $take));
                $accrual['contribution_effective_amount'] = Money::maxZero(Money::subtract($accrual['contribution_effective_amount'], $effectiveReverse));
            } else {
                $fromAvailable = Money::min($account['withdrawable'], $take);
                $account['withdrawable'] = Money::subtract($account['withdrawable'], $fromAvailable);
                $shortfall = Money::subtract($take, $fromAvailable);
                if (Money::compare($shortfall, '0.00') > 0) {
                    $account['debt'] = Money::add($account['debt'], $shortfall);
                }
                $accrual['cash_allocated_amount'] = Money::maxZero(Money::subtract($accrual['cash_allocated_amount'], $take));
            }
            Db::name('addon_idcsmart_client_level_benefit_allocation_item')->where('id', (int) $item['id'])->update([
                'reversed_source_amount' => $newSourceReversed,
                'reversed_effective_amount' => Money::add($item['reversed_effective_amount'], $effectiveReverse),
                'update_time' => time(),
            ]);
            Db::name('addon_idcsmart_client_level_benefit_allocation')->where('id', (int) $item['allocation_id'])->update([
                'reversed_source_amount' => Db::raw('reversed_source_amount+' . $take),
                'reversed_effective_amount' => Db::raw('reversed_effective_amount+' . $effectiveReverse),
                'status' => 'partially_reversed',
                'update_time' => time(),
            ]);
            $remaining = Money::subtract($remaining, $take);
        }
        if (Money::compare($remaining, '0.00') > 0) {
            $account['debt'] = Money::add($account['debt'], $remaining);
        }
        return [$accrual, $account];
    }

    private static function applyAvailableCredit($account, $amount)
    {
        $amount = Money::normalize($amount);
        $debtOffset = Money::min($account['debt'], $amount);
        $account['debt'] = Money::subtract($account['debt'], $debtOffset);
        $unallocated = Money::subtract($amount, $debtOffset);
        $account['unallocated'] = Money::add($account['unallocated'], $unallocated);
        return [$account, $unallocated, $debtOffset];
    }

    private static function emptyAccount()
    {
        return [
            'pending' => '0.00', 'unallocated' => '0.00', 'withdrawable' => '0.00',
            'withdraw_frozen' => '0.00', 'contribution_source' => '0.00',
            'contribution_effective' => '0.00', 'debt' => '0.00',
        ];
    }

    private static function validatedAmount($value, $label)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException($label . '格式错误');
        }
        return Money::normalize($value);
    }

    private static function validateReferralLevelAmount($levelId, $amount)
    {
        $current = Db::name('addon_idcsmart_client_level')->where('id', (int) $levelId)->find();
        if (empty($current)) {
            throw new \InvalidArgumentException('官方等级不存在');
        }
        $currentOwnAmount = Money::normalize($current['amount']);
        if (Money::compare($currentOwnAmount, '0.00') === 0 && Money::compare($amount, '0.00') !== 0) {
            throw new \InvalidArgumentException('基础等级的推广贡献门槛必须为 0');
        }
        $levels = Db::name('addon_idcsmart_client_level')
            ->where('id', '<>', (int) $levelId)
            ->field('id,name,amount')
            ->order('amount', 'asc')
            ->select()
            ->toArray();
        foreach ($levels as $level) {
            $policy = self::levelPolicy((int) $level['id']);
            $otherThreshold = Money::normalize($policy['referral_level_amount'] ?? $level['amount']);
            $ownOrder = Money::compare($level['amount'], $currentOwnAmount);
            if ($ownOrder < 0 && Money::compare($otherThreshold, $amount) >= 0) {
                throw new \InvalidArgumentException('推广贡献门槛必须高于较低等级“' . (string) $level['name'] . '”');
            }
            if ($ownOrder > 0 && Money::compare($otherThreshold, $amount) <= 0) {
                throw new \InvalidArgumentException('推广贡献门槛必须低于较高等级“' . (string) $level['name'] . '”');
            }
        }
    }

    private static function validatedPercent($value, $label, $max = '100.00')
    {
        $value = self::validatedAmount($value, $label);
        if (Money::compare($value, $max) > 0) {
            throw new \InvalidArgumentException($label . '超出允许范围');
        }
        return $value;
    }
}
