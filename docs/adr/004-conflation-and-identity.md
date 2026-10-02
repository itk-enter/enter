# 004: Conflation — one-to-one matching into a data set of its own

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-10-02                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

003 types each record by what it describes and aims for one entity per real-world thing, carrying the best of what
every data set says about it. Until that merge exists, a thing described by several data sets appears as several
entities, one per data set, with nothing relating them.

Records of the same thing from different data sets rarely share an identifier, so they can only be related by where
they are and what they are. Two records of one thing can lie further apart than two distinct things of the same kind
standing side by side, so position alone does not tell them apart.

How records are matched, where the merged result is published, which data set's values survive, how merged entities
are identified and how they refer back to their inputs are all undecided.

This ADR serves to decide how records from several data sets are matched and merged, and how the result is published
and identified.

### Drivers

- **Functional:** one entity per real-world thing, for every kind of thing published; configurable per kind, since
  kinds differ in how densely they stand and how precisely data sets locate them; a result that can be traced back to
  its inputs.
- **Non-functional:** repeatable — the same inputs give the same result; scales to tens of thousands of records per
  data set and a handful of data sets per kind; adding a kind costs configuration rather than a new algorithm.

### Options Considered

**Clustering by a fixed radius.** Every record within a radius of another joins its group, transitively. It is simple
and fast, but a row of neighbouring things closer than the radius collapses into one, and a chain of near matches can
span many times the radius. A radius small enough to avoid that misses records of one thing that data sets place
further apart.

**One-to-one matching, closest first.** Candidate pairs from different data sets within a radius are accepted in order
of distance, as long as a group holds at most one record per data set and every member stays within the radius of
every other. Neighbouring things each keep their own closest match instead of sharing one, and a group cannot drift.
It depends on each data set describing a thing once, and an ambiguous pair is still decided by distance alone.

**Probabilistic record linkage.** A trained or tuned model scores pairs on position together with names, addresses
and other attributes. It resolves cases position cannot, but it needs training or tuning data per kind, its decisions
are harder to explain, and it adds a dependency for cases that the rules above already settle.

**Contributing matches upstream through a shared map's quality-assurance tooling.** The matches would be reviewed by
mappers and improve a public source for everyone. It produces edits to that source rather than merged entities to
publish, requires every input to carry a licence compatible with the shared map, and depends on human review.

## Decision

Records are matched **one-to-one, closest first**, and the merged result is published as **a data set of its own**.

- **Configuration per kind:** the data model, the input data sets in priority order, and the match radius.
- **Matching:** only records of the same model and from different data sets are compared, by the distance between one
  representative point each. Pairs within the radius are accepted closest first while a group holds at most one record
  per data set and every member lies within the radius of every other. Ties are broken by identifier.
- **Completeness:** a record that matches nothing forms a group of one, so the merged data set covers every input.
- **Publication:** the merged data set has its own source identifier and uses an existing data model of the kind. The
  input data sets stay published unchanged.
- **Survivorship:** each attribute takes the value of the highest-priority input that states it. An absent value never
  overrides a stated one.
- **Identity:** a merged entity's identifier derives from its highest-priority member, so it is stable across runs
  while that member exists.
- **Provenance:** a merged entity lists the identifiers of the input entities it was built from.
- **Timing:** a merge runs after a full import, and only when every one of its inputs imported successfully.
- **Removal:** entities of a merged data set that a run no longer produces are deleted.

Rationale:

- One-to-one matching separates neighbouring things that a fixed radius merges, without the training data linkage
  needs.
- A separate data set leaves every input intact and attributable, so a wrong merge is fixed by rerunning, not by
  repairing inputs.
- Priority survivorship and member-derived identity keep a run repeatable and explainable.

## Consequences

### Positive

- One entity per real-world thing is available for every kind with a merge configured, under the same model as its
  inputs.
- Consumers who need a single picture read one data set; those who need a specific source keep reading it.
- A new kind needs a configuration, not a new algorithm.
- The matching depends only on positions and identifiers, so it can be tested and tuned without a broker.

### Negative / Trade-offs

- Each thing is published once per input data set and once more merged, so queries that span data sets see it several
  times.
- A data set that records one thing more than once defeats the one-record-per-data-set rule; such duplicates are
  merged with different neighbours, or left apart.
- A thing described at different granularity by different data sets, such as a facility and the units within it, is
  not one-to-one and is not resolved by this matching.
- A merged entity's identifier changes when its highest-priority member disappears.
- Priority applies per data set, not per attribute, so a data set that is better for some attributes than others
  needs that expressed in code for its kind.
- The licence of a merged data set follows from its inputs, and inputs without a stated licence leave it unsettled.
