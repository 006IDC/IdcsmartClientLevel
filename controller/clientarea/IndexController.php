<?php

namespace addon\idcsmart_client_level\controller\clientarea;

use addon\idcsmart_client_level\model\ClientLevelService;
use addon\idcsmart_client_level\model\BenefitLedgerService;
use addon\idcsmart_client_level\model\IdcsmartClientLevelModel;
use addon\idcsmart_client_level\model\ReferralService;
use addon\idcsmart_client_level\model\WithdrawService;
use app\event\controller\PluginBaseController;

class IndexController extends PluginBaseController
{
    public function detail()
    {
        $clientId = function_exists('get_client_id') ? (int) get_client_id() : 0;
        return json([
            'status' => 200,
            'msg' => 'success',
            'data' => ClientLevelService::currentDetail($clientId),
        ]);
    }

    public function productAmount()
    {
        $param = $this->request->param();
        $param['product_id'] = (int) ($param['id'] ?? 0);
        $param['client_id'] = function_exists('get_client_id') ? (int) get_client_id() : 0;
        $model = new IdcsmartClientLevelModel();
        return json($model->clientDiscountByAmount($param));
    }

    public function referrals()
    {
        return $this->ok(ReferralService::referrals($this->clientId(), $this->request->param()));
    }

    public function accruals()
    {
        return $this->ok(BenefitLedgerService::accruals($this->clientId(), $this->request->param()));
    }

    public function benefitFlows()
    {
        return $this->ok(BenefitLedgerService::flows($this->clientId(), $this->request->param(), false));
    }

    public function allocateBenefit()
    {
        return json(BenefitLedgerService::allocate(
            $this->clientId(),
            $this->request->param('amount', ''),
            $this->request->param('target', ''),
            $this->request->param('business_no', '')
        ));
    }

    public function withdrawMethods()
    {
        return $this->ok(['list' => WithdrawService::methods($this->clientId())]);
    }

    public function saveWithdrawMethod()
    {
        return json(WithdrawService::saveMethod($this->clientId(), $this->request->param()));
    }

    public function deleteWithdrawMethod()
    {
        return json(WithdrawService::deleteMethod($this->clientId(), (int) $this->request->param('id', 0)));
    }

    public function withdrawals()
    {
        return $this->ok(WithdrawService::listRows($this->clientId(), $this->request->param(), false));
    }

    public function createWithdrawal()
    {
        return json(WithdrawService::create(
            $this->clientId(),
            $this->request->param('amount', ''),
            (int) $this->request->param('method_id', 0),
            $this->request->param('request_key', '')
        ));
    }

    public function cancelWithdrawal()
    {
        return json(WithdrawService::cancel($this->clientId(), (int) $this->request->param('id', 0)));
    }

    private function clientId()
    {
        return function_exists('get_client_id') ? (int) get_client_id() : 0;
    }

    private function ok($data)
    {
        return json(['status' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
