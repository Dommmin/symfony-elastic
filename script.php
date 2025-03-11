<?php

require 'vendor/autoload.php';

use Elastic\Elasticsearch\ClientBuilder;

$client = ClientBuilder::create()
    ->setHosts(['http://elasticsearch:9200'])
    ->build();

$response = $client->info();
print_r($response->asArray());
