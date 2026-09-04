# データモデル

- 文書状態: 実装反映済み
- 最終更新日: 2026-09-04
- 関連文書: [要件定義](requirements.md)、[計算ルール](calculation-rules.md)

本書は論理データモデルと重要制約を定義する。実際のMigrationではLaravelとMySQLの慣例に合わせて型、制約名、タイムスタンプ等を調整できるが、業務上の一意性と履歴性を失ってはならない。

## 1. 共通方針

- 主キーは`id`とする。
- 業務日付は`date`、実出退勤は`datetime`で保存する。
- 時間量は整数分、金額は整数円とする。
- 履歴マスタの`effective_from`と`effective_to`は両端を含む日付とする。
- `effective_to = NULL`は無期限を表す。
- 同一対象の適用期間重複はFormRequest/Serviceとトランザクションで防止する。
- 過去実績を失うcascade deleteを使用しない。
- 店舗、スタッフ、履歴設定は原則として物理削除しない。
- Enum相当値はPHP Enum等で一元管理し、DBとフロントへ同じ許可値を提供する。

## 2. 関係概要

```text
staffs 1 ── 0..1 users
staffs 1 ── * staff_store_assignments * ── 1 stores
staffs 1 ── * staff_wage_rates
staffs 1 ── * staff_income_tax_settings
staffs 1 ── * staff_store_transportation_fees * ── 1 stores

staffs 1 ── * shifts * ── 0..1 stores
staffs 1 ── * attendance_records * ── 1 stores
staffs 1 ── * commissions
staffs 1 ── * payrolls

stores 1 ── * store_holidays
```

## 3. 認証

### 3.1 users

| カラム            | 概要                                       |
| ----------------- | ------------------------------------------ |
| id                | 主キー                                     |
| staff_id nullable | 社員スタッフとの関連。開発管理者だけNULL可 |
| name              | ログイン利用者名                           |
| email             | ログインメールアドレス                     |
| password          | ハッシュ済みパスワード                     |
| role              | `admin` / `employee`                       |
| timestamps        | 作成・更新日時                             |

制約:

- `email` UNIQUE
- `staff_id` UNIQUE
- `role = employee`では`staff_id`必須
- 関連スタッフは`employment_type = employee`
- アルバイトにユーザーを関連付けない

ロールと関連スタッフの整合性はサーバー側でも検証する。

## 4. 店舗

### 4.1 stores

| カラム       | 概要                                     |
| ------------ | ---------------------------------------- |
| id           | 主キー                                   |
| name         | 店舗名                                   |
| opening_time | 開店時間。シフト開始時刻候補の下限に使用 |
| closing_time | 閉店時間。シフト開始時刻候補の上限に使用 |
| is_active    | 現在利用可能か                           |
| timestamps   | 作成・更新日時                           |

店舗名の重複可否は実装前に既存運用を確認し、原則として有効店舗間の同名を避ける。

### 4.2 store_holidays

| カラム       | 概要           |
| ------------ | -------------- |
| id           | 主キー         |
| store_id     | 店舗           |
| holiday_date | 店休日         |
| timestamps   | 作成・更新日時 |

制約:

- UNIQUE(`store_id`, `holiday_date`)
- INDEX(`holiday_date`, `store_id`)

## 5. スタッフと履歴マスタ

### 5.1 staffs

| カラム                | 概要                                             |
| --------------------- | ------------------------------------------------ |
| id                    | 主キー                                           |
| name                  | 旧実装互換用の氏名。氏・半角スペース・名から同期 |
| last_name             | 氏                                               |
| first_name            | 名                                               |
| display_name nullable | シフト・勤怠等で使う任意の表示名                 |
| employment_type       | `employee` / `part_time`                         |
| hired_at nullable     | 入社日                                           |
| retired_at nullable   | 退職日。当日まで在籍                             |
| timestamps            | 作成・更新日時                                   |

`employment_status`、現在時給、現在税区分、現在扶養人数は保持しない。在籍状態と現在設定は日付・履歴テーブルから導出する。

正式氏名は`last_name + 半角スペース + first_name`とする。シフト・勤怠等の業務画面では`display_name`を優先し、未設定時は正式氏名を使用する。給与明細、給与一覧、集計帳票では常に正式氏名を使用する。

検証:

- `hired_at`と`retired_at`が両方ある場合、`hired_at <= retired_at`
- `last_name`と`first_name`はアプリケーション上必須

### 5.2 staff_store_assignments

| カラム                | 概要                   |
| --------------------- | ---------------------- |
| id                    | 主キー                 |
| staff_id              | スタッフ               |
| store_id              | 店舗                   |
| effective_from        | 所属開始日             |
| effective_to nullable | 所属終了日。当日を含む |
| timestamps            | 作成・更新日時         |

制約:

- INDEX(`staff_id`, `effective_from`, `effective_to`)
- INDEX(`store_id`, `effective_from`, `effective_to`)
- 同じスタッフ×店舗の期間重複禁止

### 5.2.1 staff_store_display_orders

月間シフトのスタッフ表示順を店舗ごとに保持する。未登録のスタッフは社員ID昇順、アルバイトID昇順の基本順を使用する。

| カラム     | 概要                      |
| ---------- | ------------------------- |
| id         | 主キー                    |
| store_id   | 表示対象店舗              |
| staff_id   | スタッフ                  |
| position   | 店舗内の表示位置、0始まり |
| timestamps | 作成・更新日時            |

制約:

- UNIQUE(`store_id`, `staff_id`)
- INDEX(`store_id`, `position`)

### 5.2.2 monthly_shift_staff_additions

他店所属スタッフを月間シフトへ明示的に追加した状態を、表示店舗と対象月ごとに保持する。通常の店舗所属とは別であり、翌月へ自動継続しない。

| カラム     | 概要                              |
| ---------- | --------------------------------- |
| id         | 主キー                            |
| store_id   | 月間シフトの表示対象店舗          |
| staff_id   | 追加する他店所属スタッフ          |
| month      | 対象月の月初日                    |
| position   | 追加スタッフ内の表示位置、0始まり |
| timestamps | 作成・更新日時                    |

制約:

- UNIQUE(`store_id`, `staff_id`, `month`)
- INDEX(`store_id`, `month`, `position`)
- 対象月に在籍し、有効な店舗所属があるスタッフだけを追加可能

### 5.3 staff_wage_rates

| カラム                | 概要                    |
| --------------------- | ----------------------- |
| id                    | 主キー                  |
| staff_id              | アルバイトスタッフ      |
| hourly_wage           | 1時間当たり時給、整数円 |
| effective_from        | 適用開始日              |
| effective_to nullable | 適用終了日。当日を含む  |
| timestamps            | 作成・更新日時          |

制約:

- `hourly_wage >= 0`
- 同一スタッフの期間重複禁止
- 社員には登録しない
- INDEX(`staff_id`, `effective_from`, `effective_to`)

### 5.4 staff_store_transportation_fees

| カラム                | 概要                          |
| --------------------- | ----------------------------- |
| id                    | 主キー                        |
| staff_id              | スタッフ                      |
| store_id              | 店舗                          |
| amount_per_day        | 実勤務1日当たり交通費、整数円 |
| tax_type              | `taxable` / `non_taxable`     |
| effective_from        | 適用開始日                    |
| effective_to nullable | 適用終了日。当日を含む        |
| timestamps            | 作成・更新日時                |

制約:

- `amount_per_day >= 0`
- 同一スタッフ×店舗の期間重複禁止
- INDEX(`staff_id`, `store_id`, `effective_from`, `effective_to`)

### 5.5 staff_income_tax_settings

| カラム                | 概要                   |
| --------------------- | ---------------------- |
| id                    | 主キー                 |
| staff_id              | アルバイトスタッフ     |
| tax_category          | `ko` / `otsu`          |
| dependent_count       | 扶養親族等の人数       |
| effective_from        | 適用開始日             |
| effective_to nullable | 適用終了日。当日を含む |
| timestamps            | 作成・更新日時         |

制約:

- `dependent_count >= 0`かつ整数
- 同一スタッフの期間重複禁止
- 社員には登録しない
- INDEX(`staff_id`, `effective_from`, `effective_to`)

### 5.6 late_night_rate_settings

| カラム                | 概要                      |
| --------------------- | ------------------------- |
| id                    | 主キー                    |
| amount_per_hour       | 1時間当たり加算額、整数円 |
| effective_from        | 適用開始日                |
| effective_to nullable | 適用終了日。当日を含む    |
| timestamps            | 作成・更新日時            |

制約:

- `amount_per_hour >= 0`
- 全社設定として期間重複禁止
- INDEX(`effective_from`, `effective_to`)

## 6. シフト

### 6.1 shifts

「休」と「急な休み」を全店舗共通状態として一意に表現するため、`off`と`absence`では`store_id = NULL`とする。詳細は[ADR-0002](adr/0002-global-day-off.md)を参照する。

| カラム              | 概要                                              |
| ------------------- | ------------------------------------------------- |
| id                  | 主キー                                            |
| staff_id            | スタッフ                                          |
| store_id nullable   | `time`/`early`/`help`の勤務店舗。休暇種別ではNULL |
| shift_date          | 営業日                                            |
| shift_type          | `time` / `early` / `help` / `off` / `absence`     |
| start_time nullable | `time`だけ必須                                    |
| timestamps          | 作成・更新日時                                    |

制約:

- UNIQUE(`staff_id`, `shift_date`)
- INDEX(`store_id`, `shift_date`)
- `time`: `store_id`と`start_time`必須、時刻は00:00〜23:00の正時
- `early`: `store_id`必須、`start_time = NULL`
- `help`: 表示中店舗以外の`store_id`必須、`start_time = NULL`
- `off`: `store_id = NULL`かつ`start_time = NULL`
- `absence`: `store_id = NULL`かつ`start_time = NULL`
- 対象日にスタッフが在籍していること
- 新規登録するスタッフには対象日にいずれかの有効店舗所属があること
- `time`/`early`/`help`では対象店舗が店休日でないこと

`store_id`は画面を表示している店舗ではなく実際の勤務予定店舗を保存する。ヘルプ勤務も同じレコード構造を使用し、候補は有効な`stores`から動的に生成する。ヘルプ先・表示中店舗への`staff_store_assignments`は必須とせず、対象日にいずれかの有効店舗へ所属していれば、交代・応援要員として追加できる。

UNIQUE制約により、複数店舗勤務、勤務と休の重複、休の複数店舗重複をDB上でも防ぐ。

## 7. 勤怠

### 7.1 attendance_records

| カラム             | 概要             |
| ------------------ | ---------------- |
| id                 | 主キー           |
| staff_id           | スタッフ         |
| store_id           | 勤務店舗         |
| work_date          | 帰属営業日       |
| clock_in_at        | 実出勤日時       |
| clock_out_at       | 実退勤日時       |
| working_minutes    | 実働分数         |
| late_night_minutes | 深夜加算対象分数 |
| timestamps         | 作成・更新日時   |

制約:

- UNIQUE(`staff_id`, `work_date`)
- INDEX(`store_id`, `work_date`)
- INDEX(`staff_id`, `work_date`)
- 出退勤は15分境界
- `work_date 12:00 <= clock_in_at < work_date + 1日 12:00`
- `clock_out_at > clock_in_at`
- `clock_out_at < clock_in_at + 24時間`
- MySQLのCHECK制約でも15分境界、日時範囲、実働分数の整合性を拒否する
- `working_minutes`と`late_night_minutes`はServiceで再計算し、クライアント値を信用しない

店休日の勤怠も同じテーブルへ保存し、集計から除外しない。

## 8. 歩合

### 8.1 commissions

| カラム     | 概要               |
| ---------- | ------------------ |
| id         | 主キー             |
| staff_id   | アルバイトスタッフ |
| year       | 給与対象年         |
| month      | 給与対象月         |
| amount     | 歩合、整数円       |
| timestamps | 作成・更新日時     |

制約:

- UNIQUE(`staff_id`, `year`, `month`)
- `amount >= 0`

## 9. 所得税マスタ

国税庁の年度別月額表には固定税額区間と算式区間があるため、単純な`min_amount`/`max_amount`/`tax_amount`だけへ限定しない。公式Excel/資料を再現可能な非実行形式で保持する。

### 9.1 income_tax_table_versions

| カラム               | 概要                   |
| -------------------- | ---------------------- |
| id                   | 主キー                 |
| tax_year             | 対象年                 |
| name                 | 公式資料名             |
| source_url           | 国税庁資料URL          |
| source_hash nullable | 取込元ファイルの検証値 |
| imported_at          | 取込日時               |
| timestamps           | 作成・更新日時         |

制約:

- `tax_year` UNIQUE

### 9.2 income_tax_rules

| カラム                    | 概要                             |
| ------------------------- | -------------------------------- |
| id                        | 主キー                           |
| table_version_id          | 年度版                           |
| tax_category              | `ko` / `otsu`                    |
| dependent_count nullable  | 甲欄の扶養人数。乙欄はNULL       |
| min_amount                | 参照額下限                       |
| max_amount nullable       | 参照額上限                       |
| calculation_type          | 固定額または公式算式の識別       |
| fixed_tax_amount nullable | 固定額区間の税額                 |
| parameters nullable       | 公式算式に必要な構造化パラメータ |
| sort_order                | 評価順                           |
| timestamps                | 作成・更新日時                   |

`parameters`に任意コードや評価式を保存しない。許可した計算型ごとの数値パラメータだけを保存し、計算ロジックは`IncomeTaxCalculationService`へ実装する。

制約:

- INDEX(`table_version_id`, `tax_category`, `dependent_count`, `min_amount`)
- 同一区分の金額範囲重複禁止
- 公式資料の全範囲を網羅していることをSeederテストで検証

## 10. 給与結果

### 10.1 payrolls

| カラム                         | 概要                                     |
| ------------------------------ | ---------------------------------------- |
| id                             | 主キー                                   |
| staff_id                       | アルバイトスタッフ                       |
| year                           | 勤務対象年                               |
| month                          | 勤務対象月                               |
| payment_date                   | 翌月10日                                 |
| tax_year                       | 使用税額表年度。支給年のスナップショット |
| working_minutes                | 全店舗総勤務分数                         |
| late_night_minutes             | 全店舗深夜分数                           |
| base_pay                       | 確定基本給                               |
| late_night_pay                 | 確定深夜勤務手当                         |
| transportation_fee_total       | 交通費合計                               |
| transportation_fee_taxable     | 課税交通費                               |
| transportation_fee_non_taxable | 非課税交通費                             |
| commission                     | 歩合                                     |
| gross_pay                      | 総支給額                                 |
| taxable_pay                    | 課税対象支給額                           |
| social_insurance_deduction     | 税額表上の社会保険料等控除               |
| tax_table_reference_amount     | 税額表参照額                             |
| income_tax                     | 所得税・復興特別所得税込み               |
| other_deductions               | その他控除                               |
| total_deductions               | 総控除額                                 |
| net_pay                        | 差引総支給額                             |
| needs_recalculation            | 入力変更後の再計算要否                   |
| calculated_at                  | 最終計算日時                             |
| timestamps                     | 作成・更新日時                           |

制約:

- UNIQUE(`staff_id`, `year`, `month`)
- INDEX(`year`, `month`, `needs_recalculation`)
- 社員の給与行を作成しない
- 交通費3項目、総支給、課税対象、控除、差引額の算術整合性をServiceとテストで保証

給与結果は明細のスナップショットである。再計算時は履歴マスタから条件を再解決して上書きする。

## 11. 再計算要否の更新

給与計算への入力を変更したServiceは、影響する計算済み`payrolls`を同一トランザクション内または整合性が保証された処理で`needs_recalculation = true`にする。

対象:

- 勤怠
- 時給履歴
- 交通費・課税区分履歴
- 深夜加算額履歴
- 所得税設定履歴
- 歩合
- 所得税マスタ

勤怠削除後は元レコードが存在しないため、`updated_at`比較だけに依存せず明示的にフラグを更新する。

## 12. 推奨インデックス

前節までの制約に加え、実クエリを計測して次を調整する。

- `shifts(store_id, shift_date)`
- `shifts(staff_id, shift_date)`
- `attendance_records(store_id, work_date)`
- `attendance_records(staff_id, work_date)`
- `store_holidays(store_id, holiday_date)`
- `commissions(staff_id, year, month)`
- `payrolls(staff_id, year, month)`
- 各履歴テーブルの対象IDと適用期間

## 13. 保留ではない実装時調整

次は要件変更ではなく、Migration作成時に決定してよい。

- 主キーの整数サイズ
- EnumをDB Enum、文字列、PHP Enumのどの組み合わせで表現するか
- `parameters`の具体的な列分割
- 金額カラムの整数型サイズ
- PDFを都度生成するか一時保存するか

いずれも確定要件、計算結果、年度差替え可能性、認可を損なわないこと。
