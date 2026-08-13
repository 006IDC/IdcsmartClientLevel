<?php

namespace addon\idcsmart_client_level\model;

use addon\idcsmart_client_level\lib\Money;
use think\facade\Db;

class ReferralService
{
    const COOKIE_NAME = 'icl_invite_code';

    public static function profile($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return [];
        }
        $row = Db::name('addon_idcsmart_client_level_referrer')->where('client_id', $clientId)->find();
        if (!$row) {
            $now = time();
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $code = self::generateCode();
                try {
                    Db::name('addon_idcsmart_client_level_referrer')->insert([
                        'client_id' => $clientId,
                        'invite_code' => $code,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    break;
                } catch (\Throwable $e) {
                    $existing = Db::name('addon_idcsmart_client_level_referrer')
                        ->where('client_id', $clientId)->find();
                    if ($existing) {
                        break;
                    }
                    if ($attempt === 19) {
                        throw $e;
                    }
                }
            }
            $row = Db::name('addon_idcsmart_client_level_referrer')->where('client_id', $clientId)->find();
        }
        return is_array($row) ? $row : [];
    }

    public static function captureInvite($code)
    {
        $code = strtolower(trim((string) $code));
        // 新生成的邀请码是 16 位十六进制；兼容 HalfAgent 导入的 4-32 位
        // 字母数字码，避免导入成功后邀请链接反而无法落 Cookie。
        if (!preg_match('/^[a-z0-9]{4,32}$/', $code)) {
            return false;
        }
        $exists = Db::name('addon_idcsmart_client_level_referrer')
            ->where('invite_code', $code)->where('status', 1)->find();
        if (!$exists) {
            return false;
        }
        if (function_exists('cookie')) {
            $options = [
                'expire' => 7 * 86400,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            try {
                if (function_exists('request') && \request()->isSsl()) {
                    $options['secure'] = true;
                }
            } catch (\Throwable $e) {
            }
            \cookie(self::COOKIE_NAME, $code, $options);
        }
        return true;
    }

    public static function bindFromCookie($inviteeClientId)
    {
        $inviteeClientId = (int) $inviteeClientId;
        if ($inviteeClientId <= 0) {
            return false;
        }
        $code = '';
        try {
            $code = function_exists('request') ? trim((string) \request()->cookie(self::COOKIE_NAME, '')) : '';
        } catch (\Throwable $e) {
        }
        if ($code === '' && isset($_COOKIE[self::COOKIE_NAME])) {
            $code = trim((string) $_COOKIE[self::COOKIE_NAME]);
        }
        if ($code === '') {
            return false;
        }
        $profile = Db::name('addon_idcsmart_client_level_referrer')
            ->where('invite_code', strtolower($code))->where('status', 1)->find();
        self::clearCookie();
        if (!$profile) {
            return false;
        }
        $result = self::bind((int) $profile['client_id'], $inviteeClientId, false, 'invite_register', 0);
        return (int) ($result['status'] ?? 400) === 200;
    }

    public static function bind($referrerClientId, $inviteeClientId, $inheritHistory = false, $source = 'admin', $adminId = 0)
    {
        $referrerClientId = (int) $referrerClientId;
        $inviteeClientId = (int) $inviteeClientId;
        $adminId = (int) $adminId;
        if ($referrerClientId <= 0 || $inviteeClientId <= 0) {
            return ['status' => 400, 'msg' => '推广人和客户不能为空'];
        }
        if ($referrerClientId === $inviteeClientId) {
            return ['status' => 400, 'msg' => '不能绑定自己'];
        }
        $now = time();
        $contributionStart = $inheritHistory ? 0 : $now;
        $source = substr(trim((string) $source), 0, 40);
        Db::startTrans();
        try {
            // 所有绑定请求都按用户 ID 固定顺序锁定关系两端，再做环路判断。
            // 这样 A->B 与 B->A 等并发请求不能同时通过事务外的旧快照。
            $clientRows = Db::name('client')
                ->whereIn('id', [$referrerClientId, $inviteeClientId])
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();
            if (count($clientRows) !== 2) {
                Db::rollback();
                return ['status' => 404, 'msg' => '推广人或客户不存在'];
            }
            if (self::wouldCreateCycle($referrerClientId, $inviteeClientId)) {
                Db::rollback();
                return ['status' => 400, 'msg' => '该绑定会形成循环关系'];
            }
            $current = Db::name('addon_idcsmart_client_level_referral_bind')
                ->where('active_invitee_id', $inviteeClientId)->lock(true)->find();
            if ($current && (int) $current['referrer_client_id'] === $referrerClientId) {
                if ($inheritHistory && (int) ($current['inherit_history'] ?? 0) !== 1) {
                    Db::name('addon_idcsmart_client_level_referral_bind')->where('id', (int) $current['id'])->update([
                        'inherit_history' => 1,
                        'contribution_start_time' => 0,
                        'source' => $source === '' ? 'admin' : $source,
                        'admin_id' => $adminId,
                        'update_time' => $now,
                    ]);
                    self::audit('referral_bind_inherit_history', $referrerClientId, $inviteeClientId, $adminId > 0 ? 'admin' : 'system', $adminId, [
                        'bind_id' => (int) $current['id'],
                    ], true);
                    Db::commit();
                    if ((int) (ClientLevelService::settings()['referral_enabled'] ?? 0) === 1) {
                        ClientLevelService::rebuild($inviteeClientId);
                    }
                    return ['status' => 200, 'msg' => '推广关系已更新并继承历史消费', 'data' => ['id' => (int) $current['id']]];
                }
                Db::commit();
                return ['status' => 200, 'msg' => '推广关系已存在', 'data' => ['id' => (int) $current['id']]];
            }
            if ($current) {
                Db::name('addon_idcsmart_client_level_referral_bind')->where('id', (int) $current['id'])->update([
                    'active_invitee_id' => null,
                    'status' => 0,
                    'end_time' => $now,
                    'update_time' => $now,
                ]);
            }
            $profile = self::profile($referrerClientId);
            $id = (int) Db::name('addon_idcsmart_client_level_referral_bind')->insertGetId([
                'referrer_client_id' => $referrerClientId,
                'invitee_client_id' => $inviteeClientId,
                'active_invitee_id' => $inviteeClientId,
                'invite_code' => (string) ($profile['invite_code'] ?? ''),
                'source' => $source === '' ? 'admin' : $source,
                'inherit_history' => $inheritHistory ? 1 : 0,
                'contribution_start_time' => $contributionStart,
                'end_time' => 0,
                'status' => 1,
                'admin_id' => $adminId,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            self::audit('referral_bind', $referrerClientId, $inviteeClientId, $adminId > 0 ? 'admin' : 'system', $adminId, [
                'bind_id' => $id,
                'source' => $source,
                'inherit_history' => $inheritHistory ? 1 : 0,
                'previous_bind_id' => $current ? (int) $current['id'] : 0,
            ], true);
            Db::commit();
            if ($inheritHistory && (int) (ClientLevelService::settings()['referral_enabled'] ?? 0) === 1) {
                ClientLevelService::rebuild($inviteeClientId);
            }
            return ['status' => 200, 'msg' => '推广关系已保存', 'data' => ['id' => $id]];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 400, 'msg' => '推广关系保存失败'];
        }
    }

    public static function activeBindForOrder($inviteeClientId, $orderTime)
    {
        $inviteeClientId = (int) $inviteeClientId;
        $orderTime = (int) $orderTime;
        if ($inviteeClientId <= 0) {
            return [];
        }
        $query = Db::name('addon_idcsmart_client_level_referral_bind')
            ->where('invitee_client_id', $inviteeClientId)
            ->where('contribution_start_time', '<=', $orderTime);
        $query->where(function ($q) use ($orderTime) {
            $q->where('end_time', 0)->whereOr('end_time', '>', $orderTime);
        });
        $row = $query->order('id', 'desc')->find();
        return is_array($row) ? $row : [];
    }

    public static function currentBind($inviteeClientId)
    {
        $row = Db::name('addon_idcsmart_client_level_referral_bind')
            ->where('active_invitee_id', (int) $inviteeClientId)->where('status', 1)->find();
        return is_array($row) ? $row : [];
    }

    public static function referrals($clientId, $param = [])
    {
        $clientId = (int) $clientId;
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 20)));
        $query = Db::name('addon_idcsmart_client_level_referral_bind')->alias('b')
            ->leftJoin('client c', 'c.id=b.invitee_client_id')
            ->where('b.referrer_client_id', $clientId)->where('b.status', 1);
        $count = (int) (clone $query)->count('b.id');
        $rows = $query->field('b.id,b.invitee_client_id,b.source,b.inherit_history,b.contribution_start_time,b.create_time,c.username,c.email,c.phone')
            ->order('b.id', 'desc')->page($page, $limit)->select()->toArray();
        $pageMetrics = self::orderMetricsForClients(array_column($rows, 'invitee_client_id'));
        foreach ($rows as &$row) {
            $row['display_name'] = self::maskedClient($row);
            unset($row['username'], $row['email'], $row['phone']);
            $metric = $pageMetrics[(int) $row['invitee_client_id']] ?? self::emptyOrderMetric();
            $row = array_merge($row, $metric);
        }
        unset($row);
        $allInviteeIds = Db::name('addon_idcsmart_client_level_referral_bind')
            ->where('referrer_client_id', $clientId)->where('status', 1)->column('invitee_client_id');
        $allMetrics = self::orderMetricsForClients($allInviteeIds);
        $summary = [
            'total_clients' => count(array_unique(array_map('intval', $allInviteeIds))),
            'paying_clients' => 0,
            'paid_order_count' => 0,
            'gross_paid_amount' => '0.00',
            'refund_amount' => '0.00',
            'net_amount' => '0.00',
            'conversion_rate' => '0.00',
        ];
        foreach ($allMetrics as $metric) {
            if ((int) $metric['paid_order_count'] > 0) {
                $summary['paying_clients']++;
            }
            $summary['paid_order_count'] += (int) $metric['paid_order_count'];
            $summary['gross_paid_amount'] = Money::add($summary['gross_paid_amount'], $metric['gross_paid_amount']);
            $summary['refund_amount'] = Money::add($summary['refund_amount'], $metric['refund_amount']);
            $summary['net_amount'] = Money::add($summary['net_amount'], $metric['net_amount']);
        }
        if ($summary['total_clients'] > 0) {
            $summary['conversion_rate'] = number_format(
                round($summary['paying_clients'] * 100 / $summary['total_clients'], 2),
                2,
                '.',
                ''
            );
        }
        return [
            'list' => $rows,
            'count' => $count,
            'summary' => $summary,
            'dashboard' => self::referralDashboard($clientId, $allInviteeIds, $summary),
        ];
    }

    private static function referralDashboard($clientId, $clientIds, $summary)
    {
        $clientId = (int) $clientId;
        $now = time();
        $todayStart = strtotime(date('Y-m-d', $now));
        $monthStart = strtotime(date('Y-m-01', $now));
        $reward = Db::name('addon_idcsmart_client_level_referral_accrual')
            ->where('referrer_client_id', $clientId)
            ->field(
                "COALESCE(SUM(net_entitlement),0) total_reward,"
                . "COALESCE(SUM(CASE WHEN create_time>={$monthStart} THEN net_entitlement ELSE 0 END),0) month_reward,"
                . "COALESCE(SUM(CASE WHEN create_time>={$todayStart} THEN net_entitlement ELSE 0 END),0) today_reward"
            )->find();

        $newOrders = 0;
        $renewOrders = 0;
        $otherOrders = 0;
        $daily = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $timestamp = strtotime('-' . $offset . ' days', $todayStart);
            $key = date('m-d', $timestamp);
            $daily[$key] = ['date' => $key, 'net_amount' => '0.00'];
        }
        $clientIds = array_values(array_unique(array_filter(array_map('intval', is_array($clientIds) ? $clientIds : []))));
        if (!empty($clientIds)) {
            $typeRows = Db::name('order')->whereIn('client_id', $clientIds)
                ->whereIn('status', ['Paid', 'Refunded'])->where('type', '<>', 'recharge')
                ->field('type,COUNT(id) count')->group('type')->select()->toArray();
            foreach ($typeRows as $typeRow) {
                $type = strtolower((string) ($typeRow['type'] ?? ''));
                $typeCount = (int) ($typeRow['count'] ?? 0);
                if ($type === 'new') {
                    $newOrders += $typeCount;
                } elseif ($type === 'renew') {
                    $renewOrders += $typeCount;
                } else {
                    $otherOrders += $typeCount;
                }
            }
            $trendRows = Db::name('order')->whereIn('client_id', $clientIds)
                ->whereIn('status', ['Paid', 'Refunded'])->where('type', '<>', 'recharge')
                ->where('pay_time', '>=', strtotime('-6 days', $todayStart))
                ->field("DATE_FORMAT(FROM_UNIXTIME(pay_time),'%m-%d') date,COALESCE(SUM(GREATEST(amount-refund_amount,0)),0) net_amount")
                ->group("DATE_FORMAT(FROM_UNIXTIME(pay_time),'%m-%d')")->select()->toArray();
            foreach ($trendRows as $trendRow) {
                $key = (string) ($trendRow['date'] ?? '');
                if (isset($daily[$key])) {
                    $daily[$key]['net_amount'] = Money::normalize($trendRow['net_amount'] ?? 0);
                }
            }
        }
        $newRenewTotal = $newOrders + $renewOrders;
        return [
            'month_reward' => Money::normalize($reward['month_reward'] ?? 0),
            'today_reward' => Money::normalize($reward['today_reward'] ?? 0),
            'total_reward' => Money::normalize($reward['total_reward'] ?? 0),
            'total_clients' => (int) ($summary['total_clients'] ?? 0),
            'order_mix' => [
                'new' => $newOrders,
                'renew' => $renewOrders,
                'other' => $otherOrders,
                'new_percent' => $newRenewTotal > 0
                    ? number_format(round($newOrders * 100 / $newRenewTotal, 2), 2, '.', '')
                    : '0.00',
            ],
            'daily_net_spend' => array_values($daily),
        ];
    }

    public static function adminBinds($param = [])
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 50)));
        $query = Db::name('addon_idcsmart_client_level_referral_bind')->alias('b')
            ->leftJoin('client r', 'r.id=b.referrer_client_id')
            ->leftJoin('client i', 'i.id=b.invitee_client_id');
        if (isset($param['status']) && $param['status'] !== '') {
            $query->where('b.status', (int) $param['status']);
        }
        if (!empty($param['client_id'])) {
            $clientId = (int) $param['client_id'];
            $query->where(function ($q) use ($clientId) {
                $q->where('b.referrer_client_id', $clientId)->whereOr('b.invitee_client_id', $clientId);
            });
        }
        $count = (int) (clone $query)->count('b.id');
        $list = $query->field('b.*,r.username referrer_name,i.username invitee_name,r.last_login_ip referrer_last_login_ip,i.last_login_ip invitee_last_login_ip')
            ->order('b.id', 'desc')->page($page, $limit)->select()->toArray();
        $metrics = self::orderMetricsForClients(array_column($list, 'invitee_client_id'));
        foreach ($list as &$row) {
            $riskFlags = [];
            $referrerIp = trim((string) ($row['referrer_last_login_ip'] ?? ''));
            $inviteeIp = trim((string) ($row['invitee_last_login_ip'] ?? ''));
            if ($referrerIp !== '' && $inviteeIp !== '' && hash_equals($referrerIp, $inviteeIp)) {
                $riskFlags[] = 'same_login_ip';
            }
            $metric = $metrics[(int) $row['invitee_client_id']] ?? self::emptyOrderMetric();
            if (Money::compare($metric['gross_paid_amount'], '0.00') > 0
                && (float) $metric['refund_amount'] / max(0.01, (float) $metric['gross_paid_amount']) >= 0.5) {
                $riskFlags[] = 'high_refund_ratio';
            }
            $row['risk_flags'] = $riskFlags;
            $row['risk_level'] = empty($riskFlags) ? 'normal' : 'watch';
            $row['paid_order_count'] = (int) $metric['paid_order_count'];
            $row['net_amount'] = $metric['net_amount'];
            unset($row['referrer_last_login_ip'], $row['invitee_last_login_ip']);
        }
        unset($row);
        return ['list' => $list, 'count' => $count];
    }

    private static function orderMetricsForClients($clientIds)
    {
        $clientIds = array_values(array_unique(array_filter(array_map('intval', is_array($clientIds) ? $clientIds : []), function ($id) {
            return $id > 0;
        })));
        if (empty($clientIds)) {
            return [];
        }
        $result = [];
        foreach (array_chunk($clientIds, 500) as $chunk) {
            $rows = Db::name('order')
                ->whereIn('client_id', $chunk)
                ->whereIn('status', ['Paid', 'Refunded'])
                ->where('type', '<>', 'recharge')
                ->field("client_id,COUNT(id) paid_order_count,COALESCE(SUM(amount),0) gross_paid_amount,COALESCE(SUM(refund_amount),0) refund_amount,COALESCE(SUM(GREATEST(amount-refund_amount,0)),0) net_amount,MIN(CASE WHEN pay_time>0 THEN pay_time ELSE create_time END) first_paid_time,MAX(CASE WHEN pay_time>0 THEN pay_time ELSE create_time END) last_paid_time")
                ->group('client_id')->select()->toArray();
            foreach ($rows as $row) {
                $metricClientId = (int) $row['client_id'];
                $result[$metricClientId] = [
                    'paid_order_count' => (int) ($row['paid_order_count'] ?? 0),
                    'gross_paid_amount' => Money::normalize($row['gross_paid_amount'] ?? 0),
                    'refund_amount' => Money::normalize($row['refund_amount'] ?? 0),
                    'net_amount' => Money::normalize($row['net_amount'] ?? 0),
                    'first_paid_time' => (int) ($row['first_paid_time'] ?? 0),
                    'last_paid_time' => (int) ($row['last_paid_time'] ?? 0),
                ];
            }
        }
        return $result;
    }

    private static function emptyOrderMetric()
    {
        return [
            'paid_order_count' => 0,
            'gross_paid_amount' => '0.00',
            'refund_amount' => '0.00',
            'net_amount' => '0.00',
            'first_paid_time' => 0,
            'last_paid_time' => 0,
        ];
    }

    public static function importHalfAgent($execute = false)
    {
        if (!self::tableExists('half_agent_agent') || !self::tableExists('half_agent_bind')) {
            return ['status' => 200, 'msg' => '未发现 HalfAgent 数据', 'data' => ['available' => 0, 'profiles' => 0, 'binds' => 0, 'conflicts' => []]];
        }
        $profiles = Db::name('half_agent_agent')->where('status', 1)->select()->toArray();
        $binds = Db::name('half_agent_bind')->select()->toArray();
        $conflicts = [];
        $failures = [];
        $profileCount = 0;
        $bindCount = 0;
        $importedProfiles = 0;
        $importedBinds = 0;
        foreach ($profiles as $profile) {
            $clientId = (int) ($profile['uid'] ?? 0);
            if ($clientId <= 0 || !Db::name('client')->where('id', $clientId)->count()) {
                continue;
            }
            $code = strtolower(trim((string) ($profile['code'] ?? '')));
            $codeUsable = preg_match('/^[a-z0-9]{4,32}$/', $code)
                && !Db::name('addon_idcsmart_client_level_referrer')->where('invite_code', $code)->where('client_id', '<>', $clientId)->count();
            if (!$codeUsable) {
                $code = self::generateCode();
            }
            $profileCount++;
            if ($execute) {
                $existing = Db::name('addon_idcsmart_client_level_referrer')->where('client_id', $clientId)->find();
                if (!$existing) {
                    try {
                        Db::name('addon_idcsmart_client_level_referrer')->insert([
                            'client_id' => $clientId, 'invite_code' => $code, 'status' => 1,
                            'create_time' => time(), 'update_time' => time(),
                        ]);
                        $importedProfiles++;
                    } catch (\Throwable $e) {
                        try {
                            self::profile($clientId);
                            $importedProfiles++;
                        } catch (\Throwable $ignored) {
                            $failures[] = ['type' => 'profile', 'client_id' => $clientId];
                        }
                    }
                }
            }
        }
        foreach ($binds as $bind) {
            $referrer = (int) ($bind['agent_uid'] ?? 0);
            $invitee = (int) ($bind['invitee_uid'] ?? 0);
            if ($referrer <= 0 || $invitee <= 0 || $referrer === $invitee) {
                continue;
            }
            $current = self::currentBind($invitee);
            if ($current && (int) $current['referrer_client_id'] !== $referrer) {
                $conflicts[] = ['invitee_client_id' => $invitee, 'existing_referrer_id' => (int) $current['referrer_client_id'], 'half_agent_referrer_id' => $referrer];
                continue;
            }
            $bindCount++;
            if ($execute && !$current) {
                $bindResult = self::bind($referrer, $invitee, false, 'half_agent_import', function_exists('get_admin_id') ? (int) get_admin_id() : 0);
                if ((int) ($bindResult['status'] ?? 500) === 200) {
                    $importedBinds++;
                } else {
                    $failures[] = [
                        'type' => 'bind',
                        'referrer_client_id' => $referrer,
                        'invitee_client_id' => $invitee,
                        'reason' => (string) ($bindResult['msg'] ?? '导入失败'),
                    ];
                }
            }
        }
        $message = $execute
            ? (empty($failures) ? 'HalfAgent 推广关系导入完成' : 'HalfAgent 导入完成，部分记录未能导入')
            : 'HalfAgent 导入预览完成';
        return ['status' => 200, 'msg' => $message, 'data' => [
            'available' => 1,
            'profiles' => $profileCount,
            'binds' => $bindCount,
            'imported_profiles' => $importedProfiles,
            'imported_binds' => $importedBinds,
            'conflicts' => $conflicts,
            'failures' => $failures,
            'financial_note' => '旧钱包、佣金、提现和会员等级未自动导入，请按财务核对报告处理期初余额。',
        ]];
    }

    public static function audit($event, $clientId, $relatedClientId, $operatorType, $operatorId, $extra = [], $strict = false)
    {
        try {
            Db::name('addon_idcsmart_client_level_audit')->insert([
                'event' => substr((string) $event, 0, 60),
                'client_id' => (int) $clientId,
                'related_client_id' => (int) $relatedClientId,
                'operator_type' => substr((string) $operatorType, 0, 20),
                'operator_id' => (int) $operatorId,
                'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '',
                'extra' => json_encode(is_array($extra) ? $extra : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => time(),
            ]);
            return true;
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }
            return false;
        }
    }

    private static function wouldCreateCycle($referrerClientId, $inviteeClientId)
    {
        $cursor = (int) $referrerClientId;
        $seen = [];
        for ($depth = 0; $depth < 50 && $cursor > 0; $depth++) {
            if ($cursor === (int) $inviteeClientId || isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $parent = self::currentBind($cursor);
            $cursor = $parent ? (int) $parent['referrer_client_id'] : 0;
        }
        return false;
    }

    private static function maskedClient($row)
    {
        $name = trim((string) ($row['username'] ?? ''));
        if ($name !== '') {
            return function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') . '***' : substr($name, 0, 1) . '***';
        }
        $email = trim((string) ($row['email'] ?? ''));
        if (strpos($email, '@') !== false) {
            [$local, $domain] = explode('@', $email, 2);
            return substr($local, 0, 1) . '***@' . $domain;
        }
        $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));
        if (strlen($phone) >= 7) {
            return substr($phone, 0, 3) . '****' . substr($phone, -4);
        }
        return '客户 #' . (int) ($row['invitee_client_id'] ?? 0);
    }

    private static function generateCode()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
        }
    }

    private static function clearCookie()
    {
        if (function_exists('cookie')) {
            try {
                \cookie(self::COOKIE_NAME, null);
            } catch (\Throwable $e) {
            }
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private static function tableExists($table)
    {
        try {
            $prefix = (string) config('database.prefix');
            if ($prefix === '') {
                $prefix = (string) config('database.connections.mysql.prefix');
            }
            $full = $prefix . $table;
            $rows = Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1', [$full]);
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
