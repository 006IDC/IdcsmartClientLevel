<?php

namespace addon\idcsmart_client_level;

use addon\idcsmart_client_level\model\ClientLevelService;
use addon\idcsmart_client_level\model\IdcsmartClientLevelModel;
use addon\idcsmart_client_level\model\ReferralService;
use addon\idcsmart_client_level\model\WithdrawService;
use app\common\lib\Plugin;
use think\facade\Db;

/**
 * V10 用户等级与累计消费自动升级插件。
 *
 * 公开方法只保留生命周期与真实 Hook，避免被 V10 自动注册成无意义 Hook。
 */
class IdcsmartClientLevel extends Plugin
{
    public $info = [
        'name' => 'IdcsmartClientLevel',
        'title' => '用户等级、双轴贡献与推广权益',
        'description' => '基于官方用户等级，支持本人消费、推广权益贡献、提现和后台人工调级。',
        'author' => '006IDC',
        'version' => '1.6.3',
    ];

    public function install()
    {
        $this->migrate();
        ClientLevelService::ensureSeedData();
        $this->removeLegacyWithdrawalAdminAuth();
        return true;
    }

    public function upgrade()
    {
        $this->migrate();
        ClientLevelService::ensureSeedData();
        if (function_exists('lang_plugins')) {
            lang_plugins('', [], true);
        }
        $this->removeLegacyWithdrawalAdminAuth();
        $this->ensureHookRegistrations();
        $this->ensureClientareaNavigation();
        return true;
    }

    public function uninstall()
    {
        // 等级、累计消费和变更日志属于业务数据，默认保留，避免误删。
        return true;
    }

    public function clientDiscountByAmount($param)
    {
        $model = new IdcsmartClientLevelModel();
        return $model->clientDiscountByAmount($param);
    }

    public function getClientLevelList($param)
    {
        return ClientLevelService::clientLevelMap($param['client_id'] ?? []);
    }

    /**
     * 向 V10 后台用户详情接口注入当前等级及可选等级。
     */
    public function adminClientIndex($param)
    {
        $clientId = (int) ($param['id'] ?? 0);
        return [
            'status' => 200,
            'msg' => 'success',
            'data' => [
                'idcsmart_client_level' => ClientLevelService::adminClientField($clientId),
            ],
        ];
    }

    public function orderPaid($param)
    {
        return ClientLevelService::syncOrder((int) ($param['id'] ?? 0), 'order_paid');
    }

    public function afterOrderRefund($param)
    {
        return ClientLevelService::syncOrder((int) ($param['id'] ?? 0), 'refund');
    }

    public function afterClientRegister($param)
    {
        $clientId = (int) ($param['id'] ?? 0);
        if ($clientId <= 0) {
            return true;
        }
        ReferralService::bindFromCookie($clientId);
        $levelId = $this->customFieldLevelId($param);
        if ($levelId > 0) {
            ClientLevelService::assignClient($clientId, $levelId, true, 'register_manual');
        } else {
            ClientLevelService::recalculateClient($clientId, 'register');
        }
        return true;
    }

    public function beforeClientEdit($param)
    {
        $levelId = $this->customFieldLevelId($param);
        if ($levelId > 0 && !ClientLevelService::levelExists($levelId)) {
            return ['status' => 400, 'msg' => '用户等级不存在或已停用'];
        }
        return true;
    }

    public function afterClientEdit($param)
    {
        $clientId = (int) ($param['id'] ?? 0);
        if ($clientId <= 0 || !$this->hasClientLevelCustomField($param)) {
            return true;
        }
        $levelId = $this->customFieldLevelId($param);
        ClientLevelService::assignClient($clientId, $levelId, $levelId > 0, 'admin_edit');
        return true;
    }

    public function afterClientDelete($param)
    {
        ClientLevelService::deleteClientData((int) ($param['id'] ?? 0));
        return true;
    }

    public function afterProductCreate($param)
    {
        ClientLevelService::syncProduct((int) ($param['id'] ?? 0));
        return true;
    }

    public function afterProductCopy($param)
    {
        ClientLevelService::syncProduct((int) ($param['id'] ?? 0));
        return true;
    }

    public function afterProductDelete($param)
    {
        ClientLevelService::deleteProductData((int) ($param['id'] ?? 0));
        return true;
    }

    /**
     * V10 官方提现通过后，只同步本插件来源的冻结账本。
     */
    public function afterIdcsmartWithdrawPass($param)
    {
        WithdrawService::handleOfficialEvent('pass', (array) $param);
        return true;
    }

    public function afterIdcsmartWithdrawReject($param)
    {
        WithdrawService::handleOfficialEvent('reject', (array) $param);
        return true;
    }

    public function afterIdcsmartWithdrawRejectPass($param)
    {
        WithdrawService::handleOfficialEvent('reopen_approved', (array) $param);
        return true;
    }

    public function afterIdcsmartWithdrawRejectPending($param)
    {
        WithdrawService::handleOfficialEvent('reopen_pending', (array) $param);
        return true;
    }

    /**
     * 官方提现“确认已汇款”没有 Hook，因此用已验证的 minute_cron
     * 补齐确认汇款状态，同时发布已结束强制冻结期的申请。
     */
    public function minuteCron($param = [])
    {
        WithdrawService::publishEligibleToOfficial(100);
        WithdrawService::syncOfficialStatuses(200);
        return true;
    }

    private function hasClientLevelCustomField($param)
    {
        return isset($param['customfield'])
            && is_array($param['customfield'])
            && array_key_exists('idcsmart_client_level', $param['customfield']);
    }

    private function customFieldLevelId($param)
    {
        if (!$this->hasClientLevelCustomField($param)) {
            return 0;
        }
        $value = $param['customfield']['idcsmart_client_level'];
        if (is_array($value)) {
            return (int) ($value['id'] ?? 0);
        }
        return (int) $value;
    }

    /**
     * V10 10.4.6 只在首次安装时导入 sidebar_clientarea.php，覆盖升级不会补录。
     *
     * 因此在插件升级中保留 ID 幂等补齐前台 nav/menu，避免要求管理员
     * 卸载重装，也不会覆盖已有菜单的排序与显示名称。
     */
    private function ensureClientareaNavigation()
    {
        $pluginId = (int) Db::name('plugin')
            ->where('module', 'addon')
            ->where('name', 'IdcsmartClientLevel')
            ->value('id');
        if ($pluginId <= 0) {
            // 首次安装时插件记录可能尚未写入，后续由 V10 核心导入声明文件。
            return;
        }

        $navName = 'nav_plugin_addon_idcsmart_client_level_clientarea';
        $pluginName = 'IdcsmartClientLevel';
        $url = 'plugin/' . $pluginId . '/index.htm';
        $nav = Db::name('nav')
            ->where('type', 'home')
            ->where('module', 'addon')
            ->where('plugin', $pluginName)
            ->where('name', $navName)
            ->find();

        if (empty($nav)) {
            $navId = (int) Db::name('nav')->insertGetId([
                'type' => 'home',
                'name' => $navName,
                'url' => $url,
                'icon' => 'usergroup',
                'parent_id' => 0,
                'order' => (int) Db::name('nav')->max('order') + 1,
                'module' => 'addon',
                'plugin' => $pluginName,
            ]);
        } else {
            $navId = (int) $nav['id'];
            Db::name('nav')->where('id', $navId)->update([
                'url' => $url,
                'icon' => 'usergroup',
            ]);
        }

        if ($navId <= 0) {
            throw new \RuntimeException('用户中心导航补齐失败');
        }

        $menu = Db::name('menu')
            ->where('type', 'home')
            ->where('nav_id', $navId)
            ->find();
        if (empty($menu)) {
            $menuName = function_exists('lang_plugins') ? (string) lang_plugins($navName) : '';
            if ($menuName === '' || $menuName === $navName) {
                $menuName = '会员等级与推广权益';
            }
            Db::name('menu')->insert([
                'type' => 'home',
                'menu_type' => 'plugin',
                'name' => $menuName,
                'language' => json_encode([], JSON_UNESCAPED_UNICODE),
                'url' => $url,
                'icon' => 'usergroup',
                'nav_id' => $navId,
                'parent_id' => 0,
                'product_id' => '',
                'order' => (int) Db::name('menu')->max('order') + 1,
                'create_time' => time(),
            ]);
        } else {
            Db::name('menu')->where('id', (int) $menu['id'])->update([
                'url' => $url,
                'icon' => 'usergroup',
            ]);
        }
    }

    private function migrate()
    {
        $prefix = $this->dbPrefix();
        foreach ($this->installSql($prefix) as $sql) {
            Db::execute($sql);
        }
        $this->ensureLegacyCompatibleColumns($prefix);
    }

    private function dbPrefix()
    {
        try {
            $prefix = (string) config('database.prefix');
            if ($prefix !== '') {
                return $prefix;
            }
        } catch (\Throwable $e) {
        }
        try {
            $prefix = (string) config('database.connections.mysql.prefix');
            if ($prefix !== '') {
                return $prefix;
            }
        } catch (\Throwable $e) {
        }
        try {
            $config = Db::getConfig();
            if (is_array($config) && !empty($config['prefix'])) {
                return (string) $config['prefix'];
            }
        } catch (\Throwable $e) {
        }
        return 'idcsmart_';
    }

    private function installSql($prefix)
    {
        $p = str_replace('`', '', (string) $prefix);
        return [
            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '等级名称',
  `amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '累计净消费升级门槛',
  `discount_percent` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '默认减免百分比,10表示九折',
  `discount_status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '折扣状态',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '等级状态',
  `background_color` varchar(20) NOT NULL DEFAULT '#2F54EB' COMMENT '等级颜色',
  `notes` varchar(1000) NOT NULL DEFAULT '' COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status_amount` (`status`,`amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户等级';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_client_link` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `addon_idcsmart_client_level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '等级ID',
  `client_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `cumulative_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '累计净消费',
  `manual_lock` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '手工等级锁定',
  `assignment_source` varchar(40) NOT NULL DEFAULT 'auto' COMMENT '分配来源',
  `last_upgrade_time` int(11) unsigned NOT NULL DEFAULT 0,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_id` (`client_id`),
  KEY `idx_level_id` (`addon_idcsmart_client_level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户等级关联';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_product_link` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `addon_idcsmart_client_level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '等级ID',
  `product_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '商品ID',
  `discount_percent` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '商品减免百分比',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_level_product` (`addon_idcsmart_client_level_id`,`product_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='等级商品折扣';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_product_group` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `addon_idcsmart_client_level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '等级ID',
  `product_group_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '商品分组ID',
  `discount_percent` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '分组减免百分比',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_level_group` (`addon_idcsmart_client_level_id`,`product_group_id`),
  KEY `idx_product_group_id` (`product_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='等级商品分组折扣';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '订单ID',
  `client_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `paid_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '订单实付金额',
  `refund_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '累计退款金额',
  `net_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '净消费金额',
  `is_consumption` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '是否计入消费',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='累计消费订单账本';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_setting` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(80) NOT NULL DEFAULT '',
  `setting_value` text NOT NULL,
  `update_admin_id` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户等级设置';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `old_level_id` int(11) unsigned NOT NULL DEFAULT 0,
  `new_level_id` int(11) unsigned NOT NULL DEFAULT 0,
  `source` varchar(40) NOT NULL DEFAULT '',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0,
  `amount_before` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `amount_after` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `admin_id` int(11) unsigned NOT NULL DEFAULT 0,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_client_time` (`client_id`,`create_time`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户等级变更日志';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_referrer` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `invite_code` varchar(32) NOT NULL DEFAULT '',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_client_id` (`client_id`), UNIQUE KEY `uk_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='等级插件推广人';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_referral_bind` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `referrer_client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `invitee_client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `active_invitee_id` int(11) unsigned NULL DEFAULT NULL,
  `invite_code` varchar(32) NOT NULL DEFAULT '',
  `source` varchar(40) NOT NULL DEFAULT '',
  `inherit_history` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `contribution_start_time` int(11) unsigned NOT NULL DEFAULT 0,
  `end_time` int(11) unsigned NOT NULL DEFAULT 0,
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `admin_id` int(11) unsigned NOT NULL DEFAULT 0,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_active_invitee` (`active_invitee_id`),
  KEY `idx_referrer_status` (`referrer_client_id`,`status`), KEY `idx_invitee_time` (`invitee_client_id`,`contribution_start_time`,`end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广绑定历史';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_referral_accrual` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_order_id` int(11) unsigned NOT NULL DEFAULT 0,
  `bind_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `referrer_client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `invitee_client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `base_net_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `eligible_paid_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '支付时锁定的可返利商品金额',
  `product_policy_snapshot` text NULL COMMENT '支付时商品返利资格快照',
  `eligibility_version` varchar(20) NOT NULL DEFAULT '1.2.0',
  `rate_percent` decimal(8,2) unsigned NOT NULL DEFAULT '0.00',
  `policy_level_id` int(11) unsigned NOT NULL DEFAULT 0,
  `net_entitlement` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `pending_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `unallocated_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `cash_allocated_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_source_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_effective_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `debt_offset_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `revision` int(11) unsigned NOT NULL DEFAULT 0,
  `mature_time` int(11) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_source_order` (`source_order_id`),
  KEY `idx_referrer_status_mature` (`referrer_client_id`,`status`,`mature_time`), KEY `idx_bind_id` (`bind_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广权益计提批次';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_benefit_account` (
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `pending` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `unallocated` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `withdrawable` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `withdraw_frozen` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_source` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_effective` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `debt` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广权益账户缓存';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_benefit_allocation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_no` varchar(64) NOT NULL DEFAULT '',
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `target` varchar(20) NOT NULL DEFAULT '',
  `source_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_rate` decimal(8,2) unsigned NOT NULL DEFAULT '0.00',
  `effective_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `reversed_source_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `reversed_effective_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_business_no` (`business_no`), KEY `idx_client_time` (`client_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广权益分配';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_benefit_allocation_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `accrual_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `source_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `effective_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `reversed_source_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `reversed_effective_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_allocation_accrual` (`allocation_id`,`accrual_id`), KEY `idx_accrual_id` (`accrual_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权益分配来源明细';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_benefit_flow` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `scene` varchar(50) NOT NULL DEFAULT '',
  `ref_type` varchar(30) NOT NULL DEFAULT '',
  `ref_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `idempotency_key` varchar(100) NOT NULL DEFAULT '',
  `pending_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `unallocated_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `withdrawable_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `frozen_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `contribution_source_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `contribution_effective_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `debt_delta` decimal(18,2) NOT NULL DEFAULT '0.00',
  `balance_before` text NULL, `balance_after` text NULL, `extra` text NULL,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_idempotency` (`idempotency_key`), KEY `idx_client_time` (`client_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广权益追加式流水';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_withdraw_method` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL DEFAULT '',
  `account_cipher` text NULL, `account_mask` varchar(255) NOT NULL DEFAULT '',
  `name_cipher` text NULL, `name_mask` varchar(100) NOT NULL DEFAULT '',
  `is_default` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0, `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_client_default` (`client_id`,`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广权益收款方式';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_withdraw` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_no` varchar(64) NOT NULL DEFAULT '', `request_key` varchar(64) NOT NULL DEFAULT '',
  `client_id` int(11) unsigned NOT NULL DEFAULT 0, `amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `method_id` bigint(20) unsigned NOT NULL DEFAULT 0, `method_type` varchar(20) NOT NULL DEFAULT '',
  `account_cipher` text NULL, `account_mask` varchar(255) NOT NULL DEFAULT '',
  `name_cipher` text NULL, `name_mask` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending', `admin_id` int(11) unsigned NOT NULL DEFAULT 0,
  `official_withdraw_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'V10官方提现ID',
  `review_note` varchar(500) NOT NULL DEFAULT '', `paid_reference` varchar(100) NOT NULL DEFAULT '',
  `paid_time` int(11) unsigned NOT NULL DEFAULT 0, `eligible_review_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '提交时锁定的最早审核时间',
  `create_time` int(11) unsigned NOT NULL DEFAULT 0, `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_business_no` (`business_no`), UNIQUE KEY `uk_request_key` (`request_key`),
  KEY `idx_client_time` (`client_id`,`create_time`), KEY `idx_status_time` (`status`,`create_time`), KEY `idx_official_withdraw_id` (`official_withdraw_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推广权益提现';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_level_policy` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `level_id` int(11) unsigned NOT NULL DEFAULT 0,
  `referral_level_amount` decimal(18,2) unsigned NULL DEFAULT NULL COMMENT '推广贡献升级门槛',
  `reward_rate_override` tinyint(1) unsigned NOT NULL DEFAULT 0, `reward_rate` decimal(8,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_rate_override` tinyint(1) unsigned NOT NULL DEFAULT 0, `contribution_rate` decimal(8,2) unsigned NOT NULL DEFAULT '100.00',
  `min_withdraw_override` tinyint(1) unsigned NOT NULL DEFAULT 0, `min_withdraw` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `update_admin_id` int(11) unsigned NOT NULL DEFAULT 0, `create_time` int(11) unsigned NOT NULL DEFAULT 0, `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_level_id` (`level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官方等级推广策略旁挂';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_manual_override` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `level_id` int(11) unsigned NOT NULL DEFAULT 0, `start_time` int(11) unsigned NOT NULL DEFAULT 0,
  `end_time` int(11) unsigned NOT NULL DEFAULT 0, `reason` varchar(500) NOT NULL DEFAULT '',
  `admin_id` int(11) unsigned NOT NULL DEFAULT 0, `status` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `create_time` int(11) unsigned NOT NULL DEFAULT 0, `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_client_id` (`client_id`), KEY `idx_end_status` (`end_time`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人工等级锁定策略';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_metric` (
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `own_net_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `referral_net_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `contribution_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `effective_amount` decimal(18,2) unsigned NOT NULL DEFAULT '0.00',
  `candidate_level_id` int(11) unsigned NOT NULL DEFAULT 0, `downgrade_due_time` int(11) unsigned NOT NULL DEFAULT 0,
  `calc_version` varchar(20) NOT NULL DEFAULT '1.1.0',
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`client_id`), KEY `idx_effective` (`effective_amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='双轴等级可重建指标';",

            "CREATE TABLE IF NOT EXISTS `{$p}addon_idcsmart_client_level_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `event` varchar(60) NOT NULL DEFAULT '',
  `client_id` int(11) unsigned NOT NULL DEFAULT 0, `related_client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `operator_type` varchar(20) NOT NULL DEFAULT '', `operator_id` int(11) unsigned NOT NULL DEFAULT 0,
  `ip_address` varchar(45) NOT NULL DEFAULT '', `extra` text NULL, `create_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_client_time` (`client_id`,`create_time`), KEY `idx_event_time` (`event`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='双轴等级增强审计';",
        ];
    }

    private function ensureLegacyCompatibleColumns($prefix)
    {
        $definitions = [
            'addon_idcsmart_client_level' => [
                'amount' => "decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '累计净消费升级门槛'",
                'discount_percent' => "decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '默认减免百分比'",
                'discount_status' => "tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '折扣状态'",
                'status' => "tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '等级状态'",
                'background_color' => "varchar(20) NOT NULL DEFAULT '#2F54EB' COMMENT '等级颜色'",
                'notes' => "varchar(1000) NOT NULL DEFAULT '' COMMENT '备注'",
                'sort' => "int(11) NOT NULL DEFAULT 0 COMMENT '排序'",
                'create_time' => "int(11) unsigned NOT NULL DEFAULT 0",
                'update_time' => "int(11) unsigned NOT NULL DEFAULT 0",
            ],
            'addon_idcsmart_client_level_client_link' => [
                'cumulative_amount' => "decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '累计净消费'",
                'manual_lock' => "tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '手工等级锁定'",
                'assignment_source' => "varchar(40) NOT NULL DEFAULT 'auto' COMMENT '分配来源'",
                'last_upgrade_time' => "int(11) unsigned NOT NULL DEFAULT 0",
                'create_time' => "int(11) unsigned NOT NULL DEFAULT 0",
                'update_time' => "int(11) unsigned NOT NULL DEFAULT 0",
            ],
            'addon_idcsmart_client_level_product_link' => [
                'create_time' => "int(11) unsigned NOT NULL DEFAULT 0",
                'update_time' => "int(11) unsigned NOT NULL DEFAULT 0",
            ],
            'addon_idcsmart_client_level_metric' => [
                'downgrade_due_time' => "int(11) unsigned NOT NULL DEFAULT 0",
            ],
            'addon_idcsmart_client_level_level_policy' => [
                'referral_level_amount' => "decimal(18,2) unsigned NULL DEFAULT NULL COMMENT '推广贡献升级门槛'",
            ],
            'addon_idcsmart_client_level_referral_accrual' => [
                'eligible_paid_amount' => "decimal(18,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '支付时锁定的可返利商品金额'",
                'product_policy_snapshot' => "text NULL COMMENT '支付时商品返利资格快照'",
                'eligibility_version' => "varchar(20) NOT NULL DEFAULT '1.2.0'",
            ],
            'addon_idcsmart_client_level_withdraw' => [
                'official_withdraw_id' => "bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'V10官方提现ID'",
                'eligible_review_time' => "int(11) unsigned NOT NULL DEFAULT 0 COMMENT '提交时锁定的最早审核时间'",
            ],
        ];

        foreach ($definitions as $table => $columns) {
            $fullTable = str_replace('`', '', $prefix . $table);
            foreach ($columns as $column => $definition) {
                $exists = Db::query("SHOW COLUMNS FROM `{$fullTable}` LIKE '" . addslashes($column) . "'");
                if (empty($exists)) {
                    Db::execute("ALTER TABLE `{$fullTable}` ADD COLUMN `{$column}` {$definition}");
                }
            }
        }

        $withdrawTable = str_replace('`', '', $prefix . 'addon_idcsmart_client_level_withdraw');
        $officialIndex = Db::query("SHOW INDEX FROM `{$withdrawTable}` WHERE Key_name='idx_official_withdraw_id'");
        if (empty($officialIndex)) {
            Db::execute("ALTER TABLE `{$withdrawTable}` ADD INDEX `idx_official_withdraw_id` (`official_withdraw_id`)");
        }

        // 1.1.4 及更早版本没有商品资格快照。升级时把既有批次视为当时全部
        // 商品参加，保持历史财务结果；新开关只作用于保存后的新支付订单。
        $accrualTable = str_replace('`', '', $prefix . 'addon_idcsmart_client_level_referral_accrual');
        $orderTable = str_replace('`', '', $prefix . 'addon_idcsmart_client_level_order');
        Db::execute("UPDATE `{$accrualTable}` a INNER JOIN `{$orderTable}` o ON o.order_id=a.source_order_id
            SET a.eligible_paid_amount=o.paid_amount,
                a.product_policy_snapshot='{\"version\":\"legacy-1.1.4\",\"scope\":\"all_products\"}',
                a.eligibility_version='legacy-1.1.4'
            WHERE a.product_policy_snapshot IS NULL OR a.product_policy_snapshot=''");
    }

    /**
     * 10.4.6 覆盖升级不会反射新增公有 Hook，这里只幂等补齐
     * 本版本新增的官方提现与分钟任务 Hook。
     */
    private function ensureHookRegistrations()
    {
        $hooks = [
            'after_idcsmart_withdraw_pass',
            'after_idcsmart_withdraw_reject',
            'after_idcsmart_withdraw_reject_pass',
            'after_idcsmart_withdraw_reject_pending',
            'minute_cron',
        ];
        foreach ($hooks as $hook) {
            $rows = Db::name('plugin_hook')
                ->where('name', $hook)
                ->where('plugin', 'IdcsmartClientLevel')
                ->where('module', 'addon')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            if (empty($rows)) {
                Db::name('plugin_hook')->insert([
                    'name' => $hook,
                    'status' => 1,
                    'plugin' => 'IdcsmartClientLevel',
                    'module' => 'addon',
                    'order' => 0,
                ]);
                continue;
            }
            $keepId = (int) $rows[0]['id'];
            Db::name('plugin_hook')->where('id', $keepId)->update(['status' => 1]);
            if (count($rows) > 1) {
                $extraIds = array_map('intval', array_column(array_slice($rows, 1), 'id'));
                Db::name('plugin_hook')->whereIn('id', $extraIds)->delete();
            }
        }
    }

    /**
     * 1.2.0 曾有插件内部提现审核权限。1.3.0 起官方提现管理是
     * 唯一后台审核入口，覆盖升级时必须一并移除旧权限及关联。
     */
    private function removeLegacyWithdrawalAdminAuth()
    {
        $controller = 'addon\\idcsmart_client_level\\controller\\AdminController::';
        $ruleNames = [
            $controller . 'withdrawals',
            $controller . 'withdrawalDetail',
            $controller . 'reviewWithdrawal',
        ];
        $ruleIds = Db::name('auth_rule')
            ->where('module', 'addon')
            ->where('plugin', 'IdcsmartClientLevel')
            ->whereIn('name', $ruleNames)
            ->column('id');
        if (!empty($ruleIds)) {
            Db::name('auth_rule_link')->whereIn('auth_rule_id', array_map('intval', $ruleIds))->delete();
            Db::name('auth_rule')->whereIn('id', array_map('intval', $ruleIds))->delete();
        }

        $authIds = Db::name('auth')
            ->where('module', 'addon')
            ->where('plugin', 'IdcsmartClientLevel')
            ->where('title', 'auth_idcsmart_client_level_finance')
            ->column('id');
        if (!empty($authIds)) {
            $authIds = array_map('intval', $authIds);
            Db::name('auth_rule_link')->whereIn('auth_id', $authIds)->delete();
            Db::name('auth_link')->whereIn('auth_id', $authIds)->delete();
            Db::name('auth')->whereIn('id', $authIds)->delete();
        }
    }
}
