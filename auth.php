<?php

$controller = 'addon\\idcsmart_client_level\\controller\\AdminController::';

return [
    [
        'title' => 'auth_idcsmart_client_level',
        'url' => '',
        'description' => '用户等级与累计消费折扣',
        'parent' => 'auth_user',
        'child' => [
            [
                'title' => 'auth_idcsmart_client_level_view',
                'url' => 'index',
                'description' => '查看等级、用户与变更记录',
                'auth_rule' => [
                    $controller . 'dashboard',
                    $controller . 'levels',
                    $controller . 'allLevels',
                    $controller . 'levelDetail',
                    $controller . 'clients',
                    $controller . 'clientLevel',
                    $controller . 'settings',
                    $controller . 'rebateProducts',
                    $controller . 'logs',
                    $controller . 'binds',
                    $controller . 'benefitFlows',
                    $controller . 'levelPolicy',
                ],
            ],
            [
                'title' => 'auth_idcsmart_client_level_manage',
                'url' => '',
                'description' => '管理等级、折扣和自动升级',
                'auth_rule' => [
                    $controller . 'createLevel',
                    $controller . 'updateLevel',
                    $controller . 'deleteLevel',
                    $controller . 'assignClient',
                    $controller . 'rebuild',
                    $controller . 'saveSettings',
                    $controller . 'saveProductRebate',
                    $controller . 'saveBind',
                    $controller . 'saveLevelPolicy',
                    $controller . 'matureBenefits',
                    $controller . 'halfAgentImport',
                ],
            ],
        ],
    ],
];
