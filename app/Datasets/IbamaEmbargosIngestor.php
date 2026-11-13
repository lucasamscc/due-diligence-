<?php

namespace App\Datasets;

use App\Datasets\Actions\PublishDatasetVersion;
use App\Datasets\Models\DatasetRecord;
use App\Datasets\Models\DatasetVersion;
use App\Diligence\Support\Cnpj;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use RuntimeException;
use ZipArchive;

/**
 * Pipeline de ingestão do dataset de embargos do IBAMA.
 * Totalmente independente das diligências: roda no scheduler, escreve uma
 * versão nova em paralelo e só no fim troca a ativa (PublishDatasetVersion).
 */
class IbamaEmbargosIngestor
{
    public const SOURCE = 'ibama_embargos';

    public function __construct(private PublishDatasetVersion $publish) {}

    /**
     * @return DatasetVersion|null null quando o arquivo é idêntico ao da versão ativa (diff vazio)
     */
    public function ingest(string $path, ?CarbonImmutable $publishedAt = null): ?DatasetVersion
    {
        $config = config('sources.datasets.'.self::SOURCE);
        $csvPath = $this->extractCsv($path);
        $hash = hash_file('sha256', $csvPath);

        if (DatasetVersion::activeFor(self::SOURCE)->where('file_hash', $hash)->exists()) {
            return null;
        }

        $version = DatasetVersion::create([
            'source' => self::SOURCE,
            'published_at' => $publishedAt ?? CarbonImmutable::createFromTimestamp(filemtime($path)),
            'source_file' => basename($path),
            'file_hash' => $hash,
            'status' => 'ingesting',
        ]);

        $reader = Reader::createFromPath($csvPath);
        $reader->setDelimiter($config['delimiter']);
        $reader->setHeaderOffset(0);
        if (strtoupper($config['encoding']) !== 'UTF-8') {
            CharsetConverter::addTo($reader, $config['encoding'], 'UTF-8');
        }

        $columns = $this->resolveColumns($reader->getHeader(), $config['columns']);
        $count = 0;

        LazyCollection::make(fn () => yield from $reader->getRecords())
            ->map(fn (array $row, int $offset) => $this->toRecord($version->id, $row, $columns, $offset))
            ->chunk(1000)
            ->each(function (LazyCollection $chunk) use (&$count) {
                $rows = $chunk->values()->all();
                // upsert: reexecutar a ingestão da MESMA versão não duplica linhas.
                DatasetRecord::upsert($rows, ['dataset_version_id', 'source_key'], ['document', 'name_normalized', 'payload']);
                $count += count($rows);
            });

        $version->update(['row_count' => $count]);

        return $this->publish->handle($version, $config['min_rows'], $config['max_shrink_ratio']);
    }

    private function toRecord(int $versionId, array $row, array $columns, int $offset): array
    {
        $get = fn (string $field) => isset($columns[$field]) ? trim((string) ($row[$columns[$field]] ?? '')) : null;

        $document = Cnpj::strip((string) $get('document'));
        $payload = [
            'key' => $get('key') ?: (string) $offset,
            'name' => $get('name'),
            'document' => $document ?: null,
            'date' => $get('date'),
            'municipality' => $get('municipality'),
            'uf' => $get('uf'),
            'description' => mb_substr((string) $get('description'), 0, 2000) ?: null,
            'process' => $get('process'),
            'cancelled' => strtoupper((string) $get('cancelled')) === 'S',
            'lifted_at' => $get('lifted_at') ?: null,
        ];

        return [
            'dataset_version_id' => $versionId,
            'source_key' => $payload['key'],
            // CPF mascarado ("***.123.456-**") vira lixo após strip: guarda só documento completo.
            'document' => in_array(strlen($document), [11, 14], true) ? $document : null,
            'name_normalized' => NameNormalizer::normalize($payload['name']),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<string, string> campo lógico => cabeçalho real do arquivo */
    private function resolveColumns(array $header, array $candidates): array
    {
        $byUpper = collect($header)->mapWithKeys(fn ($h) => [mb_strtoupper(trim($h)) => $h]);
        $resolved = [];

        foreach ($candidates as $field => $names) {
            foreach ($names as $name) {
                if ($byUpper->has($name)) {
                    $resolved[$field] = $byUpper[$name];
                    break;
                }
            }
        }

        if (! isset($resolved['document'])) {
            throw new RuntimeException('Coluna de CPF/CNPJ não encontrada. Cabeçalho do arquivo: '.implode(', ', $header));
        }

        return $resolved;
    }

    private function extractCsv(string $path): string
    {
        if (! str_ends_with(strtolower($path), '.zip')) {
            return $path;
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Não foi possível abrir {$path}");
        }

        $dir = storage_path('app/datasets/tmp/'.uniqid());
        $zip->extractTo($dir);
        $zip->close();

        $csv = collect(glob("{$dir}/*.csv") ?: [])->sortByDesc(fn ($f) => filesize($f))->first();

        return $csv ?? throw new RuntimeException("Nenhum .csv dentro de {$path}");
    }
}
