<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class ProductFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $connection = $manager->getConnection();
        $totalRecords = 10000;
        $batchSize = 10_000;

        for ($i = 1; $i <= $totalRecords; $i += $batchSize) {
            $values = [];

            // Generuj partię 10 000 rekordów
            for ($j = 0; $j < $batchSize; $j++) {
                $name = $connection->quote($faker->sentence); // Escape'owanie ciągów znaków
                $price = $faker->randomFloat(2, 0, 1000);
                $createdAt = $connection->quote($faker->dateTime->format('Y-m-d H:i:s')); // Format daty SQL
                $description = $connection->quote($faker->realTextBetween(100, 1000)); // Escape'owanie ciągów znaków

                $values[] = sprintf(
                    '(%s, %f, %s, %s)', // Formatowanie rekordu
                    $name,
                    $price,
                    $createdAt,
                    $description
                );
            }

            // Wstaw partię do bazy
            $sql = sprintf(
                'INSERT INTO products (name, price, created_at, description) VALUES %s',
                implode(', ', $values) // Łączenie rekordów
            );

            $connection->executeStatement($sql);

            // Wyczyść pamięć
            unset($values);
            gc_collect_cycles(); // Wymuś garbage collection

            // Postęp
            echo sprintf('Wstawiono %d z %d rekordów...', $i + $batchSize, $totalRecords) . PHP_EOL;
        }

        echo 'Wstawianie zakończone!' . PHP_EOL;
    }
}
