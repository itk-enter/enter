# Enter

We use [ITK-dev docker setup] and [Task](https://taskfile.dev/) for development:

``` shell
task site:install
```

``` shell
task site:update
task site:open
```

Run `task` to see what cool task are available.

## Adapter

Takes an open-data set, converts it to [NGSI-LD], and upserts it into the
context broker.

``` text
source feed (JSON)
  → SourceInterface implementation   maps fields, fixes quirks, picks the data model
    → NgsiEntity                     normalized NGSI-LD: Property / GeoProperty / Relationship
      → NgsiLdBroker                 POST /ngsi-ld/v1/entityOperations/upsert
        → context broker
```

``` shell
task import                                                # list the available sources
task import -- mtm_spatialmaps-handicap-parking                        # import one
task import -- mtm_spatialmaps-handicap-parking --dry-run --limit 5    # print the payload instead
```

### Sources

Adding a data source means adding a [`SourceInterface`](src/Source/SourceInterface.php) implementation. The easiest way
to do this is by extending [`AbstractSource`](src/Source/AbstractSource.php), e.g.:

```php
<?php

use App\Source\AbstractSource;

final readonly class MySource extends AbstractSource
{
    public function __construct(
    ) {
        parent::__construct(
            id: 'my-source',
            title: 'My source with some cool data',
            description: '',
            publisher: 'Me',
            contact: 'me@example.com',
            landingPage: 'https://my-data.example.com',
            accessUrl: 'https://my-data.example.com/data',
            mediaType: 'application/geo+json',
            crs: 'EPSG:4326',
            model: 'MyModel',
            contextUrl: '',
            updateFrequency: 'daily',
        );
    }

    …
}
```

Run

``` shell
php bin/console app:source:list
```

list all data sources.

Design decisions are recorded in [docs/adr](docs/adr/README.md).

[NGSI-LD]: https://www.etsi.org/committee/cim

## Broker

A [Scorpio Broker](https://scorpio.readthedocs.io/) is part of the development setup.

``` shell
docker compose exec phpfpm curl --silent http://scorpio.local:9090/ngsi-ld/v1/types | jq
```

If you're using the [ITK-dev docker setup], the broker can also be
accessed on <http://scorpio.enter.local.itkdev.dk/>, e.g.

``` php
curl --silent http://scorpio.enter.local.itkdev.dk/ngsi-ld/v1/types | jq
```

[ITK-dev docker setup]: https://github.com/itk-dev/devops_itkdev-docker/
