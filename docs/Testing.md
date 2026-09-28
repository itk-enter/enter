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
