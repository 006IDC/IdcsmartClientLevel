# V10 用户等级与推广权益插件 · 使用说明

为 ZJMF-CBAP V10 提供会员等级、推广关系、权益分配与官方提现衔接。

[产品首页](../README.md) · [部署说明](DEPLOYMENT.md) · [版本下载](https://github.com/006IDC/v10-client-level-public/releases) · [更新记录](../CHANGELOG.md)

## 使用前确认

- 当前公开发行：`1.6.3`；稳定身份为 `IdcsmartClientLevel / idcsmart_client_level`。
- 目标基线为 V10 10.4.6、PHP 7.3.33、MySQL 5.7，需 BCMath。完整环境要求见[部署说明](DEPLOYMENT.md)。
- 10.7.x 使用顶层 Hook `id`、显式插件路由与 JWT 契约，目标实例仍需运行集成测试，不能用 10.4.6 的结果代替。
- 推广提现需要启用官方 `IdcsmartWithdraw` 并配置至少一种收款方式；缺失时提现失败关闭，不会仅冻结后绕过审核。
- 这是独立社区项目。部署前确认 V10 授权、官方提现插件与当地返利/提现合规要求。

## 安装与首次配置

1. 下载 `idcsmart_client_level_v1.6.3_zjmf_v10_import.zip`，用 `SHA256SUMS.txt` 核对文件。GitHub 自动 Source code ZIP 不是安装包。
2. 按[部署说明](DEPLOYMENT.md)后台导入，或把 `idcsmart_client_level/` 放入 `public/plugins/addon/`；随后在后台安装并启用。仅复制文件不会创建表、权限与导航。
3. 配置官方提现插件与收款方式，再从后台“应用”进入本插件，设置等级、双轴门槛与返利商品。
4. 配置推广折算、贡献换算、观察期、提现冻结期、最低提现和同站邀请落地页；已有用户执行一次“重算全部用户”。
5. 启用推广计提前，由财务在官方提现管理确认审核及人工打款流程。安装仅在官方等级表为空时创建零优惠的“普通会员”，不会覆盖现有等级或自行开启生产折扣。

用户中心入口为“会员等级与推广权益”，包括等级总览、我的推广、权益分配和提现管理。

## 日常使用流程

1. **消费与升级**：本人净消费和已锁定推广贡献分别匹配门槛，取两条线中较高的达标等级，两项金额不相加。
2. **邀请与归属**：每名用户只有一个当前上级，保留改绑历史并阻止自邀请、循环绑定。可导入 HalfAgent 推广人和绑定，不自动迁移旧钱包、等级或提现财务数据。
3. **权益分配**：符合条件的推广消费按推广人当前官方等级策略折算；至少观察 14 天后，选择转为可提现余额或永久纳入等级贡献。同一权益只能选择一个去向，纳入贡献后不能再转现金。
4. **申请与审核**：现金提现申请至少冻结 7 天，服务端禁止提前审核或打款；审核、驳回和确认汇款统一在官方提现插件进行，本插件不另设提现审核页。
5. **查看推广**：用户可查看本月、今日与累计权益、直属客户、付费转化、订单/支付/退款、新购续费结构和近 7 日净消费。后台的同最后登录 IP、高退款比仅作人工复核提示，不自动封禁。

## 金额与退款规则

- 金额使用 `DECIMAL(18,2)` 与 BCMath 字符串，不使用浮点数。`discount_percent` 是减免比例：`10.00` 表示减免 10%，实付 90%。
- 余额充值不计消费。以核心订单 `type=recharge` 识别充值，普通商品订单不会因单个条目类型被整体排除。
- 支付时以官方 `order_item.product_id/amount` 和逐商品返利开关保存快照。开关只影响后续新支付订单，不追溯既有权益；关闭返利商品不影响购买者本人消费或官方等级。
- 部分、全额与按天退款均按订单累计 `refund_amount` 幂等冲正消费、权益和等级，不提供关闭开关或降级宽限。重复或并发 Hook 不重复扣款。
- 未实际汇款的在途提现自动驳回并按新净权益释放；已真实汇款部分形成待抵扣欠额，由后续返利优先抵扣。
- 后台可独立开关消费与推广升级通道。关闭只停止该通道授予新等级并保留现有等级，重开后恢复评定，退款回退始终执行。
- 可人工固定等级并设置到期时间与原因；期间继续累计事实，到期后恢复自动等级。
- 收款资料使用 V10 AES 加密，列表只显示掩码；冻结期结束后才向官方提现表发布必要打款快照。

## 兼容与升级边界

- 复用官方 `addon_idcsmart_client_level*` 等级、用户关联及商品折扣表。本人门槛继续使用 `amount`，推广贡献使用独立策略门槛；前台主题与 Server 模块无需为此另建等级体系。
- `client_link.cumulative_amount` 仅保存已开启通道的官方等级门槛投影，供单字段模板兼容；增强页面分别显示真实消费和推广贡献，不将两项金额相加。
- 通过 `client_discount_by_amount` Hook 返回减免金额，兼容 `mf_cloud`、`mf_dcim`、`kky_ecs` 等模块直接访问官方模型和表的契约。
- 提供 `template/clientarea/index.html`、`pc/default/index.html`、`mobile/default/index.html`，沿用官方会员主题 `aside-menu`、`top-menu`、`el-main` 壳层。
- 邀请码位于 `addon_idcsmart_client_level_referrer.invite_code`，单上级关系位于 `addon_idcsmart_client_level_referral_bind`。`/client-level/invite/{code}` 先写 HttpOnly Cookie，再跳转到后台配置的同站路径。
- 卸载默认保留等级、累计消费、权益、提现与日志数据；正常升级不卸载重装，再安装会使用原数据并执行幂等迁移。
- 普通开源安装包不等于部署方 Ed25519 签名升级包。部署方离线签名私钥不得进入仓库。

## 开发与验证

仅在源码目录和隔离测试实例中执行，不对真实订单或生产数据库运行夹具测试。

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/money_test.php
php tests/contract_test.php
node --check public/client-level-admin.js
node --check public/client-level-client.js
php tools/open_source_audit.php
php tools/build_release.php
php tools/build_github_source.php
```

普通安装包仅含运行文件；源码归档额外包含测试、文档与 CI。两个 ZIP 均以 `idcsmart_client_level/` 为唯一顶层目录，不包含 `dist/`、凭据、私钥或本机文件。当前 CI 用 PHP 7.4 运行静态契约，目标 PHP 7.3.33 的运行兼容性仍需集成验证。

<details>
<summary>已安装插件的 V10 隔离环境：运行时检查</summary>

```bash
php public/plugins/addon/idcsmart_client_level/tests/runtime_integration.php
php public/plugins/addon/idcsmart_client_level/tests/dual_axis_runtime.php
php public/plugins/addon/idcsmart_client_level/tests/invite_link_runtime.php
```

这些测试在事务内执行并回滚夹具，覆盖双轴不得相加、独立门槛、关闭通道保级、退款回退、单上级/防循环、分配幂等、官方提现状态机及固定等级到期。邀请测试覆盖 Cookie 经 `after_client_register` Hook 形成唯一绑定；`tests/full_lifecycle_audit.php` 另覆盖冻结、官方待审核、已通过未汇款、真实汇款、多笔在途提现和后续返利抵债。隔离测试不能替代备份与目标部署验收。

</details>

## 来源与许可

本仓库不含 V10 核心、官方插件 ZIP、HalfAgent 源码、其他项目静态资源或 ionCube 载荷。运行时复用宿主 ThinkPHP、数据库、Hook、JWT 与 AES，没有 Composer/NPM 运行时依赖或远程加载脚本。

作者与维护者为[零零陆云计算（006IDC）](https://github.com/006IDC)，采用 [MIT License](../LICENSE)，并非智简魔方官方项目。第三方权利与兼容说明见 [NOTICE](../NOTICE.md)，已淘汰等级合并方案见[历史说明](HISTORY.md)，公开检查范围见[开源审计](OPEN_SOURCE_AUDIT.md)。
