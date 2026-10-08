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

Example: Import and show data from the test source `test:mtm_spatialmaps-handicap-parking-on-street`:

```shell
docker compose exec phpfpm php bin/console app:source:import test:mtm_spatialmaps-handicap-parking-on-street
docker compose exec phpfpm curl 'http://scorpio:9090/ngsi-ld/v1/entities?type=https://smartdatamodels.org/dataModel.Parking/OnStreetParking'
```

See the result on <https://enter.local.itkdev.dk/test>.

One source's entities can be read back on their own by the id they are stamped with; see
[Reading a source's data](../README.md#reading-a-sources-data).

The map page at `/test` lists the test data sets, each with a toggle per source, named by the model it publishes, all
off to begin with, and reads a source's entities from the broker the first time it is switched on. Sources that split
one feed by model are listed under the data set they share; the checkbox on a data set switches every source of it at
once. Clicking a feature outlines it and opens a popup with its attributes under the data set and model it came from;
where several features overlap, the popup lists each.

### Refreshing test source data

The data for test sources are stored as plain files in the [../tests/resources/data](../tests/resources/data) folder.

The data files can be updated by running

```shell
docker compose exec phpfpm php bin/console test:source:fetch-content
```

A fetch answered with 429 or a 5xx is retried four times with growing pauses, as the public Overpass instance is often
busy. A file is only replaced by a complete response, so a failed fetch leaves the old one in place. Each response is
kept for an hour: run the command again after a partial failure and only the failed feeds are fetched. Add `--fresh`
to fetch every feed regardless.

As shown above, test sources can be imported just like real sources, but for convenience the `test:source:import-all`
command can be used to import *all test sources*:

```shell
docker compose exec phpfpm php bin/console test:source:import-all
```

To empty your local broker, e.g. before loading test data, run

```shell
docker compose exec phpfpm php bin/console app:broker:entity:delete --all
```
