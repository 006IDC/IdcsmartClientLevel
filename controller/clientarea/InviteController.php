<?php

namespace addon\idcsmart_client_level\controller\clientarea;

use addon\idcsmart_client_level\model\ReferralService;
use addon\idcsmart_client_level\model\ClientLevelService;
use app\event\controller\PluginBaseController;

class InviteController extends PluginBaseController
{
    public function invite()
    {
        $code = trim((string) $this->request->param('code', ''));
        $settings = ClientLevelService::settings();
        $defaultRedirect = ClientLevelService::normalizeInvitePath($settings['invite_default_path'] ?? '/regist.htm');
        $redirect = ClientLevelService::normalizeInvitePath(
            $this->request->param('redirect', $defaultRedirect),
            $defaultRedirect
        );
        ReferralService::captureInvite($code);
        // V10 10.4.6 的插件基类并不保证提供 redirect() 方法；直接调用会
        // 在公开邀请链接上触发 500。优先复用框架能力，并保留纯 HTML
        // 回退，确保老版本内核也只跳转到上面校验过的站内路径。
        if (method_exists($this, 'redirect')) {
            return $this->redirect($redirect);
        }
        if (function_exists('redirect')) {
            return \redirect($redirect);
        }
        return '<!doctype html><html><head><meta charset="utf-8"></head><body><script>window.location.href='
            . json_encode($redirect, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ';</script></body></html>';
    }
}
