<?php

namespace addon\idcsmart_client_level\model;

use addon\idcsmart_client_level\lib\Money;
use think\db\Query;
use think\facade\Db;

/**
 * 官方商品列表与推广返利资格。
 *
 * 商品事实始终读取 V10 核心 product/order_item；插件只在现有 setting
 * 表保存逐商品开关，不修改核心商品表，也不创建平行商品表。
 */
class ProductRebateService
{
    const SETTING_PREFIX = 'referral_product_';
    const ELIGIBILITY_VERSION = '1.2.0';

    public static function products($param = [])
    {
        $page = max(1, (int) ($param['page'] ?? 1));
        $limit = min(200, max(1, (int) ($param['limit'] ?? 50)));
        $keywords = trim((string) ($param['keywords'] ?? ''));

        $where = function (Query $query) use ($keywords, $param) {
            if ($keywords !== '') {
                $query->where('p.id|p.name', 'like', '%' . $keywords . '%');
            }
            if (!empty($param['product_group_id'])) {
                $query->where('p.product_group_id', (int) $param['product_group_id']);
            }
        };

        $base = Db::name('product')->alias('p')
            ->leftJoin('product_group pg', 'pg.id=p.product_group_id')
            ->leftJoin('product_group pgf', 'pgf.id=pg.parent_id')
            ->where($where);
        $countQuery = clone $base;
        $count = (int) $countQuery->count('p.id');
        $list = $base
            ->field('p.id,p.name,p.product_group_id,p.hidden,p.pay_type,p.price,p.cycle,p.product_id,pg.name product_group_name_second,pgf.name product_group_name_first')
            ->order('p.order', 'desc')->order('p.id', 'desc')
            ->page($page, $limit)->select()->toArray();

        $keys = [];
        foreach ($list as $row) {
            $keys[] = self::settingKey((int) $row['id']);
        }
        $settings = empty($keys) ? [] : Db::name('addon_idcsmart_client_level_setting')
            ->whereIn('setting_key', $keys)->column('setting_value', 'setting_key');
        foreach ($list as &$row) {
            $key = self::settingKey((int) $row['id']);
            // 未配置即参与，避免新建商品后因漏配而与管理员看到的默认状态不一致。
            $row['rebate_enabled'] = !isset($settings[$key]) || (string) $settings[$key] !== '0' ? 1 : 0;
            $row['price'] = Money::normalize($row['price'] ?? 0);
            $row['group_path'] = trim(implode(' / ', array_filter([
                (string) ($row['product_group_name_first'] ?? ''),
                (string) ($row['product_group_name_second'] ?? ''),
            ], function ($value) {
                return $value !== '';
            })));
        }

        return ['list' => $list, 'count' => $count, 'page' => $page, 'limit' => $limit];
    }

    public static function saveProductEligibility($productId, $enabled)
    {
        $productId = (int) $productId;
        if ($productId <= 0 || Db::name('product')->where('id', $productId)->count() <= 0) {
            return ['status' => 404, 'msg' => '商品不存在'];
        }
        $enabled = !empty($enabled) ? 1 : 0;
        $key = self::settingKey($productId);
        $adminId = function_exists('get_admin_id') ? (int) get_admin_id() : 0;
        $data = [
            'setting_value' => (string) $enabled,
            'update_admin_id' => $adminId,
            'update_time' => time(),
        ];
        Db::startTrans();
        try {
            $row = Db::name('addon_idcsmart_client_level_setting')
                ->where('setting_key', $key)
                ->lock(true)
                ->find();
            if ($row) {
                Db::name('addon_idcsmart_client_level_setting')->where('id', (int) $row['id'])->update($data);
            } else {
                $data['setting_key'] = $key;
                Db::name('addon_idcsmart_client_level_setting')->insert($data);
            }
            ReferralService::audit('product_rebate_switch', 0, 0, 'admin', $adminId, [
                'product_id' => $productId,
                'rebate_enabled' => $enabled,
                'scope' => 'new_paid_orders',
            ], true);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['status' => 500, 'msg' => '返利设置保存失败，请刷新后重试'];
        }
        return ['status' => 200, 'msg' => $enabled ? '商品已加入返利计划' : '商品已移出返利计划', 'data' => [
            'product_id' => $productId,
            'rebate_enabled' => $enabled,
        ]];
    }

    public static function removeProductSetting($productId)
    {
        $productId = (int) $productId;
        if ($productId > 0) {
            Db::name('addon_idcsmart_client_level_setting')->where('setting_key', self::settingKey($productId))->delete();
        }
    }

    /**
     * 支付时锁定订单商品资格。以后管理员切换商品开关不会静默追溯已经
     * 产生的权益；退款仍按该快照强制冲减。
     */
    public static function snapshotForOrder($orderId, $paidAmount)
    {
        $orderId = (int) $orderId;
        $paidAmount = Money::maxZero($paidAmount);
        $items = Db::name('order_item')->field('product_id,amount')
            ->where('order_id', $orderId)->select()->toArray();
        $productIds = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }
        $existingProducts = empty($productIds) ? [] : Db::name('product')
            ->whereIn('id', array_values($productIds))->column('id');
        $existingProducts = array_fill_keys(array_map('intval', $existingProducts), true);
        $keys = [];
        foreach ($productIds as $productId) {
            $keys[] = self::settingKey($productId);
        }
        $settings = empty($keys) ? [] : Db::name('addon_idcsmart_client_level_setting')
            ->whereIn('setting_key', $keys)->column('setting_value', 'setting_key');

        $eligiblePaid = '0.00';
        $snapshotItems = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $amount = Money::maxZero($item['amount'] ?? 0);
            $key = self::settingKey($productId);
            $enabled = $productId > 0
                && isset($existingProducts[$productId])
                && (!isset($settings[$key]) || (string) $settings[$key] !== '0');
            if ($enabled) {
                $eligiblePaid = Money::add($eligiblePaid, $amount);
            }
            $snapshotItems[] = [
                'product_id' => $productId,
                'amount' => $amount,
                'rebate_enabled' => $enabled ? 1 : 0,
            ];
        }
        $eligiblePaid = Money::min($eligiblePaid, $paidAmount);
        $snapshot = json_encode([
            'version' => self::ELIGIBILITY_VERSION,
            'scope' => 'payment_snapshot',
            'items' => $snapshotItems,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshot)) {
            $snapshot = '{"version":"1.2.0","scope":"encode_failed"}';
        }
        return [
            'eligible_paid_amount' => $eligiblePaid,
            'product_policy_snapshot' => $snapshot,
            'eligibility_version' => self::ELIGIBILITY_VERSION,
        ];
    }

    public static function eligibleNetAmount($eligiblePaidAmount, $refundAmount)
    {
        // 核心只给订单级累计退款额，混合商品订单按最保守口径先冲减可返利
        // 部分，避免活动商品退款后仍可套取返利。
        return Money::maxZero(Money::subtract($eligiblePaidAmount, $refundAmount));
    }

    private static function settingKey($productId)
    {
        return self::SETTING_PREFIX . max(0, (int) $productId);
    }
}
