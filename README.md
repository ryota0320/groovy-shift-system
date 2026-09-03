# Groovy Shift System

株式会社Groovy向けのシフト・勤怠・給与管理システムです。

確定仕様と実装計画は、リポジトリ内の次の文書を正本として管理します。

- [業務・機能要件](docs/requirements.md)
- [計算ルール](docs/calculation-rules.md)
- [データモデル](docs/data-model.md)
- [給与明細テンプレート対応](docs/payroll-template.md)
- [実装ロードマップ](docs/roadmap.md)
- [テストマトリクス](docs/test-matrix.md)
- [仕様変更履歴](docs/changelog.md)
- [設計判断記録](docs/adr/README.md)
- [所得税額表の年次更新手順](docs/income-tax-annual-update.md)

## 技術構成

- Laravel 13 / PHP 8.4
- Inertia.js 3 / React 19 / TypeScript
- Tailwind CSS 4 / Vite 8
- MySQL 8.4
- Docker Compose（PHP-FPM、Nginx、MySQL、Node/Vite、Laravel Scheduler）

## 必要なもの

- Docker Desktop（Docker Composeを含む）
- ホスト側のPHP、Composer、Node.jsは不要

## 初期セットアップ

```bash
./bin/setup
```

セットアップ後、<http://localhost:8082> を開きます。

ローカル開発用の初期管理者は次の通りです。

- メールアドレス: `ryota.i.0320@gmail.com`
- パスワード: `password`

これらは `.env` の `INITIAL_ADMIN_*` で変更できます。本番環境ではデフォルトパスワードを使用できません。公開ユーザー登録は無効です。

## 日常の開発コマンド

```bash
# 起動
docker compose up -d

# 停止（DBデータは保持）
docker compose down

# MigrationとSeeder
docker compose run --rm app php artisan migrate --seed

# PHPフォーマット・静的解析・テスト
docker compose run --rm app composer test

# フロントエンドのフォーマット・Lint
docker compose run --rm node npm run check

# TypeScript型チェック
docker compose run --rm node npm run types:check

# 本番用フロントエンドビルド
docker compose run --rm node npm run build

# 翌年分の国税庁・源泉徴収税額表を手動確認（年度省略時は翌年）
docker compose exec app php artisan income-tax:fetch-next-year 2028
```

テストはDocker内のSQLiteインメモリDBを使用し、開発用MySQLのデータには影響しません。

`scheduler`サービスは毎年8月20日から12月31日まで翌年分の国税庁月額表を自動確認します。取得資料を給与計算へ反映する作業は開発管理者だけが[年次更新手順](docs/income-tax-annual-update.md)に従って行います。

初期開発管理者でログインすると、サイドメニューの「所得税額表状況」で現在の適用年度と最新取得状態を確認できます。このメニューとURLは、設定された初期開発管理者のメールアドレスに限定されます。

ポートが重複する場合は `.env` の `APP_PORT`、`VITE_PORT`、`FORWARD_DB_PORT` を変更してください。

## 文書の更新方針

- `docs/requirements.md`を確定要件の正本とします。
- 実装都合で要件を暗黙に変更しません。
- 計算仕様の変更は`docs/calculation-rules.md`とテストを同時に更新します。
- データ構造の変更は`docs/data-model.md`と必要なADRを同時に更新します。
- 各フェーズの進捗と完了条件は`docs/roadmap.md`で管理します。
- 重要な仕様変更は`docs/changelog.md`へ理由と影響範囲を記録します。
