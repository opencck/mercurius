<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm\Claude;

use Closure;
use Mercurius\Llm\ModelCatalog\ModelInfo;

/**
 * The models of the subscription: the list of the module, described by the public catalog (window, modalities,
 * what the model takes) — and with the price of nothing. A call by the subscription is not billed per token, so in
 * the choice of a model it costs zero, like the ChatGPT subscription; what the same tokens would cost by the price
 * list is an estimate in the trace, not a bill.
 *
 * spec: brief/models.md#claude-по-подписке
 */
final class ClaudeCatalog {
    /** @param Closure(): list<ModelInfo> $models the models of the module with the prices of the price list */
    public function __construct(private readonly Closure $models) {}

    /** @return list<ModelInfo> */
    public function fetch(): array {
        return array_map(static fn(ModelInfo $model): ModelInfo => new ModelInfo(
            $model->id,
            $model->name,
            $model->contextLength,
            0.0,
            0.0,
            $model->inputModalities,
            $model->outputModalities,
            $model->supportedParameters,
            $model->maxCompletionTokens,
            0.0,
            0.0,
            $model->intelligenceIndex,
            $model->codingIndex,
            $model->agenticIndex,
            $model->reasoningMandatory,
        ), ($this->models)());
    }
}
