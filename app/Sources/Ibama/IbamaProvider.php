<?php

namespace App\Sources\Ibama;

use App\Datasets\Models\DatasetRecord;
use App\Datasets\Models\DatasetVersion;
use App\Sources\Contracts\RiskSourceProvider;
use App\Sources\Data\Entity;
use App\Sources\Data\ProviderResponse;
use App\Sources\Data\SourceConfig;
use App\Sources\Exceptions\PermanentSourceException;
use Illuminate\Support\Collection;

/**
 * Fonte em modo dataset local: dispatch() é um SELECT, não HTTP.
 * A ingestão do arquivo é outro pipeline (datasets:ingest-ibama), nunca disparado daqui.
 */
class IbamaProvider implements RiskSourceProvider
{
    public function __construct(private IbamaNormalizer $normalizer) {}

    public function dispatch(Entity $entity, SourceConfig $config): ProviderResponse
    {
        $dataset = $config->param('dataset', 'ibama_embargos');
        $version = DatasetVersion::activeFor($dataset)->first();

        // Sem versão ativa NÃO é "nada encontrado" — é "não sabemos".
        // Devolver vazio aqui seria o falso negativo silencioso.
        if ($version === null) {
            throw new PermanentSourceException("Dataset {$dataset} sem versão ativa: rode datasets:ingest-ibama.");
        }

        $records = DatasetRecord::query()
            ->where('dataset_version_id', $version->id)
            ->where('document', $entity->document)
            ->limit(500)
            ->pluck('payload')
            ->all();

        return new ProviderResponse([
            'dataset_version_id' => $version->id,
            'published_at' => $version->published_at?->toIso8601String(),
            'ingested_at' => $version->ingested_at?->toIso8601String(),
            'records' => $records,
        ]);
    }

    public function normalize(array $rawPayload): Collection
    {
        return $this->normalizer->normalize($rawPayload);
    }
}
