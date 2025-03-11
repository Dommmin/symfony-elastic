<?php

namespace App\Controller\Api;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ProductSearchService;
use Symfony\Component\HttpFoundation\JsonResponse;

class ProductController extends ApiController
{
    private ProductSearchService $searchService;

    public function __construct(ProductSearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    #[Route('/api/search', name: 'api_product_search', methods: ['GET'])]
    public function search(Request $request, LoggerInterface $logger): JsonResponse
    {
        $logger->info('test');
        $query = $request->query->get('query', '');
        $size = $request->query->getInt('size', 10);
        $page = $request->query->getInt('page', 1);
        $from = ($page - 1) * $size;

        $results = $this->searchService->search($query, $size, $from);

        return $this->formatSearchResults($results);
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    #[Route('/api/products/advanced-search', name: 'api_product_advanced_search', methods: ['GET'])]
    public function advancedSearch(Request $request): JsonResponse
    {
        $query = $request->query->get('query');
        $minPrice = $request->query->get('min_price') ? (float)$request->query->get('min_price') : null;
        $maxPrice = $request->query->get('max_price') ? (float)$request->query->get('max_price') : null;
        $sortBy = $request->query->get('sort_by');
        $sortOrder = $request->query->get('sort_order', 'asc');
        $size = $request->query->getInt('size', 10);
        $page = $request->query->getInt('page', 1);
        $from = ($page - 1) * $size;

        $results = $this->searchService->advancedSearch(
            $query,
            $minPrice,
            $maxPrice,
            $sortBy,
            $sortOrder,
            $size,
            $from
        );

        return $this->formatSearchResults($results);
    }

    private function formatSearchResults($results): JsonResponse
    {
        $formattedResults = [
            'total' => $results['hits']['total']['value'] ?? 0,
            'products' => []
        ];

        foreach ($results['hits']['hits'] as $hit) {
            $product = $hit['_source'];
            $product['id'] = $hit['_id'];
            $product['score'] = $hit['_score'];

            // Dodaj wyróżnienia (highlights), jeśli są dostępne
            if (isset($hit['highlight'])) {
                $product['highlights'] = $hit['highlight'];
            }

            $formattedResults['products'][] = $product;
        }

        return $this->json($formattedResults);
    }
}
