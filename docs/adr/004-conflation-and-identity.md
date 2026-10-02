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

A data set of a different model can also describe something else at the same place, such as what covers the ground a
thing stands on, and so add facts about that thing without being a record of it. One such record can concern many
things.

The data sets that describe a kind change as data sets are added and retired, and the values they state for one
attribute can contradict each other. Which value is right differs per kind and per attribute.

How records are found and matched, how contradictions are settled, where the merged result is published, how merged
entities are identified and how they refer back to their inputs are all undecided.

This ADR serves to decide how records from several data sets are found, matched and merged, and how the result is
published and identified.

### Drivers

- **Functional:** one entity per real-world thing, for every kind of thing published; configurable per kind, since
  kinds differ in how densely they stand, how precisely data sets locate them and which data sets are trustworthy for
  what; facts from data sets of other models at the same place; a result that can be traced back to its inputs.
- **Non-functional:** repeatable — the same inputs give the same result; scales to tens of thousands of records per
  data set and a handful of data sets per kind; adding a kind or a data set costs configuration rather than a new
  algorithm.

### Options Considered

#### Matching

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

**Comparing full geometries through GEOS.** Matching areas by their actual shape rather than one point each, through
the GEOS geometry engine as a PHP extension, gives exact point-in-area, overlap and edge-distance tests and exact
centroids. That separates a facility from the units within it, and an area's centroid computed without it loses
precision at real-world coordinates. GEOS is not part of the PHP runtime images, so the extension has to be built
into them, for each image variant, from a source with no recent release; it also measures in the coordinates'
own units, so distances still have to be converted to metres.

#### Inputs and contradictions

**Naming the data sets of a merge, in priority order.** The configuration lists its data sets, and the first that
states a value wins. It is simple and predictable, but every data set added for a kind needs the configuration
changed, and one order has to hold for every attribute.

**Naming the models of a merge, with an ordered list of resolution approaches.** The configuration names the models
to read, and every data set publishing them takes part. Contradictions are settled by approaches tried in a
configured order, such as majority and then the authority of data sets, per merge or per attribute. A data set added
for a kind joins without a change, and each attribute can be settled the way that suits it, but a new data set's
values take part before anyone has judged them, and some contradictions stay unsettled.

## Decision

Merges are configured by **model**, run in **four steps** — fetch, merge by location, resolve conflicts, create — and
publish their result as **a data set of its own**. Records are matched **one-to-one, closest first**.

### Configuration per merge

- The data model of the result, the models to match, and the match radius.
- Optionally, models that enrich the result, each with the attributes it supplies.
- An ordered list of resolution approaches, optionally overridden per attribute.
- Where an input model names an attribute differently from the result model, the mapping between the two.

### 1. Fetch

- Every entity of the configured models is read from the broker, from every data set that publishes them, with the
  data set it came from.
- The merged data set's own entities are left out, as are data sets kept for testing when published data is merged,
  and the other way round.

### 2. Merge by location

- **Matching:** records of the models to match are compared by the distance between one representative point each.
  Pairs within the radius are accepted closest first while a group holds at most one record per data set and every
  member lies within the radius of every other. Ties are broken by identifier.
- **Within one data set:** two records from the same data set are never matched or merged, however close they lie. A
  data set's records are taken to describe distinct things.
- **Completeness:** a record that matches nothing forms a group of one, so the merged data set covers every thing the
  matched models describe.
- **Enrichment:** a record of an enriching model supplies its configured attributes to every group whose
  representative point lies within its area, or within the radius of its point. It is not matched one-to-one, one
  record can enrich many groups, and it never forms or joins a group of its own.
- **Geometry:** whether areas are compared by their full shape through GEOS, instead of by one representative point,
  is left undecided. It becomes relevant where data sets describe the same things as areas or at different
  granularity.

### 3. Resolve conflicts

- An attribute is in conflict when the members of a group that state it state different values. An absent value never
  contradicts a stated one.
- The configured approaches are tried in order until one settles the conflict:
  - **Majority:** the value stated by more members than any other settles it. A tie does not.
  - **Source authority:** configured with an ordered list of data sets. The value of the earliest listed data set that
    states one settles it. Members whose data sets are not listed cannot settle it.
- A conflict no approach settles leaves the attribute out, and the conflicting values and the data sets stating them
  are recorded on the merged entity.
- Enriching records only supply attributes that no matched member states, and contradictions among them are settled
  the same way.

### 4. Create the merged entity

- Every merged entity is built in the configured data model, an existing model of the kind, with input attributes
  mapped where configured and taken by name otherwise.
- **Identity:** a merged entity's identifier derives from the member whose identifier sorts first.
- **Provenance:** a merged entity lists the identifiers of the input entities it was built from, enriching ones
  included.
- **Publication:** the merged data set has its own source identifier. The input data sets stay published unchanged.
- **Removal:** entities of the merged data set that a run no longer produces are deleted.

### Timing and records lost upstream

- A merge runs after a full import, and only when every data set known to publish its configured models, enriching
  ones included, imported successfully.
- What a merge does when a data set loses a record is left undecided, with the lifecycle of records. Until a record
  that disappears upstream is removed from the broker, it keeps taking part in merges. Once it is removed, the next run
  drops it from its group, and a group left with no members no longer produces an entity.

Rationale:

- One-to-one matching separates neighbouring things that a fixed radius merges, without the training data linkage
  needs.
- Configuring by model lets data sets come and go without touching the merge, and ordered approaches let each kind and
  attribute be settled the way that suits it.
- Separate steps keep matching, resolution and publication testable on their own.
- Keeping enrichment apart from matching lets another kind of data add facts without being mistaken for a record of
  the same thing.
- A separate data set leaves every input intact and attributable, so a wrong merge is fixed by rerunning, not by
  repairing inputs.

## Consequences

### Positive

- One entity per real-world thing is available for every kind with a merge configured.
- A data set added for a model takes part in its merges without any change to them.
- Consumers who need a single picture read one data set; those who need a specific source keep reading it.
- A new kind needs a configuration, not a new algorithm.
- Enriching data sets add facts to a merged entity without becoming part of its identity.
- Unsettled conflicts are visible on the entity, so they can be followed up at their source.
- The matching depends only on positions and identifiers, so it can be tested and tuned without a broker.

### Negative / Trade-offs

- A data set added for a model affects merges before anyone has judged its values, including through majority votes.
- Majority cannot settle a conflict between two members, so merges of two data sets depend on source authority.
- Each thing is published once per input data set and once more merged, so queries that span data sets see it several
  times.
- A data set that records one thing more than once defeats the one-record-per-data-set rule; such duplicates are
  merged with different neighbours, or left apart.
- A thing described at different granularity by different data sets, such as a facility and the units within it, is
  not one-to-one and is not resolved by this matching.
- Enrichment by area depends on how areas are compared, which is left undecided; a point near an area's edge, or in
  two overlapping areas, takes its value from whichever test that decision settles on.
- A merged entity's identifier changes when its first-sorting member disappears, or when a new member sorts before it.
- Until records lost upstream are removed from the broker, merges keep publishing what they said.
- The licence of a merged data set follows from its inputs, and inputs without a stated licence leave it unsettled.
