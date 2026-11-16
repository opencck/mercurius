<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Repository\ModelCatalogRepository;

final class PostgresModelCatalogRepository implements ModelCatalogRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function replace(string $provider, array $models): void {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('DELETE FROM model_catalog WHERE provider = ?', [$provider]);
            $insert = $tx->prepare(
                'INSERT INTO model_catalog (provider, id, name, context_length, input_price, output_price, input_modalities, supported_parameters,
                    max_completion_tokens, intelligence_index, coding_index, agentic_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?) ON CONFLICT (provider, id) DO NOTHING',
            );
            foreach ($models as $model) {
                $insert->execute([
                    $provider,
                    $model->id,
                    $model->name,
                    $model->contextLength,
                    $model->inputPricePerToken,
                    $model->outputPricePerToken,
                    json_encode($model->inputModalities, JSON_THROW_ON_ERROR),
                    json_encode($model->supportedParameters, JSON_THROW_ON_ERROR),
                    $model->maxCompletionTokens,
                    $model->intelligenceIndex,
                    $model->codingIndex,
                    $model->agenticIndex,
                ]);
            }
            $tx->commit();
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    private const COLUMNS = 'id, name, context_length, input_price, output_price, input_modalities::text AS input_modalities,
        supported_parameters::text AS supported_parameters, max_completion_tokens, intelligence_index, coding_index, agentic_index';

    public function find(ModelRef $model): ?ModelInfo {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM model_catalog WHERE provider = ? AND id = ?', [$model->provider, $model->model])->fetchRow();
        return $row === null ? null : self::info($row);
    }

    public function models(string $provider): array {
        $models = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM model_catalog WHERE provider = ? ORDER BY id', [$provider]) as $row) {
            $models[] = self::info($row);
        }
        return $models;
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): ModelInfo {
        $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
        return new ModelInfo(
            $id,
            isset($row['name']) && is_string($row['name']) ? $row['name'] : $id,
            isset($row['context_length']) && is_int($row['context_length']) ? $row['context_length'] : null,
            self::score($row, 'input_price'),
            self::score($row, 'output_price'),
            self::strings($row['input_modalities'] ?? null),
            [],
            self::strings($row['supported_parameters'] ?? null),
            isset($row['max_completion_tokens']) && is_int($row['max_completion_tokens']) ? $row['max_completion_tokens'] : null,
            null,
            null,
            self::score($row, 'intelligence_index'),
            self::score($row, 'coding_index'),
            self::score($row, 'agentic_index'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function score(array $row, string $key): ?float {
        return isset($row[$key]) && is_float($row[$key]) ? $row[$key] : null;
    }

    public function fetchedAt(string $provider): ?DateTimeImmutable {
        $row = $this->db->execute('SELECT extract(epoch FROM max(fetched_at))::bigint AS at FROM model_catalog WHERE provider = ?', [$provider])->fetchRow();
        $at = $row['at'] ?? null;
        return is_int($at) ? (new DateTimeImmutable())->setTimestamp($at) : null;
    }

    /** @return list<string> */
    private static function strings(mixed $json): array {
        if (!is_string($json)) {
            return [];
        }
        /** @var mixed $list */
        $list = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($list)) {
            return [];
        }
        $strings = [];
        foreach (array_keys($list) as $key) {
            if (is_string($list[$key])) {
                $strings[] = $list[$key];
            }
        }
        return $strings;
    }
}
