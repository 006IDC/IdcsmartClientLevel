<?php

namespace addon\idcsmart_client_level\model;

use think\Model;

/**
 * 保留该兼容模型，使 10.7.x Server 模块走插件公开的 clientDiscount() 契约。
 */
class IdcsmartClientLevelProductGroupModel extends Model
{
    protected $name = 'addon_idcsmart_client_level_product_group';
}
