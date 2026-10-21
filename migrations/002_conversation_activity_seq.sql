-- 会话活跃度排序修复：updated_at 秒级精度无法区分同秒内的活跃先后，
-- 列表排序改用单调递增的 updated_seq（毫秒时间戳前缀的 id）
-- 存量行为 NULL，DESC 排序时排在最后（MySQL/SQLite 行为一致）

ALTER TABLE ai_conversation ADD COLUMN updated_seq VARCHAR(32)
