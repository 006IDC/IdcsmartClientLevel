<?php

namespace addon\idcsmart_client_level\controller;

use addon\idcsmart_client_level\model\ClientLevelService;
use addon\idcsmart_client_level\model\BenefitLedgerService;
use addon\idcsmart_client_level\model\ReferralService;
use addon\idcsmart_client_level\model\ProductRebateService;
use app\event\controller\PluginAdminBaseController;

class AdminController extends PluginAdminBaseController
{
    public function dashboard()
    {
        return $this->success(ClientLevelService::dashboard());
    }

    public function levels()
    {
        return $this->success(ClientLevelService::levels($this->request->param()));
    }

    public function allLevels()
    {
        return $this->success(['list' => ClientLevelService::allLevels(false)]);
    }

    public function levelDetail()
    {
        $level = ClientLevelService::levelDetail((int) $this->request->param('id', 0));
        if (empty($level)) {
            return json(['status' => 404, 'msg' => '用户等级不存在']);
        }
        return $this->success(['level' => $level]);
    }

    public function createLevel()
    {
        return json(ClientLevelService::saveLevel($this->request->param()));
    }

    public function updateLevel()
    {
        return json(ClientLevelService::saveLevel($this->request->param()));
    }

    public function deleteLevel()
    {
        return json(ClientLevelService::deleteLevel((int) $this->request->param('id', 0)));
    }

    public function clients()
    {
        return $this->success(ClientLevelService::clients($this->request->param()));
    }

    public function clientLevel()
    {
        $clientId = (int) $this->request->param('id', 0);
        $map = ClientLevelService::clientLevelMap($clientId);
        return $this->success([
            'client_level' => $map[$clientId] ?? null,
            'detail' => ClientLevelService::currentDetail($clientId),
        ]);
    }

    public function assignClient()
    {
        return json(ClientLevelService::assignClient(
            (int) $this->request->param('client_id', 0),
            (int) $this->request->param('level_id', 0),
            !empty($this->request->param('manual_lock', 1)),
            'admin_api',
            (int) $this->request->param('expires_at', 0),
            (string) $this->request->param('reason', '')
        ));
    }

    public function rebuild()
    {
        return json(ClientLevelService::rebuild((int) $this->request->param('client_id', 0)));
    }

    public function settings()
    {
        return $this->success(['settings' => ClientLevelService::settings()]);
    }

    public function saveSettings()
    {
        return json(ClientLevelService::saveSettings($this->request->param()));
    }

    public function rebateProducts()
    {
        return $this->success(ProductRebateService::products($this->request->param()));
    }

    public function saveProductRebate()
    {
        return json(ProductRebateService::saveProductEligibility(
            (int) $this->request->param('id', 0),
            (int) $this->request->param('enabled', 0)
        ));
    }

    public function logs()
    {
        return $this->success(ClientLevelService::logs($this->request->param()));
    }

    public function binds()
    {
        return $this->success(ReferralService::adminBinds($this->request->param()));
    }

    public function saveBind()
    {
        return json(ReferralService::bind(
            (int) $this->request->param('referrer_client_id', 0),
            (int) $this->request->param('invitee_client_id', 0),
            !empty($this->request->param('inherit_history', 0)),
            'admin',
            function_exists('get_admin_id') ? (int) get_admin_id() : 0
        ));
    }

    public function benefitFlows()
    {
        return $this->success(BenefitLedgerService::flows(0, $this->request->param(), true));
    }

    public function levelPolicy()
    {
        $levelId = (int) $this->request->param('id', 0);
        return $this->success(['policy' => BenefitLedgerService::levelPolicy($levelId)]);
    }

    public function saveLevelPolicy()
    {
        return json(BenefitLedgerService::saveLevelPolicy((int) $this->request->param('id', 0), $this->request->param()));
    }

    public function matureBenefits()
    {
        return json(BenefitLedgerService::processMatured((int) $this->request->param('client_id', 0), 1000));
    }

    public function halfAgentImport()
    {
        return json(ReferralService::importHalfAgent(!empty($this->request->param('execute', 0))));
    }

    private function success($data)
    {
        return json(['status' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
