<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;

class ProductSearchService
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @throws ServerResponseException
     * @throws ClientResponseException
     */
    public function search(string $query, int $size = 10, int $from = 0): Elasticsearch
    {
        $params = [
            'index' => 'products',
            'body' => [
                'size' => $size,
                'from' => $from,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['name^2', 'description'],
                        'type' => 'best_fields',
                        'fuzziness' => 'AUTO',
                        'operator' => 'or',
                        'minimum_should_match' => '70%'
                    ]
                ],
                'highlight' => [
                    'fields' => [
                        'name' => new \stdClass(),
                        'description' => new \stdClass()
                    ],
                    'pre_tags' => ['<strong>'],
                    'post_tags' => ['</strong>']
                ],
                'sort' => [
                    '_score' => ['order' => 'desc']
                ]
            ]
        ];

        return $this->client->search($params);
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function advancedSearch(
        ?string $query = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        ?string $sortBy = null,
        ?string $sortOrder = 'asc',
        int $size = 10,
        int $from = 0
    ): Elasticsearch {
        // Budowanie zapytania
        $mustClauses = [];
        $filterClauses = [];

        // Wyszukiwanie tekstowe
        if ($query) {
            $mustClauses[] = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => ['name^2', 'description'],
                    'type' => 'best_fields',
                    'fuzziness' => 'AUTO',
                    'operator' => 'or'
                ]
            ];
        }

        // Filtrowanie według ceny
        $priceFilter = [];
        if ($minPrice !== null) {
            $priceFilter['gte'] = $minPrice;
        }
        if ($maxPrice !== null) {
            $priceFilter['lte'] = $maxPrice;
        }

        if (!empty($priceFilter)) {
            $filterClauses[] = [
                'range' => [
                    'price' => $priceFilter
                ]
            ];
        }

        // Budowanie zapytania
        $queryBody = [
            'bool' => [
                'must' => $mustClauses,
                'filter' => $filterClauses
            ]
        ];

        // Jeśli nie ma żadnych warunków, użyj match_all
        if (empty($mustClauses) && empty($filterClauses)) {
            $queryBody = ['match_all' => new \stdClass()];
        }

        // Parametry sortowania
        $sortParams = [];
        if ($sortBy) {
            $sortParams[$sortBy] = ['order' => $sortOrder];
        } else {
            $sortParams['_score'] = ['order' => 'desc'];
        }

        $params = [
            'index' => 'products',
            'body' => [
                'size' => $size,
                'from' => $from,
                'query' => $queryBody,
                'highlight' => [
                    'fields' => [
                        'name' => new \stdClass(),
                        'description' => new \stdClass()
                    ],
                    'pre_tags' => ['<strong>'],
                    'post_tags' => ['</strong>']
                ],
                'sort' => $sortParams
            ]
        ];

        return $this->client->search($params);
    }
}
