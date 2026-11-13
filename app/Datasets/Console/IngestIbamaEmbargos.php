<?php

namespace App\Datasets\Console;

use App\Datasets\IbamaEmbargosIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class IngestIbamaEmbargos extends Command
{
    protected $signature = 'datasets:ingest-ibama
        {--file= : CSV ou ZIP local (pula o download)}
        {--published-at= : Data de publicação da lista (AAAA-MM-DD); padrão: data do arquivo}';

    protected $description = 'Baixa e ingere a lista de embargos do IBAMA numa versão nova, com swap atômico';

    public function handle(IbamaEmbargosIngestor $ingestor): int
    {
        $path = $this->option('file') ?: $this->download();
        $publishedAt = $this->option('published-at') ? CarbonImmutable::parse($this->option('published-at')) : null;

        $this->info("Ingerindo {$path}…");
        $version = $ingestor->ingest($path, $publishedAt);

        if ($version === null) {
            $this->info('Arquivo idêntico ao da versão ativa — nada a fazer.');

            return self::SUCCESS;
        }

        $this->info("Versão {$version->id} ativa: {$version->row_count} registros, publicada em {$version->published_at?->toDateString()}.");

        return self::SUCCESS;
    }

    private function download(): string
    {
        $url = config('sources.datasets.ibama_embargos.url');
        $dir = storage_path('app/datasets/ibama');
        File::ensureDirectoryExists($dir);
        $target = $dir.'/'.now()->format('Ymd_His').'_'.basename(parse_url($url, PHP_URL_PATH));

        $this->info("Baixando {$url}…");
        Http::timeout(900)->sink($target)->get($url)->throw();

        return $target;
    }
}
