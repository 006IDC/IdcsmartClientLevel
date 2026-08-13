<?php

/**
 * Installed-system invitation-cookie binding test.
 *
 * The public HTTP redirect and Set-Cookie headers are checked separately. This
 * test injects that exact cookie into the real ThinkPHP request, dispatches the
 * installed after_client_register hook, verifies the resulting bind, then
 * rolls every fixture back.
 */

$root = dirname(__DIR__, 5);
if (!is_file($root . '/config.php') && is_file('/var/www/html/config.php')) {
    $root = '/var/www/html';
}
require $root . '/config.php';
require $root . '/vendor/autoload.php';

defined('IDCSMART_ROOT') || define('IDCSMART_ROOT', $root . '/');
defined('WEB_ROOT') || define('WEB_ROOT', $root . '/public/');

$app = new \think\App();
$app->debug(APP_DEBUG);
$app->initialize();

use addon\idcsmart_client_level\model\ReferralService;
use think\facade\Db;

function inviteRuntimeAssert($condition, $message)
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

$result = [];
Db::startTrans();
try {
    $plugin = Db::name('plugin')->where('module', 'addon')->where('name', 'IdcsmartClientLevel')->find();
    inviteRuntimeAssert(!empty($plugin) && (int) $plugin['status'] === 1, 'plugin is not installed and enabled');

    $profile = Db::name('addon_idcsmart_client_level_referrer')
        ->where('status', 1)->order('id', 'asc')->find();
    inviteRuntimeAssert(!empty($profile), 'active referrer profile is missing');
    inviteRuntimeAssert(preg_match('/^[a-z0-9]{4,32}$/', (string) $profile['invite_code']) === 1, 'invite code is invalid');

    $now = time();
    $inviteeId = (int) Db::name('client')->insertGetId([
        'username' => 'invite_cookie_runtime_' . $now,
        'status' => 1,
        'email' => 'invite-cookie-runtime-' . $now . '@example.com',
        'phone_code' => 86,
        'phone' => '',
        'password' => '',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    inviteRuntimeAssert($inviteeId > 0, 'invitee fixture creation failed');

    $cookies = [ReferralService::COOKIE_NAME => (string) $profile['invite_code']];
    request()->withCookie($cookies);
    $_COOKIE[ReferralService::COOKIE_NAME] = (string) $profile['invite_code'];

    $hookResult = hook('after_client_register', ['id' => $inviteeId, 'customfield' => []]);
    $bind = Db::name('addon_idcsmart_client_level_referral_bind')
        ->where('active_invitee_id', $inviteeId)->where('status', 1)->find();

    inviteRuntimeAssert(!empty($bind), 'registration hook did not create an active referral bind');
    inviteRuntimeAssert((int) $bind['referrer_client_id'] === (int) $profile['client_id'], 'referrer client mismatch');
    inviteRuntimeAssert((int) $bind['invitee_client_id'] === $inviteeId, 'invitee client mismatch');
    inviteRuntimeAssert((string) $bind['invite_code'] === (string) $profile['invite_code'], 'stored invite code mismatch');
    inviteRuntimeAssert((string) $bind['source'] === 'invite_register', 'bind source mismatch');
    inviteRuntimeAssert((int) $bind['inherit_history'] === 0, 'new registration unexpectedly inherited history');
    inviteRuntimeAssert((int) $bind['contribution_start_time'] >= $now, 'contribution start time predates registration');

    // Replaying the same registration event must not duplicate the active bind.
    request()->withCookie($cookies);
    $_COOKIE[ReferralService::COOKIE_NAME] = (string) $profile['invite_code'];
    hook('after_client_register', ['id' => $inviteeId, 'customfield' => []]);
    $activeCount = (int) Db::name('addon_idcsmart_client_level_referral_bind')
        ->where('active_invitee_id', $inviteeId)->where('status', 1)->count();
    inviteRuntimeAssert($activeCount === 1, 'registration hook replay duplicated the active bind');

    $result = [
        'status' => 'OK',
        'plugin_version' => (string) $plugin['version'],
        'referrer_client_id' => (int) $profile['client_id'],
        'invitee_client_id' => $inviteeId,
        'source' => (string) $bind['source'],
        'inherit_history' => (int) $bind['inherit_history'],
        'active_bind_count_after_replay' => $activeCount,
        'hook_handlers' => count($hookResult),
        'transaction' => 'rollback',
    ];
} finally {
    unset($_COOKIE[ReferralService::COOKIE_NAME]);
    Db::rollback();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
