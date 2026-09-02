# ADR-0001: ローカル開発にDocker Composeを使用する

- 状態: Accepted
- 決定日: 2026-09-03

## 背景

Laravel、React/Vite、MySQL、PDF/XLSX/PNG生成を含むため、ホストごとの差異を抑えた再現可能な開発・テスト環境が必要である。

## 決定

ローカル開発と標準の動作確認にDocker Composeを使用する。

- PHP/Laravel実行環境
- Webサーバー
- MySQL
- Node.js/Vite
- 必要に応じた開発補助サービス

Migration、Seeder、自動テスト、フロントビルド、ファイル生成をコンテナ環境から実行できるようにする。ホストへのPHPやMySQLの直接インストールを必須にしない。

## 影響

- Docker Desktop等のDocker実行環境が必要になる。
- READMEに初期化、起動、停止、テスト、ビルド手順を記載する。
- CIでも可能な範囲で同じバージョン・コマンドを使用する。
- 本番環境のコンテナ採用をこのADRだけで強制しない。
