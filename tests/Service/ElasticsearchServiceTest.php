<?php

namespace App\Tests\Service;

use App\Entity\Article;
use App\Service\ElasticsearchService;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

class ElasticsearchServiceTest extends KernelTestCase
{
    private $elasticsearchService;
    private $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->elasticsearchService = static::getContainer()->get(ElasticsearchService::class);

        // Wyczyść bazę danych przed każdym testem
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement($platform->getTruncateTableSQL('article', true));
    }

    public function testIndexArticle(): void
    {
        // Stwórz testowy artykuł
        $article = $this->createTestArticle('Test Index Article');

        // Zindeksuj artykuł
        $this->elasticsearchService->indexArticle($article);

        // Pobierz artykuł z Elasticsearch
        $indexedArticle = $this->elasticsearchService->getArticleById($article->getId());

        $this->assertNotNull($indexedArticle);
        $this->assertEquals($article->getTitle(), $indexedArticle['title']);
        $this->assertEquals($article->getContent(), $indexedArticle['content']);
    }

    public function testSearchArticles(): void
    {
        // Stwórz kilka artykułów o różnych tytułach
        $this->createTestArticle('PHP Programming Guide');
        $this->createTestArticle('Laravel Framework Tutorial');
        $this->createTestArticle('Symfony Components in Detail');

        // Poczekaj na indeksację
        sleep(1);

        // Wyszukaj artykuły zawierające słowo "PHP"
        $results = $this->elasticsearchService->searchArticles('PHP');

        $this->assertGreaterThan(0, $results['total']);
        $this->assertGreaterThan(0, count($results['hits']));

        // Sprawdź, czy wyniki zawierają słowo "PHP"
        $containsPhp = false;
        foreach ($results['hits'] as $hit) {
            if (strpos(strtolower($hit['title']), 'php') !== false ||
                strpos(strtolower($hit['content']), 'php') !== false) {
                $containsPhp = true;
                break;
            }
        }

        $this->assertTrue($containsPhp);
    }

    private function createTestArticle(string $title): Article
    {
        $article = new Article();
        $article->setTitle($title);
        $article->setContent('This is the content for the article: ' . $title);
        $article->setAuthor('Test Author');
        $article->setCategory('Test');
        $article->setTags(['test', 'article']);
        $article->setPublishedAt(new \DateTimeImmutable());

        $this->entityManager->persist($article);
        $this->entityManager->flush();

        // Indeksuj artykuł
        $this->elasticsearchService->indexArticle($article);

        return $article;
    }
}
