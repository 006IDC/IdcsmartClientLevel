<?php

namespace addon\idcsmart_client_level\model;

use addon\idcsmart_client_level\lib\Money;
use think\facade\Db;
use think\Model;

class IdcsmartClientLevelModel extends Model
{
    protected $name = 'addon_idcsmart_client_level';

    protected $schema = [
        'id' => 'int',
        'name' => 'string',
        'amount' => 'string',
        'discount_percent' => 'string',
        'discount_status' => 'int',
        'status' => 'int',
        'background_color' => 'string',
        'notes' => 'string',
        'sort' => 'int',
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    /**
     * 返回当前用户在指定商品上的等级与减免比例。
     */
    public function clientDiscount($param)
    {
        $clientId = (int) ($param['client_id'] ?? 0);
        if ($clientId <= 0 && function_exists('get_client_id')) {
            $clientId = (int) get_client_id();
        }
        $productId = (int) ($param['product_id'] ?? $param['id'] ?? 0);
        if ($clientId <= 0 || $productId <= 0) {
            return [];
        }

        $level = IdcsmartClientLevelClientLinkModel::alias('cl')
            ->field('l.id,l.name,l.discount_percent,l.background_color,l.discount_status,l.status')
            ->leftJoin('addon_idcsmart_client_level l', 'l.id=cl.addon_idcsmart_client_level_id')
            ->where('cl.client_id', $clientId)
            ->where('l.status', 1)
            ->find();
        if (empty($level) || (int) $level['discount_status'] !== 1) {
            return [];
        }

        $percent = Db::name('addon_idcsmart_client_level_product_link')
            ->where('addon_idcsmart_client_level_id', (int) $level['id'])
            ->where('product_id', $productId)
            ->value('discount_percent');

        if ($percent === null) {
            $groupId = (int) Db::name('product')->where('id', $productId)->value('product_group_id');
            if ($groupId > 0) {
                $percent = Db::name('addon_idcsmart_client_level_product_group')
                    ->where('addon_idcsmart_client_level_id', (int) $level['id'])
                    ->where('product_group_id', $groupId)
                    ->value('discount_percent');
            }
        }
        if ($percent === null) {
            $percent = $level['discount_percent'];
        }

        return [
            'id' => (int) $level['id'],
            'name' => (string) $level['name'],
            'product_id' => $productId,
            'discount_percent' => Money::normalize($percent),
            'background_color' => (string) $level['background_color'],
        ];
    }

    /**
     * 兼容核心 ResModuleLogic / UpstreamLogic 的直接模型调用，返回减免金额字符串。
     */
    public function productDiscount($param)
    {
        $level = $this->clientDiscount([
            'client_id' => $param['client_id'] ?? 0,
            'product_id' => $param['product_id'] ?? $param['id'] ?? 0,
        ]);
        if (empty($level)) {
            return '0.00';
        }
        return Money::discount($param['amount'] ?? 0, $level['discount_percent']);
    }

    /**
     * V10 client_discount_by_amount Hook。
     */
    public function clientDiscountByAmount($param)
    {
        $level = $this->clientDiscount($param);
        $discount = empty($level)
            ? '0.00'
            : Money::discount($param['amount'] ?? 0, $level['discount_percent']);

        return [
            'status' => 200,
            'msg' => 'success',
            'data' => [
                'discount' => $discount,
                'price_client_level_discount' => $discount,
                'level' => $level,
            ],
        ];
    }
}
