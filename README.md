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

The sources are also published on `/sources` as a page and on `/sources.json` for programs.

### Reading a source's data

Every entity carries a `sourceId` attribute holding the id of the source it came from, and each entry in
`/sources.json` carries an `entities_url` that reads them back out of the broker, through the application's
proxy under `/data/`. Asked for GeoJSON in the simplified format, the broker answers with one feature per entity
and plain values:

``` shell
curl --silent \
  --header 'Accept: application/geo+json' \
  --header 'Link: <https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"' \
  'http://enter.local.itkdev.dk/data/ngsi-ld/v1/entities?q=sourceId=="mtm_spatialmaps-handicap-parking"&format=simplified&limit=1000'
```

What we have learned about the [NGSI-LD API](https://cim.etsi.org/NGSI-LD/official/) on the way:

- `format=simplified` strips the `Property` wrappers and leaves bare values. It replaces `options=keyValues`,
  which the specification has deprecated.
- `Accept: application/geo+json` turns the answer into a GeoJSON `FeatureCollection`, with each entity's
  `location` as its feature's geometry.
- The `Link` request header names a JSON-LD context. Without one, a short type name such as `OnStreetParking`
  expands under the default context and matches nothing, and attribute names come back as full IRIs. With the
  source's `context_url` the broker reads and answers in the names the source declared.
- A term defined in no context, such as `sourceId`, expands under the default context both when published and
  when queried, so it filters the same with or without a `Link` header.
- A query needs no `type`. `q=sourceId=="…"` on its own selects a source's entities.
- The broker returns at most 1000 entities per request. It links to the next page in a `Link` response header
  with `rel="next"`, and to none on the last page. Asked with `count=true`, it states the total in the
  `NGSILD-Results-Count` header.
- The next-page link is written under the broker's own path, `/ngsi-ld/v1/entities`, not the proxy's, so a
  reader behind `/data/` keeps its own path and takes only the link's query.

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
