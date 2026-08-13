<?php

namespace addon\idcsmart_client_level\model;

use think\Model;

class IdcsmartClientLevelProductLinkModel extends Model
{
    protected $name = 'addon_idcsmart_client_level_product_link';

    protected $schema = [
        'id' => 'int',
        'addon_idcsmart_client_level_id' => 'int',
        'product_id' => 'int',
        'discount_percent' => 'string',
        'create_time' => 'int',
        'update_time' => 'int',
    ];
}
