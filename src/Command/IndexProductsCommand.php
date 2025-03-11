<?php

namespace App\Command;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:index-products', description: 'Indeksuje produkty w Elasticsearch')]
class IndexProductsCommand extends Command
{
    private Client $client;
    private EntityManagerInterface $entityManager;

    public function __construct(Client $client, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->client = $client;
        $this->entityManager = $entityManager;
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        // Krok 1: Sprawdź stan klastra Elasticsearch
        try {
            $health = $this->client->cluster()->health();
            $io->info(sprintf('Stan klastra Elasticsearch: %s', $health['status']));

            if ($health['status'] === 'red') {
                $io->warning('Klaster Elasticsearch jest w stanie "red". Próba naprawy...');

                // Opcjonalnie: Można tutaj dodać logikę naprawy klastra
                sleep(5); // Daj czas na ewentualne automatyczne naprawy

                // Sprawdź ponownie
                $health = $this->client->cluster()->health();
                if ($health['status'] === 'red') {
                    $io->error('Klaster nadal w stanie "red". Zalecane jest zresetowanie Elasticsearch.');
                    if (!$io->confirm('Czy kontynuować mimo to?', false)) {
                        return Command::FAILURE;
                    }
                }
            }
        } catch (\Exception $e) {
            $io->error('Błąd połączenia z Elasticsearch: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Krok 2: Usuń stary indeks i zaczekaj na zakończenie operacji
        try {
            if ($this->client->indices()->exists(['index' => 'products'])) {
                $io->info('Usuwanie starego indeksu "products"...');
                $this->client->indices()->delete(['index' => 'products']);

                // Poczekaj na zakończenie operacji
                sleep(2);

                // Sprawdź, czy faktycznie indeks został usunięty
                $attempts = 0;
                while ($this->client->indices()->exists(['index' => 'products']) && $attempts < 5) {
                    $io->info('Oczekiwanie na usunięcie indeksu...');
                    sleep(2);
                    $attempts++;
                }
            } else {
                $io->info('Indeks "products" nie istniał wcześniej.');
            }
        } catch (\Exception $e) {
            $io->warning('Błąd podczas usuwania indeksu: ' . $e->getMessage());
            // Kontynuuj, ponieważ może to być problem z brakiem indeksu
        }

        // Krok 3: Utwórz nowy indeks z optymalną konfiguracją dla środowiska jednowęzłowego
        try {
            $io->info('Tworzenie nowego indeksu "products"...');
            $this->client->indices()->create([
                'index' => 'products',
                'body' => [
                    'settings' => [
                        'number_of_shards' => 1,                 // Tylko jedna szarda w środowisku jednowęzłowym
                        'number_of_replicas' => 0,               // Bez replik w środowisku jednowęzłowym
                        'refresh_interval' => '30s',             // Rzadsze odświeżanie dla lepszej wydajności
                        'index.write.wait_for_active_shards' => 1, // Kontynuuj nawet jeśli aktywna jest tylko 1 szarda
                    ],
                    'mappings' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'analyzer' => 'standard'],
                            'price' => ['type' => 'float'],
                            'created_at' => ['type' => 'date'],
                            'description' => ['type' => 'text', 'analyzer' => 'standard']
                        ]
                    ]
                ]
            ]);

            // Poczekaj na inicjalizację indeksu
            $io->info('Czekam na inicjalizację indeksu...');
            $this->waitForIndexReady('products', $io);

        } catch (\Exception $e) {
            $io->error('Błąd tworzenia indeksu: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Krok 4: Indeksuj produkty w mniejszych partiach z obsługą błędów
        $batchSize = 500; // Mniejszy rozmiar partii
        $maxRetries = 3;  // Maksymalna liczba prób dla nieudanych partii

        $productRepository = $this->entityManager->getRepository(Product::class);

        // Pobierz łączną liczbę produktów
        $count = $productRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $io->info(sprintf('Znaleziono %d produktów do zaindeksowania.', $count));

        // Inicjalizacja paska postępu
        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        // Przetwarzanie wsadowe z użyciem offsetów
        $offset = 0;
        $totalIndexed = 0;
        $failedProducts = [];

        while ($totalIndexed < $count) {
            // Upewnij się, że używasz wersji queryBuilder, która odświeża połączenie
            $this->entityManager->clear();

            $products = $productRepository->createQueryBuilder('p')
                ->setFirstResult($offset)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();

            if (empty($products)) {
                break;
            }

            $params = ['body' => []];
            $productIds = [];

            foreach ($products as $product) {
                $productId = $product->getId();
                $productIds[] = $productId;

                $params['body'][] = [
                    'index' => [
                        '_index' => 'products',
                        '_id' => $productId
                    ]
                ];

                $params['body'][] = [
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'created_at' => $product->getCreatedAt()->format('c'),
                    'description' => $product->getDescription()
                ];
            }

            if (!empty($params['body'])) {
                $success = false;
                $attempt = 0;

                while (!$success && $attempt < $maxRetries) {
                    try {
                        // Przed wysłaniem bulk, sprawdź stan indeksu
                        if ($attempt > 0) {
                            $io->info('Próba ponownego indeksowania partii, próba ' . ($attempt + 1));
                            // Daj dodatkowy czas przed ponowną próbą
                            sleep(5 * $attempt);

                            // Sprawdź stan indeksu
                            $this->waitForIndexReady('products', $io);
                        }

                        $response = $this->client->bulk($params);

                        // Sprawdź, czy były błędy
                        if (isset($response['errors']) && $response['errors']) {
                            $hasShardErrors = false;
                            $errorItems = [];

                            foreach ($response['items'] as $idx => $item) {
                                if (isset($item['index']['error'])) {
                                    $errorReason = $item['index']['error']['reason'] ?? 'Nieznany błąd';
                                    $errorType = $item['index']['error']['type'] ?? 'unknown';

                                    // Sprawdź, czy to błąd związany z szardami
                                    if (strpos($errorReason, 'shard') !== false ||
                                        $errorType === 'unavailable_shards_exception') {
                                        $hasShardErrors = true;
                                    }

                                    $productId = $productIds[$idx / 2];
                                    $errorItems[$productId] = $errorReason;
                                }
                            }

                            if ($hasShardErrors) {
                                // Jeśli są błędy związane z szardami, spróbuj ponownie
                                $io->warning('Wystąpiły błędy szardów, próba ponowna za chwilę...');
                                $attempt++;
                                continue;
                            } else {
                                // Inne błędy - zapisz je i kontynuuj
                                foreach ($errorItems as $productId => $reason) {
                                    $failedProducts[$productId] = $reason;
                                }
                                $success = true; // Kontynuuj, pomimo "zwykłych" błędów
                            }
                        } else {
                            $success = true;
                        }
                    } catch (\Exception $e) {
                        $io->warning(sprintf('Błąd podczas indeksowania partii: %s', $e->getMessage()));
                        $attempt++;

                        if ($attempt >= $maxRetries) {
                            // Zapisz wszystkie produkty z tej partii jako nieudane
                            foreach ($productIds as $productId) {
                                $failedProducts[$productId] = $e->getMessage();
                            }
                        }
                    }
                }
            }

            $totalIndexed += count($products);
            $progressBar->advance(count($products));
            $offset += $batchSize;

            // Okresowe odświeżanie indeksu
            if ($totalIndexed % 2000 === 0) {
                try {
                    $this->client->indices()->refresh(['index' => 'products']);
                    $io->info(sprintf('Zaindeksowano %d produktów. Odświeżono indeks.', $totalIndexed));
                } catch (\Exception $e) {
                    $io->warning('Nie można odświeżyć indeksu: ' . $e->getMessage());
                }
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Krok 5: Finalizacja indeksu
        try {
            $io->info('Finalizacja indeksu...');

            // Odśwież indeks na koniec
            $this->client->indices()->refresh(['index' => 'products']);

            // Zoptymalizuj indeks
            $this->client->indices()->forcemerge([
                'index' => 'products',
                'max_num_segments' => 1  // Skonsoliduj do jednego segmentu dla lepszej wydajności odczytu
            ]);

            // Sprawdź liczbę zaindeksowanych dokumentów
            $stats = $this->client->count(['index' => 'products']);
            $io->success(sprintf('Pomyślnie zaindeksowano %d z %d dokumentów', $stats['count'], $count));

            // Raportuj nieudane produkty
            if (!empty($failedProducts)) {
                $io->warning(sprintf('Nie udało się zaindeksować %d produktów', count($failedProducts)));
                // Opcjonalnie: zapisz listę nieudanych produktów do pliku
            }
        } catch (\Exception $e) {
            $io->error('Błąd podczas finalizacji indeksu: ' . $e->getMessage());
        }

        $executionTime = microtime(true) - $startTime;
        $io->info(sprintf('Czas wykonania: %.2f minut', $executionTime / 60));

        return Command::SUCCESS;
    }

    /**
     * Czeka na gotowość indeksu.
     */
    private function waitForIndexReady(string $indexName, SymfonyStyle $io, int $maxAttempts = 10): bool
    {
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            try {
                $health = $this->client->cluster()->health([
                    'index' => $indexName,
                    'wait_for_status' => 'yellow',
                    'timeout' => '10s'
                ]);

                if (in_array($health['status'], ['green', 'yellow'])) {
                    $io->info(sprintf('Indeks %s jest gotowy (status: %s)', $indexName, $health['status']));
                    return true;
                }

                $io->info(sprintf('Oczekiwanie na indeks %s (status: %s)...', $indexName, $health['status']));
            } catch (\Exception $e) {
                $io->warning('Błąd podczas sprawdzania stanu indeksu: ' . $e->getMessage());
            }

            sleep(3);
            $attempts++;
        }

        $io->warning(sprintf('Indeks %s może nie być w pełni gotowy po %d próbach', $indexName, $maxAttempts));
        return false;
    }
}
