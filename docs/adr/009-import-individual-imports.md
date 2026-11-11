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

Each source is imported on its own. Each night, a cron job on the server imports the sources one at a time, with the
command that imports one source. Each import runs in its own process. Thus, a source that is slow or fails, or that
stops its process, affects only its own data set. The cron job gets the list of sources from the application, so a
new source needs no change on the server. The command that imports all the sources in one run stays, for use by hand.

We do not keep one run for all the sources, for the reasons in the context. We also do not give each source a cron job
of its own, because then each new source needs a change on the server, outside the code.

We also do not send a message for each source from a schedule in the application, for a worker to import. A message
can try a failed import again after some minutes, and a message that fails is kept for a developer to send again. But
the messages, the schedule and the queue add parts that the team must set up, watch and understand when something goes
wrong. The cron jobs use commands that already exist, and the whole team knows how a cron job works.

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

The data sets that a merge reads are its group. Each night, at a fixed time after the imports, a second cron job runs
the merges. Each merge looks in the import history, and it runs only if at least one data set in its group has new
data. A data set has new data when its last successful import of the last 24 hours has another payload hash than its
last successful import before that. The other data sets of the group take part with the entities that the broker
already has, also when their import failed that night, because a failed import deletes nothing.

The application does not record the runs of the merges. If a merge fails one night, the next night finds no new data,
and the merge waits until a data set changes again. Thus, once a week, a third cron job runs all the merges without
the check. A merged data set is then never more than one week behind its data sets.

We do not run a merge after each import with new data. A merge that reads several data sets would then run several
times in one night and publish a result between the imports. One run at a fixed time uses all the new data of the
night.

We also do not record each run of a merge with the payload hashes that it read. With that record, a merge that failed
can find its new data again the next night. But the record needs one more table, and the weekly run gives the same
result some days later.

For example, a merge of benches reads three data sets:

| Data set             | Last import before the last 24 hours | Import in the last 24 hours |
|----------------------|--------------------------------------|-----------------------------|
| Benches, register A  | succeeded, `51a0…`                   | succeeded, `7c1e…` (new)    |
| Benches, map B       | succeeded, `9b2d…`                   | failed                      |
| Picnic tables, map B | succeeded, `e44f…`                   | succeeded, `e44f…`          |

The merge runs, because "Benches, register A" has new data. It uses the benches that map B gave on an earlier night.

## Consequences

### Positive

- A source that is slow or fails does not delay or stop the other sources.
- A merge uses the new data of one data set, also when another data set of its group fails.
- A merge does not run when no data set in its group changed.
- The history shows when an entity went away, and the feed bodies show why.
- The cron jobs use commands that already exist. The application gets no queue or schedule for the imports.
- The history grows slowly, because each feed body is stored one time and compressed.

### Negative / Trade-offs

- The application needs a database and schema migrations, also on the server.
- The history has no rule that deletes old records, so it grows without a limit.
- The compression of the feed bodies uses a feature of MariaDB, so the schema needs MariaDB.
- The history keeps feed bodies and identifiers, not each value over time. To see an old value, somebody must read an
  old feed body.
- The cron jobs are on the server, outside the code. A change of their times needs a change on the server.
- A failed import is not tried again until the next night.
- A merge that fails waits until a data set changes again, or until the weekly run.
- New data that arrives after the time of the merges waits until the next night.
- A merged data set can combine data sets that were imported on different nights.
- An import whose process stops before the end keeps the status `running`.
- A data set whose import fails each night keeps its old entities, and only the history shows it.
