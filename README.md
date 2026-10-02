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

To import every source in one run:

``` shell
docker compose exec phpfpm bin/console app:source:import-all
```

A failing source does not stop the run, and the command exits with a failure code if any source failed. Once the run is
done, it dispatches a [`SourcesImportedEvent`](src/Import/Event/SourcesImportedEvent.php) saying which sources imported
and which failed, so work that depends on a complete import can listen for it.

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

The sources are also published on `/sources` as a page and on `/sources.json` for programs.

### Reading a source's data

Every entity carries a `sourceId` attribute holding the id of the source it came from, and each entry in
`/sources.json` carries an `entities_url` that reads them back out of the broker, which nginx serves under
`/ngsi-ld/v1/`. Asked for `application/geo+json` in the simplified format, the broker answers in plain GeoJSON:

``` shell
curl --silent \
  --header 'Accept: application/geo+json' \
  --header 'Link: <https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"' \
  'http://enter.local.itkdev.dk/ngsi-ld/v1/entities?q=sourceId=="mtm_spatialmaps-handicap-parking"&format=simplified&limit=1000&count=true'
```

The `Link` header names the source's context (`context_url` in the list), which is what lets the broker answer
with the attribute names the source declared. The broker returns at most 1000 entities per request, states the
total in the `NGSILD-Results-Count` response header and points to the next page in its own `Link` response
header.

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
