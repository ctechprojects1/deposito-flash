<?php

namespace App\Console\Commands;

use App\Services\StockImportService;
use Illuminate\Console\Command;

class ImportInitialStock extends Command
{
    protected $signature = 'stock:import
                            {arquivo : Caminho do arquivo CSV a ser importado}
                            {--delimiter= : Força o delimitador (padrão: detecta , ou ;)}
                            {--somar : Soma na quantidade existente em vez de sobrescrever}';

    protected $description = 'Importa estoque de um CSV (Time, Codigo_Produto, Nome, Quantidade).';

    public function handle(StockImportService $service): int
    {
        $caminho = $this->argument('arquivo');

        $this->info('Iniciando importação...');

        try {
            $resultado = $service->importFromPath(
                $caminho,
                $this->option('delimiter') ?: null,
                (bool) $this->option('somar'),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Importação concluída.');
        $this->table(
            ['Importadas', 'Ignoradas', 'Locais', 'Produtos'],
            [[$resultado['importados'], $resultado['ignorados'], $resultado['locais'], $resultado['produtos']]]
        );

        foreach (array_slice($resultado['erros'], 0, 20) as $erro) {
            $this->warn($erro);
        }

        return self::SUCCESS;
    }
}
