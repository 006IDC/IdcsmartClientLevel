<?php

namespace addon\idcsmart_client_level\model;

use think\Model;

class IdcsmartClientLevelClientLinkModel extends Model
{
    protected $name = 'addon_idcsmart_client_level_client_link';

    protected $schema = [
        'id' => 'int',
        'addon_idcsmart_client_level_id' => 'int',
        'client_id' => 'int',
        'cumulative_amount' => 'string',
        'manual_lock' => 'int',
        'assignment_source' => 'string',
        'last_upgrade_time' => 'int',
        'create_time' => 'int',
        'update_time' => 'int',
    ];
}
