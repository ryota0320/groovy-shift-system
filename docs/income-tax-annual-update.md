# 所得税額表の年次更新手順

- 対象者: 開発管理者
- 最終更新日: 2026-09-03

本手順は、国税庁「給与所得の源泉徴収税額表（月額表）」の翌年分を取得し、給与計算へ安全に反映するためのものです。通常の業務利用者が操作する画面は設けません。

`ryota.i.0320@gmail.com`の開発管理者でログインすると、サイドメニューの「所得税額表状況」から現在年度、投入済み年度、翌年分の取得状態、最終確認日時、取得エラーを閲覧できます。この画面は確認専用で、税額表の取込や承認は行いません。

## 安全方針

- 毎年8月20日から12月31日まで、Dockerの`scheduler`サービスが翌年分の公開状況を毎日06:10（Asia/Tokyo）に確認します。
- 取得元は`https://www.nta.go.jp`配下の対象年度ページと`01-07.xls`または`01-07.xlsx`だけを許可します。
- Excelとして開けること、月額表の主要列に十分な数値行があること、ファイルサイズが上限内であることを保存前に検証します。
- 同じSHA-256のファイルは再保存しません。国税庁側のファイルが差し替わった場合は、別リビジョンとして保存します。
- 自動取得したファイルは「開発管理者の確認待ち」であり、DBや給与計算へ自動投入しません。
- 必要年度を承認・投入するまで、アプリケーションは前年データで代用せず、対象年度の給与計算を明示的エラーにします。

## 自動取得の確認

Docker Compose起動時は`scheduler`も起動します。

```bash
docker compose up -d
docker compose ps scheduler
docker compose logs scheduler
```

新しい資料を取得すると、`storage/logs/laravel.log`へ通知を記録し、次のローカル専用ディレクトリへ保存します。

```text
storage/app/private/income-tax/sources/{年度}/
├── {SHA-256}.xls または {SHA-256}.xlsx
├── {SHA-256}.json
└── latest.json
```

このディレクトリはGit管理対象外です。JSONには公式URL、SHA-256、取得日時、確認待ち状態を記録します。

## 手動実行

自動確認を待たずに実行する場合、年度を指定します。年度を省略すると実行日の翌年が対象です。

```bash
docker compose exec app php artisan income-tax:fetch-next-year 2028
docker compose exec app php artisan income-tax:fetch-next-year
```

結果は次のいずれかです。

- 未公開: 何も保存せず正常終了
- 変更なし: 取得済みファイルとSHA-256が一致
- 新規取得: ローカルへ保存し、開発管理者の確認待ち
- 取得・検証エラー: 何も給与計算へ反映せず異常終了

いずれの結果も年度別の`status.json`へ最終確認日時とともに保存され、「所得税額表状況」画面へ反映されます。

## 給与計算へ反映する手順

新規取得後は、以下を同じ変更単位で実施します。

1. `latest.json`の公式ページURL、Excel URL、SHA-256を確認する。
2. 国税庁の説明ページで対象年、甲欄、乙欄、扶養人数、金額境界、高額給与帯の算式変更を確認する。
3. `scripts/generate-income-tax-csv.php`の年度別設定と表構造検証を、新年度の公式表に合わせて追加または修正する。
4. 取得したExcelから`database/data/income-tax/{年度}.csv`を生成する。
5. `database/seeders/IncomeTaxTableSeeder.php`へ年度、正式名称、公式URL、SHA-256、期待ルール件数を追加する。
6. 代表値、全境界、甲欄0〜7人、乙欄、高額給与帯、年跨ぎ支給日のテストを追加・更新する。
7. DockerでSeeder、全PHPテスト、静的解析を実行する。
8. CSV、Seeder、テスト、文書をレビューし、問題がなければコミットしてデプロイする。

生成例（パスと拡張子は`latest.json`の値を使用）:

```bash
docker compose exec app php scripts/generate-income-tax-csv.php \
  2028 \
  storage/app/private/income-tax/sources/2028/{SHA-256}.xlsx \
  database/data/income-tax/2028.csv

docker compose exec app php artisan db:seed --class=IncomeTaxTableSeeder
docker compose exec app composer test
```

税額表のレイアウトや公式算式が変わった場合は、既存年度へ無理に当てはめず、変換処理と検証を更新します。取得ファイルだけを直接DBへ投入する運用は禁止します。

## 異常時対応

- 9月末までに翌年分を取得できない場合は、国税庁の対象年ページを開発管理者が直接確認します。
- HTTPエラー、リンク構成変更、Excel検証エラーが出た場合は、取得元URLとページ構造を確認し、検証を弱めずに実装を更新します。
- 公式ファイルのSHA-256が以前の値から変わった場合は、差し替え理由と表内容の差分を確認してから新しい値を採用します。
- 更新が年末までに完了しない場合、翌年支給分の給与計算を開始しません。前年分の税額表で代替しません。
