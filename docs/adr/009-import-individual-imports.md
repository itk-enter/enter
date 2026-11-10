# 009: Import - individual imports with a history

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Jeppe Julius Krogh                                     |
| **Date**           | 2026-11-10                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

Each source reads one feed, converts its records to entities and upserts them into the broker (001) as one data set.
Then the import deletes the entities that the feed no longer gives. The merges of 004 read these data sets and publish
merged data sets.

Today, one run imports all the sources, one after another. When the run is done, a merge runs if every data set that
it reads was imported in the run. A slow source delays all the sources after it. If one data set of a merge fails, the
merge does not run, also when its other data sets have new data. Each merge also runs after each run, when no data set
changed.

The application keeps no record of the imports. When an entity goes away from a data set, nobody can tell when that
happened, or if the source no longer gave it or the import left it out.

This ADR serves to decide how the sources are imported, where the history of the imports is kept, and when a merge
runs.

## Decision

### Individual imports

Each source is imported on its own. Each night, a schedule in the application sends one message for each source. The
worker of 008 takes the messages and imports the sources one at a time. A source that is slow or fails affects only
its own data set. A message that fails after its retries is kept in the database, so a developer can find it and send
it again. The schedule gives its times in Danish time, because the application runs in UTC. The commands that import
one source or all the sources stay, for use by hand.

We do not keep one run for all the sources, for the reasons in the context. We also do not give each source a cron job
on the server, because then each new source needs a change on the server, outside the code.

### The import history

The application keeps a history of the imports in its own database. Each import of a source gets a record with its
start and end time, its status, the reason for a failure, and the number of entities that it upserted, rejected and
deleted. The record refers to the feed body that the import read. A feed body is stored one time, also when several
imports read it, and the database compresses it. The history also records the identifier of each entity that an
import added or removed.

Each import record has a payload hash: a fingerprint of all the entities that the import sent to the broker. The
order of the entities and the order of their keys have no effect on it. If two imports of a source have the same
payload hash, the data set did not change.

The broker can also keep a history, through the NGSI-LD temporal API. We turn it off. The broker stores a copy of each
attribute at each upsert, also when the value did not change, and it has no setting to skip the copies or to delete
old ones. In our tests, the temporal API also did not find the entities that were deleted.

For example, bench B-12 goes away from the data set "Benches, map B":

| Import     | Status    | Payload hash | Removed |
|------------|-----------|--------------|---------|
| 2026-11-09 | succeeded | `4f17…`      |         |
| 2026-11-10 | succeeded | `c3f8…`      | B-12    |

The history shows that B-12 went away in the import on 10 November. The feed bodies of the two imports show if the
source no longer gave B-12, or if the import left it out.

### Merges on new data

The data sets that a merge reads are its group. At a fixed time after the imports of the night, a merge runs if at
least one data set in its group has new data. A data set has new data when the payload hash of its last successful
import is different from the payload hash that the last run of the merge read. The other data sets of the group take
part with the entities that the broker already has, also when their import failed that night, because a failed import
deletes nothing. The application records each run of a merge, with the payload hash of each data set that it read.

We do not run a merge after each import with new data. A merge that reads several data sets would then run several
times in one night and publish a result between the imports. One run at a fixed time uses all the new data of the
night.

For example, a merge of benches reads three data sets:

| Data set             | Night import | Payload hash   | Last run of the merge read |
|----------------------|--------------|----------------|----------------------------|
| Benches, register A  | succeeded    | `7c1e…` (new)  | `51a0…`                    |
| Benches, map B       | failed       | `9b2d…` (kept) | `9b2d…`                    |
| Picnic tables, map B | succeeded    | `e44f…`        | `e44f…`                    |

The merge runs, because "Benches, register A" has new data. It uses the benches that map B gave on an earlier night.

## Consequences

### Positive

- A source that is slow or fails does not delay or stop the other sources.
- A merge uses the new data of one data set, also when another data set of its group fails.
- A merge does not run when no data set in its group changed.
- The history shows when an entity went away, and the feed bodies show why.
- A failed message is kept, not dropped.
- The history grows slowly, because each feed body is stored one time and compressed.

### Negative / Trade-offs

- The application needs a database and schema migrations, also on the server.
- The history has no rule that deletes old records, so it grows without a limit.
- The compression of the feed bodies uses a feature of MariaDB, so the schema needs MariaDB.
- The history keeps feed bodies and identifiers, not each value over time. To see an old value, somebody must read an
  old feed body.
- New data that arrives after the time of the merges waits until the next night.
- A merged data set can combine data sets that were imported on different nights.
- If the worker stops, no source is imported. An import that the worker did not finish keeps the status `running`.
- A data set whose import fails each night keeps its old entities, and only the history shows it.
