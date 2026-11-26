# 009: Import history - kept by the application, not the broker

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Jeppe Krogh                                            |
| **Date**           | 2026-11-26                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

001 publishes the data sets in Scorpio, an NGSI-LD context broker. An import reads the feed of a source, writes its
entities to the broker and deletes the entities that the feed no longer gives. After the import, the broker shows only
the current state.

The team must be able to answer questions about the past. Why is an entity no longer in the broker? When did a source
last give new data? Did an import fail, and why? Without a history, the answers are lost after the next import.

NGSI-LD defines a temporal API, which gives the old values of an entity. Scorpio records this history by default. The
history is part of the standard and needs no other database, and a deleted entity stays in it with the time of the
delete. But each write adds a copy of each attribute, also when the value did not change. Scorpio has no setting that
skips these copies or deletes old ones, so each import of unchanged data makes the history larger. The history also
does not tell which import made a change, which feed the import read, or why an import failed. In tests, some queries
of the temporal API failed: counts, paging and geographic queries.

The other option is to keep no history. This is the most simple option, but the questions above then have no answer.

This ADR serves to decide where the history of the imports is kept, and what it records.

## Decision

The broker records no history. The Scorpio service sets `SCORPIO_HISTORY_AUTORECORDING=false`, so the temporal API
stays empty.

The application keeps a history of the imports in a database of its own. For each import, the history records the
source, the start, the end, the result and the reason of a failure. It also records how many entities the import
wrote, how many the broker rejected and how many the import deleted. Last, it records a hash, a short fingerprint of
all the entities that the import sent to the broker. If two imports of a source have the same hash, the data did not
change.

The history keeps the raw body of each feed that an import read. It keeps each different body one time only, and
compressed, so a feed that did not change adds no new body. With the body, the team can see what a source gave on a
given day.

The history also records each entity that an import added to the broker or removed from it. An entity that stays in
the broker gets no record, also when its values change. Thus the history tells when an entity came or went and which
import made the change. It does not keep the old values of an entity.

The history must not stop an import. If the database fails, the import continues and the application logs the error.

For example, a made-up source of benches has three imports:

| Import | Feed body | Data changed | Added         | Removed |
|--------|-----------|--------------|---------------|---------|
| Day 1  | F-1       | first import | B-1, B-2, B-3 |         |
| Day 2  | F-1       | no           |               |         |
| Day 3  | F-2       | yes          | B-4           | B-2     |

The history keeps three import records, two feed bodies and five records of added or removed entities. To find why
B-2 is no longer in the broker, look at day 3. That import removed B-2, and feed F-2 shows that the source no longer
gave it. The broker history would keep three copies of each attribute of B-1 and B-3, and it would not show the feed.

## Consequences

### Positive

- The team can find when and why an entity came or went, and which feed the import read.
- A failed import is in the history with its reason, also when the broker did not change.
- An import of unchanged data adds only a small record. The history grows when the data changes, not with each import.
- The hash shows if the data of a source changed, without a comparison of each entity.
- The application can show the state of each source, for example the last successful import and how often the data
  changes.
- The broker database does not grow with copies of attributes that did not change.

### Negative / Trade-offs

- The application needs a database of its own, in development and on the server, and migrations for its schema.
- The history does not keep the changed values of an entity that stays in the broker. To find an old value, somebody
  must read an old feed body.
- Consumers cannot get old values through the NGSI-LD API, because the temporal API is empty.
- The merged data sets have no history. The application does not record a merge run.
- If the database fails during an import, the history has a gap. Only the log shows it.
- The history has no rule that deletes old records yet. It grows each time a feed changes.
- A change to the broker outside of an import, for example a delete by hand, is not in the history.
