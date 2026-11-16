-- Воркеры запуска: потолок воркеров на запуск и стоимость запуска с воркерами читают их по родителю.

CREATE INDEX runs_by_parent ON runs (parent_run_id) WHERE parent_run_id IS NOT NULL;
