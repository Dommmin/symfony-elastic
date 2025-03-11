# Symfony and Elasticsearch Integratio
## Features

- **Elasticsearch Integration:** Data is indexed into Elasticsearch for high-performance searching.
- **Search Functionality:** Users can perform highly optimized and advanced searches across the dataset, including
  full-text searches, filtering, and sorting.
- **Data Indexing:** Synchronize data from the Symfony application into Elasticsearch for real-time updates.
- **Pagination and Sorting:** Elastic-powered searches with support for pagination and sorting.
- **Custom Query Support:** Use custom Elasticsearch queries to retrieve exactly what is needed.

## Usage Example

1. **Indexing Data:**
   Use a Symfony command or service to index the application's data.
   ```php
   php bin/console app:index-products
   ```

2. **Search Functionality:**
Use 
    ```php
   /api/search?query={searchTerm}
   ```
   to retrieve search results.