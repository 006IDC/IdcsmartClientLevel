# IdcsmartClientLevel

`IdcsmartClientLevel` 是 ZJMF-CBAP V10 Addon 插件，插件标识为 `idcsmart_client_level`。

当前版本 `1.6.3`。用户安装或覆盖升级后，会在用户中心菜单看到“会员等级与推广权益”，可进入等级总览、我的推广、权益分配与提现管理。

作者与维护者：`006IDC`。源码不公开维护者私人邮箱。

> 这是独立社区项目，不是智简魔方官方发布或官方维护的插件。`ZJMF-CBAP` 及其官方插件的商标与权利归各自权利人所有。

## 开源与来源边界

- 本仓库只发布可读取的插件源码、测试和开发文档，不包含 ZJMF-CBAP 核心源码、官方插件 ZIP、HalfAgent 源码或其他项目的静态资源。
- 插件只依赖宿主系统在运行时提供的 ThinkPHP、数据库、Hook、JWT 与 AES 能力；没有 Composer/NPM 运行时依赖或远程加载脚本。
- 兼容表名、模型类名和路由契约来自目标 V10 版本的可观测运行接口；开源包内不含 ionCube 载荷。
- 代码以 [MIT License](LICENSE) 发布。部署前请自行确认 ZJMF-CBAP 授权、官方提现插件及所在地区的返利/提现合规要求。
- 兼容性与第三方权利说明见 [NOTICE.md](NOTICE.md)；已淘汰的等级合并方案只保留简短历史说明，见 [docs/HISTORY.md](docs/HISTORY.md)。
- 本次公开发布的检查范围、测试结果和已知边界见 [docs/OPEN_SOURCE_AUDIT.md](docs/OPEN_SOURCE_AUDIT.md)。

## 功能

- 继续使用 V10 官方 `addon_idcsmart_client_level*` 等级、用户等级关联和商品折扣表，前台主题及 Server 模块无需改造。
- 本人累计净消费与“已锁定推广贡献”使用独立门槛分别匹配官方等级，取两条线中较高的达标等级，金额绝不相加。
- 推广客户的符合条件消费按推广人当前官方等级策略折算，经过退款观察期后由推广人选择转为可提现余额或永久纳入等级贡献。
- 后台“返利商品”直接读取官方商品列表，每个商品可独立加入或移出返利计划；活动商品关闭后不产生推广权益，但不影响购买者本人的累计消费和官方等级。
- 推广关系只允许一个当前上级，保留改绑历史、阻止自邀请和循环绑定；可安全导入 HalfAgent 推广人及绑定，但不会自动迁移旧钱包、等级和提现财务数据。
- 用户推广页从 V10 官方订单和现有权益账本实时汇总本月/今日/累计权益、直属客户、付费转化、订单、支付、退款、新购续费结构和近 7 日净消费；后台提供同最后登录 IP 和高退款比的人工复核提示，不自动封禁。
- 收款资料在插件内使用 V10 AES 能力加密保存，列表只显示掩码；冻结期结束后才把必要打款快照发布到 V10 官方提现表。
- 后台不再设单独提现审核页；官方“提现”插件统一处理通过、驳回与确认汇款，来源列显示本插件标题。
- 退款按订单累计退款额幂等冲正；未实际汇款的在途提现会自动驳回并按新净权益释放，已真实汇款的部分形成待抵扣欠额，由后续返利优先抵扣。
- 后台可分别开关本人消费与推广贡献升级通道；关闭只停止该通道授予新等级并保留现有等级，重新开启后恢复评定，退款回退始终强制执行。
- 每个官方等级继续使用 `amount` 作为本人消费门槛，并在既有等级策略表设置独立的推广贡献门槛；后台还可设置全局推广折算、贡献换算、观察期、提现冻结期、最低提现和站内邀请默认落地页。
- 后台可固定为管理员指定等级、设置到期时间和原因；固定期间仍继续累计事实，到期后按实际累计恢复自动等级。
- 通过 V10 `client_discount_by_amount` Hook 返回减免金额，并兼容 `mf_cloud`、`mf_dcim`、`kky_ecs` 等模块直接访问的官方模型和数据表契约。

## 金额与升级规则

- `discount_percent` 是“减免比例”：`10.00` 表示减免 10%，用户实付 90%。
- 财务计算使用 `DECIMAL(18,2)` 与 BCMath 字符串，不使用浮点数。
- 本人累计净消费只与消费门槛比较，已锁定推广贡献只与推广贡献门槛比较；两条线分别得到候选等级后取较高者，禁止把两项金额拼接达标。
- 官方 `client_link.cumulative_amount` 继续作为单字段模板兼容进度：保存已开启通道对应的官方等级门槛投影，不再保存两项金额之和；增强页面分别展示真实消费和推广贡献。
- 待分配权益只能选择一个去向；纳入贡献后不能转回现金，确保同一权益不被重复使用。
- 余额充值订单永不计入消费，避免充值后套取等级或推广权益。
- 部分退款、全额退款和按天退款均强制使用订单累计 `refund_amount` 冲减消费、推广权益和官方等级，不提供关闭开关或降级宽限。
- 推广权益先经过至少 14 天退款观察期，只有到期后才能分配；选择现金后提现申请再冻结至少 7 天，服务端禁止提前审核或打款。
- 订单记账按 `order_id` 幂等且并发安全地更新，退款使用订单的累计 `refund_amount`，重复或并发 Hook 不会重复扣款。
- 充值识别以 V10 核心订单 `type=recharge` 为准；普通商品订单不会因单个条目类型被整体排除。
- 商品返利资格以支付时的官方 `order_item.product_id/amount` 和逐商品开关生成快照；开关只影响后续新支付订单，不追溯已计提权益。订单级退款仍强制冲减该快照中的可返利金额。
- 邀请码并非 V10 核心 `client` 字段，而是保存在 `addon_idcsmart_client_level_referrer.invite_code`；当前单上级关系保存在 `addon_idcsmart_client_level_referral_bind`。公开链接 `/client-level/invite/{code}` 先写入 HttpOnly 邀请 Cookie，再跳转到后台配置的同站路径。

## 安装

完整的环境要求、目录结构、升级、上线检查与排错步骤见
[部署与升级说明](docs/DEPLOYMENT.md)。

GitHub Release 提供两个不同用途的文件：

- `idcsmart_client_level_v1.6.3_zjmf_v10_import.zip`：V10 正式安装包，普通用户应下载这个文件。
- `SHA256SUMS.txt`：安装包校验值，可在上传前确认文件没有损坏。

不要把 GitHub 自动生成的 “Source code” 压缩包直接上传到 V10 后台；它包含测试和开发文档，不是安装包。

1. 使用 V10 后台的“插件”上传导入包，或将 `idcsmart_client_level` 目录放入 `public/plugins/addon/`。
2. 在插件列表安装并启用 `IdcsmartClientLevel`。
3. 安装并启用 V10 官方 `IdcsmartWithdraw` 提现插件，至少保留一种官方收款方式；未启用时推广提现会失败关闭，不会只冻结不审核。
4. 从后台“应用”列表打开插件设置等级、双轴策略和返利商品。安装时只在官方等级表为空时创建一个零优惠的“普通会员”，不会覆盖现有等级或擅自开启生产折扣。
5. 对安装前已有的用户，执行一次“重算全部用户”。
6. 生产启用推广计提前先配置观察期、最低提现和各等级覆盖策略，并由财务在官方提现管理确认人工打款流程。

也可以把安装包解压到 V10 根目录的 `public/plugins/addon/`。完成后目录必须是：

```text
public/plugins/addon/idcsmart_client_level/
├── IdcsmartClientLevel.php
├── controller/
├── model/
├── public/
└── template/
```

随后仍需进入 V10 后台执行“安装”和“启用”；只复制文件不会创建表、权限和导航。

卸载不删除等级、累计消费和日志数据。再次安装会使用原数据并执行幂等表结构迁移。

## 验证

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/money_test.php
php tests/contract_test.php
node --check public/client-level-admin.js
node --check public/client-level-client.js
php tools/open_source_audit.php
```

开发源码中保留测试文件；正式安装包和签名升级包只包含运行所需文件。本仓库的 `tools/build_release.php` 可以生成不含测试和开发文档的普通首装包。

发布维护者可分别生成普通安装包和 GitHub 源码归档：

```bash
php tools/build_release.php
php tools/build_github_source.php
```

两个 ZIP 都只有一个顶层目录 `idcsmart_client_level/`。普通安装包适合上传到
V10 或解压到 `public/plugins/addon/`；GitHub 源码包额外包含测试、文档与 CI，
不包含 `dist/`、凭据、私钥或本机文件。

已安装并启用插件的 V10 测试环境可额外执行：

```bash
php public/plugins/addon/idcsmart_client_level/tests/runtime_integration.php
php public/plugins/addon/idcsmart_client_level/tests/dual_axis_runtime.php
php public/plugins/addon/idcsmart_client_level/tests/invite_link_runtime.php
```

运行时测试都在数据库事务内执行并回滚夹具。双轴测试额外覆盖两项指标不得相加、独立门槛、关闭通道保级、退款强制回退、单上级/防循环、权益分配幂等、官方提现状态机和人工固定等级到期。`invite_link_runtime.php` 覆盖邀请 Cookie 经已安装 `after_client_register` Hook 创建唯一绑定；`full_lifecycle_audit.php` 额外覆盖私有冻结期、官方待审核、官方已通过未汇款、已真实汇款、多笔在途提现和后续返利抵债。

## 兼容目标

- 目标基线为 ZJMF-CBAP 10.4.6、PHP 7.3.33、MySQL 5.7；发布结果以随包审计报告为准。
- 按 V10 10.7.x 契约使用顶层 Hook `id`、显式插件路由与 JWT 请求；实际 10.7.x 目标实例仍需在发布前运行上述集成测试，不应用 10.4.6 测试结果代替。
- 同时提供 `template/clientarea/index.html`、`pc/default/index.html` 和 `mobile/default/index.html`，并使用官方会员主题的 `aside-menu`、`top-menu`、`el-main` 页面壳层。
- PHP 需开启 BCMath 扩展。
- 持续集成使用 PHP 7.4 运行静态契约，因为当前 GitHub Actions 的 Ubuntu 运行器不再可靠提供 PHP 7.3；目标系统的 PHP 7.3.33 兼容性仍由 V10 运行时集成测试验证。
- 开源安装包不等于私有更新中心的 Ed25519 升级包。生产更新仍应由各部署方使用自己的离线密钥签名，私钥永远不应进入本仓库。

## 许可证

本项目由 `006IDC` 以 MIT License 发布。详见 [LICENSE](LICENSE)。
