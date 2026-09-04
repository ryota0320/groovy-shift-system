# 運用・障害対応手順

- 文書状態: 運用手順
- 最終更新日: 2026-09-04

## 1. 日常確認

```bash
docker compose ps
docker compose logs --tail=200 app scheduler web
docker compose exec app php artisan about
```

`app`、`web`、`db`、`node`、`scheduler`が起動していることを確認する。画面が表示されない場合は、最初に`web`と`app`のログを確認する。所得税額表の年次取得は、開発管理者でログインし「所得税額表状況」から最終確認日時と取得状態を確認する。

同じ作業ディレクトリから、異なる`VITE_PORT`を指定した複数の`node`サービスを同時に起動しない。Viteの接続先を示す`public/hot`が共有され、停止済み環境のポートが画面へ残る場合がある。背景だけが表示される場合は、通常環境の`docker compose restart node`で接続先を復旧してからブラウザを再読み込みする。

## 2. バックアップ

更新作業やMigrationの前にMySQLをバックアップする。

```bash
mkdir -p backups
docker compose exec -T db sh -c 'mysqldump --single-transaction -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > backups/groovy_shift_YYYYMMDD_HHMMSS.sql
```

バックアップファイルはリポジトリへコミットせず、アクセス制限と世代管理を行った保存先へ移す。給与・勤怠を含むため、メールや公開ストレージへ無保護で置かない。

## 3. 復元

復元は現在のDB内容を上書きする。対象環境とバックアップファイルを確認し、利用停止中に実行する。

```bash
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < backups/groovy_shift_YYYYMMDD_HHMMSS.sql
docker compose exec app php artisan optimize:clear
```

復元後はログイン、店舗、当月シフト、日次勤怠、給与・集計の順に確認する。

## 4. アプリケーション更新

Xserverステージング環境への配置は[Xserverステージング環境](xserver-staging-deployment.md)に従う。

1. DBバックアップを取得する。
2. 更新対象のコミットを取得する。
3. 次をDocker上で実行する。

```bash
docker compose build app scheduler node
docker compose run --rm app composer install --optimize-autoloader --no-interaction
docker compose run --rm node npm ci
docker compose run --rm node npm run build
docker compose run --rm app php artisan migrate --force
docker compose up -d --force-recreate app scheduler web
docker compose exec app php artisan optimize:clear
```

更新後は`/up`、ログイン、ダッシュボード、日別シフト、日次勤怠、ファイル出力を確認する。
本番用イメージを別途作成する場合だけ、開発依存を含めない`composer install --no-dev`をビルド工程で使用する。

## 5. ファイル生成エラー

画面の「再試行」を押す前に、対象年月・店舗、給与の計算済み状態、再計算必要状態を確認する。解決しない場合は次のログを確認する。

```bash
docker compose logs --since=15m app web
docker compose exec app tail -n 200 storage/logs/laravel.log
```

画面には内部例外を表示しない。調査には発生日時、操作した画面、店舗、対象年月、スタッフを記録し、給与額や個人情報を一般公開の連絡先へ貼り付けない。

## 6. 本番環境の必須設定

- `APP_ENV=production`、`APP_DEBUG=false`にする。
- `APP_KEY`とDBパスワードを環境ごとに安全に生成し、リポジトリへ保存しない。
- `INITIAL_ADMIN_PASSWORD=password`を使用しない。
- HTTPSを必須にし、リバースプロキシ側でもHTTPからHTTPSへ転送する。
- DBとバックアップを外部公開せず、復元テストを定期的に行う。
- `scheduler`を常時起動し、所得税額表の取得状況を年末まで確認する。

所得税額表の正式反映は[所得税額表の年次更新手順](income-tax-annual-update.md)に従う。自動取得された資料を未検証のまま給与計算へ適用しない。
