# Groovy Shift System

株式会社Groovy向けのシフト・勤怠・給与管理システムです。

現在は実装準備段階です。確定仕様と実装計画は、リポジトリ内の次の文書を正本として管理します。

- [業務・機能要件](docs/requirements.md)
- [計算ルール](docs/calculation-rules.md)
- [データモデル](docs/data-model.md)
- [給与明細テンプレート対応](docs/payroll-template.md)
- [実装ロードマップ](docs/roadmap.md)
- [テストマトリクス](docs/test-matrix.md)
- [仕様変更履歴](docs/changelog.md)
- [設計判断記録](docs/adr/README.md)

ローカル開発環境はDocker Composeで構築します。起動方法、初期セットアップ、テストコマンドはPhase 0の実装時に本READMEへ追記します。

## 文書の更新方針

- `docs/requirements.md`を確定要件の正本とします。
- 実装都合で要件を暗黙に変更しません。
- 計算仕様の変更は`docs/calculation-rules.md`とテストを同時に更新します。
- データ構造の変更は`docs/data-model.md`と必要なADRを同時に更新します。
- 各フェーズの進捗と完了条件は`docs/roadmap.md`で管理します。
- 重要な仕様変更は`docs/changelog.md`へ理由と影響範囲を記録します。
