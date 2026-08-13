<?php

use think\facade\Route;

Route::get('client-level/invite/:code', "\\addon\\idcsmart_client_level\\controller\\clientarea\\InviteController@invite")
    ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'invite', '_action' => 'invite']);

Route::group('console/v1/client_level', function () {
    Route::get('', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@detail")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'detail']);
    Route::get('product/:id/amount', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@productAmount")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'product_amount']);
    Route::get('referrals', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@referrals")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'referrals']);
    Route::get('accruals', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@accruals")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'accruals']);
    Route::get('benefit/flows', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@benefitFlows")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'benefit_flows']);
    Route::get('withdraw/methods', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@withdrawMethods")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'withdraw_methods']);
    Route::get('withdrawals', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@withdrawals")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'withdrawals']);
})
    ->middleware(\app\http\middleware\CheckHome::class)
    ->middleware(\app\http\middleware\ParamFilter::class);

Route::group('console/v1/client_level', function () {
    Route::post('benefit/allocate', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@allocateBenefit")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'allocate_benefit']);
    Route::post('withdraw/method', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@saveWithdrawMethod")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'save_withdraw_method']);
    Route::delete('withdraw/method/:id', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@deleteWithdrawMethod")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'delete_withdraw_method']);
    Route::post('withdrawal', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@createWithdrawal")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'create_withdrawal']);
    Route::post('withdrawal/:id/cancel', "\\addon\\idcsmart_client_level\\controller\\clientarea\\IndexController@cancelWithdrawal")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'index', '_action' => 'cancel_withdrawal']);
})
    ->middleware(\app\http\middleware\CheckHome::class)
    ->middleware(\app\http\middleware\ParamFilter::class)
    ->middleware(\app\http\middleware\RejectRepeatRequest::class);

Route::group(DIR_ADMIN . '/v1/client_level', function () {
    Route::get('dashboard', "\\addon\\idcsmart_client_level\\controller\\AdminController@dashboard")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'dashboard']);
    Route::get('all', "\\addon\\idcsmart_client_level\\controller\\AdminController@allLevels")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'all_levels']);
    Route::get('clients', "\\addon\\idcsmart_client_level\\controller\\AdminController@clients")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'clients']);
    Route::get('client/:id', "\\addon\\idcsmart_client_level\\controller\\AdminController@clientLevel")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'client_level']);
    Route::get('settings', "\\addon\\idcsmart_client_level\\controller\\AdminController@settings")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'settings']);
    Route::get('products', "\\addon\\idcsmart_client_level\\controller\\AdminController@rebateProducts")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'rebate_products']);
    Route::get('logs', "\\addon\\idcsmart_client_level\\controller\\AdminController@logs")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'logs']);
    Route::get('binds', "\\addon\\idcsmart_client_level\\controller\\AdminController@binds")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'binds']);
    Route::get('benefit/flows', "\\addon\\idcsmart_client_level\\controller\\AdminController@benefitFlows")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'benefit_flows']);
    Route::get('level/:id/policy', "\\addon\\idcsmart_client_level\\controller\\AdminController@levelPolicy")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'level_policy']);
    Route::get(':id', "\\addon\\idcsmart_client_level\\controller\\AdminController@levelDetail")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'level_detail']);
    Route::get('', "\\addon\\idcsmart_client_level\\controller\\AdminController@levels")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'levels']);
})
    ->middleware(\app\http\middleware\CheckAdmin::class)
    ->middleware(\app\http\middleware\ParamFilter::class);

Route::group(DIR_ADMIN . '/v1/client_level', function () {
    Route::post('', "\\addon\\idcsmart_client_level\\controller\\AdminController@createLevel")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'create_level']);
    Route::put('client', "\\addon\\idcsmart_client_level\\controller\\AdminController@assignClient")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'assign_client']);
    Route::post('rebuild', "\\addon\\idcsmart_client_level\\controller\\AdminController@rebuild")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'rebuild']);
    Route::put('settings', "\\addon\\idcsmart_client_level\\controller\\AdminController@saveSettings")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'save_settings']);
    Route::put('product/:id/rebate', "\\addon\\idcsmart_client_level\\controller\\AdminController@saveProductRebate")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'save_product_rebate']);
    Route::post('bind', "\\addon\\idcsmart_client_level\\controller\\AdminController@saveBind")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'save_bind']);
    Route::put('level/:id/policy', "\\addon\\idcsmart_client_level\\controller\\AdminController@saveLevelPolicy")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'save_level_policy']);
    Route::post('benefit/mature', "\\addon\\idcsmart_client_level\\controller\\AdminController@matureBenefits")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'mature_benefits']);
    Route::post('half_agent/import', "\\addon\\idcsmart_client_level\\controller\\AdminController@halfAgentImport")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'half_agent_import']);
    Route::put(':id', "\\addon\\idcsmart_client_level\\controller\\AdminController@updateLevel")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'update_level']);
    Route::delete(':id', "\\addon\\idcsmart_client_level\\controller\\AdminController@deleteLevel")
        ->append(['_plugin' => 'idcsmart_client_level', '_controller' => 'admin', '_action' => 'delete_level']);
})
    ->middleware(\app\http\middleware\CheckAdmin::class)
    ->middleware(\app\http\middleware\ParamFilter::class)
    ->middleware(\app\http\middleware\RejectRepeatRequest::class);
