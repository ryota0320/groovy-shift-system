# 所得税月額表データ

`2026.csv`と`2027.csv`は、国税庁が公開する「給与所得の源泉徴収税額表（月額表）」の公式Excelから生成した取込データです。手入力では編集しません。

| 税年度          | 原典                                                                            | SHA-256                                                            | ルール数 |
| --------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------ | -------: |
| 2026（令和8年） | `https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2026/data/01-07.xls`  | `50aafa072df1bb6b6aa253a021f7cc246265c3f2393f9988ee01ad121bc4f310` |    2,162 |
| 2027（令和9年） | `https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2027/data/01-07.xlsx` | `f2f331de1207ae0da6a3f416c7ad233de9411b0210a65e928c39527f1791fea5` |    2,135 |

再生成例:

```bash
docker compose exec app php scripts/generate-income-tax-csv.php \
  2026 /tmp/01-07.xls database/data/income-tax/2026.csv
docker compose exec app php scripts/generate-income-tax-csv.php \
  2027 /tmp/01-07.xlsx database/data/income-tax/2027.csv
```

投入時は`IncomeTaxTableSeeder`が件数、甲欄0〜7人・乙欄、金額範囲の連続性、公式算式パラメータを検証します。原典ファイルのSHA-256と代表値・境界値は`PayrollManagementTest`で照合します。
