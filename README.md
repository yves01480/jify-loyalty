# Jify Loyalty

**[下載 v0.5.1 安裝 ZIP](https://github.com/yves01480/jify-loyalty/releases/download/v0.5.1/jify-loyalty.zip)** · [版本紀錄](https://github.com/yves01480/jify-loyalty/releases) · [試用／問題回報](https://github.com/yves01480/jify-loyalty/issues/new?template=store-feedback.md)

免費開源（GPL-2.0-or-later）。已有 WordPress＋WooCommerce 商店即可在測試站開始；主機與其他服務費用另計。

## 讓完成的訂單累積點數，讓會員下次結帳能用上。

適合想在自己的 WooCommerce 商店管理會員集點與折抵的店家。點數與異動紀錄留在 WordPress 資料庫，LINE 綁定可以視需要另外啟用。

- **完成訂單自動集點：** 訂單狀態變成「已完成」後，依設定發放點數。
- **會員自己查：** 在「我的帳戶」查看餘額與近期點數異動。
- **結帳使用點數：** 設定折抵方式與兌換規則，讓會員在結帳時選用。

### 第一次試用，就走完一次集點與折抵

1. 在測試站啟用外掛，打開 **Jify Loyalty**；若已有 Jify Setting 選單，則到 **Jify Setting → Jify Loyalty**。
2. 設定「每 NT$100 累積 1 點」，先不啟用商品限制或點數上限。
3. 用已登入的測試會員購買 NT$1,000 商品，將訂單設為「已完成」。
4. 到會員帳戶確認新增 **10 點**，再依你設定的兌換規則測試下一筆訂單折抵。

**適合先驗證：** 集點、會員查詢與折抵是否符合你的商店流程。

> 目前 v0.5.1 不會在退款後自動回沖已發點數；有退款時需要人工核對、調整。LINE 需自行設定 channel credentials。這是早期公開版本，請先在測試站驗證。

### 下載後怎麼安裝

1. 下載上方 **jify-loyalty.zip**，不需解壓縮。
2. 到 WordPress **外掛 → 安裝外掛 → 上傳外掛**，選取 ZIP 後安裝、啟用。
3. 依上面的第一次試用情境設定；版本需求與完整行為請見下方英文文件。

有想套用的店家情境？[告訴我你的設定與預期結果](https://github.com/yves01480/jify-loyalty/issues/new?template=store-feedback.md)，也歡迎回報第一次安裝卡在哪一步。GitHub Issue 是公開的，請使用測試資料。

---

## English documentation

> A self-hosted WooCommerce loyalty system with automatic earning, checkout redemption, an auditable points ledger, and optional LINE Login binding.

![License: GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)
![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Stable](https://img.shields.io/badge/stable-0.5.1-orange)

Jify Loyalty is designed for WooCommerce stores that want to own their loyalty data instead of relying on a monthly SaaS subscription. It awards points when orders are completed, lets customers redeem points during checkout, and records every balance change in a dedicated audit log.

LINE Login binding is optional and targets markets where LINE is a common customer identity channel, including Taiwan, Japan, and Thailand.

## Why This Project Exists

Many WooCommerce loyalty tools bundle the store into a hosted platform or make basic point workflows difficult to inspect. Jify Loyalty keeps the core workflow inside WordPress:

- The store owns the points ledger.
- Every earn, redemption, and manual adjustment is traceable.
- Customers can redeem points without leaving checkout.
- LINE identity can be added without making it mandatory.

## Core Features

- **Automatic earning** — award points when an order reaches `completed` status.
- **Checkout redemption** — apply points as a discount with AJAX total recalculation.
- **Rule engine** — configure fixed-value, percentage, or product-based redemption rules.
- **My Account history** — show members their current balance and transaction history.
- **LINE Login binding** — map an optional LINE identity to an existing WooCommerce account.
- **Admin adjustments** — change balances manually while recording the reason.
- **Independent audit table** — store each points movement with a `reference_id`.
- **Product and taxonomy restrictions** — limit redemption to selected products, categories, or tags.

## Typical Flow

```text
Customer completes order
        ↓
WooCommerce order becomes completed
        ↓
Jify Loyalty calculates earned points
        ↓
Points transaction is written to the audit ledger
        ↓
Customer sees balance in My Account
        ↓
Customer selects a redemption option at checkout
        ↓
Points are validated again and deducted after order creation
```

## Architecture

```text
WooCommerce lifecycle hooks
├── Order completion
├── Checkout calculation
├── Checkout order creation
├── My Account endpoints
└── Thank-you page

Jify Loyalty
├── Points ledger and balance queries
├── Redemption rule evaluation
├── WooCommerce session state
├── Admin settings and adjustments
├── LINE OAuth binding
└── Audit history

WordPress database
└── Dedicated points transaction table
```

## Installation

1. Upload the plugin to `/wp-content/plugins/jify-loyalty`, or install it through the WordPress Plugins screen.
2. Activate **Jify Loyalty**. The required points table is created during activation.
3. Open **Jify Loyalty**, or **Jify Setting → Jify Loyalty** if the shared Jify menu is present.
4. Configure the earning rate, redemption rules, and optional LINE Login credentials.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- WooCommerce

## Configuration

The plugin supports two redemption approaches:

### Direct conversion

Set a cash value for each point and let members choose how many points to spend.

### Rule-based redemption

Create explicit offers such as:

```text
100 points → NT$50 discount
500 points → NT$300 discount
800 points → selected product reward
```

Rules can be restricted to selected products, categories, or tags.

## Data and Auditability

Every points movement is written as a transaction rather than storing only a mutable balance. Typical reference identifiers include order earning, checkout redemption, and administrator adjustments.

This makes it possible to:

- Reconstruct a member balance.
- Investigate disputed transactions.
- Export the ledger through database tools or WP-CLI.
- Build reporting or migration tools later without replacing the core data model.

## Current Limitations

- Refunds do not automatically reverse previously awarded points in version `0.5.1`.
- A built-in CSV export interface is not included yet.
- LINE Login requires the store owner to provide and configure their own LINE channel credentials.
- Compatibility should be verified against the store's checkout customizations and other fee-producing plugins.

## Documentation and Demo

Product documentation and the Jify plugin suite overview are available at [jify.cloud](https://jify.cloud).

The canonical WordPress plugin metadata and changelog live in [`readme.txt`](readme.txt).

## Project Status

`0.5.1` is an early public release. The plugin is suitable for evaluation and controlled store deployments, but store owners should validate earning, redemption, tax, refund, and checkout behavior in staging before production use.

## Contributing

Useful contribution areas include:

- Automatic refund reversal.
- CSV export and reporting.
- Additional identity adapters.
- WooCommerce checkout compatibility tests.
- Rule-engine validation and fixtures.
- Translations and regional documentation.

Please open an issue before proposing a large architectural change.

## Ownership

I designed and implemented the product workflow, WooCommerce integration, rule engine, points ledger, LINE binding flow, and public documentation.

AI coding tools are used in parts of the engineering workflow. Product decisions, architecture, integration, review, release criteria, and maintenance decisions remain my responsibility.

## License

GPL-2.0-or-later. See [`license.txt`](license.txt).
