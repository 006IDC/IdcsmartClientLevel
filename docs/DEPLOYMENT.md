# 部署与升级说明

本文适用于 `IdcsmartClientLevel 1.6.3`，目标目录为 ZJMF-CBAP V10 的
`public/plugins/addon/idcsmart_client_level/`。

## 环境要求

- ZJMF-CBAP V10；已验证基线为 10.4.6。
- PHP 7.3 或更高版本，并启用 BCMath、JSON、PDO MySQL 和 OpenSSL。
- MySQL 5.7 或兼容版本，表引擎支持事务和行锁。
- 使用推广提现时，应先安装并启用 V10 的提现插件，且至少配置一种收款方式。
- 在操作真实订单前先备份数据库和现有插件目录。

示例站点统一写作 `https://example.com`。部署时应替换为自己的 HTTPS 站点，
不要把数据库密码、JWT、服务器口令或签名私钥写入插件文件。

## 方式一：V10 后台导入

1. 下载 `idcsmart_client_level_v1.6.3_zjmf_v10_import.zip`。
2. 在 V10 后台的插件管理中上传 ZIP。
3. 安装并启用 `IdcsmartClientLevel`。
4. 打开插件后台，先保存等级、双线门槛、观察期、提现冻结期和返利商品。
5. 对安装前已经存在的用户执行一次“重算全部用户”。

安装 ZIP 内只有一个顶层目录 `idcsmart_client_level/`，不是签名升级包，
不要在外层再套一层目录。

## 方式二：直接放入 plugins 目录

将安装包解压到：

```text
<V10根目录>/public/plugins/addon/
└── idcsmart_client_level/
    ├── IdcsmartClientLevel.php
    ├── controller/
    ├── model/
    ├── public/
    └── template/
```

随后在 V10 后台完成“安装”和“启用”。仅复制目录不会自动创建数据库表、
权限或导航记录。

Linux 示例：

```bash
cd /path/to/v10/public/plugins/addon
unzip /path/to/idcsmart_client_level_v1.6.3_zjmf_v10_import.zip
```

Web 服务用户需要能读取插件文件。不要把整个插件目录设置成全局可写。

## 从旧版本升级

1. 备份数据库和 `public/plugins/addon/idcsmart_client_level/`。
2. 保持目录名、插件名和命名空间不变，使用 V10 的覆盖升级流程。
3. 升级完成后再次执行一次升级动作或插件自检，确认 `upgrade()` 可重复执行。
4. 检查原等级、用户等级、推广绑定和设置仍存在。
5. 清理浏览器缓存，分别检查后台和用户中心页面。

不要通过卸载再安装来升级。`uninstall()` 默认保留业务和审计数据，但卸载会
改变插件、权限和导航状态，不是标准升级方式。

## 上线前检查

- 本人消费与推广贡献使用独立门槛，不能相加达标。
- 充值订单不计入消费；部分退款和全额退款都会强制回退相应金额与等级。
- 推广权益至少观察 14 天，提现申请至少冻结 7 天。
- 活动商品已经从返利商品列表中关闭。
- 邀请落地页是以 `/` 开头的站内路径，例如 `/regist.htm`，不是外部 URL。
- 普通用户只能看到自己的推广、权益、收款方式和提现记录。
- 提现审核只在 V10 提现管理中进行。

## 验证与排错

在源码目录执行：

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/money_test.php
php tests/contract_test.php
node --check public/client-level-admin.js
node --check public/client-level-client.js
php tools/open_source_audit.php
```

如页面修改后未生效，先确认插件版本、目录层级和文件权限，再清理 V10 及浏览器
缓存。接口错误应在后台日志中排查；不要把原始异常、数据库信息或口令展示给
普通用户。

## 卸载与数据保留

插件卸载默认不删除等级、权益、提现和审计表。需要彻底清理时，应先完成财务
对账和数据备份，再由数据库管理员按 `docs/DATABASE.md` 中的表清单处理。插件
不会在普通卸载流程中自动执行不可恢复的数据删除。
