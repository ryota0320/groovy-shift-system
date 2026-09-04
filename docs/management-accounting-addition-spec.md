# 株式会社Groovy 店舗運営管理機能 追加実装仕様書

## 売上・仕入・経費・FL・PL・店舗KPI追加 v1.1

- 最終更新: 2026-09-04
- v1.1: 追加確認事項、端数規則、過去店舗、実装フェーズを確定
- Git管理上の正本: `docs/management-accounting-addition-spec.md`

## 0. 目的

既存のシフト・勤怠・給与管理システムへ、現行Excelで管理している売上・仕入・経費・FL・PL・店舗KPIを追加する。既存システムを作り直さず、`stores`、営業日、認証、選択店舗、勤怠、給与、人件費集計、Inertiaレイアウト、出力基盤を再利用する。

売上・仕入・経費・FL・PLは既存Workforceとは別のManagement
Accountingドメインとして追加し、既存`MonthlyAggregationService`へ全ロジックを詰め込まない。

## 1. 技術・既存前提

既存技術を継続利用する。 - PHP 8.4 / Laravel 13 - React 19 / TypeScript
/ Inertia.js 3 / Tailwind CSS 4 - MySQL 8.4 - Dompdf / PhpSpreadsheet /
PHP GD - PHPUnit / Vitest

再利用対象: - `stores` - `SelectedStoreService` -
`BusinessDateService` - `attendance_records` - `payrolls` -
既存勤怠/人件費集計 - Laravel認証/セッション - Inertia共通レイアウト -
適用期間管理パターン

時間は整数分、金額は整数円を基本とする。

## 2. Excelから抽出した業務構造

``` text
日次売上 ─┐
勤怠給与 ─┼→ 日次KPI/FL → 月次PL
仕入 ─────┤
経費 ─────┤
社員人件費┘
```

Excelの壊れた参照、`#REF!`、`#DIV/0!`、不自然なセル参照は再現せず、業務ルールを再設計する。

## 3. 確定した会計・KPI方針

-   売上は税込管理。
-   初期版PL、人件費率、原価率、FL率の分母も税込売上。
-   初期版のFは「食材仕入+ドリンク仕入」の簡易原価。棚卸を含む正式売上原価は将来拡張。
-   Lはアルバイト人件費+社員人件費。
-   アルバイト人件費は既存勤怠から自動取得。
-   社員人件費は店舗×月で総額を手入力。
-   社員人件費は店舗営業日数で均等配賦し日別FLへ加算。
-   店舗別PLと全店舗合算PLの両方を出す。
-   店舗間移動は全店舗PLで二重計上しない。
-   歩合は給与計算には含めるが、日次FL・月次PLのLには含めない。給与支給額と経営管理上のLが一致しない場合があることは意図した仕様とする。
-   初期目標:
    人件費20%、食材13%、ドリンク7%、F20%、FL40%。店舗×適用期間で変更可能。

社員人件費日割りは月額との整合を必ず保つ。例:
`base=floor(月額/営業日数)`、余りを営業日の先頭から1円ずつ配賦し、日別合計=月額とする。

## 4. 権限

ロールを`developer / owner / employee`へ整理する。

### developer

全機能・全データ・開発専用機能を利用可能。

### owner

開発専用機能以外すべて利用可能。

### employee

開発専用機能以外は原則すべて利用可能。アルバイトの時給、交通費、歩合、税設定、給与、給与明細も閲覧・操作可能。
制限するのは「他社員個人のセンシティブ情報」のみ。

employeeが他社員について閲覧可: - 氏名、所属、シフト、勤怠、勤務時間

閲覧不可: - 個人給与/月給 - 個人別社員人件費 - 個人税情報/扶養 -
個別待遇情報

店舗単位の社員人件費合計、FL、PL、全店舗PLはemployeeも閲覧可能。

他社員のログインメールアドレス管理、アカウント作成・削除、アカウント関連設定はdeveloper/ownerだけに許可し、employeeには許可しない。employeeはアルバイト情報については既存仕様どおり広く閲覧・操作できる。

画面非表示だけでなくLaravel側でも認可する。Gate/Policy等で最低限:
`development.manage`, `workforce.manage`,
`staff_sensitive_employee.view`, `payroll.manage`, `sales.manage`,
`purchases.manage`, `expenses.manage`, `management_kpi.view`,
`management_pl.view`, `management_targets.manage`を整理する。

## 5. 新規ドメイン境界

``` text
既存 Workforce
Shift / Attendance / Payroll / Labor Aggregation
             │ store_id / business_date / month
             ↓
新規 Management Accounting
Sales / Purchases / Expenses / EmployeeLaborCost / Targets
             ↓
KPI / FL / PL
```

既存勤怠テーブルへ売上・仕入・経費カラムを追加しない。

## 6. 日次売上

1店舗×1営業日につき1件。

`daily_sales` - id - store_id FK - business_date date - customer_count
unsigned int - gross_sales_amount unsigned bigint - notes nullable -
created_by / updated_by - timestamps - unique(store_id,business_date)

決済方法はマスタ化する。

`sales_payment_methods` - id, name, code, display_order, is_active,
timestamps

初期値: - cash 現金 - credit_card クレジット - meal_ticket_point
お食事券・ポイント - invoice 請求書払い - paypay PayPay - other_qr
その他QR - other その他

`daily_sales_payments` - id - daily_sale_id - payment_method_id -
amount - timestamps - unique(daily_sale_id,payment_method_id)

`gross_sales_amount = Σ決済別金額`とし、決済合計と売上合計が不一致になる二重入力を避ける。

客単価: `gross_sales_amount / customer_count`。
客数0ならnull、画面は`—`。

### 日次売上画面

店舗、営業日、前日/翌日、客数、決済別売上、備考を入力。売上合計・客単価は自動。
スマホは縦カード形式。

### 月間売上一覧

営業日、曜日、客数、売上、客単価、決済別金額を一覧。月間総売上、月間客数、平均客単価、営業日数、平均日商を表示。

平均日商は`月間売上合計 / 売上入力済み日数`とする。売上入力済み日数には売上0円の`daily_sales`も含め、レコードがない日は含めない。画面には平均日商、営業日数、売上入力済み日数、売上未入力日数を併記する。

売上未入力日数の判定対象は、過去月では対象月の全営業日、現在月では月初から現在営業日までとする。未来日は未入力へ含めず、未来月の入力済み・未入力日数は原則0件とする。

## 7. 現金増減

Excelの考え方を再現:
`cash_net_increase = cash_sales - cash_purchase_payments - cash_expense_payments`
これはPL利益とは別指標として明示する。

## 8. 仕入先

`suppliers` - id, name, is_active, notes nullable, timestamps

全店舗共通マスタ。

## 9. 仕入区分

`purchase_categories` - id, name, code, management_category,
display_order, is_active, timestamps

初期値: - food 食材 - drink ドリンク - other その他仕入

Fへ含めるのはfood/drink。otherはFへ含めず、月次PLで「その他仕入」として費用控除する。厨房備品、掃除用品、文房具、消耗品等の備品は仕入として管理せず、expensesへ登録する。

## 10. 仕入

`purchases` - id - store_id - business_date - supplier_id nullable -
purchase_category_id - amount - payment_method - notes nullable -
created_by / updated_by - timestamps

初期版は税込金額。
計上日は納品/仕入が発生した営業日。請求書日・カード引落日とは分離。

支払方法: cash / credit_card / invoice / bank_transfer / other

店舗×日で食材、ドリンク、その他仕入を集計。
店舗×月で日別・区分別・仕入先別を確認可能。

## 11. 店舗間移動

外部仕入と分離する。

`inventory_transfers` - id - business_date - from_store_id -
to_store_id - purchase_category_id - amount - notes nullable -
created_by / updated_by - timestamps

同一店舗間は禁止。

店舗別管理では移動元をマイナス、移動先をプラスとして配賦可能にする。
全店舗合算では内部移動を相殺し、会社全体の仕入/原価を増減させない。

## 12. 経費科目

`expense_categories` - id - name - code - display_order - is_active -
timestamps

初期値は現行Excelを基準: - 消耗品費 - 衛生費 - 租税公課 - 福利厚生費 -
販売促進費 - 旅費交通費 - 通信費 - 水光熱費 - その他

厨房備品、掃除用品、文房具、消耗品等は適切な経費科目を選択してexpensesへ登録し、purchasesへ重複登録しない。

社員が追加・編集可能（developer/owner/employeeに開放）。

## 13. 経費

`expenses` - id - store_id - business_date - expense_category_id -
amount - payment_method - description nullable - notes nullable -
created_by / updated_by - timestamps

初期版は税込。 計上日は経費が発生した営業日。カード引落日ではない。
支払方法はcash / credit_card / bank_transfer / invoice / other。

初期版ではレシート/請求書画像アップロードは必須にしない。将来添付可能な設計を妨げない。

## 14. 固定費

毎月繰り返す家賃等を毎月手入力せず自動反映できるようにする。

`recurring_expense_rules` - id - store_id - expense_category_id - name -
amount - effective_from - effective_to nullable - is_active - timestamps

例: - 家賃 - 通信費 - システム利用料 - 広告費 - 保険 - ゴミ回収等

固定費設定から毎月expensesレコードを自動生成しない。月次PL計算時に対象月1日時点で有効な固定費設定を直接加算する。`effective_from`/`effective_to`は月単位で入力し、DBでは各月1日へ正規化したdateとして保持する。開始月・終了月はともに適用対象とする。

例として、家賃300,000円、effective_from=2026-09の場合、2026年9月以降の各月PLへ300,000円を加算する。同一店舗・同一固定費の適用期間重複は禁止する。

初期版の日次FLには固定費を含めない。FLR/FLRA等を将来追加する場合に日割り配賦できる設計を残す。

## 15. 社員人件費

個人別社員給与は新規管理しない。 店舗×月の総社員人件費のみ入力する。

`store_monthly_employee_labor_costs` - id - store_id - year - month -
amount - notes nullable - created_by / updated_by - timestamps -
unique(store_id,year,month)

この総額はemployeeも閲覧可能。個人別内訳は存在させない。

### 日次配賦

その月の店舗営業日へ均等配賦。 店休日には配賦しない。
配賦合計は必ず月額と一致させる。

営業日数が0日で月額が登録されている場合、月次PLには全額を計上し、日次KPIへの配賦額は全日0円とする。画面に「配賦可能な営業日がありません。社員人件費は月次PLにのみ計上されています。」と警告する。

## 16. 営業日判定

日次KPIと社員人件費配賦で営業日定義を共通化する。

推奨`StoreBusinessDayService`: - 店休日なら非営業日 -
売上/勤怠/仕入等の実績がある場合の例外を扱える -
月の営業日一覧を一箇所で返す

店休日に実勤務がある既存仕様を考慮し、「店休日=絶対に実績が存在しない」と仮定しない。
社員人件費配賦の営業日数については原則「店休日以外の日」とする。

店休日でも売上・仕入・経費は完全禁止しない。画面とLaravel側の`holiday_confirmed`相当の確認値で「この店舗は店休日です。データを登録しますか？」と確認し、承認後は通常どおり保存してKPI/PLへ含める。社員人件費の日次配賦については、実績の有無にかかわらず店休日を配賦対象にしない。

## 17. KPI目標

店舗×適用期間で変更可能。

`store_management_targets` - id - store_id - labor_cost_rate_target
decimal(5,2) - food_cost_rate_target decimal(5,2) - drink_cost_rate_target
decimal(5,2) - effective_from - effective_to nullable - timestamps

初期値: L 20% Food 13% Drink 7% F 20% FL 40%

FとFLはFood+Drink、F+Lから算出してもよく、重複保存を避ける。

率は20%を20.00として保存し、画面は小数第2位まで表示する。F目標率はfood+drink、FL目標率はlabor+food+drinkから算出し、独立カラムへ重複保存しない。

## 18. 日次人件費L

日次L:

``` text
既存アルバイト日別人件費
+ 日割り社員人件費
```

アルバイト側は既存勤怠を正とする。
既存集計には基本給相当+深夜+交通費があるため、それを利用する。

歩合は日次FL・月次PLのLへ含めない。給与計算には従来どおり含める。

## 19. 日次F

初期版:

``` text
food_cost = 当日食材仕入 ± 店舗間移動
drink_cost = 当日ドリンク仕入 ± 店舗間移動
F = food_cost + drink_cost
```

その他仕入・備品・経費はFへ含めない。その他仕入は日次F/FLには含めず、月次PLで別費用として控除する。

## 20. 日次KPI計算

売上\>0の場合:

``` text
labor_cost_rate = L / gross_sales * 100
food_cost_rate = food_cost / gross_sales * 100
drink_cost_rate = drink_cost / gross_sales * 100
food_total_rate = F / gross_sales * 100
FL_amount = F + L
FL_rate = FL_amount / gross_sales * 100
```

売上=0の場合、率は0ではなくnull。画面は`—`。

目標差額:

``` text
target_labor_amount = floor(sales * labor_target / 100)
labor_variance = target_labor_amount - actual_L

target_food_amount = floor(sales * food_target / 100)
food_variance = target_food_amount - actual_food

target_drink_amount = floor(sales * drink_target / 100)
drink_variance = target_drink_amount - actual_drink

target_FL_amount = floor(sales * FL_target / 100)
FL_variance = target_FL_amount - actual_FL
```

差額は`目標金額 - 実績金額`とする。正の差額=目標内、0=目標どおり、負=超過。実績率は小数第3位を四捨五入し、小数第2位まで表示する。売上0円または売上未入力の場合、率と目標金額・差額はnullとし、画面は`—`とする。

## 21. 日次KPI画面

URL例:`/management/kpi/daily`

表示: - 店舗 - 営業日 - 売上 - 客数 - 客単価 - AB人件費 -
社員人件費日割り - L合計 - 人件費率 - 食材仕入 - 食材率 - ドリンク仕入 -
ドリンク率 - F - F率 - FL - FL率 - 各目標 - 各差額 - 現金売上 -
現金仕入/経費 - 現金増加

employeeにも全表示可。社員人件費は店舗合計のみ。

## 22. 経営ダッシュボード

既存Dashboardとは分離またはタブ追加。

店舗×期間についてカード表示: - 今日/当月売上 - 客数 - 客単価 - L率 -
F率 - FL率 - 目標との差 - 月間経費 - 当月累計営業利益

初期版では予測計算を行わない。当月累計営業利益は次で算出する。

``` text
現在営業日までの売上
- 現在営業日までのF
- 現在営業日までのアルバイト人件費
- 現在営業日までに配賦された社員人件費
- 現在営業日までの変動経費
- 対象月の固定費全額
- 現在営業日までのその他仕入
```

社員人件費は現在営業日までの日次配賦額を使用し、固定費は日割りせず対象月1日時点で有効な月額全額を控除する。「入力済みデータを基にした当月累計」であることを画面に明記し、平均日商や予算から月末を予測する機能は初期版では実装しない。

グラフ候補: - 日別売上推移 - 日別FL率 - 人件費率 - 食材/ドリンク率

グラフライブラリ追加は必要最小限とする。

## 23. 月次PL

店舗×月で算出。

初期版の基本構造:

``` text
税込売上
- 食材仕入原価
- ドリンク仕入原価
= 簡易粗利益

- その他仕入

- アルバイト人件費
- 社員人件費
= 人件費控除後利益

- 変動経費
- 固定費
= 営業利益
```

表示: - 売上 - 食材仕入 - ドリンク仕入 - F合計/F率 - その他仕入 - AB人件費 -
社員人件費 - L合計/L率 - FL金額/FL率 - 経費科目別金額 - 固定費 -
総経費 - 営業利益 - 営業利益率

Excel独自のFLR/FLRA等は初期版では必須としない。現在実際に必要なFLとPLを優先する。

## 24. 全店舗合算PL

同年月について、現在有効な店舗、または対象月に実績が存在する店舗を合算する。無効店舗でも対象月に実績があれば過去の全店舗PLへ含める。

-   全店舗売上
-   F
-   L
-   経費
-   営業利益
-   各率

店舗間移動は相殺する。
単純に店舗別移動込みFを足して会社全体Fを二重計上しない。

実績判定には売上、仕入、経費、店舗間移動、アルバイト勤怠、店舗×月社員人件費、対象月1日時点で有効な固定費設定を含める。

店舗別PL一覧から「全店舗」へ切替可能にする。

## 25. 月次PL画面

URL例:`/management/pl`

上部: - 店舗（全店舗を含む） - 年月

セクション: 1. 売上 2. 原価F 3. 人件費L 4. FL 5. 経費 6. 利益

店舗選択に「全店舗」を追加。

現在有効な店舗は常に選択可能とする。無効店舗は対象月に前節の実績が存在する場合だけ選択可能とし、過去の店舗別PLを確認できるようにする。既存`SelectedStoreService`は有効店舗だけを扱うため、経営管理画面では過去店舗を解決できる専用Serviceまたはpage-localな選択処理を使用する。「全店舗」は経営管理画面内の選択値とし、既存Workforce画面の選択店舗sessionを壊さない。

## 26. 月締め

初期版は既存システム方針に合わせ、強制ロックを実装しない。
過去売上/仕入/経費を修正するとKPI/PLは再計算される。

ただし将来`draft / confirmed / closed`を追加できるよう、集計ロジックと入力データを分離する。

## 27. Service設計

新規Service候補: - `SalesCalculationService` -
`PurchaseAggregationService` - `ExpenseAggregationService` -
`EmployeeLaborAllocationService` - `StoreBusinessDayService` -
`ManagementTargetResolver` - `StoreKpiService` - `ProfitLossService` -
`ManagementExportService`

既存`MonthlyAggregationService`は既存勤怠read
modelとして利用するが、Management Accountingの中心Serviceにしない。

共通のアルバイト人件費取得について、既存`MonthlyAggregationService`と`PayrollCalculationService`で重複しているrate解決/丸めを将来的に共通化する。今回の追加でさらに同じ給与計算を再実装しない。

## 28. Route案

既存`biz`認証を新ロール仕様へ更新した上で、例:

``` text
GET/PUT /management/sales/daily
GET     /management/sales/monthly
GET/POST/PUT /management/suppliers
GET/POST/PUT /management/purchase-categories
GET/POST/PUT/DELETE /management/purchases
GET/POST/PUT/DELETE /management/transfers
GET/POST/PUT /management/expense-categories
GET/POST/PUT/DELETE /management/expenses
GET/POST/PUT /management/recurring-expenses
GET/PUT /management/employee-labor-costs
GET/POST/PUT /management/targets
GET /management/kpi/daily
GET /management/kpi/monthly
GET /management/pl
GET /management/dashboard
GET /management/export.xlsx
```

URLはWayfinder/Laravel慣例に合わせ調整可。

## 29. React/Inertia Page案

``` text
resources/js/pages/management/
  dashboard.tsx
  sales/daily.tsx
  sales/monthly.tsx
  purchases/index.tsx
  expenses/index.tsx
  employee-labor-costs/index.tsx
  targets/index.tsx
  kpi/daily.tsx
  kpi/monthly.tsx
  pl/index.tsx
```

スマホでは入力系をカードUI、月次集計/PLはセクションカード+必要に応じ横スクロール表とする。

## 30. Navigation

既存メニューへ「店舗経営」カテゴリを追加。

例: - 経営ダッシュボード - 売上 - 仕入 - 経費 - FL/KPI - PL -
マスタ/目標

developer/owner/employeeすべて表示。 開発メニューはdeveloperのみ。

## 31. FormRequest/Validation

### 売上

-   store必須
-   business_date必須
-   customer_count\>=0
-   決済金額\>=0整数
-   店舗×営業日unique
-   gross_salesはサーバーで決済合計から算出
-   店休日は`holiday_confirmed`相当の確認値がある場合だけ保存可

### 仕入

-   store/date/category/amount必須
-   amount\>=0
-   supplierは任意
-   支払方法enum
-   店休日は`holiday_confirmed`相当の確認値がある場合だけ保存可

### 店舗間移動

-   from/to必須
-   from != to
-   amount\>0
-   category必須

### 経費

-   store/date/category/amount必須
-   amount\>=0
-   支払方法enum
-   店休日は`holiday_confirmed`相当の確認値がある場合だけ保存可

### 社員人件費

-   store/year/month必須
-   amount\>=0
-   unique(store,year,month)

### 目標

-   各率0〜100
-   各率decimal(5,2)
-   effective_from\<=effective_to
-   同一店舗の適用期間重複を禁止

### 固定費

-   amount\>=0
-   effective_from/effective_toは年月入力を各月1日のdateへ正規化
-   対象月1日時点で有効な設定だけを月次PLへ加算
-   同一店舗・同一固定費の適用期間重複を禁止

フロントだけに依存せずLaravel側で保証。

## 32. Transaction

Transaction対象: - 日次売上+決済内訳保存 - 店舗間移動 -
固定費ルール更新 - 目標期間更新 - 一括インポートを将来追加する場合

## 33. Index/Unique推奨

``` text
daily_sales unique(store_id,business_date)
daily_sales_payments unique(daily_sale_id,payment_method_id)
purchases index(store_id,business_date)
purchases index(supplier_id,business_date)
expenses index(store_id,business_date)
inventory_transfers index(from_store_id,business_date)
inventory_transfers index(to_store_id,business_date)
store_monthly_employee_labor_costs unique(store_id,year,month)
store_management_targets index(store_id,effective_from,effective_to)
```

## 34. 削除方針

店舗/マスタは原則無効化。
売上、仕入、経費等の誤登録は削除可能としてよいが確認ダイアログ必須。

初期版では監査履歴を必須にしないという既存方針を維持する。ただし財務機能であるため`created_by/updated_by`は保存する。

将来監査ログ追加を妨げない。

## 35. Excel出力

新規経営管理XLSXを追加。

例:`YYYY年MM月_店舗経営集計.xlsx`

推奨シート: 1. 日次売上 2. 日次KPI 3. 仕入 4. 経費 5. 店舗PL 6. 全店舗PL

既存勤怠XLSXは変更せず別出力でもよい。

## 36. 0除算・欠損値

売上0の率は`null`。 画面/XLSXでは`—`または空欄。
Excelの`#DIV/0!`を再現しない。

未入力と0円を区別する必要がある項目はnullableを利用する。

## 37. PL/KPIの計算正本

画面ごとに計算式を実装しない。

``` text
DB入力
 ↓
Sales/Purchase/Expense/Labor Services
 ↓
StoreKpiService / ProfitLossService
 ↓
Inertia画面 / XLSX
```

Reactは金額計算の正本にしない。
画面プレビュー以外の正式値はLaravel計算結果を使用。

## 38. 全店舗集計

全店舗PLでは以下を単純合算: - 外部売上 - 外部仕入 - 外部経費 -
AB人件費 - 社員人件費

店舗間移動は相殺。
店舗間内部売上等を将来追加する場合も同様に連結消去する。

## 39. セキュリティ

-   全経営画面認証必須
-   CSRF/XSS/SQL Injectionは既存標準対策
-   権限はLaravel側で強制
-   employeeに他社員個人センシティブ情報を返すInertia
    props/APIを作らない
-   developer専用情報はowner/employeeへ送信自体しない
-   XLSX等のダウンロードも認可する

## 40. 既存システムへの影響

変更が必要: - UserRole enum - role middleware - Inertia navigation -
開発管理者判定 - 一部既存Route認可 - 新Management
Accountingテーブル/Service/Page

変更しない/極力触らない: - shiftsの基本構造 -
attendance_recordsの基本構造 - Payroll計算式 - 所得税ロジック -
PDF給与明細 - 既存勤怠XLSX - 深夜計算

## 41. 既存開発者判定

現行の「指定メール+admin role」によるdevelopment
admin判定は、`developer`ロールへ移行する。

移行時: - `ryota.i.0320@gmail.com`をdeveloperへ -
それ以外の既存adminをownerへ - 既存employeeはemployeeを維持

ロール変更でログイン不能を起こさないMigration/Seederを用意。

移行後のdevelopment admin判定はdeveloperロールを正とし、指定メールとの二重条件にはしない。developerは将来複数作成できる設計とする。他社員のログインアカウント管理はdeveloper/ownerだけに認可する。

## 42. 初期データ

Seeder候補: - sales_payment_methods - purchase_categories -
expense_categories - 初期management targets

店舗は既存storesを利用。

仕入先は現在Excelにあるものを初期登録可能だが、正式一覧は運用開始前に確認する。

## 43. テスト

### 売上

-   決済合計=売上
-   客単価
-   客数0
-   未入力と0円売上の区別
-   平均日商は売上入力済み日数で除算
-   現在月の未来日を未入力件数へ含めない
-   店舗×日unique
-   月次合計
-   店休日の確認なし拒否/確認後保存

### 仕入

-   区分別
-   仕入先別
-   現金仕入
-   その他仕入をFから除外しPL費用へ含める
-   店舗間移動
-   全店舗で内部移動相殺

### 経費

-   科目別
-   支払方法別
-   現金経費
-   固定費適用期間
-   固定費をexpensesへ自動生成しない
-   対象月1日時点の固定費を全額計上

### 社員人件費

-   店舗×月
-   営業日均等配賦
-   端数配賦
-   日別合計=月額
-   営業日0日は日次0円、月次全額、警告表示

### KPI

-   L率
-   food率
-   drink率
-   F率
-   FL率
-   目標差
-   目標金額floorと率小数第2位表示
-   売上0でnull
-   店休日

### PL

-   店舗別
-   全店舗
-   内部移動消去
-   経費科目
-   営業利益
-   歩合を日次/月次Lから除外
-   無効店舗でも対象月実績があれば店舗別/全店舗PLへ含める
-   当月累計営業利益は予測せず確定式どおり算出

### 権限

-   developer全許可
-   owner開発機能拒否
-   employee開発機能拒否
-   employeeアルバイト給与閲覧可
-   employee他社員個人センシティブ情報拒否
-   employee店舗社員人件費合計/PL閲覧可

## 44. 対象外

初期版では実装しない: - 棚卸/在庫 - 正式売上原価（期首+仕入-期末） -
POS自動連携 - 会計ソフト連携 - 銀行/カード自動連携 - 消費税申告計算 -
証憑画像必須管理 - 月次締めロック/承認 - 監査ログ完全版 -
社員個人給与管理 - FLR/FLRA等のExcel独自拡張指標 -
同一営業日の複数店舗勤務 - 本部共通費の高度な配賦

## 45. 実装フェーズ

既存Phase 0〜6と区別するため、本追加機能は`Management Phase M0〜M8`として管理する。テストと文書更新は最終フェーズへ先送りせず、各フェーズの完了条件に含める。

### M0 仕様・設計基盤

- 本仕様書、データモデル、計算ルール、Route/権限表、テストマトリクスをリポジトリへ追加
- 金額・率・丸め、営業日、未入力/null、過去店舗の共通規約を文書化
- Migration順序と既存データ移行手順を確定

完了条件: 実装対象・対象外・計算式・権限に未確定事項がなく、既存テストが全件成功する。

### M1 ロール・認可移行

- UserRoleをdeveloper/owner/employeeへ移行
- 現開発管理者→developer、その他admin→owner、employee維持
- Gate/Policy、navigation、Inertia props、既存Route認可を更新
- 他社員アカウント/センシティブ情報のサーバー側遮断

完了条件: ログイン不能や権限昇格を起こさず、3ロールのFeature Testが成功する。

### M2 経営管理共通マスタ

- 決済方法、仕入区分、仕入先、経費科目、固定費、目標のtable/model/request/page
- StoreBusinessDayService、経営管理用店舗選択、無効店舗の過去表示
- Seederと適用期間重複検証

完了条件: 将来追加店舗を含め、マスタの追加・編集・無効化、期間解決、認可が動作する。

### M3 売上管理

- 日次売上、決済内訳、客数、備考
- 店休日確認、未入力/0円区別、客単価
- 月間一覧、平均日商、入力済み/未入力日数、現金売上

完了条件: 決済合計を売上正本として日次/月次集計でき、未来日を未入力扱いしない。

### M4 仕入・店舗間移動

- 仕入先別/区分別仕入、支払方法、現金仕入
- 食材/ドリンク/その他仕入の分類
- 店舗間移動と全店舗相殺

完了条件: 店舗別F、その他仕入費用、全店舗外部仕入が二重計上なく算出される。

### M5 経費・固定費・社員人件費

- 経費入力、現金経費、店休日確認
- 固定費の月初有効設定をPL計算時に直接参照
- 店舗×月社員人件費と営業日均等配賦、0営業日警告

完了条件: 固定費の重複レコードを生成せず、社員人件費の日別合計と月額が一致する。

### M6 KPI・FL

- 既存勤怠からアルバイト人件費を共通取得し、給与計算を複製しない
- Sales/Purchase/Expense/Laborのread model
- 日次/月次F・L・FL、率、目標金額、差額、現金増減
- 歩合除外、売上0/null、丸めのUnit/Feature Test

完了条件: 画面とServiceで同じ計算結果を使用し、全境界値テストが成功する。

### M7 PL・経営ダッシュボード

- 店舗別月次PL、全店舗PL、内部移動消去
- 無効店舗の過去PL
- 当月累計営業利益と日次推移グラフ

完了条件: 店舗別と全店舗の加減算が検算でき、正式会計PLではない旨を画面表示する。

### M8 出力・レスポンシブ・総合受入

- 経営管理XLSX
- PC/スマホ/タブレットUI調整
- 権限、download、エラー処理、性能、既存機能回帰テスト
- 要件、計算ルール、データモデル、操作/運用文書の同期

完了条件: 第46節の全項目を満たし、PHP/Frontend Test、実ブラウザ受入、XLSX検算が成功する。

## 46. 完了条件

-   3店舗および将来追加店舗で利用可能
-   日次税込売上/客数/決済別売上を入力可能
-   客単価、平均日商等を自動算出
-   仕入先/仕入区分/仕入を管理
-   店舗間移動を管理し全店舗PLで相殺
-   経費/固定費を管理
-   店舗×月社員人件費を入力し営業日へ正しく配賦
-   既存AB人件費と社員人件費からLを算出
-   食材/ドリンク仕入から簡易Fを算出
-   日別/月次のF/L/FLと目標差を表示
-   店舗別PLを表示
-   全店舗合算PLを表示
-   現金増減を表示
-   developer/owner/employeeの権限が仕様通り
-   employeeはアルバイト情報を全て扱える
-   employeeは他社員個人センシティブ情報を取得できない
-   XLSX出力可能
-   スマホ/タブレットで主要入力・閲覧可能
-   主要Unit/Feature Testが成功

## 47. Codexへの注意

既存コードを先に読み、既存Service/Enum/Request/UIパターンへ合わせて実装すること。
既存勤怠・給与ロジックを経営管理側でコピーしない。
Excelのセル式をそのまま移植しない。
KPI/PLの計算定義は本仕様書を正とする。
初期版は税込・簡易仕入原価であり、正式会計PL/税務PLと誤認させない命名・説明を行うこと。
