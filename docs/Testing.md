# Testing

## Test sources

For local testing and development we use a test controller that's only enabled in the `dev` and `test` environments.

Furthermore, we use static test sources (fetching locally stored data) for testing and development. Test sources are
identified by the `#[TestDefinition]` attribute (rather than `#[Definition]` as real sources).

Test sources can be listed with the `test:source:list` command:

```shell
docker compose exec phpfpm php bin/console test:source:list
```

(the `app:source:list` command will list all source; including test sources.)

Example: Import and show data from the test source `test:mtm_spatialmaps-handicap-parking`:

```shell
docker compose exec phpfpm php bin/console app:source:import test:mtm_spatialmaps-handicap-parking
docker compose exec phpfpm curl 'http://scorpio:9090/ngsi-ld/v1/entities?type=https://smartdatamodels.org/dataModel.Parking/OnStreetParking'
```

See the result on <https://enter.local.itkdev.dk/test>.

### The developer map's data

The test controller reads what the test sources published back out of the broker, as plain GeoJSON:

| Path                           | Returns                                                                   |
| ------------------------------ | ------------------------------------------------------------------------- |
| `/test/datasets.json`          | The test sources as data sets, each with `id`, `title`, `model` and `url` |
| `/test/map/{sourceId}.geojson` | One data set as a `FeatureCollection`, one feature per entity             |

Each feature carries the entity's attributes as plain values under the names the source declared, plus `dataset` (the
source id) and `id` (the entity id). The model and the source stamp are sent to the broker under the source's own
context, so a new test source needs nothing beyond its `#[TestDefinition]` to be served.

### Refreshing test source data

The data for test sources are stored as plain files in the [../tests/resources/data](../tests/resources/data) folder.

The data files can be updated by running

```shell
docker compose exec phpfpm php bin/console test:source:fetch-content
```

As shown above, test sources can be imported just like real sources, but for convenience the `test:source:import-all`
command can be used to import *all test sources*:

```shell
docker compose exec phpfpm php bin/console test:source:import-all
```

To empty your local broker, e.g. before loading test data, run

```shell
docker compose exec phpfpm php bin/console app:broker:entity:delete --all
```
