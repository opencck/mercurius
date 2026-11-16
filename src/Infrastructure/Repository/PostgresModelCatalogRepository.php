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
                'INSERT INTO model_catalog (provider, id, name, context_length, input_price, output_price, input_modalities, supported_parameters, max_completion_tokens)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?) ON CONFLICT (provider, id) DO NOTHING',
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
                ]);
            }
            $tx->commit();
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    public function find(ModelRef $model): ?ModelInfo {
        $row = $this->db->execute(
            'SELECT name, context_length, input_price, output_price, input_modalities::text AS input_modalities,
                    supported_parameters::text AS supported_parameters, max_completion_tokens
             FROM model_catalog WHERE provider = ? AND id = ?',
            [$model->provider, $model->model],
        )->fetchRow();
        if ($row === null) {
            return null;
        }
        $name = $row['name'] ?? null;
        $window = $row['context_length'] ?? null;
        $in = $row['input_price'] ?? null;
        $out = $row['output_price'] ?? null;
        $max = $row['max_completion_tokens'] ?? null;
        return new ModelInfo(
            $model->model,
            is_string($name) ? $name : $model->model,
            is_int($window) ? $window : null,
            is_float($in) ? $in : null,
            is_float($out) ? $out : null,
            self::strings($row['input_modalities'] ?? null),
            [],
            self::strings($row['supported_parameters'] ?? null),
            is_int($max) ? $max : null,
        );
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
