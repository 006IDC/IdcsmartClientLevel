<?php

// V10 会自动补全 addon\idcsmart_client_level\controller\clientarea\ 前缀。
return [
    [
        'title' => 'clientarea_auth_idcsmart_client_level',
        'url' => '',
        'child' => [
            [
                'title' => 'clientarea_auth_idcsmart_client_level_view',
                'url' => '',
                'auth_rule' => [
                    'IndexController::detail',
                    'IndexController::productAmount',
                    'IndexController::referrals',
                    'IndexController::accruals',
                    'IndexController::benefitFlows',
                    'IndexController::allocateBenefit',
                    'IndexController::withdrawMethods',
                    'IndexController::saveWithdrawMethod',
                    'IndexController::deleteWithdrawMethod',
                    'IndexController::withdrawals',
                    'IndexController::createWithdrawal',
                    'IndexController::cancelWithdrawal',
                ],
                'auth_rule_title' => [
                    'clientarea_auth_idcsmart_client_level_detail',
                    'clientarea_auth_idcsmart_client_level_product_amount',
                    'clientarea_auth_idcsmart_client_level_referrals',
                    'clientarea_auth_idcsmart_client_level_accruals',
                    'clientarea_auth_idcsmart_client_level_benefit_flows',
                    'clientarea_auth_idcsmart_client_level_allocate',
                    'clientarea_auth_idcsmart_client_level_withdraw_methods',
                    'clientarea_auth_idcsmart_client_level_save_withdraw_method',
                    'clientarea_auth_idcsmart_client_level_delete_withdraw_method',
                    'clientarea_auth_idcsmart_client_level_withdrawals',
                    'clientarea_auth_idcsmart_client_level_create_withdrawal',
                    'clientarea_auth_idcsmart_client_level_cancel_withdrawal',
                ],
            ],
        ],
    ],
];
