# Xserverステージング環境

- 対象URL: `https://groovy.dev-smarty.com`
- SSH接続名: `xserver-groovy`
- サーバーID: `xs662848`
- PHP: `/usr/bin/php8.4`
- Composer: `/home/xs662848/bin/composer`
- アプリケーション: `/home/xs662848/groovy-shift-system/staging`
- 公開ディレクトリ: `/home/xs662848/dev-smarty.com/public_html/groovy.dev-smarty.com`
- デプロイ元: `main`

ローカルDockerを開発環境、`groovy.dev-smarty.com`を外部公開するステージング環境として使用する。Laravel本体と`.env`は`public_html`の外に置き、公開ディレクトリには`public`配下だけを配置する。

## 1. Xserverサーバーパネル

1. `dev-smarty.com`の「サブドメイン設定」で`groovy`を追加する。
2. ドキュメントルートを`dev-smarty.com/public_html/groovy.dev-smarty.com`にする。
3. 「無料独自SSLを利用する」を有効にする。
4. `dev-smarty.com`のPHPを8.4へ変更する。
5. ステージング専用のデータベースとDBユーザーを作成し、アクセス権を付与する。

サブドメインとSSLの反映には時間がかかる場合がある。HTTPSで証明書エラーがなくなるまで実データを扱わない。

## 2. SSH

`~/.ssh/config`へ次を設定する。秘密鍵やパスフレーズはGitへ保存しない。

```sshconfig
Host xserver-groovy
  HostName xs662848.xsrv.jp
  User xs662848
  Port 10022
  IdentityFile ~/.ssh/id_ed25519
  AddKeysToAgent yes
  UseKeychain yes
```

接続を確認する。

```bash
ssh xserver-groovy
```

## 3. 初回設定

サーバーへコードを配置した後、`deploy/xserver/.env.staging.example`を基に次のファイルをサーバー上で作成する。

```text
/home/xs662848/groovy-shift-system/staging/.env
```

次の値は必ずステージング専用値へ置き換える。

- `APP_KEY`
- `DB_HOST`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `INITIAL_ADMIN_PASSWORD`

`APP_ENV=production`と`APP_DEBUG=false`は変更しない。公開環境向けのパスワード規則と安全設定を有効にするため、用途がステージングでもLaravelの実行環境は`production`とする。

初回だけ次を実行する。

```bash
ssh xserver-groovy
cd /home/xs662848/groovy-shift-system/staging
/usr/bin/php8.4 artisan key:generate
/usr/bin/php8.4 artisan migrate --seed --force
```

管理者でログインできることを確認したら、`.env`の`INITIAL_ADMIN_PASSWORD`を空にして`/usr/bin/php8.4 artisan config:cache`を再実行する。初期パスワードをサーバー上へ残さない。

## 4. デプロイ

`main`がテスト済みで作業ツリーが空であることを確認し、ローカルから実行する。

```bash
./scripts/deploy-xserver-staging.sh
```

スクリプトはフロントエンドをDockerでビルドし、Laravel本体を非公開領域、公開アセットをサブドメインのドキュメントルートへ同期する。既存の`.env`、`storage`、Basic認証を含む公開先`.htaccess`は上書きしない。Docker開発用の`public/hot`は同期対象から除外し、ステージングではビルド済みアセットを使用する。

## 5. Cron

XserverサーバーパネルのCron設定に、毎分実行で次を登録する。

```cron
cd /home/xs662848/groovy-shift-system/staging && /usr/bin/php8.4 artisan schedule:run >> /home/xs662848/groovy-shift-system/staging/storage/logs/scheduler.log 2>&1
```

現在のアプリケーションには常駐キューを必要とするJobがない。Jobを追加した段階で、Xserver向けキュー実行方法を別途設計する。

## 6. 公開制限

サーバーパネルの「アクセス制限」で`groovy.dev-smarty.com`の公開ディレクトリにBasic認証を設定する。アプリ側ログインと二重に保護し、ステージングには実在スタッフの給与・勤怠情報を登録しない。

メールは初期状態で`MAIL_MAILER=log`とし、実在アドレスへ送信しない。パスキーは正式な本番ドメインへ移行してから登録する。

## 7. 確認

```bash
curl -I https://groovy.dev-smarty.com/up
ssh xserver-groovy 'tail -n 100 /home/xs662848/groovy-shift-system/staging/storage/logs/laravel.log'
```

ブラウザではログイン、店舗、月間シフト、日別シフト、日次勤怠、給与計算、PDF、Excelの順に確認する。
