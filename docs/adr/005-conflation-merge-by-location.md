# 005: Conflation, merge by location — one-to-one matching, closest first, through a grid index

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

004 runs conflation as a pipeline whose second step, merge by location, groups the records that describe the same
real-world thing. Every later step works on these groups: conflicts are settled within a group, and each group
becomes one merged entity.

Records of one thing from different data sets rarely share an identifier, so they can only be related by where they
are and what they are. Data sets place the same thing differently, by survey, by hand on a map or by an address
looked up as a position, so two records of one thing can lie metres apart.

Position alone does not tell them apart. Benches along a promenade can stand 2–3 m from each other, while two data
sets can place the same bench 1–2 m apart. A rule loose enough to join the two records of one bench can also join
its neighbour.

The data sets of a kind can each hold tens of thousands of records. Comparing every record with every other grows
with the square of their number: two data sets of 50,000 records each give some 2.5 × 10⁹ comparisons, which is too
slow to run after every import.

Some kinds are described in ways a rule based on points cannot match, such as a toilet given by one data set as a
point at its door and by another as the outline of its building.

Which records take part, how they are matched, how candidate pairs are found, how distance is measured and how a kind
customizes matching are undecided.

This ADR serves to decide how merge by location groups records, its default rule, and how a merge replaces or extends
that rule.

### Drivers

- **Functional:** neighbouring things stay apart; records of one thing join even when placed metres apart; every
  record ends in exactly one group; a kind the default does not suit can match by rules of its own.
- **Non-functional:** repeatable, so the same records give the same groups whatever their order; fast enough for
  tens of thousands of records per data set and a handful of data sets per kind; testable without a broker.

### Options Considered

#### Matching rule

**Clustering by a fixed radius.** Every record within a radius of another joins its group, and so on transitively.
It is simple and fast, but a row of things standing closer than the radius collapses into one, and a chain of near
matches can span many times the radius. A radius small enough to avoid that misses records of one thing that data
sets place further apart.

**One-to-one matching, closest first.** Pairs of records from different data sets within a radius are accepted in
order of distance, as long as a group holds at most one record per data set and every member stays within the radius
of every other. Neighbouring things each keep their own closest match instead of sharing one, and a group cannot
drift. It depends on each data set describing a thing once, and an ambiguous pair is still decided by distance alone.

**Probabilistic record linkage.** A trained or tuned model scores pairs on position together with names, addresses
and other attributes. It resolves cases position cannot, but it needs training or tuning data per kind, its decisions
are harder to explain, and it adds a dependency for cases the rules above already settle.

**Contributing matches upstream through a shared map's quality-assurance tooling.** Matches are reviewed by mappers
and improve a public source for everyone. It produces edits to that source rather than merged entities to publish,
requires every input to carry a compatible licence, and depends on human review.

#### Finding candidate pairs

**Comparing every record with every other.** It needs no structure, but the work grows with the square of the number
of records.

**A spatial query in the broker or a database.** The broker is asked for the records near each record. It uses an
index the store already has, but makes one request per record, ties matching to one store's query language, and
cannot be tested without it.

**A grid index in memory.** Records are put into square cells at least the radius wide, so any record within the
radius of another lies in its cell or one of the eight around it, and only those are compared. The work grows with
the number of records rather than its square, and it is plain code that is tested without a store, but it is code of
our own to maintain. It is the cell method for the fixed-radius near neighbours problem, described by Bentley, Stanat
and Williams in [The complexity of finding fixed-radius near neighbors][bentley-1977] (1977); see also
[Fixed-radius near neighbors][frnn].

#### Measuring distance

**Planar distance in a projected coordinate system.** Exact within the projection's zone, but every record has to be
transformed first, and the result is only right within one zone.

**A geometry library's distance function.** Libraries already in the project give the same great-circle distance,
but build objects per call, which makes them several times slower in a loop that runs once per candidate pair, and
they have no spatial index.

**The haversine formula on a sphere.** A few lines, works anywhere on earth without a transform, and differs from the
ellipsoid by a fraction of a percent, far less than how precisely positions are recorded.

#### Comparing areas

**One representative point per record.** A point's own position, or an area's centroid. Every record compares the
same way, but a record of the same thing placed elsewhere within a large area can lie beyond the radius.

**Full geometries through GEOS.** Exact point-in-area, overlap and edge-distance tests, through the GEOS geometry
engine as a PHP extension. It separates a facility from the units within it, but the extension is not part of the
PHP runtime images and has to be built into each of them, from a source with no recent release.

## Decision

Merge by location **matches one-to-one, closest first**, finds candidate pairs through **a grid index in memory**,
measures distance by **the haversine formula**, and compares **one representative point per record**. This default
is an implementation of an **abstract merge-by-location class**, and a merge that needs other rules names a subclass
of it in its declaration.

### Configuration

The merge names the models whose records are matched, and the implementation it uses with its radius:

```php
#[Conflation(
    id: 'merged-benches',
    resultModel: 'ResultModel',
    defining: ['ModelA', 'ModelB'],
    mergeByLocation: new OneToOneClosestFirst(radius: 5.0),
)]
final class BenchConflation implements ConflationInterface
{
}
```

- **Defining models:** the models whose records are matched, each able to form a merged entity. Every data set that
  publishes them takes part, so a data set added for a model joins its merges without a change to them.
- **Radius:** the furthest apart, in metres, two records of one thing can lie. It differs per kind: a few metres for
  benches, more for things located by address.

### The default: one-to-one, closest first

- **Representative point:** each record is compared by one point: its own position, or an area's centroid.
- **Candidate pairs:** a grid index pairs each record only with records in its own and the eight surrounding cells.
  Cells are sized in degrees, at least the radius wide at the record furthest from the equator, so no transform is
  needed.
- **Matching:** pairs within the radius are taken closest first, and a pair joins its two records' groups only while
  the result holds at most one record per data set and every member lies within the radius of every other. Ties are
  broken by identifier.
- **Within one data set:** two records from the same data set are never matched, however close they lie. A data set's
  records are taken to describe distinct things.
- **Completeness:** a record that matches nothing forms a group of one, so the merged data set covers every thing the
  defining models describe.

#### Example

Three benches stand 2.5 m apart along a promenade. Data set A records them as `A-1`, `A-2` and `A-3`. Data set B
records the same benches as `B-1`, `B-2` and `B-3`, each 0.8 m off. The radius is 5 m:

```text
A-1      A-2      A-3
 |  0.8 m |        |
B-1      B-2      B-3
<-2.5 m->
```

- **A fixed radius of 5 m** reaches the neighbours, so all six records end in one group, and three benches become one.
- **One-to-one, closest first** takes the three 0.8 m pairs first. After that, `A-1` and `B-2` are within 5 m, but
  their groups already hold a record from each data set, so they are not joined. The result is three groups, one per
  bench.

### Customizing

A merge whose kind the default does not suit names a subclass of the abstract merge-by-location class. The subclass
replaces the default, or extends it by calling the default and adjusting its groups. It receives every record of the
defining models with its full geometry, so it can compare areas by shape:

```php
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    defining: ['ModelA', 'ModelB'],
    mergeByLocation: new PointInArea(radius: 5.0),
)]
final class ToiletConflation implements ConflationInterface
{
}
```

Data set A gives a toilet as a point at its door, and data set B as the outline of a 30 m long building. The outline's
centroid lies 15 m from the door, beyond the radius, so the default leaves them apart. `PointInArea` extends the
default: it first matches a point to the area it lies within, or whose edge lies within the radius, and leaves the
remaining records to the default.

### Limits every implementation keeps

- Only records of defining models form groups.
- Every record belongs to exactly one group.
- A group holds at most one record per data set.
- The same records give the same groups, whatever their order.

The pipeline checks the first three on the groups an implementation returns, and a run whose groups break them fails
without publishing.

Rationale:

- One-to-one matching separates neighbouring things that a fixed radius merges, without the training data linkage
  needs.
- A grid index brings the work down from the square of the number of records to roughly the number of records, and
  is tested without a store.
- Haversine distance needs no transform and is accurate far beyond how precisely positions are recorded.
- One representative point keeps the default simple and the same for every kind; a kind that needs shapes gets them
  through a customization.
- An abstract class with a default lets most kinds use configuration alone, and gives a kind described by areas rules
  of its own that another kind can reuse.

## Consequences

### Positive

- Neighbouring things of one kind stay apart even when they stand closer than the radius.
- A group cannot drift: every member lies within the radius of every other.
- Matching tens of thousands of records per data set takes seconds, not hours.
- The default depends only on positions and identifiers, so it is tested and tuned without a broker.
- A customization that breaks the limits fails its run instead of publishing a wrong merge.

### Negative / Trade-offs

- A data set that records one thing twice defeats the one-record-per-data-set rule: its duplicates are matched with
  different neighbours, or left apart.
- An ambiguous pair, such as two equally close candidates, is decided by distance and identifier alone.
- A thing described at different granularity by different data sets, such as a car park and its bays, is not
  one-to-one and needs a customization.
- An area is matched by its centroid alone by default, so a record placed elsewhere within a large area can stay
  apart.
- Comparing areas by full shape through GEOS is left undecided; a customization that needs it has to wait for the
  extension, or compare shapes in code of its own.
- The grid index and the distance function are code of our own to maintain.

[bentley-1977]: https://doi.org/10.1016/0020-0190(77)90070-9
[frnn]: https://en.wikipedia.org/wiki/Fixed-radius_near_neighbors
