# 設計判断記録（ADR）

ADRは、要件を満たすために選択した重要な実装方式と理由を残す文書である。要件そのものは[要件定義](../requirements.md)を正とする。

## 状態

- Proposed: 検討中
- Accepted: 採用
- Superseded: 後続ADRで置換済み
- Rejected: 不採用

## 一覧

| ADR | タイトル | 状態 |
|---|---|---|
| [0001](0001-docker-compose.md) | ローカル開発にDocker Composeを使用する | Accepted |
| [0002](0002-global-day-off.md) | 休みを店舗非依存のシフト行として保存する | Accepted |
| [0003](0003-work-date-late-night.md) | 深夜加算区間をwork_dateから構築する | Accepted |

## 作成ルール

- データ構造、外部依存、計算方法、運用へ長期的な影響がある判断を記録する。
- 採用済みADRを直接逆の内容へ書き換えず、新しいADRで置換する。
- 要件変更をADRだけで行わず、要件定義と変更履歴も更新する。
