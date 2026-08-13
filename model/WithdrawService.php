<?php

namespace addon\idcsmart_client_level\model;

use addon\idcsmart_client_level\lib\Money;
use think\facade\Db;

class WithdrawService
{
    const OFFICIAL_SOURCE = 'IdcsmartClientLevel';
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const PAID = 'paid';
    const CANCELLED = 'cancelled';

    public static function saveMethod($clientId, $param)
    {
        $clientId = (int) $clientId;
        $id = (int) ($param['id'] ?? 0);
        $type = strtolower(trim((string) ($param['type'] ?? '')));
        $account = trim((string) ($param['account'] ?? ''));
        $name = trim((string) ($param['name'] ?? ''));
        if ($clientId <= 0 || !in_array($type, ['alipay', 'wechat', 'bank', 'other'], true)) {
            return ['status' => 400, 'msg' => '收款方式无效'];
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($account === '' || strlen($account) > 100 || $name === '' || $nameLength > 100) {
            return ['status' => 400, 'msg' => '请填写有效的收款账号和收款人'];
        }
        $cipher = self::encrypt($account);
        $nameCipher = self::encrypt($name);
        if ($cipher === '' || $nameCipher === '') {
            return ['status' => 500, 'msg' => '收款方式暂时无法保存，请联系管理员'];
        }
        $data = [
            'type' => $type,
            'account_cipher' => $cipher,
            'account_mask' => self::maskAccount($account),
            'name_mask' => self::maskName($name),
            'name_cipher' => $nameCipher,
            'is_default' => !empty($param['is_default']) ? 1 : 0,
            'update_time' => time(),
        ];
        Db::startTrans();
        try {
            if ($data['is_default'] === 1) {
                Db::name('addon_idcsmart_client_level_withdraw_method')->where('client_id', $clientId)->update(['is_default' => 0, 'update_time' => time()]);
            }
            if ($id > 0) {
                $row = Db::name('addon_idcsmart_client_level_withdraw_method')->where('id', $id)->where('client_id', $clientId)->lock(true)->find();
                if (!$row) {
                    Db::rollback();
                    return ['status' => 404, 'msg' => '收款方式不存在'];
                }
                Db::name('addon_idcsmart_client_level_withdraw_method')->where('id', $id)->update($data);
            } else {
                $data['client_id'] = $clientId;
                $data['create_time'] = time();
                $id = (int) Db::name('addon_idcsmart_client_level_withdraw_method')->insertGetId($data);
            }
            ReferralService::audit('withdraw_method_save', $clientId, 0, 'client', $clientId, [
                'method_id' => $id,
                'type' => $type,
                'is_default' => (int) $data['is_default'],
            ], true);
            Db::commit();
            return ['status' => 200, 'msg' => '收款方式已保存', 'data' => ['id' => $id]];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 400, 'msg' => '收款方式保存失败'];
        }
    }

    public static function methods($clientId)
    {
        return Db::name('addon_idcsmart_client_level_withdraw_method')
            ->field('id,type,account_mask,name_mask,is_default,create_time,update_time')
            ->where('client_id', (int) $clientId)->order('is_default', 'desc')->order('id', 'desc')->select()->toArray();
    }

    public static function deleteMethod($clientId, $methodId)
    {
        $clientId = (int) $clientId;
        $methodId = (int) $methodId;
        Db::startTrans();
        try {
            $method = Db::name('addon_idcsmart_client_level_withdraw_method')
                ->where('id', $methodId)->where('client_id', $clientId)->lock(true)->find();
            if (empty($method)) {
                Db::rollback();
                return ['status' => 404, 'msg' => '收款方式不存在'];
            }
            $used = Db::name('addon_idcsmart_client_level_withdraw')
                ->where('method_id', $methodId)->where('client_id', $clientId)
                ->whereIn('status', [self::PENDING, self::APPROVED])->count();
            if ($used > 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '该收款方式存在处理中提现，不能删除'];
            }
            Db::name('addon_idcsmart_client_level_withdraw_method')->where('id', $methodId)
                ->where('client_id', $clientId)->delete();
            ReferralService::audit('withdraw_method_delete', $clientId, 0, 'client', $clientId, [
                'method_id' => $methodId,
                'type' => (string) ($method['type'] ?? ''),
            ], true);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 500, 'msg' => '收款方式删除失败，请刷新后重试'];
        }
        return ['status' => 200, 'msg' => '收款方式已删除'];
    }

    public static function create($clientId, $amount, $methodId, $requestKey)
    {
        $clientId = (int) $clientId;
        $methodId = (int) $methodId;
        $requestKey = trim((string) $requestKey);
        try {
            $amount = self::validatedAmount($amount);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'msg' => $e->getMessage()];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestKey)) {
            return ['status' => 400, 'msg' => '请勿重复提交，请刷新后重试'];
        }
        $existing = Db::name('addon_idcsmart_client_level_withdraw')->where('request_key', $requestKey)->find();
        if ($existing) {
            if ((int) $existing['client_id'] !== $clientId || Money::compare($existing['amount'], $amount) !== 0) {
                return ['status' => 409, 'msg' => '请勿重复提交，请刷新后重试'];
            }
            return ['status' => 200, 'msg' => '提现申请已提交', 'data' => ['id' => (int) $existing['id'], 'business_no' => $existing['business_no']]];
        }
        if (!self::officialIntegrationAvailable()) {
            return ['status' => 400, 'msg' => '提现服务暂不可用，请联系管理员'];
        }
        $policy = BenefitLedgerService::policyForClient($clientId);
        if (Money::compare($amount, $policy['min_withdraw']) < 0) {
            return ['status' => 400, 'msg' => '最低提现金额为 ' . $policy['min_withdraw']];
        }
        $method = Db::name('addon_idcsmart_client_level_withdraw_method')->where('id', $methodId)->where('client_id', $clientId)->find();
        if (!$method) {
            return ['status' => 404, 'msg' => '收款方式不存在'];
        }

        Db::startTrans();
        try {
            BenefitLedgerService::ensureAccount($clientId);
            $account = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->lock(true)->find();
            $before = BenefitLedgerService::accountValues($account);
            if (Money::compare($before['debt'], '0.00') > 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '存在退款待抵扣，暂不能提现'];
            }
            if (Money::compare($before['withdrawable'], $amount) < 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '可提现余额不足'];
            }
            $after = $before;
            $after['withdrawable'] = Money::subtract($after['withdrawable'], $amount);
            $after['withdraw_frozen'] = Money::add($after['withdraw_frozen'], $amount);
            $businessNo = self::businessNo();
            $eligibleReviewTime = time() + ((int) $policy['withdrawal_review_days'] * 86400);
            $id = (int) Db::name('addon_idcsmart_client_level_withdraw')->insertGetId([
                'business_no' => $businessNo,
                'request_key' => $requestKey,
                'client_id' => $clientId,
                'amount' => $amount,
                'method_id' => $methodId,
                'method_type' => $method['type'],
                'account_cipher' => $method['account_cipher'],
                'account_mask' => $method['account_mask'],
                'name_cipher' => $method['name_cipher'],
                'name_mask' => $method['name_mask'],
                'status' => self::PENDING,
                'admin_id' => 0,
                'official_withdraw_id' => 0,
                'review_note' => '',
                'paid_reference' => '',
                'eligible_review_time' => $eligibleReviewTime,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            BenefitLedgerService::writeAccount($clientId, $after);
            BenefitLedgerService::flow($clientId, 'withdraw_freeze', 'withdraw', $id, 'withdraw:freeze:' . $businessNo, $before, $after, ['amount' => $amount]);
            Db::commit();
            return ['status' => 200, 'msg' => '提现申请已提交，请等待审核', 'data' => [
                'id' => $id,
                'business_no' => $businessNo,
                'eligible_review_time' => $eligibleReviewTime,
            ]];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 400, 'msg' => '提现申请失败，请刷新后重试'];
        }
    }

    public static function cancel($clientId, $id)
    {
        return self::release((int) $id, self::PENDING, self::CANCELLED, (int) $clientId, 0, '用户取消');
    }

    /**
     * 冻结期结束后才把申请发布到 V10 官方提现表。这使官方审核页
     * 成为唯一审核入口，同时不会暴露一条可被提前审核的记录。
     */
    public static function publishEligibleToOfficial($limit = 100, $clientId = 0)
    {
        $limit = min(500, max(1, (int) $limit));
        $clientId = (int) $clientId;
        if (!self::officialIntegrationAvailable()) {
            return ['status' => 400, 'msg' => '官方提现插件不可用', 'data' => ['published' => 0]];
        }
        $query = Db::name('addon_idcsmart_client_level_withdraw')
            ->where('status', self::PENDING)
            ->where('official_withdraw_id', 0)
            ->order('id', 'asc');
        if ($clientId > 0) {
            $query->where('client_id', $clientId);
        }
        $rows = $query->limit($limit)->select()->toArray();
        $published = 0;
        foreach ($rows as $row) {
            $eligibleReviewTime = self::eligibleReviewTime($row);
            if ($eligibleReviewTime > time()) {
                continue;
            }
            if (self::publishOfficialRow((int) $row['id'])) {
                $published++;
            }
        }
        return ['status' => 200, 'msg' => '官方提现发布完成', 'data' => ['published' => $published]];
    }

    /**
     * 官方确认汇款没有事件 Hook，分钟任务和用户查询时均做幂等对账。
     */
    public static function syncOfficialStatuses($limit = 200, $clientId = 0)
    {
        $limit = min(1000, max(1, (int) $limit));
        $clientId = (int) $clientId;
        if (!self::officialIntegrationAvailable()) {
            return ['status' => 400, 'msg' => '官方提现插件不可用', 'data' => ['synced' => 0]];
        }
        $query = Db::name('addon_idcsmart_client_level_withdraw')
            ->where('official_withdraw_id', '>', 0)
            ->whereIn('status', [self::PENDING, self::APPROVED])
            ->order('id', 'asc');
        if ($clientId > 0) {
            $query->where('client_id', $clientId);
        }
        $rows = $query->field('id,client_id,official_withdraw_id')->limit($limit)->select()->toArray();
        $synced = 0;
        foreach ($rows as $row) {
            $official = Db::name('addon_idcsmart_withdraw')->alias('w')
                ->leftJoin('transaction t', 't.id=w.transaction_id')
                ->field('w.id,w.source,w.status,w.reason,w.transaction_id,w.update_time,t.transaction_number')
                ->where('w.id', (int) $row['official_withdraw_id'])
                ->where('w.source', self::OFFICIAL_SOURCE)
                ->find();
            if (!$official) {
                continue;
            }
            $status = (int) $official['status'];
            // 尚未实际汇款时，退款欠额优先撤销在途提现。官方已经确认
            // 汇款(status=3)则必须如实同步，欠额留待后续权益抵扣。
            if ($status !== 3 && self::clientHasDebt((int) $row['client_id'])) {
                self::reconcileRefundExposure((int) $row['client_id']);
                $synced++;
                continue;
            }
            if ($status === 1) {
                self::markOfficialApproved((int) $official['id'], 0);
                $synced++;
            } elseif ($status === 2) {
                self::markOfficialRejected((int) $official['id'], (string) ($official['reason'] ?? ''), 0);
                $synced++;
            } elseif ($status === 3) {
                self::markOfficialApproved((int) $official['id'], 0);
                $reference = trim((string) ($official['transaction_number'] ?? ''));
                self::markOfficialPaid((int) $official['id'], $reference !== '' ? $reference : 'OFFICIAL-' . (int) $official['id']);
                $synced++;
            }
        }
        return ['status' => 200, 'msg' => '官方提现对账完成', 'data' => ['synced' => $synced]];
    }

    /**
     * 退款冲正后撤销尚未实际汇款的提现，释放整笔冻结并优先抵扣欠额。
     *
     * 一笔提现可能大于本次退款短缺，因此整笔驳回后把净剩余返还可提现，
     * 由用户按新净额重新提交。官方已汇款记录只做如实对账，不能伪撤销。
     */
    public static function reconcileRefundExposure($clientId, $orderId = 0)
    {
        $clientId = (int) $clientId;
        $orderId = (int) $orderId;
        if ($clientId <= 0) {
            return ['status' => 400, 'msg' => '用户参数错误', 'data' => ['rejected' => 0, 'paid' => 0, 'remaining_debt' => '0.00']];
        }

        $rejected = 0;
        $paid = 0;
        $checked = 0;
        while ($checked < 200 && self::clientHasDebt($clientId)) {
            $rows = Db::name('addon_idcsmart_client_level_withdraw')
                ->where('client_id', $clientId)
                ->whereIn('status', [self::PENDING, self::APPROVED])
                ->order('create_time', 'asc')->order('id', 'asc')
                ->limit(20)->select()->toArray();
            if (empty($rows)) {
                break;
            }
            $progress = false;
            foreach ($rows as $row) {
                if (!self::clientHasDebt($clientId)) {
                    break;
                }
                $checked++;
                $outcome = self::rejectWithdrawalForRefund((int) $row['id'], $orderId);
                if ($outcome === 'rejected') {
                    $rejected++;
                    $progress = true;
                } elseif ($outcome === 'paid') {
                    $paid++;
                    $progress = true;
                }
                if ($checked >= 200) {
                    break;
                }
            }
            if (!$progress) {
                break;
            }
        }

        $remainingDebt = self::clientDebt($clientId);
        ReferralService::audit('withdraw_refund_reconcile', $clientId, 0, 'system', 0, [
            'order_id' => $orderId,
            'rejected' => $rejected,
            'paid' => $paid,
            'remaining_debt' => $remainingDebt,
        ]);
        return ['status' => 200, 'msg' => '退款在途提现对账完成', 'data' => [
            'rejected' => $rejected,
            'paid' => $paid,
            'remaining_debt' => $remainingDebt,
        ]];
    }

    public static function handleOfficialEvent($event, $param)
    {
        if ((string) ($param['source'] ?? '') !== self::OFFICIAL_SOURCE) {
            return true;
        }
        $officialId = (int) ($param['id'] ?? 0);
        if ($officialId <= 0) {
            return true;
        }
        $adminId = function_exists('get_admin_id') ? (int) get_admin_id() : 0;
        if ($event === 'pass') {
            self::markOfficialApproved($officialId, $adminId);
        } elseif ($event === 'reject') {
            self::markOfficialRejected($officialId, (string) ($param['reason'] ?? '官方提现驳回'), $adminId);
        } elseif ($event === 'reopen_approved' || $event === 'reopen_pending') {
            // 驳回时冻结额已释放并可能被使用，官方的“恢复状态”不能
            // 无条件重新冻结。保持财务守恒，要求用户重新提交新申请。
            self::markOfficialRejected($officialId, '推广权益驳回后已释放冻结，请用户重新提交', $adminId);
            self::forceOfficialRejected($officialId, '推广权益驳回后已释放冻结，请用户重新提交');
        }
        return true;
    }

    private static function publishOfficialRow($id)
    {
        $debtClientId = 0;
        Db::startTrans();
        try {
            $row = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $id)->lock(true)->find();
            if (!$row || (string) $row['status'] !== self::PENDING || (int) $row['official_withdraw_id'] > 0) {
                Db::rollback();
                return false;
            }
            $eligibleReviewTime = self::eligibleReviewTime($row);
            if ($eligibleReviewTime > time()) {
                Db::rollback();
                return false;
            }
            BenefitLedgerService::ensureAccount((int) $row['client_id']);
            $benefitAccount = Db::name('addon_idcsmart_client_level_benefit_account')
                ->where('client_id', (int) $row['client_id'])->lock(true)->find();
            if (Money::compare($benefitAccount['debt'] ?? 0, '0.00') > 0) {
                $debtClientId = (int) $row['client_id'];
                Db::rollback();
                self::reconcileRefundExposure($debtClientId);
                return false;
            }
            $account = self::decrypt($row['account_cipher']);
            $name = self::decrypt($row['name_cipher']);
            $methodId = self::officialMethodId((string) $row['method_type']);
            if ($account === '' || $name === '' || $methodId <= 0) {
                throw new \RuntimeException('official_withdraw_payload_invalid');
            }
            $officialId = (int) Db::name('addon_idcsmart_withdraw')->insertGetId([
                'source' => self::OFFICIAL_SOURCE,
                'amount' => $row['amount'],
                'method' => (string) $row['method_type'],
                'addon_idcsmart_withdraw_method_id' => $methodId,
                'card_number' => (string) $row['method_type'] === 'bank' ? $account : '',
                // 2.0.1 官方页面对收款方式名称和机器值的判断不一致，
                // 银行卡同时填 account，确保官方审核弹窗可见。
                'account' => $account,
                'name' => $name,
                'notes' => '推广权益提现 ' . $row['business_no'] . '；已完成强制冻结期',
                'client_id' => (int) $row['client_id'],
                'status' => 0,
                'reason' => '',
                'admin_id' => 0,
                'fee' => '0.00',
                'transaction_id' => 0,
                'create_time' => (int) $row['create_time'],
                'update_time' => time(),
            ]);
            if ($officialId <= 0) {
                throw new \RuntimeException('official_withdraw_insert_failed');
            }
            $affected = Db::name('addon_idcsmart_client_level_withdraw')
                ->where('id', (int) $row['id'])
                ->where('status', self::PENDING)
                ->where('official_withdraw_id', 0)
                ->update(['official_withdraw_id' => $officialId, 'update_time' => time()]);
            if ((int) $affected !== 1) {
                throw new \RuntimeException('official_withdraw_link_race');
            }
            ReferralService::audit('withdraw_publish_official', (int) $row['client_id'], 0, 'system', 0, [
                'withdraw_id' => (int) $row['id'],
                'official_withdraw_id' => $officialId,
                'amount' => $row['amount'],
            ], true);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
    }

    private static function markOfficialApproved($officialId, $adminId)
    {
        Db::startTrans();
        try {
            $row = Db::name('addon_idcsmart_client_level_withdraw')
                ->where('official_withdraw_id', (int) $officialId)->lock(true)->find();
            if (!$row) {
                Db::rollback();
                return false;
            }
            if ((string) $row['status'] === self::PAID) {
                Db::commit();
                return true;
            }
            if (in_array((string) $row['status'], [self::REJECTED, self::CANCELLED], true)) {
                Db::rollback();
                self::forceOfficialRejected((int) $officialId, '推广权益冻结已释放，请用户重新提交');
                return false;
            }
            BenefitLedgerService::ensureAccount((int) $row['client_id']);
            $benefitAccount = Db::name('addon_idcsmart_client_level_benefit_account')
                ->where('client_id', (int) $row['client_id'])->lock(true)->find();
            if (Money::compare($benefitAccount['debt'] ?? 0, '0.00') > 0) {
                $clientId = (int) $row['client_id'];
                Db::rollback();
                self::reconcileRefundExposure($clientId);
                self::forceOfficialRejected((int) $officialId, '关联推广订单退款，申请已撤销，请按剩余净权益重新提交');
                return false;
            }
            if ((string) $row['status'] === self::APPROVED) {
                Db::commit();
                return true;
            }
            if (self::eligibleReviewTime($row) > time()) {
                Db::rollback();
                Db::name('addon_idcsmart_withdraw')->where('id', (int) $officialId)->where('source', self::OFFICIAL_SOURCE)
                    ->update(['status' => 0, 'reason' => '强制冻结期尚未结束', 'update_time' => time()]);
                return false;
            }
            $affected = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $row['id'])
                ->where('status', self::PENDING)->update([
                    'status' => self::APPROVED,
                    'admin_id' => (int) $adminId,
                    'review_note' => '官方提现审核通过',
                    'update_time' => time(),
                ]);
            if ((int) $affected !== 1) {
                throw new \RuntimeException('official_approve_race');
            }
            ReferralService::audit('withdraw_approve_official', (int) $row['client_id'], 0, 'admin', (int) $adminId, [
                'withdraw_id' => (int) $row['id'],
                'official_withdraw_id' => (int) $officialId,
                'amount' => $row['amount'],
            ], true);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
    }

    private static function markOfficialRejected($officialId, $reason, $adminId)
    {
        $row = Db::name('addon_idcsmart_client_level_withdraw')->where('official_withdraw_id', (int) $officialId)->find();
        if (!$row || in_array((string) $row['status'], [self::REJECTED, self::CANCELLED], true)) {
            return true;
        }
        if ((string) $row['status'] === self::PAID) {
            return false;
        }
        $result = self::release((int) $row['id'], null, self::REJECTED, 0, (int) $adminId, $reason !== '' ? $reason : '官方提现驳回');
        return (int) ($result['status'] ?? 0) === 200;
    }

    private static function markOfficialPaid($officialId, $reference)
    {
        Db::startTrans();
        try {
            $row = Db::name('addon_idcsmart_client_level_withdraw')
                ->where('official_withdraw_id', (int) $officialId)->lock(true)->find();
            if (!$row) {
                Db::rollback();
                return false;
            }
            if ((string) $row['status'] === self::PAID) {
                Db::commit();
                return true;
            }
            if ((string) $row['status'] === self::PENDING) {
                Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $row['id'])->where('status', self::PENDING)
                    ->update(['status' => self::APPROVED, 'review_note' => '官方提现已通过', 'update_time' => time()]);
                $row['status'] = self::APPROVED;
            }
            if ((string) $row['status'] !== self::APPROVED) {
                Db::rollback();
                return false;
            }
            $clientId = (int) $row['client_id'];
            BenefitLedgerService::ensureAccount($clientId);
            $account = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->lock(true)->find();
            $before = BenefitLedgerService::accountValues($account);
            if (Money::compare($before['withdraw_frozen'], $row['amount']) < 0) {
                throw new \RuntimeException('official_paid_frozen_mismatch');
            }
            $after = $before;
            $after['withdraw_frozen'] = Money::subtract($after['withdraw_frozen'], $row['amount']);
            $affected = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $row['id'])
                ->where('status', self::APPROVED)->update([
                    'status' => self::PAID,
                    'review_note' => '官方提现已确认汇款',
                    'paid_reference' => substr(trim((string) $reference), 0, 100),
                    'paid_time' => time(),
                    'update_time' => time(),
                ]);
            if ((int) $affected !== 1) {
                throw new \RuntimeException('official_paid_race');
            }
            BenefitLedgerService::writeAccount($clientId, $after);
            BenefitLedgerService::flow($clientId, 'withdraw_paid', 'withdraw', (int) $row['id'], 'withdraw:paid:' . $row['business_no'], $before, $after, [
                'amount' => $row['amount'],
                'paid_reference' => substr(trim((string) $reference), 0, 100),
                'official_withdraw_id' => (int) $officialId,
            ]);
            ReferralService::audit('withdraw_paid_official', $clientId, 0, 'system', 0, [
                'withdraw_id' => (int) $row['id'],
                'official_withdraw_id' => (int) $officialId,
                'amount' => $row['amount'],
            ], true);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
    }

    private static function forceOfficialRejected($officialId, $reason)
    {
        try {
            Db::name('addon_idcsmart_withdraw')
                ->where('id', (int) $officialId)
                ->where('source', self::OFFICIAL_SOURCE)
                ->whereIn('status', [0, 1])
                ->update(['status' => 2, 'reason' => self::textLimit($reason, 500), 'update_time' => time()]);
        } catch (\Throwable $e) {
        }
    }

    private static function rejectWithdrawalForRefund($id, $orderId)
    {
        Db::startTrans();
        try {
            $row = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $id)->lock(true)->find();
            if (!$row || !in_array((string) $row['status'], [self::PENDING, self::APPROVED], true)) {
                Db::commit();
                return 'skipped';
            }

            $officialId = (int) ($row['official_withdraw_id'] ?? 0);
            if ($officialId > 0) {
                $official = Db::name('addon_idcsmart_withdraw')
                    ->where('id', $officialId)->where('source', self::OFFICIAL_SOURCE)->lock(true)->find();
                if ($official && (int) $official['status'] === 3) {
                    Db::commit();
                    return self::markOfficialPaid($officialId, self::officialPaidReference($official)) ? 'paid' : 'skipped';
                }
                if ($official && in_array((int) $official['status'], [0, 1], true)) {
                    Db::name('addon_idcsmart_withdraw')->where('id', $officialId)->whereIn('status', [0, 1])->update([
                        'status' => 2,
                        'reason' => '关联推广订单退款，申请已撤销，请按剩余净权益重新提交',
                        'update_time' => time(),
                    ]);
                }
            }

            $clientId = (int) $row['client_id'];
            BenefitLedgerService::ensureAccount($clientId);
            $account = Db::name('addon_idcsmart_client_level_benefit_account')
                ->where('client_id', $clientId)->lock(true)->find();
            $before = BenefitLedgerService::accountValues($account);
            if (Money::compare($before['withdraw_frozen'], $row['amount']) < 0) {
                throw new \RuntimeException('refund_reconcile_frozen_mismatch');
            }
            $after = $before;
            $after['withdraw_frozen'] = Money::subtract($after['withdraw_frozen'], $row['amount']);
            $offset = Money::min($after['debt'], $row['amount']);
            $after['debt'] = Money::subtract($after['debt'], $offset);
            $after['withdrawable'] = Money::add($after['withdrawable'], Money::subtract($row['amount'], $offset));

            $affected = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $row['id'])
                ->where('status', (string) $row['status'])->update([
                    'status' => self::REJECTED,
                    'admin_id' => 0,
                    'review_note' => '关联推广订单退款，申请已撤销，请按剩余净权益重新提交',
                    'update_time' => time(),
                ]);
            if ((int) $affected !== 1) {
                throw new \RuntimeException('refund_reconcile_status_race');
            }
            BenefitLedgerService::writeAccount($clientId, $after);
            BenefitLedgerService::flow(
                $clientId,
                'withdraw_refund_release',
                'withdraw',
                (int) $row['id'],
                'withdraw:refund_release:' . $row['business_no'],
                $before,
                $after,
                ['amount' => $row['amount'], 'debt_offset' => $offset, 'order_id' => (int) $orderId, 'official_withdraw_id' => $officialId]
            );
            ReferralService::audit('withdraw_refund_rejected', $clientId, 0, 'system', 0, [
                'withdraw_id' => (int) $row['id'],
                'official_withdraw_id' => $officialId,
                'order_id' => (int) $orderId,
                'amount' => $row['amount'],
                'debt_offset' => $offset,
            ], true);
            Db::commit();
            return 'rejected';
        } catch (\Throwable $e) {
            Db::rollback();
            return 'error';
        }
    }

    private static function clientHasDebt($clientId)
    {
        return Money::compare(self::clientDebt((int) $clientId), '0.00') > 0;
    }

    private static function clientDebt($clientId)
    {
        try {
            $value = Db::name('addon_idcsmart_client_level_benefit_account')
                ->where('client_id', (int) $clientId)->value('debt');
            return Money::normalize($value ?? 0);
        } catch (\Throwable $e) {
            return '0.00';
        }
    }

    private static function officialPaidReference($official)
    {
        $official = is_array($official) ? $official : [];
        $transactionId = (int) ($official['transaction_id'] ?? 0);
        if ($transactionId > 0) {
            $reference = trim((string) Db::name('transaction')->where('id', $transactionId)->value('transaction_number'));
            if ($reference !== '') {
                return $reference;
            }
        }
        return 'OFFICIAL-' . (int) ($official['id'] ?? 0);
    }

    private static function eligibleReviewTime($row)
    {
        $row = is_array($row) ? $row : [];
        $eligible = (int) ($row['eligible_review_time'] ?? 0);
        if ($eligible > 0) {
            return $eligible;
        }
        $policy = BenefitLedgerService::policyForClient((int) ($row['client_id'] ?? 0));
        $eligible = (int) ($row['create_time'] ?? time()) + ((int) $policy['withdrawal_review_days'] * 86400);
        if (!empty($row['id'])) {
            Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $row['id'])
                ->where('eligible_review_time', 0)->update(['eligible_review_time' => $eligible, 'update_time' => time()]);
        }
        return $eligible;
    }

    private static function officialIntegrationAvailable()
    {
        try {
            $plugin = Db::name('plugin')->where('module', 'addon')->where('name', 'IdcsmartWithdraw')->find();
            if (!$plugin || (int) $plugin['status'] !== 1) {
                return false;
            }
            Db::name('addon_idcsmart_withdraw')->where('id', 0)->count();
            return Db::name('addon_idcsmart_withdraw_method')->count() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function officialMethodId($type)
    {
        $type = strtolower(trim((string) $type));
        $keywords = [
            'bank' => ['银行', 'bank'],
            'alipay' => ['支付宝', 'alipay'],
            'wechat' => ['微信', 'wechat', 'weixin'],
            'other' => ['其他', 'other'],
        ];
        $methods = Db::name('addon_idcsmart_withdraw_method')->field('id,name')->order('id', 'asc')->select()->toArray();
        foreach ($methods as $method) {
            $name = function_exists('mb_strtolower')
                ? mb_strtolower((string) $method['name'], 'UTF-8')
                : strtolower((string) $method['name']);
            foreach ($keywords[$type] ?? [] as $keyword) {
                if (strpos($name, $keyword) !== false) {
                    return (int) $method['id'];
                }
            }
        }
        return !empty($methods) ? (int) $methods[0]['id'] : 0;
    }

    public static function listRows($clientId, $param = [], $admin = false)
    {
        if (!$admin && (int) $clientId > 0) {
            self::publishEligibleToOfficial(20, (int) $clientId);
            self::syncOfficialStatuses(50, (int) $clientId);
        }
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 20)));
        $query = Db::name('addon_idcsmart_client_level_withdraw');
        if (!$admin) {
            $query->where('client_id', (int) $clientId);
        } elseif (!empty($param['client_id'])) {
            $query->where('client_id', (int) $param['client_id']);
        }
        if (!empty($param['status'])) {
            $query->where('status', trim((string) $param['status']));
        }
        $count = (int) (clone $query)->count();
        $list = $query->field('id,business_no,client_id,amount,method_type,account_mask,name_mask,status,admin_id,official_withdraw_id,review_note,paid_reference,paid_time,eligible_review_time,create_time,update_time')
            ->order('id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$row) {
            $row['eligible_review_time'] = self::eligibleReviewTime($row);
        }
        unset($row);
        return ['list' => $list, 'count' => $count];
    }

    private static function release($id, $requiredStatus, $targetStatus, $ownerClientId, $adminId, $note)
    {
        Db::startTrans();
        try {
            $query = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $id);
            if ($ownerClientId > 0) {
                $query->where('client_id', $ownerClientId);
            }
            $row = $query->lock(true)->find();
            if (!$row) {
                Db::rollback();
                return ['status' => 404, 'msg' => '提现记录不存在'];
            }
            $allowed = $requiredStatus !== null
                ? [(string) $requiredStatus]
                : [self::PENDING, self::APPROVED];
            if (!in_array((string) $row['status'], $allowed, true)) {
                Db::rollback();
                return ['status' => 400, 'msg' => '当前状态不能执行该操作'];
            }
            if ($targetStatus === self::CANCELLED && (int) ($row['official_withdraw_id'] ?? 0) > 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '该申请已进入审核流程，暂时不能取消'];
            }
            $clientId = (int) $row['client_id'];
            BenefitLedgerService::ensureAccount($clientId);
            $account = Db::name('addon_idcsmart_client_level_benefit_account')->where('client_id', $clientId)->lock(true)->find();
            $before = BenefitLedgerService::accountValues($account);
            if (Money::compare($before['withdraw_frozen'], $row['amount']) < 0) {
                throw new \RuntimeException('withdraw_frozen_mismatch');
            }
            $after = $before;
            $after['withdraw_frozen'] = Money::subtract($after['withdraw_frozen'], $row['amount']);
            if (Money::compare($after['debt'], '0.00') > 0) {
                $offset = Money::min($after['debt'], $row['amount']);
                $after['debt'] = Money::subtract($after['debt'], $offset);
                $after['withdrawable'] = Money::add($after['withdrawable'], Money::subtract($row['amount'], $offset));
            } else {
                $after['withdrawable'] = Money::add($after['withdrawable'], $row['amount']);
            }
            $affected = Db::name('addon_idcsmart_client_level_withdraw')->where('id', (int) $id)
                ->where('status', (string) $row['status'])->update([
                    'status' => $targetStatus, 'admin_id' => (int) $adminId,
                    'review_note' => self::textLimit($note, 500), 'update_time' => time(),
                ]);
            if ((int) $affected !== 1) {
                throw new \RuntimeException('withdraw_status_race');
            }
            BenefitLedgerService::writeAccount($clientId, $after);
            BenefitLedgerService::flow($clientId, 'withdraw_release', 'withdraw', (int) $id, 'withdraw:release:' . $row['business_no'], $before, $after, ['target_status' => $targetStatus]);
            ReferralService::audit('withdraw_' . $targetStatus, $clientId, 0, $adminId > 0 ? 'admin' : 'client', $adminId > 0 ? $adminId : $clientId, ['withdraw_id' => (int) $id, 'amount' => $row['amount']], true);
            Db::commit();
            return ['status' => 200, 'msg' => $targetStatus === self::CANCELLED ? '提现已取消' : '提现已驳回并释放冻结金额'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 400, 'msg' => '提现状态更新失败，请刷新后重试'];
        }
    }

    private static function encrypt($value)
    {
        if (!function_exists('aes_password_encode')) {
            return '';
        }
        try {
            return (string) aes_password_encode((string) $value);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function decrypt($value)
    {
        if (!function_exists('aes_password_decode')) {
            return '';
        }
        try {
            return (string) aes_password_decode((string) $value);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function maskAccount($account)
    {
        $account = trim((string) $account);
        $length = strlen($account);
        if ($length <= 4) {
            return str_repeat('*', max(2, $length));
        }
        return substr($account, 0, 2) . str_repeat('*', min(10, $length - 4)) . substr($account, -2);
    }

    private static function maskName($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        return (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1)) . '**';
    }

    private static function validatedAmount($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('提现金额格式错误');
        }
        $value = Money::normalize($value);
        if (Money::compare($value, '0.00') <= 0) {
            throw new \InvalidArgumentException('提现金额必须大于零');
        }
        return $value;
    }

    private static function textLimit($value, $length)
    {
        $value = trim((string) $value);
        return function_exists('mb_substr')
            ? mb_substr($value, 0, (int) $length, 'UTF-8')
            : substr($value, 0, (int) $length);
    }

    private static function businessNo()
    {
        try {
            return 'CLW' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
        } catch (\Throwable $e) {
            return 'CLW' . date('YmdHis') . strtoupper(substr(md5(uniqid('', true)), 0, 10));
        }
    }
}
