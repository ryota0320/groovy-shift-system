# 現行システム分析レポート

- 調査日: 2026-09-04（Asia/Tokyo）
- 調査対象コミット: `4e6b3fc`
- 対象: 株式会社Groovy シフト・勤怠・給与管理システム
- 目的: 売上・仕入・経費・FL・PL・店舗別KPIの追加仕様を作る前に、現行実装と接続点を確定する
- 調査根拠: 実コード、Migration、稼働中Docker/MySQLスキーマ、Laravel Route、PHP/フロントエンドテスト

> 本書は現行実装を正として記述する。将来案は必ず「将来の推奨」と明記し、実装済みの事実と区別する。本調査ではアプリケーションコード、Migration、DB、設定を変更していない。

## 1. 技術スタック

| 区分           | 現状                                                                               |
| -------------- | ---------------------------------------------------------------------------------- |
| バックエンド   | PHP 8.4（Docker実測 8.4.25）、Laravel Framework 13.30.1                            |
| フロントエンド | React 19.2.8、TypeScript 5.9.3、Inertia.js 3.7.0                                   |
| CSS/UI         | Tailwind CSS 4.3.3、Radix UI、Lucide React、Sonner、class-variance-authority       |
| DB             | MySQL 8.4（稼働コンテナ実測 8.4.8）                                                |
| Node           | Docker Node 22.23.2、npm 10.9.8                                                    |
| ビルド/検査    | Vite 8.2.2、Vite Plus 0.3.0、TypeScript、ESLint相当の`vp check`                    |
| 認証           | Laravel Fortify 1.39.0、セッション認証、メール確認、パスワード再設定、2FA、Passkey |
| PDF            | `dompdf/dompdf` 3.1.6、IPAゴシック                                                 |
| Excel          | `phpoffice/phpspreadsheet` 5.9.0                                                   |
| PNG            | PHP GD + FreeTypeによるサーバー描画                                                |
| テスト         | PHPUnit 12.5.34、Vitest（Vite Plus経由）                                           |
| 静的解析/整形  | Larastan 3.11.0、Laravel Pint 1.30.5                                               |
| ルート型生成   | Laravel Wayfinder 0.1.21 / Vite plugin 0.1.10                                      |

主なComposer packageは、Laravel、Inertia Laravel adapter、Fortify、Dompdf、PhpSpreadsheet、Wayfinder、Tinkerである。主なnpm packageはReact、Inertia React、Tailwind、Vite、Radix UI群、Lucide React、Sonner、`clsx`、`tailwind-merge`、`input-otp`である。

ローカル環境はDocker Composeで、`app`（PHP-FPM）、`web`（nginx 1.29-alpine）、`node`（Vite開発サーバー）、`db`（MySQL）、`scheduler`（`php artisan schedule:work`）から成る。既定ポートはWeb 8082、Vite 5173、MySQLホスト転送33060。PHP/MySQLともタイムゾーンはAsia/Tokyoである。

## 2. 現在の機能一覧

- Fortifyによるログイン、ログアウト、メール確認、パスワード再設定、2FA、Passkey、プロフィール・セキュリティ・表示テーマ設定
- 店舗の追加・編集・有効/無効、開店/閉店時間、年月別店休日
- スタッフの氏/名/表示名、社員/アルバイト、在籍期間、初期所属、25件ページング・検索
- スタッフの所属、時給、店舗別交通費、所得税区分の期間履歴と初期CSV/XLSXインポート
- 社員へのログインアカウント付与（アルバイトは不可）
- 店舗別の月間/日別シフト、休・急休・早番・他店ヘルプ、追加スタッフ、店舗別表示順、月間PNG
- 営業日単位の日次勤怠、急な出勤、店休日勤務確認、急休/交代、勤怠削除
- アルバイト給与、歩合、交通費課税区分、所得税、再計算要否、個別PDF・一括ZIP
- 店舗別月次、日別人件費、全店舗横断集計、XLSX出力
- 2026/2027年所得税額マスタ、翌年度原典の自動取得と開発管理者向け状態画面

未実装なのは、売上、客数、決済別売上、仕入、仕入先、在庫/棚卸、経費、固定費、予算、FL、PL、店舗経営KPI、会計締め・承認、会計監査ログである。

## 3. ディレクトリ構成

```text
app/
├── Actions/Fortify/             # 認証アクション
├── Concerns/                    # 共通処理
├── Console/Commands/            # 所得税原典取得コマンド
├── Data/                        # 集計DTO
├── Enums/                       # 雇用、権限、シフト、税区分
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   ├── Master/              # 店舗・スタッフ・履歴・初期移行
│   │   └── Settings/
│   ├── Middleware/              # Inertia共有、権限、セキュリティヘッダー
│   └── Requests/                # FormRequest
├── Models/Concerns/             # 適用期間scope
├── Providers/
└── Services/                    # シフト、勤怠、給与、税、集計、出力
database/
├── data/income-tax/             # 2026.csv、2027.csv
├── factories/
├── migrations/
└── seeders/
resources/
├── css/
├── js/
│   ├── actions/                 # Wayfinder生成物
│   ├── components/ui/           # 共通UI
│   ├── hooks/
│   ├── layouts/                 # app/auth/settings
│   ├── lib/                     # 表示・入力・ダウンロード補助とテスト
│   ├── pages/                   # Inertia Page
│   ├── routes/                  # Wayfinder生成物
│   └── types/
└── views/pdf/                   # 給与明細Blade
routes/                           # web.php、settings.php、console.php
tests/
├── Feature/
│   ├── Auth/
│   └── Settings/
└── Unit/
docker/                           # nginx、php.ini
docs/                             # 要件、計算、データモデル、運用、ADR等
```

`Policies/`は存在せず、認可はRoute middleware、Controller/Service内検証、Userモデル制約で行っている。独立したDomain層やRepository層も存在しない。

## 4. Route

共通略記: `biz` = `web, auth, verified, role:admin,employee`、`dev` = `web, auth, verified, development-admin`。

### 4.1 業務Route

| Method       | URL                                        | Name                                      | Controller@Action                                | MW                           |
| ------------ | ------------------------------------------ | ----------------------------------------- | ------------------------------------------------ | ---------------------------- |
| GET          | `/`                                        | `home`                                    | Closure                                          | web                          |
| GET          | `/dashboard`                               | `dashboard`                               | DashboardController                              | web, auth, verified          |
| PUT          | `/selected-store`                          | `selected-store.update`                   | SelectedStoreController@update                   | biz                          |
| GET          | `/stores`                                  | `stores.index`                            | Master\\StoreController@index                    | biz                          |
| POST         | `/stores`                                  | `stores.store`                            | Master\\StoreController@store                    | biz                          |
| GET          | `/stores/{store}/edit`                     | `stores.edit`                             | Master\\StoreController@edit                     | biz                          |
| PUT/PATCH    | `/stores/{store}`                          | `stores.update`                           | Master\\StoreController@update                   | biz                          |
| POST         | `/stores/{store}/holidays`                 | `stores.holidays.store`                   | Master\\StoreHolidayController@store             | biz                          |
| DELETE       | `/stores/{store}/holidays/{holiday}`       | `stores.holidays.destroy`                 | Master\\StoreHolidayController@destroy           | biz                          |
| GET          | `/staffs`                                  | `staffs.index`                            | Master\\StaffController@index                    | biz                          |
| GET          | `/staffs/create`                           | `staffs.create`                           | Master\\StaffController@create                   | biz                          |
| POST         | `/staffs`                                  | `staffs.store`                            | Master\\StaffController@store                    | biz                          |
| GET          | `/staffs/{staff}/edit`                     | `staffs.edit`                             | Master\\StaffController@edit                     | biz                          |
| PUT/PATCH    | `/staffs/{staff}`                          | `staffs.update`                           | Master\\StaffController@update                   | biz                          |
| GET/POST     | `/staffs-import`                           | `staffs.import.index/store`               | Master\\StaffInitialImportController@index/store | biz                          |
| GET          | `/staffs-import/template`                  | `staffs.import.template`                  | Master\\StaffInitialImportController@template    | biz                          |
| POST/DELETE  | `/staffs/{staff}/account`                  | `staffs.account.store/destroy`            | Master\\StaffUserController                      | biz                          |
| POST/PUT     | `/staffs/{staff}/assignments[...]`         | `staffs.assignments.store/update`         | Master\\StaffHistoryController                   | biz                          |
| POST/PUT     | `/staffs/{staff}/wage-rates[...]`          | `staffs.wage-rates.store/update`          | Master\\StaffHistoryController                   | biz                          |
| POST/PUT     | `/staffs/{staff}/transportation-fees[...]` | `staffs.transportation-fees.store/update` | Master\\StaffHistoryController                   | biz                          |
| POST/PUT     | `/staffs/{staff}/income-tax-settings[...]` | `staffs.income-tax-settings.store/update` | Master\\StaffHistoryController                   | biz                          |
| GET          | `/shifts/monthly`                          | `shifts.monthly`                          | ShiftController@monthly                          | biz                          |
| GET          | `/shifts/daily`                            | `shifts.daily`                            | ShiftController@daily                            | biz                          |
| POST         | `/shifts`                                  | `shifts.store`                            | ShiftController@store                            | biz                          |
| PUT          | `/shifts/cell`                             | `shifts.cell.save`                        | ShiftController@saveCell                         | biz                          |
| PUT          | `/shifts/daily`                            | `shifts.daily.save`                       | ShiftController@saveDaily                        | biz                          |
| PUT          | `/shifts/monthly/order`                    | `shifts.monthly.order.save`               | ShiftController@saveMonthlyOrder                 | biz                          |
| POST/DELETE  | `/shifts/monthly/staffs`                   | `shifts.monthly.staffs.store/destroy`     | ShiftController@add/removeMonthlyStaff           | biz                          |
| GET          | `/shifts/monthly.png`                      | `shifts.monthly.png`                      | ShiftPngController                               | biz                          |
| GET          | `/attendance/daily`                        | `attendance.daily`                        | AttendanceController@daily                       | biz                          |
| PUT          | `/attendance/daily`                        | `attendance.daily.save`                   | AttendanceController@saveDaily                   | biz                          |
| DELETE       | `/attendance/{attendanceRecord}`           | `attendance.destroy`                      | AttendanceController@destroy                     | biz                          |
| GET          | `/payrolls`                                | `payrolls.index`                          | PayrollController@index                          | biz                          |
| POST         | `/payrolls/calculate-all`                  | `payrolls.calculate-all`                  | PayrollController@calculateAll                   | biz                          |
| POST         | `/payrolls/{staff}/calculate`              | `payrolls.calculate`                      | PayrollController@calculate                      | biz                          |
| PUT          | `/commissions`                             | `commissions.update`                      | CommissionController@update                      | biz                          |
| DELETE       | `/commissions/{staff}/{year}/{month}`      | `commissions.destroy`                     | CommissionController@destroy                     | biz                          |
| GET          | `/payrolls/{staff}/statement`              | `payrolls.statement`                      | PayrollStatementController@show                  | biz                          |
| GET          | `/payroll-statements.zip`                  | `payrolls.statements.bulk`                | PayrollStatementController@bulk                  | biz                          |
| GET          | `/aggregations`                            | `aggregations.index`                      | AggregationController@index                      | biz                          |
| GET          | `/aggregations.xlsx`                       | `aggregations.xlsx`                       | AggregationExportController                      | biz                          |
| GET/POST/PUT | `/settings/late-night-rates[...]`          | `late-night-rates.*`                      | Master\\LateNightRateSettingController           | biz                          |
| GET          | `/settings/income-tax-status`              | `income-tax-status.index`                 | IncomeTaxStatusController                        | dev                          |
| GET/PATCH    | `/settings/profile`                        | `profile.edit/update`                     | Settings\\ProfileController                      | auth（GETはverifiedなし）    |
| GET          | `/settings/security`                       | `security.edit`                           | Settings\\SecurityController                     | auth, verified, password確認 |
| PUT          | `/settings/password`                       | `user-password.update`                    | Settings\\SecurityController@update              | auth, verified, throttle     |
| GET          | `/settings/appearance`                     | `appearance.edit`                         | Inertia Controller                               | auth, verified               |

### 4.2 Fortify・フレームワークRoute

| Method / URL                                     | Name                                                   | Action / middleware                                                  |
| ------------------------------------------------ | ------------------------------------------------------ | -------------------------------------------------------------------- |
| GET `/login`                                     | login                                                  | Fortify AuthenticatedSession@create / web, guest                     |
| POST `/login`                                    | login.store                                            | AuthenticatedSession@store / web, guest, throttle:login              |
| POST `/logout`                                   | logout                                                 | AuthenticatedSession@destroy / web, auth                             |
| GET `/forgot-password`                           | password.request                                       | PasswordResetLink@create / web, guest                                |
| POST `/forgot-password`                          | password.email                                         | PasswordResetLink@store / web, guest                                 |
| GET `/reset-password/{token}`                    | password.reset                                         | NewPassword@create / web, guest                                      |
| POST `/reset-password`                           | password.update                                        | NewPassword@store / web, guest                                       |
| GET `/email/verify`                              | verification.notice                                    | EmailVerificationPrompt / web, auth                                  |
| GET `/email/verify/{id}/{hash}`                  | verification.verify                                    | VerifyEmail / web, auth, signed, throttle                            |
| POST `/email/verification-notification`          | verification.send                                      | EmailVerificationNotification@store / web, auth, throttle            |
| GET/POST `/two-factor-challenge`                 | two-factor.login / .store                              | TwoFactorAuthenticatedSession / web, guest（POSTはthrottle）         |
| POST `/user/two-factor-authentication`           | two-factor.enable                                      | TwoFactorAuthentication@store / web, auth, password.confirm          |
| DELETE `/user/two-factor-authentication`         | two-factor.disable                                     | 同@destroy / web, auth, password.confirm                             |
| POST `/user/confirmed-two-factor-authentication` | two-factor.confirm                                     | ConfirmedTwoFactorAuthentication@store / web, auth, password.confirm |
| GET `/user/two-factor-qr-code`                   | two-factor.qr-code                                     | TwoFactorQrCode@show / web, auth, password.confirm                   |
| GET `/user/two-factor-secret-key`                | two-factor.secret-key                                  | TwoFactorSecretKey@show / web, auth, password.confirm                |
| GET/POST `/user/two-factor-recovery-codes`       | two-factor.recovery-codes / .regenerate-recovery-codes | RecoveryCode@index/store / web, auth, password.confirm               |
| GET `/user/confirm-password`                     | password.confirm                                       | ConfirmablePassword@show / web, auth                                 |
| POST `/user/confirm-password`                    | password.confirm.store                                 | ConfirmablePassword@store / web, auth                                |
| GET `/user/confirmed-password-status`            | password.confirmation                                  | ConfirmedPasswordStatus@show / web, auth                             |
| GET `/passkeys/login/options`                    | passkey.login-options                                  | PasskeyLogin@index / web, guest, throttle                            |
| POST `/passkeys/login`                           | passkey.login                                          | PasskeyLogin@store / web, guest, throttle                            |
| GET `/passkeys/confirm/options`                  | passkey.confirm-options                                | PasskeyConfirmation@index / web, auth, throttle                      |
| POST `/passkeys/confirm`                         | passkey.confirm                                        | PasskeyConfirmation@store / web, auth, throttle                      |
| GET `/user/passkeys/options`                     | passkey.registration-options                           | PasskeyRegistration@index / web, auth, password.confirm, throttle    |
| POST `/user/passkeys`                            | passkey.store                                          | PasskeyRegistration@store / 同上                                     |
| DELETE `/user/passkeys/{passkey}`                | passkey.destroy                                        | PasskeyRegistration@destroy / 同上                                   |
| GET `/.well-known/passkey-endpoints`             | well-known.passkeys                                    | Closure / web                                                        |
| ANY `/settings`                                  | -                                                      | RedirectController / web, auth                                       |
| GET `/up`                                        | -                                                      | Laravel health Closure / middlewareなし                              |
| GET `_inertia/devtools/entries[/{id}]`           | -                                                      | Inertia DevTools Entries@index/show / DevTools Authorize等           |
| GET/PUT `/storage/{path}`                        | storage.local / .upload                                | Laravel local disk Closure / Route表示上middlewareなし               |

`local` disk rootは`storage/app/private`かつ`serve=true`であるため、storage Routeは本番で必要性と認可条件を確認すべき接点である。

公開ユーザー登録Routeは無効である。

## 5. Controllers

| Controller                     | Method                           | 担当 / 呼出Service / Controller直書きロジック                                                                   |
| ------------------------------ | -------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| DashboardController            | `__invoke`                       | SelectedStore、BusinessDateを利用。シフト人数、勤怠未入力、所属内訳はQuery Builderで直接集計                    |
| SelectedStoreController        | `update`                         | SelectedStoreServiceでセッションへ保存                                                                          |
| StoreController                | `index/store/edit/update`        | 店舗CRUD、店休日月絞込。削除はなく無効化。保存自体はEloquent直接                                                |
| StoreHolidayController         | `store/destroy`                  | ShiftMasterDataGuardで勤務シフトとの矛盾を防止後、Eloquent保存/削除                                             |
| StaffController                | `index/create/store/edit/update` | 検索・25件paginate、登録時初期所属をtransaction保存、履歴表示、在籍変更guard                                    |
| StaffHistoryController         | 履歴のstore/update               | EffectivePeriodService、ShiftMasterDataGuard、PayrollRecalculationServiceを使用                                 |
| StaffUserController            | `store/destroy`                  | 社員限定、User作成/更新/削除。アルバイト拒否                                                                    |
| StaffInitialImportController   | `index/store/template`           | StaffInitialImportService、PhpSpreadsheetでテンプレート生成                                                     |
| ShiftController                | 月次/日次/各保存/並替/追加削除   | ShiftCalendarService、ShiftSaveService、SelectedStoreService。追加・削除・順序はtransactionと直接Eloquentも使用 |
| ShiftPngController             | `__invoke`                       | ShiftPngServiceからPNG response                                                                                 |
| AttendanceController           | `daily/saveDaily/destroy`        | AttendanceCalendarService、AttendanceSaveService、SelectedStoreService、BusinessDateService                     |
| CommissionController           | `update/destroy`                 | アルバイト限定、Commissionをupsert/delete、PayrollRecalculationServiceでstale化                                 |
| PayrollController              | `index/calculate/calculateAll`   | 一覧QueryはController、計算はPayrollCalculationService                                                          |
| PayrollStatementController     | `show/bulk`                      | 対象給与の妥当性検証、PayrollPdfService、ZipArchive。一時ZIPをレスポンス後削除                                  |
| AggregationController          | `index`                          | MonthlyAggregationService、SelectedStoreService                                                                 |
| AggregationExportController    | `__invoke`                       | MonthlyAggregationServiceとAttendanceExcelService                                                               |
| IncomeTaxStatusController      | `__invoke`                       | 適用DB版とIncomeTaxSourceStatusServiceの取得状態を統合表示                                                      |
| LateNightRateSettingController | `index/store/update`             | EffectivePeriodServiceとPayrollRecalculationService                                                             |
| ProfileController              | `edit/update`                    | ユーザー名・メール更新、Fortify系validation                                                                     |
| SecurityController             | `edit/update`                    | 2FA/Passkey表示とパスワード更新                                                                                 |

給与、勤務時間、深夜計算、PDF/Excel/PNG生成の本体はControllerに直書きされていない。一方、一覧用Query、対象期間抽出、ZIP組立、スタッフ追加/削除はControllerにも業務判断が残る。

## 6. Services / Action / Domainロジック

| Service                      | 入力 → 処理 → 出力                                                                                                        |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| BusinessDateService          | 現在日時 → 12:00未満なら前日、それ以降なら当日 → 現在営業日                                                               |
| SelectedStoreService         | request/query/session → 有効店舗検証、セッション`selected_store_id`、名前順先頭fallback → 選択Store                       |
| EffectivePeriodService       | 履歴Query・期間・除外ID → 行ロック、inclusiveな期間重複検査/1件解決 → Modelまたは明示エラー                               |
| ShiftCalendarService         | Store・月/日 → 所属、既存シフト、追加行、休日、競合、表示順を統合 → Inertia用calendar配列                                 |
| ShiftSaveService             | context店舗・staff・日・種別・勤務店舗/時刻 → 在籍/所属/休日/営業時間/同日競合を検証してtransaction置換 → Shiftまたは削除 |
| ShiftMasterDataGuard         | 店休日/在籍/所属変更案 → 既存シフト・勤怠が範囲外にならないか検査 → 保存可否                                              |
| ShiftPngService              | Store・月 → ShiftCalendarをGD/FreeType描画 → PNG bytes                                                                    |
| AttendanceCalendarService    | Store・work_date → 所属、予定、実績、急出勤候補、他店競合、警告を統合 → 日次画面props                                     |
| AttendanceTimeService        | work_date・出退勤offset → 15分、営業日範囲、24時間未満を検証、実働と22:00〜翌08:00重複を算出 → datetime/分数              |
| AttendanceSaveService        | Store・work_date・複数record → transaction、店休確認、在籍/所属/予定、他店重複を検証、upsert → attendance、給与stale化    |
| AttendanceSummaryService     | 月・任意Store → 社員勤怠を集計 → 社員別時間配列                                                                           |
| PayrollCalculationService    | Staff・年月 → 全店舗勤怠と日付時点の履歴、歩合、支払日時点の税設定/税表を解決、月末丸め → Payroll upsert                  |
| PayrollRecalculationService  | staff/月または全件 → 既存Payrollの`needs_recalculation=true` → 更新件数相当                                               |
| IncomeTaxCalculationService  | 年、甲乙、扶養、参照額 → 該当version/ruleを検索し整数算式を評価 → 所得税円額                                              |
| IncomeTaxSourceFetchService  | 年 → 国税庁HTML/Excelのみ取得、URL・サイズ・Excel構造・SHA-256検証 → private storage原典とmetadata                        |
| IncomeTaxSourceStatusService | 年/状態 → private JSON保存/読取 → 開発管理者画面用状態                                                                    |
| MonthlyAggregationService    | 年月・任意Store → 勤怠と履歴を日別/店舗別/全店舗で集計、Payroll snapshot結合 → MonthlyAggregationReport                   |
| AttendanceExcelService       | MonthlyAggregationReport → 2 sheetのXLSX生成 → private一時file path                                                       |
| PayrollPdfService            | 保存済みPayroll → 正式氏名・出勤日数を読込、Blade+Dompdf → A4横PDF bytes                                                  |
| StaffInitialImportService    | CSV/XLSX → header/値/期間/重複を検証、全件transaction → staffと履歴の登録件数                                             |

Fortifyの`Actions/Fortify`にはパスワードルール、ユーザー認証処理がある。独立したDomain Entityはなく、Eloquent Model + Serviceが事実上のドメイン層である。

## 7. Models

全Modelは`guarded`ではなく原則`fillable`を指定する。

| Model / table                                                 | fillable・casts・特殊処理                                                                                                   | 主なrelation/scope                                                                                                 |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| User / users                                                  | staff_id,name,email,password,role。password=hashed、role=UserRole、2FA日時。bootでemployee userのstaff必須・staffは社員限定 | belongsTo Staff、`isDevelopmentAdmin()`                                                                            |
| Staff / staffs                                                | name,last_name,first_name,display_name,employment_type,hired_at,retired_at。saving時にlegacy `name`を正式氏名へ同期         | user、全履歴、shift、attendance、payroll。`full_name`、`preferred_name` accessor、`isEmployedOn`、`inDisplayOrder` |
| Store / stores                                                | name,opening_time,closing_time,is_active                                                                                    | holidays、assignments、display orders、additions、transport、shift、attendance。`allowsShiftStartTime`             |
| StoreHoliday / store_holidays                                 | store_id,holiday_date。date cast                                                                                            | belongsTo Store                                                                                                    |
| StaffStoreAssignment / staff_store_assignments                | staff_id,store_id,effective_from,to                                                                                         | belongsTo Staff/Store、HasEffectivePeriod                                                                          |
| StaffWageRate / staff_wage_rates                              | staff_id,hourly_wage,effective_from,to                                                                                      | belongsTo Staff、HasEffectivePeriod                                                                                |
| StaffStoreTransportationFee / staff_store_transportation_fees | staff_id,store_id,amount_per_day,tax_type,effective期間。tax_type enum                                                      | belongsTo Staff/Store、HasEffectivePeriod                                                                          |
| StaffIncomeTaxSetting / staff_income_tax_settings             | staff_id,tax_category,dependent_count,effective期間。category enum                                                          | belongsTo Staff、HasEffectivePeriod                                                                                |
| LateNightRateSetting / late_night_rate_settings               | amount_per_hour,effective期間                                                                                               | HasEffectivePeriod                                                                                                 |
| StaffStoreDisplayOrder / staff_store_display_orders           | store_id,staff_id,position                                                                                                  | belongsTo Store/Staff                                                                                              |
| MonthlyShiftStaffAddition / monthly_shift_staff_additions     | store_id,staff_id,month,position。month=date                                                                                | belongsTo Store/Staff                                                                                              |
| Shift / shifts                                                | staff_id,store_id,shift_date,shift_type,start_time。date/ShiftType cast                                                     | belongsTo Staff/Store                                                                                              |
| AttendanceRecord / attendance_records                         | staff_id,store_id,work_date,clock_in/out,working_minutes,late_night_minutes                                                 | belongsTo Staff/Store、date/datetime casts                                                                         |
| Commission / commissions                                      | staff_id,year,month,amount。数値cast                                                                                        | belongsTo Staff                                                                                                    |
| Payroll / payrolls                                            | 全給与結果列                                                                                                                | belongsTo Staff、日付/数値/bool/datetime casts                                                                     |
| IncomeTaxTableVersion / income_tax_table_versions             | tax_year,name,source_url,source_hash,imported_at                                                                            | hasMany IncomeTaxRule                                                                                              |
| IncomeTaxRule / income_tax_rules                              | version,category,dependent,金額範囲,計算種別,固定税額,parameters,order                                                      | belongsTo version、parameters=json                                                                                 |

`HasEffectivePeriod`は`effective_from <= 対象日`かつ`effective_to IS NULL OR effective_to >= 対象日`で、開始日・終了日とも含む。

### 7.1 usersとstaffs

- 関係はstaff 1 : user 0..1。`users.staff_id`はnullable、unique、staffsへのrestrict FK。
- 社員がログインする時だけuserを作る。UserモデルとControllerの両方で、employee roleのuserはstaff必須かつstaffの雇用区分が社員であることを検証する。
- アルバイトへのuser作成は拒否する。公開ユーザー登録も無効。
- 開発管理者は例外的にstaff_id nullを許可する。現行指定メールは`ryota.i.0320@gmail.com`で、開発管理者専用判定にはメール一致とadmin roleの両方が必要。
- 認証後はInertia共通propsの`auth.user`としてUserが共有される。Staff relationは必要な処理で`user->staff`から取得し、全画面へ常時展開はしていない。

### 7.2 店舗と選択店舗

- 店舗は追加・編集・有効/無効化があり、削除Routeはない。過去データ保全のため通常は無効化する。
- 現在選択中店舗の正本はsessionの`selected_store_id`。画面の`store_id` queryがあれば検証後sessionにも反映する。
- 無効店舗は新規選択不可。記憶店舗が無効なら有効店舗の名前順先頭へfallbackし、有効店舗0件なら未選択を明示する。
- React stateだけ、User/Store DB列だけで選択を保持しているわけではない。URL queryは対象ページ・共有URLの期間/店舗指定として併用される。

## 8. DBテーブル定義

以下はMigrationだけでなく、稼働MySQLスキーマでも照合した。金額はすべて整数円である。

### 8.1 業務テーブル

| Table                           | 主なカラム（型、null/default）                                                                                                                              | Index / FK / 制約                                                                  |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| stores                          | id bigint、name varchar、opening_time time default 17:00、closing_time time default 10:00、is_active bool default 1、timestamps                             | name unique、is_active index                                                       |
| store_holidays                  | id、store_id bigint、holiday_date date、timestamps                                                                                                          | unique(store,date)、index(date,store)、store FK restrict                           |
| staffs                          | id、name varchar、last_name/first_name/display_name nullable、employment_type varchar、hired_at/retired_at nullable、timestamps                             | employment/hired/retired index                                                     |
| users                           | id、staff_id nullable、name、email、password、2FA fields nullable、role default employee、timestamps                                                        | email unique、staff_id unique FK staffs restrict、role index                       |
| staff_store_assignments         | staff_id、store_id、effective_from date、effective_to nullable、timestamps                                                                                  | staff/effective・store/effective index、両FK restrict。DB上の期間重複uniqueはなし  |
| staff_wage_rates                | staff_id、hourly_wage unsigned int、effective_from/to、timestamps                                                                                           | staff/effective index、staff FK restrict                                           |
| staff_store_transportation_fees | staff_id、store_id、amount_per_day unsigned int、tax_type varchar、effective_from/to                                                                        | staff+store+effective index、両FK restrict                                         |
| staff_income_tax_settings       | staff_id、tax_category varchar、dependent_count unsigned smallint、effective_from/to                                                                        | staff/effective index、staff FK restrict                                           |
| late_night_rate_settings        | amount_per_hour unsigned int、effective_from/to                                                                                                             | effective期間index                                                                 |
| staff_store_display_orders      | store_id、staff_id、position unsigned int、timestamps                                                                                                       | unique(store,staff)、index(store,position)、両FK cascade                           |
| monthly_shift_staff_additions   | store_id、staff_id、month date、position unsigned int、timestamps                                                                                           | unique(store,staff,month)、index(store,month,position)、両FK cascade               |
| shifts                          | staff_id、store_id nullable、shift_date date、shift_type varchar、start_time nullable、timestamps                                                           | unique(staff,date)、store/date・staff/date index、FK restrict、形状CHECK           |
| attendance_records              | staff_id、store_id、work_date、clock_in_at/out_at datetime、working_minutes/late_night_minutes unsigned int                                                 | unique(staff,work_date)、store/date・staff/date index、FK restrict、時刻/分数CHECK |
| commissions                     | staff_id、year smallint、month tinyint、amount unsigned bigint、timestamps                                                                                  | unique(staff,year,month)、year/month index、staff FK restrict                      |
| payrolls                        | staff_id、year、month、payment_date、tax_year、勤務分、深夜分、各支給/控除、net_pay、needs_recalculation default 0、calculated_at nullable                  | unique(staff,year,month)、index(year,month,stale)、staff FK restrict               |
| income_tax_table_versions       | tax_year、name、source_url、source_hash、imported_at、timestamps                                                                                            | tax_year unique                                                                    |
| income_tax_rules                | table_version_id、tax_category、dependent_count nullable、min/max_amount、calculation_type、fixed_tax_amount nullable、parameters json nullable、sort_order | unique(version,category,dependent,min)、lookup index、version FK cascade           |

`payrolls`の金額列は`base_pay`、`late_night_pay`、交通費total/taxable/non_taxable、`commission`、`gross_pay`、`taxable_pay`、`social_insurance_deduction`、`tax_table_reference_amount`、`income_tax`、`other_deductions`、`total_deductions`、`net_pay`である。`net_pay`だけsigned bigint、他はunsigned bigintで、現行計算では差引支給額が負になる可能性も型上は保持できる。

CHECK制約の要点:

- shifts: `off`/`absence`はstore/startともnull。`early`/`help`はstore必須・start null。`time`はstore/start必須かつ分は00。
- attendance: 出退勤は15分境界、`clock_in_at`はwork_date当日12:00以上〜翌日12:00未満、clock_outはclock_inより後かつ24時間未満。保存分数と`TIMESTAMPDIFF`一致、深夜分は実働以下かつ最大600分。

### 8.2 Laravel基盤テーブル

`password_reset_tokens`、`sessions`、`passkeys`、`cache`、`cache_locks`、`jobs`、`job_batches`、`failed_jobs`、`migrations`がある。Queue基盤テーブルはあるが、現行業務処理は同期実行が中心である。

存在しない重要テーブル: `system_settings`、会社/法人、会計年度/締め、売上、決済、仕入先、仕入、在庫、棚卸、経費、勘定科目、予算、目標、監査ログ。

## 9. ER図

```text
staffs 1 ─── 0..1 users
staffs 1 ─── N staff_store_assignments N ─── 1 stores
staffs 1 ─── N staff_wage_rates
staffs 1 ─── N staff_store_transportation_fees N ─── 1 stores
staffs 1 ─── N staff_income_tax_settings
stores 1 ─── N store_holidays
stores 1 ─── N staff_store_display_orders N ─── 1 staffs
stores 1 ─── N monthly_shift_staff_additions N ─── 1 staffs
staffs 1 ─── N shifts N ─── 0..1 stores
staffs 1 ─── N attendance_records N ─── 1 stores
staffs 1 ─── N commissions
staffs 1 ─── N payrolls
income_tax_table_versions 1 ─── N income_tax_rules
late_night_rate_settings（全社共通履歴）
```

`system_settings`は未実装であり、ERには含まれない。現状は単一会社・複数店舗を前提とし、company_id/tenant_idを持たない。

## 10. シフト設計

### 10.1 データ表現と「休」

`shifts`はスタッフ×営業日で1件だけ持つ。`shift_date`が営業日、`store_id`が実際の勤務予定店舗である。

| 状態                 | DB表現                                               |
| -------------------- | ---------------------------------------------------- |
| 未設定               | レコードなし                                         |
| 時刻                 | `shift_type=time`、store必須、start_time必須（00分） |
| 早番                 | `early`、store必須、start null                       |
| 時刻未定の他店ヘルプ | `help`、ヘルプ先store必須、start null                |
| 休                   | `off`、store/start null                              |
| 急な休み             | `absence`、store/start null                          |
| 店休                 | Shiftレコードではなく`store_holidays`を画面上で合成  |

したがって「休」「急休」は店舗単位ではなく、スタッフ×営業日の全店舗共通状態である。unique(staff,date)により同日に別店舗勤務と休を併存できず、同日の複数店舗勤務そのものも現行仕様では不可能である。

営業時間が日跨ぎ（例17:00〜翌10:00）の場合、開始時刻候補は開店から閉店までの1時間単位。DB値00:00は画面で「24」と表示する。早番と他店舗名は時間以外の候補として残る。対象店が店休日ならその店での勤務保存を拒否するが、所属店が店休日でも営業中の他店ヘルプは可能である。

### 10.2 月間シフト

- Page: `resources/js/pages/shifts/monthly.tsx`。主なpropsはstores、selected_store、month、previous/next、days、staffs、addable_staffs。
- 表示対象は対象月の所属スタッフ、表示店舗の既存シフト、月次追加スタッフの和集合。
- 基本順は社員ID昇順→アルバイトID昇順。ドラッグ&ドロップ順は`staff_store_display_orders`に店舗単位で保存する。
- 他店スタッフは「スタッフ追加」で末尾へ追加し、`monthly_shift_staff_additions`へ店舗×staff×月で保存する。所属外の行だけ削除可能。
- 追加行削除時は、その表示店舗で対象月に勤務するShiftと追加状態だけを削除する。所属店舗・他店舗のShift、全店舗共通の休/急休、勤怠、staffは保持する。
- セル変更は`PUT /shifts/cell`で即時保存。別店舗で既に勤務予定があるセルは「-」で非活性。元店舗の予定が消えれば編集可能になる。
- 別店舗画面から保存しても、別日の所属店舗Shiftを一括クリアしない。ヘルプ先で時刻を詳細化した場合も、所属店舗側はヘルプ先店舗名として表示する。
- 横スクロール、先頭スタッフ列と日付headerのsticky、土日/店休の列色、ライト/ダーク双方の交互行色を実装。
- 月間表はPC中心の横長UIで、スマホでは横スクロールによる操作となる。専用のカードUIはない。

### 10.3 日別シフト

- Page: `resources/js/pages/shifts/daily.tsx`。店舗・対象日・前後日、交代/応援スタッフ追加、シフト一覧を持つ。
- 表示店舗の所属/既存Shiftを表示し、他店舗所属の在籍スタッフを追加できる。
- 時刻と他店舗名を1つの選択欄に統合し、店舗専用selectはない。
- 編集値はReact stateに保持し、最後に`PUT /shifts/daily`で一括保存。Service側transactionにより全件成功/全件rollback。
- `beforeunload`とInertia遷移前の確認により未保存警告を出す。
- `md`未満はカード、`md`以上は表、保存ボタンは小画面で画面下固定。

### 10.4 制約上の注意

現行の1スタッフ×1営業日uniqueはシンプルだが、将来「同日にA店→B店」の実績や店舗別人件費配賦が必要になると構造変更が必要である。現状のFL設計は、同一営業日の人件費が必ず1店舗へ帰属する前提で進める必要がある。

## 11. 勤怠設計

### 11.1 attendance_recordsと営業日

`work_date`が営業日で、`clock_in_at`/`clock_out_at`は実日時である。コード上は`business_date`というDB列はなく、画面上の「営業日」とDBの`work_date`が同じ意味を持つ。

営業日切替は12:00。例えば9/5 22:00〜9/6 08:00は`work_date=9/5`、9/5 01:00〜05:00なら`work_date=9/4`である。日次画面の既定日は、現在時刻が12:00未満なら前営業日、それ以降なら当日になる。

### 11.2 入力・時間計算

- 実出勤は営業日当日12:00〜翌11:45を15分単位で手入力する。
- 実退勤も15分単位。出勤より後かつ1勤務24時間未満であれば、翌10:00を超えても入力できる。
- Reactの時刻input、FormRequest、AttendanceTimeService、MySQL CHECKの多層で検証する。
- 画面では0:00〜11:59を翌日の実日時として解釈するが、時刻ラベルに「翌」は付けない。
- 実働分 = clock_out offset - clock_in offset。休憩控除は未実装。
- 深夜対象 = 勤務半開区間と `[work_date 22:00, work_date+1 08:00)` の重複分。最大600分。

期待されていた深夜仕様と現行コードは一致している。

### 11.3 日次運用

- 対象日の所属スタッフ、該当Shift、既存勤怠を統合表示。シフト未登録でも所属中なら急な出勤として保存可能。
- 他店所属スタッフも追加可能。対象日に別店舗勤怠/勤務Shiftがあれば競合として拒否/非活性。
- 店休日勤務は確認ダイアログと`holiday_confirmed`が必要だが、確認後は通常勤務として保存し給与にも含める。
- 予定時刻との差が15分以上なら警告するが、保存は妨げない。早番/急出勤は予定時刻がないため差分警告なし。
- 急休への変更、代替スタッフの追加・予定設定を同画面から行える。勤怠登録済みスタッフを急休にはできない。
- 勤怠削除は確認後`DELETE`で物理削除。関連する計算済み給与をstaleにする。
- PCは表、モバイルはカード。未保存警告と小画面下部固定保存ボタンあり。

### 11.4 社員勤怠

社員もattendanceへ同じ形式で保存し、勤務日数・店舗別時間・月間総時間・全店舗時間を集計する。社員の基本給/人件費/給与明細は現状対象外で、深夜時間・金額列も集計画面で「対象外」となる部分がある。

## 12. 給与設計

対象はアルバイトのみ。対象月の全店舗勤怠をスタッフ単位でまとめ、勤務日ごとの有効単価を使用する。

### 12.1 計算式

```text
raw_base = Σ(各勤務日の working_minutes × 当日時給)
base_pay = ceil(raw_base / 60)

raw_late = Σ(各勤務日の late_night_minutes × 当日深夜加算時給)
late_night_pay = ceil(raw_late / 60)

transportation_fee_total
  = Σ(出勤したstaff×store×日の有効な日額。未設定は0)
transportation_fee_taxable / non_taxable
  = tax_type別の上記内訳

commission = staff×対象年月の値。未設定は0
gross_pay = base_pay + late_night_pay + transportation_fee_total + commission
taxable_pay = base_pay + late_night_pay + commission + transportation_fee_taxable
social_insurance_deduction = 0（初期版固定）
tax_table_reference_amount = max(0, taxable_pay - social_insurance_deduction)
income_tax = 支払年の税額表(甲乙・扶養・参照額)
other_deductions = 0
total_deductions = income_tax + other_deductions
net_pay = gross_pay - total_deductions
```

交通費・深夜加算設定・歩合は未設定なら0。時給、支払日時点の所得税設定、支払年の税額表は必須で、欠落/重複時は保存せず明示エラーにする。

### 12.2 支払日・保存・再計算

- 締めは対象月末、`payment_date`は対象月の翌月10日。
- `tax_year`は勤務月ではなくpayment_dateの年。12月勤務分は翌年税表を使用。
- `updateOrCreate(staff_id, year, month)`でPayroll snapshotを保存し、計算時に`needs_recalculation=false`、`calculated_at=now`。
- 一括計算は全アルバイトを1 transactionで計算し、1人でも失敗すれば全件rollback。
- 勤怠保存/削除、歩合、時給/交通費/深夜/税設定、税額マスタ再投入は対象Payrollをstaleにする。自動再計算はしない。
- 画面は`resources/js/pages/payrolls/index.tsx`。年月移動、歩合編集/0円化、個別/全員再計算、stale表示、個別PDF、一括ZIPを提供。
- 個別/一括明細は`gross_pay > 0`かつ計算済み・非staleだけが対象。

### 12.3 丸め

| 集計単位         | 現行丸め                                                                             |
| ---------------- | ------------------------------------------------------------------------------------ |
| 正式月次基本給   | 月内の`分×時給`を合計し、最後に`ceil(/60)`                                           |
| 正式月次深夜手当 | 月内の`深夜分×加算額`を合計し、最後に`ceil(/60)`                                     |
| 交通費・歩合     | 整数円の単純加算                                                                     |
| 日別表示         | 日単位のraw基本給/深夜を各々最後にceil。labor_costは両raw+交通費を合わせて最後にceil |
| 店舗別月次表示   | 店舗×staffのraw合計を表示単位の最後にceil                                            |
| 正式給与         | `payrolls` snapshotが正。日別/店舗別の表示丸め合計と一致しない場合がある             |
| 所得税算式       | ruleによりfixed、percentage floor、marginal floor、10円単位丸め                      |

Controller、PDF、Excelで給与を再計算はせず、PDFは保存済みPayroll、全店舗集計はPayroll snapshotを参照する。

## 13. 所得税設計

- 正式投入済み: 2026年「令和8年分」と2027年「令和9年分」の国税庁月額表。CSVはそれぞれ2,162/2,135 rules。
- `IncomeTaxTableSeeder`がversionをupsertし、rulesを入れ替え、件数・甲欄0〜7人・乙欄・全金額範囲連続・算式parameterを検証する。
- `staff_income_tax_settings`は甲欄/乙欄、扶養人数、inclusiveな適用期間を保持。給与では支払日当日の設定を1件解決する。
- 甲欄は扶養0〜7人列。8人以上は7人税額から1人当たり1,610円を減算（0円未満にしない）。乙欄のdependentはnull。
- 税表検索はversion年、category、dependent、`min_amount <= 参照額 < max_amount`（最終行max null）で1 ruleを解決する。
- 非課税交通費はtaxable_payから除外。社会保険控除は現状0。
- 翌年度原典は8月20日〜12月31日の毎日06:10にSchedulerが国税庁だけから取得確認する。取得は自動適用ではなくprivate storageへ保存し、開発管理者レビューとSeeder更新が必要。
- 開発管理者は`INITIAL_ADMIN_EMAIL`とadmin roleの両方が一致するユーザーだけ。適用版/原典取得状態は専用画面で確認できる。

【問題点】原典ExcelからDB ruleへの完全自動変換・承認フローはない。これは誤課税防止の意図的な手動ゲートである。また、所得税は給与源泉税であり、将来の売上/仕入で扱う消費税とは別ドメインにする必要がある。

## 14. 集計設計

`MonthlyAggregationService`が画面とXLSXの共通読取モデルを作る。

- 店舗別月次: スタッフ別の出勤日数、実働、アルバイト深夜、基本給相当、深夜手当、交通費、人件費と合計。
- 日別: staff×日×店舗を基に同項目を日別集約。raw精度を保持し、各表示単位の最後だけ切上げ。
- 全店舗横断: staff×月の店舗別実働、総実働、深夜、保存済み正式Payroll（基本給、深夜手当、交通費、総支給）、未計算/stale状態。
- 社員: 出勤日数・実働・店舗別時間は表示。給与/人件費は対象外。
- 歩合: 全社月次給与には入るが店舗別人件費へ配賦されない。

【現状】店舗別のL候補として使えるのは、アルバイトの時給相当＋深夜＋交通費である。

【問題点】社員給与、法定福利費、歩合の店舗配賦、賞与、残業/休日割増、休憩は入らない。よって経営PLの正式な「人件費」と同義ではない。

【将来の推奨】KPIには「勤怠ベース変動人件費」「給与/会計ベース総人件費」を別指標として保持し、どちらをFLへ使うか明示する。

## 15. PDF / Excel / PNG

### PDF/ZIP

- `resources/views/pdf/payroll-statement.blade.php`をDompdfでA4横1ページ化。添付テンプレート基準。
- 会社名は株式会社Groovy。勤怠、支給、控除、合計の4 block。正式氏名は「氏 半角space 名」。
- 日本語はDocker内IPAゴシック、remote asset無効、chroot制限。ファイル名は`YYYY年MM月_氏名_給与明細.pdf`。
- 同姓同名がZIP内で重複した時だけ`_スタッフID{id}`を付ける。
- 一括はPHP `ZipArchive`、`YYYY年MM月_給与明細一括.zip`。生成途中失敗は成功responseを返さず一時fileを削除。レスポンス後も削除。
- Routeは認証・メール確認・role middleware付き。ただしadmin/employeeの両roleが全スタッフ分を取得でき、本人分だけに制限するPolicyはない。ブラウザdownloadなのでスマホからも呼べるが、端末別の実機受入は未実施。

### Excel

- PhpSpreadsheetで`YYYY年MM月_勤怠人件費集計.xlsx`を生成。
- Sheet 1「店舗別月次集計」: staff、雇用、出勤日数、勤務/深夜、基本給相当、深夜手当、交通費、人件費、合計。
- Sheet 2「全店舗横断集計」: staff、雇用、出勤日数、店舗別時間、総勤務/深夜、正式給与内訳、給与状態。
- 社員は時間を表示し金額は「対象外」。画面と同じMonthlyAggregationReportを使用。
- private一時fileをdownload後削除。

### PNG

- クライアントcanvasではなくShiftPngServiceがGD/FreeTypeでサーバー生成。
- ファイル名はControllerで店舗・年月に基づき生成。会社名、年月、店舗、staff行、日付、土日/店休色、開始時刻を描画。
- 時刻以外は早/休/急休/他店舗名等のcalendar displayを利用。日本語IPA font必須。

## 16. React / Inertia画面構成

| Page                                       | 主な責務                                                    |
| ------------------------------------------ | ----------------------------------------------------------- |
| `dashboard.tsx`                            | 選択店舗、現在営業日のシフト/勤怠サマリー                   |
| `stores/index.tsx`, `edit.tsx`             | 店舗CRUD、営業時間、有効状態、年月別店休                    |
| `staffs/index/create/edit/import.tsx`      | 検索/page、氏名/表示名/雇用/在籍、各履歴、user、初期移行    |
| `shifts/monthly.tsx`                       | 横スクロール月間表、cell即時保存、D&D、追加/削除、PNG       |
| `shifts/daily.tsx`                         | 日別local edits、一括保存、応援追加、responsive table/card  |
| `attendance/daily.tsx`                     | 手入力時刻、一括保存、急出勤/急休/交代、削除、responsive UI |
| `payrolls/index.tsx`                       | 歩合、計算、stale、PDF/ZIP                                  |
| `aggregations/index.tsx`                   | 店舗別/日別/全店集計、XLSX、横scroll table                  |
| `settings/late-night-rates.tsx`            | 深夜単価履歴                                                |
| `settings/income-tax-status.tsx`           | 開発管理者だけの適用/取得状況                               |
| `settings/profile/security/appearance.tsx` | account設定                                                 |
| `auth/*`                                   | Fortify login、reset、verify、2FA                           |

Inertia propsがサーバー状態の正本で、通常は`router.get/post/put/delete`でLaravelへ送る。現在選択店舗はReactだけではなくsessionが正本で、query `store_id`を受けた画面はsessionへ反映する。表示テーマはcookie、sidebar開閉もcookie。グローバルクライアントstate storeはない。

## 17. Validation / 認可

- FormRequest: AttendanceDaily、Commission、MonthlyShiftStaff、ShiftCell/Daily/Order、Store/StoreHoliday、Staff、各履歴、StaffUser、StaffImport、profile/security。
- 1日1店舗/休との排他: Requestのshape、ShiftSaveServiceのrow lock、DB unique/CHECK。
- 店休日: StoreHoliday登録時に既存勤務Shiftをguard。Shift保存は店休拒否。勤怠は明示確認後のみ許可。
- 15分: React time input、Request `multiple_of:15`、AttendanceTimeService、DB CHECK。
- 翌朝: 固定10:00上限ではなく、出勤可能範囲は翌11:45、退勤は出勤後24時間未満。
- 在籍/所属: Staff/Assignment変更時に既存Shift・勤怠を範囲外へ出さない。新規Shift/勤怠も対象日を検証。
- 適用期間: FormRequestで開始<=終了、EffectivePeriodServiceのtransaction/lockで重複拒否。DBにはexclusion制約がなく、Laravel経由が前提。
- 税区分: PHP enum validation。甲乙/扶養、交通費taxable/non_taxable。
- route認可: 多くの業務Routeはadminとemployeeの両方を許可している。機能別Policyはない。所得税状況だけ指定メールのdevelopment admin限定。

【問題点】employeeにも店舗・staff・給与・歩合・単価変更を含む広いRoute権限がある。新しい財務情報を追加する前に閲覧/入力/承認/確定/出力の権限行列が必要である。

## 18. Tests

調査時実行結果:

- PHP: 196 passed、6,084 assertions、失敗0。
- Frontend: 5 files、26 passed、失敗0。
- Browser/E2E専用suite: なし。PCブラウザは手動確認済みとのこと。スマホ/タブレット実機は後日確認予定。

PHP test fileはUnit 5本、Feature 22本（Auth/Settingsを含む）。主なcoverage:

| 領域                                                | 有無 / 代表test                                                                  |
| --------------------------------------------------- | -------------------------------------------------------------------------------- |
| 日跨ぎ・12時境界・深夜・15分・24h未満               | 有。AttendanceTimeServiceTest、BusinessDateServiceTest、AttendanceManagementTest |
| 店休・休・急休・他店help・同日競合                  | 有。ShiftManagementTest、AttendanceManagementTest                                |
| 適用期間・在籍・所属・初期import                    | 有。EffectivePeriodTest、MasterDataTest                                          |
| 給与・丸め・履歴・stale                             | 有。PayrollManagementTest、PhaseFiveOutputTest                                   |
| 2026/2027所得税・甲乙・扶養・境界/連続性            | 有。PayrollManagementTest、IncomeTaxSourceFetchTest                              |
| PDF/ZIP/Excel/PNG・認証                             | 有。PhaseFiveOutputTest、PhaseSixAcceptanceTest                                  |
| 権限                                                | 有。ただし現行の粗いrole設定が期待値                                             |
| React表示補助・monthly state・download              | 26 testあり                                                                      |
| 実ブラウザD&D、sticky、responsive visual regression | 自動testなし                                                                     |
| 大量データ性能、同時編集の実DB負荷                  | 専用testなし                                                                     |

## 19. Seeder / 初期データ

- `DatabaseSeeder`: IncomeTaxTableSeederを呼び、店舗「46」「オニカイ」「蛸福」をupdateOrCreate。営業時間はDB default 17:00〜翌10:00。
- 初期管理者: `INITIAL_ADMIN_NAME/EMAIL/PASSWORD`からupdateOrCreate。staff_id null、admin、verified。認証情報未設定ならskip。本番でpassword文字列`password`は禁止。
- `IncomeTaxTableSeeder`: 2026/2027のCSVをDBへ投入し、全Payrollをstale化。
- スタッフseed/test dataは本番Seederにない。初期スタッフは管理画面のCSV/XLSX importを使用。
- 共通設定テーブルSeederはない。深夜加算は画面から履歴登録する。

## 20. 未実装 / TODO

コード内の`TODO`、`FIXME`、`HACK`、`XXX`は調査時0件だった。ただし、次は機能として未実装または意図的対象外である。

- 社員給与、社会保険、住民税、休憩、残業/休日割増、有休管理
- 同一営業日の複数店舗勤務
- Payrollの承認/確定/支払済みstatus（あるのはstale boolのみ）
- 操作監査ログ、変更理由、承認workflow、締め後lock
- 売上/仕入/経費/在庫/予算/FL/PL/KPI
- 外部POS/会計/銀行/カード連携、CSV取込履歴・idempotency key
- Browser E2E、visual regression、スマホ/タブレット実機受入

コメントアウトはLaravel設定の標準exampleが中心で、現行業務の暫定分岐は確認されなかった。Inertia DevTools route、local storage serve routeは環境依存のため、本番構成で有効範囲を確認する必要がある。

## 21. 技術的負債・変更リスク

### 現状

1. `MonthlyAggregationService`と`PayrollCalculationService`が、履歴解決、raw賃金、丸めを別々に実装している。
2. Dashboardにも勤務人数等の直接Queryがあり、集計定義が複数箇所へ増えやすい。
3. roleはadmin/employeeの2種だが、大半の業務Routeは同権限。Policy、店舗scope、承認権限がない。
4. 金額は給与向け整数円のみ。消費税区分、税込/税抜、税率履歴、端数規則、通貨を持たない。
5. 単一会社前提で、会計年度・会計期間・締め・伝票番号・確定versionがない。
6. Shift/Attendanceが1スタッフ×営業日1件で、分割勤務/複数店配賦を表現できない。
7. 一括給与/PDF/XLSXは同期処理。件数増大時のjob化、進捗、再実行管理がない。
8. 適用期間重複はService保証で、DB単体では防げない。

### 問題点

売上や仕入をPageごとに直接集計すると、FL/PL/KPI間で数値定義がずれる危険がある。また「仕入=原価」とすると在庫増減を扱えず、飲食店の正確な売上原価にならない。社員を除外した現在の人件費をそのままLと呼ぶことも誤解を生む。

### 将来の推奨

- 先に会計/KPI用語集と計算規約を決め、日次/店舗/月次が同じApplication Serviceまたはread modelを参照する。
- 金額は整数円を基本にしても、税抜計算・配賦途中は十分なdecimal精度を保持し、丸め時点を明文化する。
- 財務recordにdraft/confirmed/closed、source、created_by/updated_by/confirmed_by、取込batch、変更履歴を持たせる。
- 重いimport/export/再集計はQueue化し、idempotentなjobと実行statusを持たせる。
- 既存Serviceを書き換える前に、共通のRateResolver/LaborCostCalculatorを抽出する設計を追加仕様で定義する。

## 22. 現在のシステム構成図

```text
Browser
  ├─ React 19 + TypeScript + Tailwind/Radix
  └─ Inertia router / form
          ↓ HTTP + CSRF + session cookie
nginx :8082
          ↓ PHP-FPM
Laravel 13
  ├─ Fortify / Middleware（auth, verified, role）
  ├─ Controller / FormRequest
  ├─ Application Services
  ├─ Eloquent Models
  ├─ Dompdf / PhpSpreadsheet / GD / ZipArchive
  └─ Scheduler → 国税庁原典取得
          ↓ PDO
MySQL 8.4

private storage
  ├─ 所得税原典・取得status
  └─ PDF ZIP/XLSX一時file（response後削除）
```

Vite開発サーバーはDocker node service、nginxはpublicをdocument rootとする。SessionとcacheはDB設定が基本で、Queue tableは存在する。

## 23. 現在の主要データフロー

```text
A. Shift
React月間cell / 日別一括
 → Shift FormRequest
 → ShiftSaveService（在籍・所属・休日・営業時間・競合）
 → shifts
 → ShiftCalendarService
 → 月間/日別画面 または ShiftPngService → PNG

B. Attendance
React日次（work_date + 15分時刻）
 → AttendanceDailyRequest
 → AttendanceSaveService
 → AttendanceTimeService（実日時・実働・深夜重複）
 → attendance_records
 → Payrollをstale
 → 日次画面 / MonthlyAggregationService

C. Payroll
attendance_records + 日付時点wage/late/transport
 + commissions + 支払日時点staff tax setting
 → PayrollCalculationService
 → IncomeTaxCalculationService + income_tax_rules
 → payrolls snapshot
 → 給与画面 / PDF / 全店舗集計

D. Income tax source
Laravel Scheduler
 → 国税庁page/Excelの取得・検証
 → private storage + status JSON
 → 開発管理者review
 → CSV/Seeder（別工程）
 → income_tax_table_versions/rules

E. PDF/ZIP
非stale・gross>0 payroll
 → PayrollPdfService + Blade + Dompdf
 → 個別PDF、またはZipArchive
 → download（no-store）→ 一時file削除

F. Excel
attendance + histories + payroll snapshots
 → MonthlyAggregationService（共通report）
 → AttendanceExcelService / PhpSpreadsheet
 → XLSX download → 一時file削除
```

## 24. 売上・仕入・経費・FL・PL追加時の接続ポイント

### 24.1 既存資産を活かす接続案

| 追加機能               | 既存への接続点                     | 新規ドメインの候補                           | 注意                                                                       |
| ---------------------- | ---------------------------------- | -------------------------------------------- | -------------------------------------------------------------------------- |
| 日次売上・客数・客単価 | `stores.id`、営業日概念            | daily_sales、sales_services                  | 客単価は`net_sales / customer_count`。営業日cutoffを勤怠と揃えるか決定     |
| 決済別売上             | daily_sales                        | payment_methods、daily_sales_payments        | 現金/カード等の合計が売上と一致する制約、手数料は別expenseか明確化         |
| 仕入・仕入先           | stores.id                          | suppliers、purchases、purchase_lines         | 購入日、営業日、計上日、支払日を混同しない                                 |
| 食材/ドリンク原価      | purchases                          | items/categories、inventories、stocktakes    | 正式原価には期首在庫+仕入-期末在庫が必要。仕入だけでは実際原価率にならない |
| 経費・現金支出         | stores.id                          | expense_categories、expenses、payments       | 支出日と費用計上月、税込/税抜、証憑、支払方法が必要                        |
| 固定費                 | stores.id                          | recurring_expense_rules、expense_allocations | 店舗固定/全社共通、日割/月割、配賦basisをversion管理                       |
| 日次人件費率           | MonthlyAggregationServiceの日別raw | labor_cost_read_model / KPI service          | 現行はアルバイト変動費のみ。売上0日の扱いも定義                            |
| 原価率・FL率           | sales + COGS + labor               | KPI calculation service/snapshot             | 分母は税抜売上か、F/Lの範囲、丸め、確定statusを統一                        |
| 目標差額               | stores.id、対象月                  | budgets/targets、target_versions             | 売上、F、L、経費ごとの目標と改定履歴                                       |
| 月次PL                 | store/月                           | pl_accounts、monthly_pl_snapshots            | 管理会計PLと会計ソフトPLのどちらか、発生/現金basis、共通費配賦を確定       |
| 店舗経営dashboard      | SelectedStoreService、既存layout   | management_dashboard page、KPI query service | session店舗選択を再利用し、期間queryをURLへ残す                            |

### 24.2 追加仕様書を作る前に必要な追加確認事項

依頼された調査項目に加え、次を確定しないと数値の正しさを保証できない。

1. **営業日と締め時刻**: 売上/POSも12:00切替にするか。POSの営業日を正本にするか。
2. **売上定義**: 税込/税抜、値引、取消、返金、service料、売掛、ポイント、現金過不足をどう扱うか。
3. **消費税**: 税率履歴、軽減税率、内税/外税、仕入税額、端数単位。給与所得税とは完全に分離する。
4. **原価定義**: 仕入額を簡易Fとするか、棚卸を含む売上原価を正式Fとするか。食材/ドリンク/消耗品の境界。
5. **在庫**: 月末棚卸、店舗間移動、廃棄、賄い、単位換算、評価法が必要か。
6. **人件費定義**: 社員給与、法定福利、歩合、交通費、賞与、応援人件費をLへ含めるか。応援勤務は勤務店/所属店どちらへ配賦するか。
7. **共通費配賦**: 本部費、家賃等を店舗へ配賦するbasis（固定額、売上比、面積比等）と適用期間。
8. **会計期間**: 暦月か、決算期、月次締め日、締め後修正/再締め、過去月lock。
9. **入力・連携元**: 手入力、CSV、POS、会計ソフト、銀行。外部ID、取込batch、重複防止、再取込/取消規則。
10. **権限・承認**: 店長、本部、経理、開発管理者ごとの店舗scope、閲覧/入力/承認/締め/出力。
11. **監査**: 誰がいつ変更・承認したか、変更前後、理由、証憑保管期間。
12. **予算/目標**: 日/月、店舗、version、曜日/祝日配賦、途中改定と実績比較。
13. **KPI確定値**: リアルタイム暫定値と月締め確定snapshotの両方が必要か。
14. **PL科目**: 売上・原価・販管費の科目体系、店舗別/全社、内部取引、丸めと0除算表示。
15. **データ保持/性能**: 想定店舗数、日次伝票行数、保存年数、export量、backup/restore要件。

### 24.3 推奨する実装境界

```text
既存 Workforce domain
  shifts / attendance / payroll / labor aggregation
                    │ staff_id, store_id, business_date, month
                    ↓
新規 Management Accounting domain
  Sales ─ Purchases/Inventory ─ Expenses ─ Budgets
                    ↓
       KPI / FL / PL calculation services
                    ↓
       daily/monthly confirmed snapshots
                    ↓
       store management dashboard / exports
```

【将来の推奨】既存テーブルへ売上・仕入列を追加するのではなく、`store_id`、営業日、年月を契約点として別ドメインで追加する。既存`SelectedStoreService`、レイアウト、認証、export方式、BusinessDateの考え方は再利用できる。一方、FL/PLの集計本体は既存`MonthlyAggregationService`へ肥大化させず、会計定義と締め状態を持つ専用Service/read modelに分離するのが安全である。

### 24.4 結論

既存の店舗・営業日・勤怠・アルバイト変動人件費は、新しい店舗経営機能の良い土台になる。ただし追加仕様書では、最初に「原価F」「人件費L」「売上」「会計月」「確定値」の定義を固定する必要がある。特に在庫を無視した仕入原価、社員を除いたL、締めのないPLを正式値として扱わないことが重要である。
