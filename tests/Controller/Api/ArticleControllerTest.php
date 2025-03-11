<?php

namespace App\Tests\Controller\Api;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ArticleControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Wyczyść bazę danych przed każdym testem
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement($platform->getTruncateTableSQL('article', true));

        // Dodaj testowe dane
        $this->addTestArticle();
    }

    private function addTestArticle(): Article
    {
        $article = new Article();
        $article->setTitle('Test Article');
        $article->setContent('This is a test article content');
        $article->setAuthor('Test Author');
        $article->setCategory('Test');
        $article->setTags(['test', 'article']);
        $article->setPublishedAt(new \DateTimeImmutable());

        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    public function testGetCollection(): void
    {
        $this->client->request('GET', '/api/articles');

        $this->assertResponseIsSuccessful();
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertGreaterThan(0, count($responseData['data']));
    }

    public function testGetItem(): void
    {
        // Pobierz pierwszy artykuł z bazy danych
        $article = $this->entityManager->getRepository(Article::class)->findOneBy([]);

        $this->client->request('GET', '/api/articles/' . $article->getId());

        $this->assertResponseIsSuccessful();
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals($article->getTitle(), $responseData['title']);
        $this->assertEquals($article->getContent(), $responseData['content']);
    }

    public function testPostCollection(): void
    {
        $articleData = [
            'title' => 'New Test Article',
            'content' => 'New test article content',
            'author' => 'New Test Author',
            'category' => 'New Test',
            'tags' => ['new', 'test'],
            'publishedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ];

        $this->client->request(
            'POST',
            '/api/articles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($articleData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals($articleData['title'], $responseData['title']);

        // Sprawdź, czy artykuł został zapisany w bazie danych
        $article = $this->entityManager->getRepository(Article::class)->findOneBy(['title' => $articleData['title']]);
        $this->assertNotNull($article);
    }

    public function testPutItem(): void
    {
        // Pobierz pierwszy artykuł z bazy danych
        $article = $this->entityManager->getRepository(Article::class)->findOneBy([]);

        $updatedData = [
            'title' => 'Updated Test Article',
            'content' => 'Updated test article content',
            'author' => $article->getAuthor(),
            'category' => $article->getCategory(),
            'tags' => $article->getTags()
        ];

        $this->client->request(
            'PUT',
            '/api/articles/' . $article->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($updatedData)
        );

        $this->assertResponseIsSuccessful();
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals($updatedData['title'], $responseData['title']);
        $this->assertEquals($updatedData['content'], $responseData['content']);

        // Sprawdź, czy artykuł został zaktualizowany w bazie danych
        $updatedArticle = $this->entityManager->getRepository(Article::class)->find($article->getId());
        $this->assertEquals($updatedData['title'], $updatedArticle->getTitle());
    }

    public function testDeleteItem(): void
    {
        // Pobierz pierwszy artykuł z bazy danych
        $article = $this->entityManager->getRepository(Article::class)->findOneBy([]);
        $articleId = $article->getId();

        $this->client->request('DELETE', '/api/articles/' . $articleId);

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Sprawdź, czy artykuł został usunięty z bazy danych
        $deletedArticle = $this->entityManager->getRepository(Article::class)->find($articleId);
        $this->assertNull($deletedArticle);
    }
}
