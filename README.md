# V10 用户等级与推广权益插件（开源版）

适用于 ZJMF-CBAP V10 的用户等级与推广权益 Addon，使用独立消费和推广贡献门槛，并衔接官方提现流程。

[使用文档](docs/repository-guide.md) · [部署说明](docs/DEPLOYMENT.md) · [版本下载](https://github.com/006IDC/v10-client-level-public/releases) · [更新记录](CHANGELOG.md)

**当前发行：** [v1.6.3](https://github.com/006IDC/v10-client-level-public/releases/tag/v1.6.3)

## 主要功能

- 本人净消费与已锁定推广贡献双轴评定，门槛分别比较、不相加。
- 单上级邀请、防循环、商品返利快照与退款幂等冲正。
- 权益选择纳入贡献或提现，衔接官方 IdcsmartWithdraw 审核及汇款。
- 人工固定等级、到期恢复与推广数据汇总。

## 安装与升级

目标基线：V10 10.4.6、PHP 7.3.33、MySQL 5.7；需 BCMath 扩展。

1. 从 [Releases](https://github.com/006IDC/v10-client-level-public/releases) 下载 `idcsmart_client_level_v1.6.3_zjmf_v10_import.zip`，核对 `SHA256SUMS.txt`，通过 V10 原生流程安装并启用。
2. 保持 `IdcsmartClientLevel / idcsmart_client_level` 身份；提前配置官方提现插件与收款方式。已有用户按[部署与升级](docs/DEPLOYMENT.md)说明重算，详细配置见[使用文档](docs/repository-guide.md)。

> 普通开源安装包不等同部署方签名升级包；充值不计消费，退款强制回算。

## 版本与兼容性

- V10 10.7.x 实例须另做运行时集成测试；部署和升级要求以对应版本文档为准。
- 2026-10-22 维护了 GitHub Actions 依赖，仅更新工作流与说明，保留当前公开发行版本。完整变化见[更新记录](CHANGELOG.md)。

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护，采用 [MIT License](LICENSE)，作者与维护者为 006IDC。这是独立社区项目；第三方权利见 [NOTICE](NOTICE.md)，公开检查范围见[开源审计](docs/OPEN_SOURCE_AUDIT.md)。
