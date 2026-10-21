# V10 用户等级与推广权益插件

## 产品介绍

V10 用户等级与推广权益 Addon，使用独立消费和推广贡献门槛并衔接官方提现流程。

## 版本概览

| 项目 | 公开说明 |
| --- | --- |
| 公开发行版本 | [v1.6.3](https://github.com/006IDC/IdcsmartClientLevel/releases/tag/v1.6.3) |
| 适用环境 | 目标基线 V10 10.4.6 / PHP 7.3.33 / MySQL 5.7；需 BCMath |
| 使用边界 | 10.7.x 实例须另做运行时集成测试；充值不计消费，退款强制回算。普通开源安装包不等同部署方签名升级包。 |

版本以本仓公开 Release 为准；完整技术说明见[运行与维护说明](docs/repository-guide.md)。

## 主要功能

- 本人净消费与已锁定推广贡献双轴评定，门槛分别比较、不相加。
- 单上级邀请、防循环、商品返利快照与退款幂等冲正。
- 权益选择纳入贡献或提现，衔接官方 IdcsmartWithdraw 审核及汇款。
- 人工固定等级、到期恢复与推广数据汇总。

## 使用与升级

从 [Releases](https://github.com/006IDC/IdcsmartClientLevel/releases) 下载 `idcsmart_client_level_v1.6.3_zjmf_v10_import.zip` 并核对 `SHA256SUMS.txt`。保持 `IdcsmartClientLevel / idcsmart_client_level` 身份，通过 V10 原生安装和启用；官方提现插件及收款方式应先配置。已有用户需按说明重算，详细步骤见[部署与升级](docs/DEPLOYMENT.md)及[技术说明](docs/repository-guide.md)。

## 版本演进

版本变化见[变更记录](CHANGELOG.md)。适用环境和部署检查以对应版本文档为准。

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。采用 [MIT License](LICENSE)，作者与维护者为 006IDC。这是独立社区项目；第三方权利见 [NOTICE](NOTICE.md)，公开检查范围见[开源审计](docs/OPEN_SOURCE_AUDIT.md)。
