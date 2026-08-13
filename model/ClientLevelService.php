<?php

namespace addon\idcsmart_client_level\model;

use addon\idcsmart_client_level\lib\Money;
use think\facade\Db;

class ClientLevelService
{
    const MIN_REFERRAL_OBSERVATION_DAYS = 14;
    const MIN_WITHDRAWAL_REVIEW_DAYS = 7;

    private static $settingDefaults = [
        'auto_upgrade' => '1',
        'own_spend_level_enabled' => '1',
        'referral_contribution_level_enabled' => '1',
        'referral_enabled' => '0',
        'referral_reward_rate' => '10.00',
        'contribution_exchange_rate' => '100.00',
        'min_withdraw_amount' => '100.00',
        'referral_observation_days' => '14',
        'withdrawal_review_days' => '7',
        'invite_default_path' => '/regist.htm',
        'default_allocation' => 'manual',
    ];

    public static function ensureSeedData()
    {
        $now = time();
        foreach (self::$settingDefaults as $key => $value) {
            $exists = Db::name('addon_idcsmart_client_level_setting')
                ->where('setting_key', $key)
                ->find();
            if (empty($exists)) {
                Db::name('addon_idcsmart_client_level_setting')->insert([
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'update_admin_id' => 0,
                    'update_time' => $now,
                ]);
            }
        }

        // 这些旧开关在 1.1.4 起不再参与业务判断。保留旧行避免破坏
        // 覆盖升级，但强制写成安全值，防止旧后台或缓存误导管理员。
        foreach ([
            'refund_affects_amount' => '1',
            'auto_downgrade' => '1',
            'include_recharge' => '0',
            'downgrade_grace_days' => '0',
            'default_allocation' => 'manual',
        ] as $legacyKey => $safeValue) {
            Db::name('addon_idcsmart_client_level_setting')
                ->where('setting_key', $legacyKey)
                ->update(['setting_value' => $safeValue, 'update_admin_id' => 0, 'update_time' => $now]);
        }

        self::enforceMinimumDaySetting('referral_observation_days', self::MIN_REFERRAL_OBSERVATION_DAYS, $now);
        self::enforceMinimumDaySetting('withdrawal_review_days', self::MIN_WITHDRAWAL_REVIEW_DAYS, $now);
        BenefitLedgerService::enforceMinimumObservation(self::MIN_REFERRAL_OBSERVATION_DAYS);

        if ((int) Db::name('addon_idcsmart_client_level')->count() === 0) {
            $levelId = (int) Db::name('addon_idcsmart_client_level')->insertGetId([
                'name' => '普通会员',
                'amount' => '0.00',
                'discount_percent' => '0.00',
                'discount_status' => 1,
                'status' => 1,
                'background_color' => '#64748B',
                'notes' => '安装时创建的零优惠基础等级，可在后台编辑。',
                'sort' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            self::syncLevelProducts($levelId, '0.00', []);
        }
    }

    public static function settings()
    {
        $rows = Db::name('addon_idcsmart_client_level_setting')
            ->column('setting_value', 'setting_key');
        $settings = array_merge(self::$settingDefaults, is_array($rows) ? $rows : []);
        return [
            'auto_upgrade' => (int) $settings['auto_upgrade'],
            'own_spend_level_enabled' => (int) $settings['own_spend_level_enabled'],
            'referral_contribution_level_enabled' => (int) $settings['referral_contribution_level_enabled'],
            'referral_enabled' => (int) $settings['referral_enabled'],
            'referral_reward_rate' => Money::normalize($settings['referral_reward_rate']),
            'contribution_exchange_rate' => Money::normalize($settings['contribution_exchange_rate']),
            'min_withdraw_amount' => Money::normalize($settings['min_withdraw_amount']),
            'referral_observation_days' => max(self::MIN_REFERRAL_OBSERVATION_DAYS, (int) $settings['referral_observation_days']),
            'withdrawal_review_days' => max(self::MIN_WITHDRAWAL_REVIEW_DAYS, (int) $settings['withdrawal_review_days']),
            'invite_default_path' => self::normalizeInvitePath($settings['invite_default_path'] ?? '/regist.htm'),
            'default_allocation' => 'manual',
            'refund_level_rollback_required' => 1,
            'recharge_excluded' => 1,
        ];
    }

    public static function saveSettings($param)
    {
        $allowed = array_keys(self::$settingDefaults);
        $adminId = function_exists('get_admin_id') ? (int) get_admin_id() : 0;
        $changes = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $param)) {
                continue;
            }
            try {
                if (in_array($key, ['auto_upgrade', 'own_spend_level_enabled', 'referral_contribution_level_enabled', 'referral_enabled'], true)) {
                    $value = !empty($param[$key]) ? '1' : '0';
                } elseif (in_array($key, ['referral_reward_rate', 'contribution_exchange_rate'], true)) {
                    $value = self::validatedPercent($param[$key], $key === 'referral_reward_rate' ? '推广折算比例' : '贡献换算比例', $key === 'contribution_exchange_rate' ? '1000.00' : '100.00');
                } elseif ($key === 'min_withdraw_amount') {
                    $value = self::validatedMoney($param[$key], '最低提现金额');
                } elseif (in_array($key, ['referral_observation_days', 'withdrawal_review_days'], true)) {
                    $days = (int) $param[$key];
                    $minimum = $key === 'referral_observation_days'
                        ? self::MIN_REFERRAL_OBSERVATION_DAYS
                        : self::MIN_WITHDRAWAL_REVIEW_DAYS;
                    if ($days < $minimum || $days > 3650) {
                        throw new \InvalidArgumentException('天数必须在 ' . $minimum . ' 到 3650 之间');
                    }
                    $value = (string) $days;
                } elseif ($key === 'invite_default_path') {
                    $value = self::normalizeInvitePath($param[$key], '');
                    if ($value === '') {
                        throw new \InvalidArgumentException('默认邀请落地页必须是以 / 开头的站内路径');
                    }
                } else {
                    $value = in_array((string) $param[$key], ['manual', 'withdrawable', 'contribution'], true) ? (string) $param[$key] : 'manual';
                }
            } catch (\InvalidArgumentException $e) {
                return ['status' => 400, 'msg' => $e->getMessage()];
            }
            $changes[$key] = $value;
        }

        Db::startTrans();
        try {
            $now = time();
            foreach ($changes as $key => $value) {
                $row = Db::name('addon_idcsmart_client_level_setting')
                    ->where('setting_key', $key)
                    ->lock(true)
                    ->find();
                $data = [
                    'setting_value' => $value,
                    'update_admin_id' => $adminId,
                    'update_time' => $now,
                ];
                if (empty($row)) {
                    $data['setting_key'] = $key;
                    Db::name('addon_idcsmart_client_level_setting')->insert($data);
                } else {
                    Db::name('addon_idcsmart_client_level_setting')->where('id', (int) $row['id'])->update($data);
                }
            }
            ReferralService::audit('settings_update', 0, 0, 'admin', $adminId, [
                'changed_keys' => array_keys($changes),
            ], true);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 500, 'msg' => '设置保存失败，请刷新后重试'];
        }
        return ['status' => 200, 'msg' => '设置已保存'];
    }

    public static function normalizeInvitePath($value, $fallback = '/regist.htm')
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 1000 || $value[0] !== '/'
            || strpos($value, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return (string) $fallback;
        }
        $decoded = rawurldecode($value);
        if (strpos($value, '//') === 0 || strpos($decoded, '//') === 0
            || strpos($decoded, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            return (string) $fallback;
        }
        return $value;
    }

    private static function enforceMinimumDaySetting($key, $minimum, $now)
    {
        $row = Db::name('addon_idcsmart_client_level_setting')->where('setting_key', (string) $key)->find();
        if ($row && (int) ($row['setting_value'] ?? 0) < (int) $minimum) {
            Db::name('addon_idcsmart_client_level_setting')->where('id', (int) $row['id'])->update([
                'setting_value' => (string) (int) $minimum,
                'update_admin_id' => 0,
                'update_time' => (int) $now,
            ]);
        }
    }

    public static function levelExists($levelId)
    {
        return Db::name('addon_idcsmart_client_level')
            ->where('id', (int) $levelId)
            ->where('status', 1)
            ->count() > 0;
    }

    public static function levels($param = [])
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 20)));
        $query = Db::name('addon_idcsmart_client_level');
        if (!empty($param['keywords'])) {
            $query->where('name', 'like', '%' . trim((string) $param['keywords']) . '%');
        }
        if (isset($param['status']) && $param['status'] !== '') {
            $query->where('status', (int) $param['status']);
        }
        $countQuery = clone $query;
        $count = (int) $countQuery->count();
        $list = $query->order('amount', 'asc')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $memberCounts = Db::name('addon_idcsmart_client_level_client_link')
            ->field('addon_idcsmart_client_level_id,COUNT(*) count')
            ->group('addon_idcsmart_client_level_id')
            ->select()
            ->toArray();
        $memberCounts = array_column($memberCounts, 'count', 'addon_idcsmart_client_level_id');
        foreach ($list as &$level) {
            $level['amount'] = Money::normalize($level['amount']);
            $policy = BenefitLedgerService::levelPolicy((int) $level['id']);
            $level['referral_level_amount'] = Money::normalize($policy['referral_level_amount'] ?? $level['amount']);
            $level['discount_percent'] = Money::normalize($level['discount_percent']);
            $level['pay_percent'] = Money::subtract('100.00', $level['discount_percent']);
            $level['member_count'] = (int) ($memberCounts[$level['id']] ?? 0);
        }
        $settings = self::settings();
        return ['list' => $list, 'count' => $count, 'axis_settings' => [
            'own_spend_level_enabled' => (int) $settings['own_spend_level_enabled'],
            'referral_contribution_level_enabled' => (int) $settings['referral_contribution_level_enabled'],
        ]];
    }

    public static function allLevels($onlyEnabled = false)
    {
        $query = Db::name('addon_idcsmart_client_level');
        if ($onlyEnabled) {
            $query->where('status', 1);
        }
        $levels = $query->order('amount', 'asc')->order('sort', 'desc')->order('id', 'asc')->select()->toArray();
        foreach ($levels as &$level) {
            $level['amount'] = Money::normalize($level['amount']);
            $policy = BenefitLedgerService::levelPolicy((int) $level['id']);
            $level['referral_level_amount'] = Money::normalize($policy['referral_level_amount'] ?? $level['amount']);
            $level['discount_percent'] = Money::normalize($level['discount_percent']);
            $level['pay_percent'] = Money::subtract('100.00', $level['discount_percent']);
        }
        return $levels;
    }

    public static function levelDetail($levelId)
    {
        $level = Db::name('addon_idcsmart_client_level')->where('id', (int) $levelId)->find();
        if (empty($level)) {
            return [];
        }
        $level['amount'] = Money::normalize($level['amount']);
        $policy = BenefitLedgerService::levelPolicy((int) $level['id']);
        $level['referral_level_amount'] = Money::normalize($policy['referral_level_amount'] ?? $level['amount']);
        $level['discount_percent'] = Money::normalize($level['discount_percent']);
        $level['pay_percent'] = Money::subtract('100.00', $level['discount_percent']);
        $level['product_discounts'] = Db::name('addon_idcsmart_client_level_product_link')
            ->alias('pl')
            ->field('pl.product_id,p.name product_name,pl.discount_percent')
            ->leftJoin('product p', 'p.id=pl.product_id')
            ->where('pl.addon_idcsmart_client_level_id', (int) $levelId)
            ->order('pl.product_id', 'asc')
            ->select()
            ->toArray();
        $level['product_group_discounts'] = Db::name('addon_idcsmart_client_level_product_group')
            ->alias('pgd')
            ->field('pgd.product_group_id,pg.name product_group_name,pgd.discount_percent')
            ->leftJoin('product_group pg', 'pg.id=pgd.product_group_id')
            ->where('pgd.addon_idcsmart_client_level_id', (int) $levelId)
            ->order('pgd.product_group_id', 'asc')
            ->select()
            ->toArray();
        return $level;
    }

    public static function saveLevel($param)
    {
        $id = (int) ($param['id'] ?? 0);
        $isCreate = $id <= 0;
        $adminId = function_exists('get_admin_id') ? (int) get_admin_id() : 0;
        $name = trim((string) ($param['name'] ?? ''));
        try {
            $amount = self::validatedMoney($param['amount'] ?? '', '累计消费门槛');
            $discount = self::validatedPercent($param['discount_percent'] ?? '', '默认减免比例');
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'msg' => $e->getMessage()];
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 100) {
            return ['status' => 400, 'msg' => '等级名称不能为空且不能超过100个字符'];
        }
        $duplicate = Db::name('addon_idcsmart_client_level')->where('name', $name);
        if ($id > 0) {
            $duplicate->where('id', '<>', $id);
        }
        if ($duplicate->count() > 0) {
            return ['status' => 400, 'msg' => '等级名称已存在'];
        }
        $sameAmount = Db::name('addon_idcsmart_client_level')->where('amount', $amount);
        if ($id > 0) {
            $sameAmount->where('id', '<>', $id);
        }
        if ($sameAmount->count() > 0) {
            return ['status' => 400, 'msg' => '累计消费门槛不能与其他等级相同'];
        }

        $now = time();
        $status = !empty($param['status']) ? 1 : 0;
        $data = [
            'name' => $name,
            'amount' => $amount,
            'discount_percent' => $discount,
            // 部分 V10 Server 模块直接联表读取 discount_status，不会额外检查
            // 等级 status。停用等级时必须同时关闭折扣，避免直连链路继续优惠。
            'discount_status' => $status === 1 && !empty($param['discount_status']) ? 1 : 0,
            'status' => $status,
            'background_color' => self::validatedColor($param['background_color'] ?? '#2F54EB'),
            'notes' => self::textLimit($param['notes'] ?? '', 1000),
            'sort' => (int) ($param['sort'] ?? 0),
            'update_time' => $now,
        ];

        Db::startTrans();
        try {
            if ($id > 0) {
                if (empty(Db::name('addon_idcsmart_client_level')->where('id', $id)->lock(true)->find())) {
                    Db::rollback();
                    return ['status' => 404, 'msg' => '用户等级不存在'];
                }
                Db::name('addon_idcsmart_client_level')->where('id', $id)->update($data);
            } else {
                $data['create_time'] = $now;
                $id = (int) Db::name('addon_idcsmart_client_level')->insertGetId($data);
            }

            // 后台当前编辑的是“全局等级折扣”。未显式传入商品特例时，
            // 同步到全部商品关联表，保证直接查该表的 Server 模块也立即生效。
            $productOverrides = array_key_exists('product_discounts', $param)
                ? self::discountMap($param['product_discounts'], 'product_id')
                : [];
            self::syncLevelProducts($id, $discount, $productOverrides);

            if (array_key_exists('product_group_discounts', $param)) {
                self::replaceGroupDiscounts(
                    $id,
                    self::discountMap($param['product_group_discounts'], 'product_group_id')
                );
            }

            // 等级表单中的推广门槛与覆盖规则必须和官方等级一次提交。
            // 避免前端分两个请求时出现“等级已保存、策略未保存”的半成功状态。
            if (array_key_exists('referral_level_amount', $param)) {
                $policyResult = BenefitLedgerService::saveLevelPolicy($id, $param);
                if ((int) ($policyResult['status'] ?? 500) !== 200) {
                    throw new \InvalidArgumentException((string) ($policyResult['msg'] ?? '等级推广策略保存失败'));
                }
            }
            ReferralService::audit($isCreate ? 'level_create' : 'level_update', 0, 0, 'admin', $adminId, [
                'level_id' => $id,
                'name' => $name,
                'amount' => $amount,
                'discount_percent' => $discount,
                'status' => $status,
            ], true);
            Db::commit();
        } catch (\InvalidArgumentException $e) {
            Db::rollback();
            return ['status' => 400, 'msg' => $e->getMessage()];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 500, 'msg' => '用户等级保存失败'];
        }
        return ['status' => 200, 'msg' => '用户等级已保存', 'data' => ['id' => $id]];
    }

    public static function deleteLevel($levelId)
    {
        $levelId = (int) $levelId;
        if ($levelId <= 0) {
            return ['status' => 400, 'msg' => '参数错误'];
        }
        $adminId = function_exists('get_admin_id') ? (int) get_admin_id() : 0;
        Db::startTrans();
        try {
            $level = Db::name('addon_idcsmart_client_level')->where('id', $levelId)->lock(true)->find();
            if (empty($level)) {
                Db::rollback();
                return ['status' => 404, 'msg' => '用户等级不存在'];
            }
            if (Db::name('addon_idcsmart_client_level_client_link')
                ->where('addon_idcsmart_client_level_id', $levelId)->count() > 0) {
                Db::rollback();
                return ['status' => 400, 'msg' => '仍有用户属于该等级，请先转移用户或停用等级'];
            }
            Db::name('addon_idcsmart_client_level_product_link')
                ->where('addon_idcsmart_client_level_id', $levelId)->delete();
            Db::name('addon_idcsmart_client_level_product_group')
                ->where('addon_idcsmart_client_level_id', $levelId)->delete();
            Db::name('addon_idcsmart_client_level_level_policy')->where('level_id', $levelId)->delete();
            Db::name('addon_idcsmart_client_level')->where('id', $levelId)->delete();
            ReferralService::audit('level_delete', 0, 0, 'admin', $adminId, [
                'level_id' => $levelId,
                'name' => (string) ($level['name'] ?? ''),
            ], true);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 500, 'msg' => '等级删除失败'];
        }
        return ['status' => 200, 'msg' => '等级已删除'];
    }

    public static function clients($param = [])
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 20)));
        $query = Db::name('client')->alias('c')
            ->leftJoin('addon_idcsmart_client_level_client_link cl', 'cl.client_id=c.id')
            ->leftJoin('addon_idcsmart_client_level l', 'l.id=cl.addon_idcsmart_client_level_id')
            ->leftJoin('addon_idcsmart_client_level_metric m', 'm.client_id=c.id')
            ->leftJoin('addon_idcsmart_client_level_benefit_account ba', 'ba.client_id=c.id');
        if (!empty($param['keywords'])) {
            $keywords = trim((string) $param['keywords']);
            $query->where('c.id|c.username|c.email|c.phone', 'like', '%' . $keywords . '%');
        }
        if (!empty($param['level_id'])) {
            $query->where('cl.addon_idcsmart_client_level_id', (int) $param['level_id']);
        }
        $countQuery = clone $query;
        $count = (int) $countQuery->count('c.id');
        $list = $query
            ->field('c.id,c.username,c.email,c.phone_code,c.phone,c.status,l.id level_id,l.name level_name,l.background_color,cl.cumulative_amount,cl.manual_lock,cl.assignment_source,cl.last_upgrade_time,m.own_net_amount,m.referral_net_amount,m.contribution_amount,m.effective_amount,m.downgrade_due_time,ba.pending benefit_pending,ba.unallocated benefit_unallocated,ba.withdrawable,ba.withdraw_frozen,ba.debt benefit_debt')
            ->order('c.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        foreach ($list as &$client) {
            $client['cumulative_amount'] = Money::normalize($client['cumulative_amount'] ?? 0);
            $client['manual_lock'] = (int) ($client['manual_lock'] ?? 0);
            $client['level_id'] = (int) ($client['level_id'] ?? 0);
        }
        $settings = self::settings();
        return ['list' => $list, 'count' => $count, 'axis_settings' => [
            'own_spend_level_enabled' => (int) $settings['own_spend_level_enabled'],
            'referral_contribution_level_enabled' => (int) $settings['referral_contribution_level_enabled'],
        ]];
    }

    public static function clientLevelMap($clientIds)
    {
        if (!is_array($clientIds)) {
            $clientIds = [(int) $clientIds];
        }
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        if (empty($clientIds)) {
            return [];
        }
        $rows = Db::name('addon_idcsmart_client_level_client_link')->alias('cl')
            ->field('cl.client_id,l.id,l.name,l.background_color')
            ->leftJoin('addon_idcsmart_client_level l', 'l.id=cl.addon_idcsmart_client_level_id')
            ->whereIn('cl.client_id', $clientIds)
            ->where('l.status', 1)
            ->select()
            ->toArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['client_id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'background_color' => (string) $row['background_color'],
            ];
        }
        return $result;
    }

    public static function adminClientField($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0 || empty(Db::name('client')->where('id', $clientId)->find())) {
            return ['id' => 0, 'list' => []];
        }

        // 兼容插件安装前已存在的老用户：首次查看详情时建立自动等级关联。
        self::recalculateClient($clientId, 'admin_client_index');
        $levelId = (int) Db::name('addon_idcsmart_client_level_client_link')
            ->where('client_id', $clientId)
            ->value('addon_idcsmart_client_level_id');

        $list = self::allLevels(true);
        foreach ($list as &$level) {
            // 核心页面只需选项基本字段，不把内部策略和备注暴露给表单。
            $level = [
                'id' => (int) $level['id'],
                'name' => (string) $level['name'],
                'background_color' => (string) $level['background_color'],
            ];
        }
        unset($level);

        return ['id' => $levelId, 'list' => $list];
    }

    public static function assignClient($clientId, $levelId, $manualLock = true, $source = 'admin_manual', $expiresAt = 0, $reason = '')
    {
        $clientId = (int) $clientId;
        $levelId = (int) $levelId;
        if ($clientId <= 0 || empty(Db::name('client')->where('id', $clientId)->find())) {
            return ['status' => 404, 'msg' => '用户不存在'];
        }
        if ($levelId > 0 && !self::levelExists($levelId)) {
            return ['status' => 400, 'msg' => '用户等级不存在或已停用'];
        }
        self::ensureClientLink($clientId);
        if ($levelId <= 0) {
            $manualLock = false;
        }

        Db::startTrans();
        try {
            $link = Db::name('addon_idcsmart_client_level_client_link')
                ->where('client_id', $clientId)
                ->lock(true)
                ->find();
            if (empty($link)) {
                throw new \RuntimeException('用户等级关联创建失败');
            }
            if ($levelId > 0 && empty(Db::name('addon_idcsmart_client_level')->where('id', $levelId)->where('status', 1)->lock(true)->find())) {
                Db::rollback();
                return ['status' => 400, 'msg' => '用户等级不存在或已停用'];
            }
            $spend = self::authoritativeSpend($clientId);
            $account = BenefitLedgerService::accountSummary($clientId, false);
            $settings = self::settings();
            $evaluation = self::dualAxisEvaluation(
                $spend,
                $account['contribution_effective'] ?? '0.00',
                $settings,
                false
            );
            $oldLevelId = (int) ($link['addon_idcsmart_client_level_id'] ?? 0);
            $targetLevelId = $levelId > 0 ? $levelId : $oldLevelId;
            $targetLevel = $targetLevelId > 0
                ? Db::name('addon_idcsmart_client_level')->where('id', $targetLevelId)->find()
                : [];
            $compatibilityAmount = self::officialCompatibilityAmount($spend, $evaluation, $targetLevel, false);
            Db::name('addon_idcsmart_client_level_client_link')->where('id', (int) $link['id'])->update([
                'addon_idcsmart_client_level_id' => $targetLevelId,
                'cumulative_amount' => $compatibilityAmount,
                'manual_lock' => $manualLock ? 1 : 0,
                'assignment_source' => $source,
                'last_upgrade_time' => $targetLevelId !== $oldLevelId ? time() : (int) ($link['last_upgrade_time'] ?? 0),
                'update_time' => time(),
            ]);
            $override = Db::name('addon_idcsmart_client_level_manual_override')->where('client_id', $clientId)->find();
            $overrideData = [
                'level_id' => $targetLevelId,
                'start_time' => time(),
                'end_time' => $manualLock ? max(0, (int) $expiresAt) : time(),
                'reason' => self::textLimit($reason, 500),
                'admin_id' => function_exists('get_admin_id') ? (int) get_admin_id() : 0,
                'status' => $manualLock ? 1 : 0,
                'update_time' => time(),
            ];
            if ($override) {
                Db::name('addon_idcsmart_client_level_manual_override')->where('id', (int) $override['id'])->update($overrideData);
            } else {
                $overrideData['client_id'] = $clientId;
                $overrideData['create_time'] = time();
                Db::name('addon_idcsmart_client_level_manual_override')->insert($overrideData);
            }
            if ($targetLevelId !== $oldLevelId) {
                self::writeLog($clientId, $oldLevelId, $targetLevelId, $source, 0, $link['cumulative_amount'] ?? 0, $compatibilityAmount, [
                    'own_net_amount' => $spend,
                    'contribution_amount' => $account['contribution_effective'] ?? '0.00',
                    'manual_expires_at' => (int) $expiresAt,
                    'reason' => trim((string) $reason),
                ]);
            }
            $adminId = function_exists('get_admin_id') ? (int) get_admin_id() : 0;
            ReferralService::audit('client_level_assign', $clientId, 0, $adminId > 0 ? 'admin' : 'system', $adminId, [
                'old_level_id' => $oldLevelId,
                'new_level_id' => $targetLevelId,
                'fixed' => $manualLock ? 1 : 0,
                'expires_at' => (int) $expiresAt,
                'source' => (string) $source,
                'reason' => trim((string) $reason),
            ], true);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        if (!$manualLock) {
            // 恢复自动时强制匹配当前应得等级；该显式管理操作不受“自动降级”
            // 开关影响，同时只产生一条最终等级变更日志。
            return self::recalculateClient($clientId, 'admin_unlock', 0, true);
        }
        return ['status' => 200, 'msg' => '用户等级已更新'];
    }

    public static function recalculateClient($clientId, $source = 'auto', $orderId = 0, $forceMatch = false)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return ['status' => 400, 'msg' => '用户ID无效'];
        }
        self::ensureClientLink($clientId);
        Db::startTrans();
        try {
            // 同一用户的支付、退款和管理重算串行化，避免并发 Hook 重复写等级日志。
            $link = Db::name('addon_idcsmart_client_level_client_link')
                ->where('client_id', $clientId)
                ->lock(true)
                ->find();
            if (empty($link)) {
                throw new \RuntimeException('用户等级关联创建失败');
            }
            $spend = self::authoritativeSpend($clientId);
            $benefit = BenefitLedgerService::accountSummary($clientId, false);
            $contribution = Money::normalize($benefit['contribution_effective'] ?? 0);
            $oldAmount = Money::normalize($link['cumulative_amount'] ?? 0);
            $settings = self::settings();
            $isRefundRollback = strpos((string) $source, 'refund') !== false;
            $evaluation = self::dualAxisEvaluation($spend, $contribution, $settings, $isRefundRollback);
            $manualLock = (int) ($link['manual_lock'] ?? 0) === 1;
            if ($manualLock) {
                $override = Db::name('addon_idcsmart_client_level_manual_override')
                    ->where('client_id', $clientId)->where('status', 1)->lock(true)->find();
                if ($override && (int) ($override['end_time'] ?? 0) > 0 && (int) $override['end_time'] <= time()) {
                    Db::name('addon_idcsmart_client_level_manual_override')->where('id', (int) $override['id'])->update([
                        'status' => 0, 'update_time' => time(),
                    ]);
                    Db::name('addon_idcsmart_client_level_client_link')->where('id', (int) $link['id'])->update([
                        'manual_lock' => 0, 'assignment_source' => 'manual_expired', 'update_time' => time(),
                    ]);
                    $manualLock = false;
                    $forceMatch = true;
                    $source = 'manual_expired';
                }
            }

            $candidate = $evaluation['candidate'];
            $candidateLevelId = empty($candidate) ? 0 : (int) $candidate['id'];
            $metric = Db::name('addon_idcsmart_client_level_metric')->where('client_id', $clientId)->lock(true)->find();
            // 退款、推广贡献冲正和权威重算均必须立即回到应得等级。
            // 旧版降级宽限字段仅作兼容保留，不再参与等级计算。
            $downgradeDueTime = 0;

            if ($manualLock) {
                $newLevelId = (int) ($link['addon_idcsmart_client_level_id'] ?? 0);
                $shouldChange = false;
                $downgrade = false;
            } else {
                $oldLevelId = (int) ($link['addon_idcsmart_client_level_id'] ?? 0);
                $oldLevel = $oldLevelId > 0
                    ? Db::name('addon_idcsmart_client_level')->where('id', $oldLevelId)->find()
                    : [];

                $priority = self::compareLevelPriority($candidate, $oldLevel);
                if ($isRefundRollback) {
                    // 退款只允许保持或回退，不能借退款回调补做一次历史升级。
                    $newLevelId = $priority < 0 ? $candidateLevelId : $oldLevelId;
                    $shouldChange = $newLevelId !== $oldLevelId;
                    $downgrade = $shouldChange;
                } elseif ($forceMatch && in_array((string) $source, ['admin_unlock', 'manual_expired'], true)) {
                    // 显式解除管理员固定或固定到期时，恢复当前开启通道的实际等级。
                    $newLevelId = $candidateLevelId;
                    $shouldChange = $newLevelId !== $oldLevelId;
                    $downgrade = $priority < 0;
                } elseif (empty($candidate) || $settings['auto_upgrade'] !== 1) {
                    $shouldChange = false;
                    $downgrade = false;
                    $newLevelId = $oldLevelId;
                } else {
                    // 普通支付、贡献分配、前台查看与后台重算只补升级。
                    // 关闭任一通道不会让用户在随后一次普通重算中被降级。
                    $shouldChange = $priority > 0 || (int) ($oldLevel['status'] ?? 0) !== 1;
                    $newLevelId = $shouldChange ? (int) $candidate['id'] : $oldLevelId;
                    $downgrade = $shouldChange && $priority < 0;
                }

                if ($shouldChange) {
                    $downgradeDueTime = 0;
                    $assignmentSource = ($forceMatch || $isRefundRollback)
                        ? $source
                        : 'auto_upgrade_' . $evaluation['source'];
                    Db::name('addon_idcsmart_client_level_client_link')->where('id', (int) $link['id'])->update([
                        'addon_idcsmart_client_level_id' => $newLevelId,
                        'assignment_source' => $assignmentSource,
                        'last_upgrade_time' => time(),
                        'update_time' => time(),
                    ]);
                }
            }
            $finalLevelId = isset($newLevelId)
                ? (int) $newLevelId
                : (int) ($link['addon_idcsmart_client_level_id'] ?? 0);
            $finalLevel = $finalLevelId > 0
                ? Db::name('addon_idcsmart_client_level')->where('id', $finalLevelId)->find()
                : [];
            $compatibilityAmount = self::officialCompatibilityAmount($spend, $evaluation, $finalLevel, !$manualLock);
            Db::name('addon_idcsmart_client_level_client_link')->where('id', (int) $link['id'])->update([
                'cumulative_amount' => $compatibilityAmount,
                'update_time' => time(),
            ]);
            if (!empty($shouldChange)) {
                self::writeLog(
                    $clientId,
                    (int) ($link['addon_idcsmart_client_level_id'] ?? 0),
                    $finalLevelId,
                    $assignmentSource,
                    (int) $orderId,
                    $oldAmount,
                    $compatibilityAmount,
                    [
                        'own_net_amount' => $spend,
                        'contribution_amount' => $contribution,
                        'referral_net_amount' => $benefit['referral_net_amount'] ?? '0.00',
                        'qualification_source' => $evaluation['source'],
                    ]
                );
            }
            $result = [
                'status' => 200,
                'msg' => !empty($shouldChange)
                    ? ($downgrade ? '退款后等级已回退' : '用户等级已自动升级')
                    : '等级数据已更新',
                'data' => [
                    'own_net_amount' => $spend,
                    'contribution_amount' => $contribution,
                    'cumulative_amount' => $compatibilityAmount,
                    'level_id' => $finalLevelId,
                    'qualification_source' => $evaluation['source'],
                    'downgrade_due_time' => $downgradeDueTime,
                ],
            ];
            $metricData = [
                'own_net_amount' => $spend,
                'referral_net_amount' => Money::normalize($benefit['referral_net_amount'] ?? 0),
                'contribution_amount' => $contribution,
                'effective_amount' => $compatibilityAmount,
                'candidate_level_id' => $candidateLevelId,
                'downgrade_due_time' => $downgradeDueTime,
                'calc_version' => '1.6.0',
                'update_time' => time(),
            ];
            if ($metric) {
                Db::name('addon_idcsmart_client_level_metric')->where('client_id', $clientId)->update($metricData);
            } else {
                $metricData['client_id'] = $clientId;
                Db::name('addon_idcsmart_client_level_metric')->insert($metricData);
            }
            Db::commit();
            return $result;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public static function syncOrder($orderId, $source = 'order_paid')
    {
        $order = self::recordOrder((int) $orderId);
        if (empty($order)) {
            return true;
        }
        $referrerId = 0;
        if ((int) (self::settings()['referral_enabled'] ?? 0) === 1
            || Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', (int) $order['id'])->count() > 0) {
            $referrerId = BenefitLedgerService::syncOrder($order);
        }
        $forceMatch = strpos((string) $source, 'refund') !== false;
        if ($forceMatch && $referrerId > 0) {
            WithdrawService::reconcileRefundExposure($referrerId, (int) $order['id']);
        }
        self::recalculateClient((int) $order['client_id'], $source, (int) $order['id'], $forceMatch);
        if ($referrerId > 0 && $referrerId !== (int) $order['client_id']) {
            self::recalculateClient($referrerId, $source . '_referral', (int) $order['id'], $forceMatch);
        }
        return true;
    }

    public static function rebuild($clientId = 0)
    {
        $clientId = (int) $clientId;
        $clientIds = $clientId > 0
            ? [$clientId]
            : array_map('intval', Db::name('client')->column('id'));
        $updated = 0;
        $referrers = [];
        foreach ($clientIds as $id) {
            $orders = Db::name('order')
                ->where('client_id', $id)
                ->whereIn('status', ['Paid', 'Refunded'])
                ->column('id');
            foreach ($orders as $orderId) {
                $order = self::recordOrder((int) $orderId);
                if ($order && ((int) (self::settings()['referral_enabled'] ?? 0) === 1
                    || Db::name('addon_idcsmart_client_level_referral_accrual')->where('source_order_id', (int) $order['id'])->count() > 0)) {
                    $referrerId = BenefitLedgerService::syncOrder($order);
                    if ($referrerId > 0) {
                        $referrers[$referrerId] = true;
                    }
                }
            }
            self::recalculateClient($id, 'admin_rebuild', 0, true);
            $updated++;
        }
        BenefitLedgerService::processMatured(0, 1000);
        foreach (array_keys($referrers) as $referrerId) {
            self::recalculateClient((int) $referrerId, 'admin_rebuild_referral', 0, true);
        }
        return ['status' => 200, 'msg' => '双轴等级重算完成', 'data' => ['updated_clients' => $updated, 'updated_referrers' => count($referrers)]];
    }

    public static function processExpiredManualOverrides($limit = 500)
    {
        $limit = min(2000, max(1, (int) $limit));
        $clientIds = Db::name('addon_idcsmart_client_level_manual_override')
            ->where('status', 1)->where('end_time', '>', 0)->where('end_time', '<=', time())
            ->order('end_time', 'asc')->limit($limit)->column('client_id');
        $updated = 0;
        foreach ($clientIds as $clientId) {
            self::recalculateClient((int) $clientId, 'manual_expired');
            $updated++;
        }
        return ['status' => 200, 'msg' => '到期人工等级已处理', 'data' => ['updated' => $updated]];
    }

    public static function currentDetail($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return [];
        }
        self::recalculateClient($clientId, 'client_view');
        $link = Db::name('addon_idcsmart_client_level_client_link')->alias('cl')
            ->field('cl.cumulative_amount,cl.manual_lock,l.id level_id,l.name level_name,l.background_color,l.amount,l.discount_percent')
            ->leftJoin('addon_idcsmart_client_level l', 'l.id=cl.addon_idcsmart_client_level_id')
            ->where('cl.client_id', $clientId)
            ->where('l.status', 1)
            ->find();
        if (empty($link)) {
            return [];
        }
        $metric = Db::name('addon_idcsmart_client_level_metric')->where('client_id', $clientId)->find();
        $account = BenefitLedgerService::accountSummary($clientId, true);
        $spend = Money::normalize($metric['own_net_amount'] ?? self::authoritativeSpend($clientId));
        $contribution = Money::normalize($metric['contribution_amount'] ?? ($account['contribution_effective'] ?? 0));
        $compatibilityAmount = Money::normalize($link['cumulative_amount']);
        $settings = self::settings();
        $profile = ReferralService::profile($clientId);
        $override = Db::name('addon_idcsmart_client_level_manual_override')
            ->where('client_id', $clientId)->where('status', 1)->find();
        $levels = self::allLevels(true);
        $progress = self::dualAxisProgress((int) $link['level_id'], $spend, $contribution, $settings, $levels);
        $next = $progress['next_level'];
        foreach ($levels as &$level) {
            $level['discount_list'] = Db::name('addon_idcsmart_client_level_product_link')->alias('pl')
                ->field('pl.product_id,p.name product_name,pl.discount_percent')
                ->leftJoin('product p', 'p.id=pl.product_id')
                ->where('pl.addon_idcsmart_client_level_id', (int) $level['id'])
                ->where('p.id', '>', 0)
                ->order('p.id', 'asc')
                ->select()
                ->toArray();
        }
        return [
            'level_id' => (int) $link['level_id'],
            'level_name' => (string) $link['level_name'],
            'background_color' => (string) $link['background_color'],
            'discount_percent' => Money::normalize($link['discount_percent']),
            'pay_percent' => Money::subtract('100.00', $link['discount_percent']),
            'cycle_consume_amount' => $compatibilityAmount,
            'cumulative_amount' => $compatibilityAmount,
            'effective_amount' => $compatibilityAmount,
            'own_net_amount' => $spend,
            'referral_net_amount' => Money::normalize($metric['referral_net_amount'] ?? ($account['referral_net_amount'] ?? 0)),
            'contribution_amount' => $contribution,
            'benefit_pending_amount' => Money::normalize($account['pending'] ?? 0),
            'benefit_unallocated_amount' => Money::normalize($account['unallocated'] ?? 0),
            'withdrawable_amount' => Money::normalize($account['withdrawable'] ?? 0),
            'withdraw_frozen_amount' => Money::normalize($account['withdraw_frozen'] ?? 0),
            'benefit_debt_amount' => Money::normalize($account['debt'] ?? 0),
            'invite_code' => (string) ($profile['invite_code'] ?? ''),
            'referral_policy' => $account['policy'] ?? BenefitLedgerService::policyForClient($clientId),
            'cycle_end_time' => 0,
            'next_level_need_amount' => $progress['legacy_gap'],
            'next_level' => $next,
            'level_axes' => $progress['axes'],
            'axis_progress_percent' => $progress['percent'],
            'manual_lock' => (int) $link['manual_lock'],
            'manual_lock_end_time' => (int) ($override['end_time'] ?? 0),
            'manual_lock_reason' => (string) ($override['reason'] ?? ''),
            'downgrade_due_time' => (int) ($metric['downgrade_due_time'] ?? 0),
            'levels' => $levels,
        ];
    }

    public static function logs($param = [])
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(100, max(1, (int) ($param['limit'] ?? 50)));
        $query = Db::name('addon_idcsmart_client_level_log')->alias('lg')
            ->leftJoin('client c', 'c.id=lg.client_id')
            ->leftJoin('addon_idcsmart_client_level oldl', 'oldl.id=lg.old_level_id')
            ->leftJoin('addon_idcsmart_client_level newl', 'newl.id=lg.new_level_id');
        if (!empty($param['client_id'])) {
            $query->where('lg.client_id', (int) $param['client_id']);
        }
        $countQuery = clone $query;
        $count = (int) $countQuery->count('lg.id');
        $list = $query->field('lg.*,c.username,oldl.name old_level_name,newl.name new_level_name')
            ->order('lg.id', 'desc')->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'count' => $count];
    }

    public static function dashboard()
    {
        $account = Db::name('addon_idcsmart_client_level_benefit_account')
            ->field('SUM(pending) pending,SUM(unallocated) unallocated,SUM(withdrawable) withdrawable,SUM(withdraw_frozen) frozen,SUM(contribution_effective) contribution,SUM(debt) debt')
            ->find();
        return [
            'levels' => (int) Db::name('addon_idcsmart_client_level')->count(),
            'members' => (int) Db::name('addon_idcsmart_client_level_client_link')->where('addon_idcsmart_client_level_id', '>', 0)->count(),
            'manual_members' => (int) Db::name('addon_idcsmart_client_level_client_link')->where('manual_lock', 1)->count(),
            'ledger_orders' => (int) Db::name('addon_idcsmart_client_level_order')->where('is_consumption', 1)->count(),
            'active_referrals' => (int) Db::name('addon_idcsmart_client_level_referral_bind')->where('status', 1)->count(),
            'withdraw_pending' => (int) Db::name('addon_idcsmart_client_level_withdraw')->whereIn('status', ['pending', 'approved'])->count(),
            'benefit_totals' => [
                'pending' => Money::normalize($account['pending'] ?? 0),
                'unallocated' => Money::normalize($account['unallocated'] ?? 0),
                'withdrawable' => Money::normalize($account['withdrawable'] ?? 0),
                'frozen' => Money::normalize($account['frozen'] ?? 0),
                'contribution' => Money::normalize($account['contribution'] ?? 0),
                'debt' => Money::normalize($account['debt'] ?? 0),
            ],
            'settings' => self::settings(),
        ];
    }

    public static function syncProduct($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0 || empty(Db::name('product')->where('id', $productId)->find())) {
            return;
        }
        $levels = Db::name('addon_idcsmart_client_level')->field('id,discount_percent')->select()->toArray();
        foreach ($levels as $level) {
            $exists = Db::name('addon_idcsmart_client_level_product_link')
                ->where('addon_idcsmart_client_level_id', (int) $level['id'])
                ->where('product_id', $productId)
                ->find();
            if (empty($exists)) {
                Db::name('addon_idcsmart_client_level_product_link')->insert([
                    'addon_idcsmart_client_level_id' => (int) $level['id'],
                    'product_id' => $productId,
                    'discount_percent' => Money::normalize($level['discount_percent']),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
        }
    }

    public static function deleteProductData($productId)
    {
        if ((int) $productId > 0) {
            Db::name('addon_idcsmart_client_level_product_link')->where('product_id', (int) $productId)->delete();
            ProductRebateService::removeProductSetting((int) $productId);
        }
    }

    public static function deleteClientData($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return;
        }
        Db::name('addon_idcsmart_client_level_client_link')->where('client_id', $clientId)->delete();
        Db::name('addon_idcsmart_client_level_referrer')->where('client_id', $clientId)->update(['status' => 0, 'update_time' => time()]);
        $binds = Db::name('addon_idcsmart_client_level_referral_bind')
            ->where(function ($q) use ($clientId) {
                $q->where('referrer_client_id', $clientId)->whereOr('invitee_client_id', $clientId);
            })->where('status', 1)->select()->toArray();
        foreach ($binds as $bind) {
            Db::name('addon_idcsmart_client_level_referral_bind')->where('id', (int) $bind['id'])->update([
                'active_invitee_id' => null, 'status' => 0, 'end_time' => time(), 'update_time' => time(),
            ]);
        }
        // 订单账本、权益、提现和审计属于财务证据，删除客户时默认保留。
    }

    private static function syncLevelProducts($levelId, $defaultPercent, $overrides)
    {
        $products = Db::name('product')->field('id')->select()->toArray();
        $productIds = [];
        foreach ($products as $product) {
            $productId = (int) $product['id'];
            if ($productId <= 0) {
                continue;
            }
            $productIds[] = $productId;
            $exists = Db::name('addon_idcsmart_client_level_product_link')
                ->where('addon_idcsmart_client_level_id', (int) $levelId)
                ->where('product_id', $productId)
                ->find();
            $explicit = is_array($overrides);
            $percent = $explicit
                ? ($overrides[$productId] ?? $defaultPercent)
                : ($exists['discount_percent'] ?? $defaultPercent);
            $data = [
                'discount_percent' => Money::normalize($percent),
                'update_time' => time(),
            ];
            if (empty($exists)) {
                $data['addon_idcsmart_client_level_id'] = (int) $levelId;
                $data['product_id'] = $productId;
                $data['create_time'] = time();
                Db::name('addon_idcsmart_client_level_product_link')->insert($data);
            } else {
                Db::name('addon_idcsmart_client_level_product_link')->where('id', (int) $exists['id'])->update($data);
            }
        }
        if (is_array($overrides) && !empty($productIds)) {
            Db::name('addon_idcsmart_client_level_product_link')
                ->where('addon_idcsmart_client_level_id', (int) $levelId)
                ->whereNotIn('product_id', $productIds)
                ->delete();
        }
    }

    private static function replaceGroupDiscounts($levelId, $overrides)
    {
        Db::name('addon_idcsmart_client_level_product_group')
            ->where('addon_idcsmart_client_level_id', (int) $levelId)
            ->delete();
        foreach ($overrides as $groupId => $percent) {
            if ($groupId <= 0 || empty(Db::name('product_group')->where('id', $groupId)->find())) {
                continue;
            }
            Db::name('addon_idcsmart_client_level_product_group')->insert([
                'addon_idcsmart_client_level_id' => (int) $levelId,
                'product_group_id' => (int) $groupId,
                'discount_percent' => Money::normalize($percent),
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function discountMap($items, $idKey)
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException('商品折扣配置格式错误');
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item[$idKey] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result[$id] = self::validatedPercent($item['discount_percent'] ?? '', '商品减免比例');
        }
        return $result;
    }

    private static function recordOrder($orderId)
    {
        if ($orderId <= 0) {
            return [];
        }
        $order = Db::name('order')->where('id', $orderId)->find();
        if (empty($order) || !in_array($order['status'], ['Paid', 'Refunded'], true)) {
            return [];
        }
        // V10 核心在订单自身的 type 字段定义充值订单。不能因为普通订单中出现
        // 某个 recharge 条目就排除整张商品订单。
        $isRecharge = (string) ($order['type'] ?? '') === 'recharge';
        $isConsumption = !$isRecharge;
        $paid = Money::maxZero($order['amount'] ?? 0);
        // 核心 refund_amount 是累计退款额。退款必须无条件冲减本人消费、
        // 推广计提和官方等级，不允许后台通过开关绕过。
        $refund = Money::maxZero($order['refund_amount'] ?? 0);
        $net = $isConsumption ? Money::maxZero(Money::subtract($paid, $refund)) : '0.00';
        $data = [
            'client_id' => (int) $order['client_id'],
            'paid_amount' => $paid,
            'refund_amount' => $refund,
            'net_amount' => $net,
            'is_consumption' => $isConsumption ? 1 : 0,
            'update_time' => time(),
        ];
        // 先更新后插入；若并发 Hook 同时插入，唯一键竞争后再次更新赢家，保证
        // 支付链路不会因重复键异常中断。
        $updated = Db::name('addon_idcsmart_client_level_order')->where('order_id', $orderId)->update($data);
        if ((int) $updated === 0) {
            $insert = $data;
            $insert['order_id'] = $orderId;
            $insert['create_time'] = time();
            try {
                Db::name('addon_idcsmart_client_level_order')->insert($insert);
            } catch (\Throwable $e) {
                $winner = Db::name('addon_idcsmart_client_level_order')
                    ->where('order_id', $orderId)
                    ->find();
                if (empty($winner)) {
                    throw $e;
                }
                Db::name('addon_idcsmart_client_level_order')->where('order_id', $orderId)->update($data);
            }
        }
        return $order;
    }

    private static function authoritativeSpend($clientId)
    {
        $orders = Db::name('order')->alias('o')
            ->field('o.id,o.type,o.amount,o.refund_amount')
            ->where('o.client_id', (int) $clientId)
            ->whereIn('o.status', ['Paid', 'Refunded'])
            ->select()
            ->toArray();
        $total = '0.00';
        foreach ($orders as $order) {
            if ((string) ($order['type'] ?? '') === 'recharge') {
                continue;
            }
            $refund = $order['refund_amount'] ?? 0;
            $net = Money::maxZero(Money::subtract($order['amount'] ?? 0, $refund));
            $total = Money::add($total, $net);
        }
        return $total;
    }

    public static function ownSpend($clientId)
    {
        return self::authoritativeSpend((int) $clientId);
    }

    private static function dualAxisEvaluation($ownAmount, $referralAmount, $settings, $includeDisabled)
    {
        $ownAmount = Money::normalize($ownAmount);
        $referralAmount = Money::normalize($referralAmount);
        $ownEnabled = $includeDisabled || (int) ($settings['own_spend_level_enabled'] ?? 1) === 1;
        $referralEnabled = $includeDisabled || (int) ($settings['referral_contribution_level_enabled'] ?? 1) === 1;
        $levels = self::allLevels(true);
        $ownCandidate = null;
        $referralCandidate = null;
        $ownRank = -1;
        $referralRank = -1;
        foreach ($levels as $rank => $level) {
            if ($ownEnabled && Money::compare($level['amount'], $ownAmount) <= 0) {
                $ownCandidate = $level;
                $ownRank = (int) $rank;
            }
            if ($referralEnabled && Money::compare($level['referral_level_amount'], $referralAmount) <= 0) {
                $referralCandidate = $level;
                $referralRank = (int) $rank;
            }
        }
        if ($ownRank < 0 && $referralRank < 0) {
            $candidate = null;
            foreach ($levels as $level) {
                if (Money::compare($level['amount'], '0.00') === 0) {
                    $candidate = $level;
                    break;
                }
            }
            $source = $candidate ? 'base' : 'none';
        } elseif ($ownRank > $referralRank) {
            $candidate = $ownCandidate;
            $source = 'own';
        } elseif ($referralRank > $ownRank) {
            $candidate = $referralCandidate;
            $source = 'referral';
        } else {
            $candidate = $ownCandidate ?: $referralCandidate;
            $source = $ownCandidate && $referralCandidate ? 'both' : ($ownCandidate ? 'own' : 'referral');
        }
        return [
            'candidate' => $candidate,
            'own_candidate' => $ownCandidate,
            'referral_candidate' => $referralCandidate,
            'source' => $source,
            'own_enabled' => $ownEnabled,
            'referral_enabled' => $referralEnabled,
        ];
    }

    private static function compareLevelPriority($candidate, $current)
    {
        if (empty($candidate) && empty($current)) {
            return 0;
        }
        if (empty($candidate)) {
            return -1;
        }
        if (empty($current)) {
            return 1;
        }
        return Money::compare($candidate['amount'], $current['amount']);
    }

    /**
     * 官方模板只有一个累计字段。双线模式不能把两项金额相加，因此这里
     * 保存当前等级门槛与已开启通道进度的较大值，作为官方模板的兼容投影。
     */
    private static function officialCompatibilityAmount($ownAmount, $evaluation, $currentLevel, $preserveLevelFloor = true)
    {
        $amount = '0.00';
        if (!empty($evaluation['own_enabled'])) {
            $amount = Money::normalize($ownAmount);
        }
        if (!empty($evaluation['referral_enabled']) && !empty($evaluation['referral_candidate'])) {
            $amount = Money::max($amount, Money::normalize($evaluation['referral_candidate']['amount']));
        }
        if ($preserveLevelFloor && !empty($currentLevel)) {
            $amount = Money::max($amount, Money::normalize($currentLevel['amount']));
        }
        return Money::normalize($amount);
    }

    private static function dualAxisProgress($currentLevelId, $ownAmount, $referralAmount, $settings, $levels)
    {
        $ownEnabled = (int) ($settings['own_spend_level_enabled'] ?? 1) === 1;
        $referralEnabled = (int) ($settings['referral_contribution_level_enabled'] ?? 1) === 1;
        $currentRank = -1;
        foreach ($levels as $rank => $level) {
            if ((int) $level['id'] === (int) $currentLevelId) {
                $currentRank = (int) $rank;
                break;
            }
        }
        $next = isset($levels[$currentRank + 1]) ? $levels[$currentRank + 1] : null;
        $axes = [
            'own_spend_enabled' => $ownEnabled ? 1 : 0,
            'referral_contribution_enabled' => $referralEnabled ? 1 : 0,
            'mode' => $ownEnabled && $referralEnabled ? 'both' : ($ownEnabled ? 'own' : ($referralEnabled ? 'referral' : 'none')),
            'formula' => $ownEnabled && $referralEnabled
                ? '本人消费与推广贡献分别达标，取较高等级'
                : ($ownEnabled
                    ? '当前按本人真实消费评定等级'
                    : ($referralEnabled ? '当前按已锁定推广贡献评定等级' : '等级升级通道暂未开启，现有等级保留')),
            'own_spend_amount' => Money::normalize($ownAmount),
            'referral_contribution_amount' => Money::normalize($referralAmount),
        ];
        if (!$ownEnabled && !$referralEnabled) {
            return ['axes' => $axes, 'percent' => '100.00', 'legacy_gap' => '0.00', 'next_level' => null];
        }
        if (empty($next)) {
            return ['axes' => $axes, 'percent' => '100.00', 'legacy_gap' => '0.00', 'next_level' => null];
        }
        $ownThreshold = Money::normalize($next['amount']);
        $referralThreshold = Money::normalize($next['referral_level_amount']);
        $ownGap = Money::maxZero(Money::subtract($ownThreshold, $ownAmount));
        $referralGap = Money::maxZero(Money::subtract($referralThreshold, $referralAmount));
        $percent = 0.0;
        if ($ownEnabled) {
            $percent = (float) $ownThreshold > 0 ? min(100, ((float) $ownAmount / (float) $ownThreshold) * 100) : 100;
        }
        if ($referralEnabled) {
            $referralPercent = (float) $referralThreshold > 0 ? min(100, ((float) $referralAmount / (float) $referralThreshold) * 100) : 100;
            $percent = max($percent, $referralPercent);
        }
        $axes['own_next_gap'] = $ownGap;
        $axes['referral_next_gap'] = $referralGap;
        $nextLevel = [
            'id' => (int) $next['id'],
            'name' => (string) $next['name'],
            'amount' => $ownThreshold,
            'referral_level_amount' => $referralThreshold,
        ];
        return [
            'axes' => $axes,
            'percent' => number_format($percent, 2, '.', ''),
            'legacy_gap' => $ownEnabled ? $ownGap : ($referralEnabled ? $referralGap : '0.00'),
            'next_level' => $nextLevel,
        ];
    }

    private static function ensureClientLink($clientId)
    {
        $link = Db::name('addon_idcsmart_client_level_client_link')
            ->where('client_id', (int) $clientId)
            ->find();
        if (!empty($link)) {
            return $link;
        }
        try {
            $id = (int) Db::name('addon_idcsmart_client_level_client_link')->insertGetId([
                'addon_idcsmart_client_level_id' => 0,
                'client_id' => (int) $clientId,
                'cumulative_amount' => '0.00',
                'manual_lock' => 0,
                'assignment_source' => 'auto',
                'last_upgrade_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            return Db::name('addon_idcsmart_client_level_client_link')->where('id', $id)->find();
        } catch (\Throwable $e) {
            $link = Db::name('addon_idcsmart_client_level_client_link')
                ->where('client_id', (int) $clientId)
                ->find();
            if (empty($link)) {
                throw new \RuntimeException('用户等级关联创建失败', 0, $e);
            }
            return $link;
        }
    }

    private static function writeLog($clientId, $oldLevelId, $newLevelId, $source, $orderId, $before, $after, $notes = [])
    {
        Db::name('addon_idcsmart_client_level_log')->insert([
            'client_id' => (int) $clientId,
            'old_level_id' => (int) $oldLevelId,
            'new_level_id' => (int) $newLevelId,
            'source' => self::textLimit($source, 40),
            'order_id' => (int) $orderId,
            'amount_before' => Money::maxZero($before),
            'amount_after' => Money::maxZero($after),
            'admin_id' => function_exists('get_admin_id') ? (int) get_admin_id() : 0,
            'notes' => self::textLimit(is_array($notes)
                ? (string) json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $notes, 500),
            'create_time' => time(),
        ]);
    }

    private static function validatedMoney($value, $label)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException($label . '必须是最多两位小数的非负金额');
        }
        return Money::normalize($value);
    }

    private static function textLimit($value, $length)
    {
        $value = trim((string) $value);
        return function_exists('mb_substr')
            ? mb_substr($value, 0, (int) $length, 'UTF-8')
            : substr($value, 0, (int) $length);
    }

    private static function validatedPercent($value, $label, $max = '100.00')
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException($label . '必须是最多两位小数的非负数字');
        }
        $value = Money::normalize($value);
        if (Money::compare($value, $max) > 0) {
            throw new \InvalidArgumentException($label . '不能超过' . $max);
        }
        return $value;
    }

    private static function validatedColor($value)
    {
        $value = trim((string) $value);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : '#2F54EB';
    }
}
