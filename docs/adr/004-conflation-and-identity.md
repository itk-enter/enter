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

Not every data set that concerns a thing describes it. A data set can describe something else at the same place, such
as what covers the ground a thing stands on, and so add facts about the things there without being a record of any of
them. One such record can concern many things.

Data sets can describe one kind by different models and geometries, some as points and some as areas. Rules that
suit a kind described by points of one model do not always suit a kind described in several ways.

The data sets that describe a kind change as data sets are added and retired, and the values they state for one
attribute can contradict each other. Which value is right differs per kind and per attribute.

How records are found and matched, which data can define a merged thing and which can only add to one, how
contradictions are settled, how rules of a kind's own are added, where the merged result is published, how merged
entities are identified and how they refer back to their inputs are all undecided.

This ADR serves to decide how records from several data sets are found, matched, merged and augmented, and how the
result is published and identified.

### Drivers

- **Functional:** one entity per real-world thing, for every kind of thing published; configurable per kind, since
  kinds differ in how densely they stand, how precisely data sets locate them and which data sets are trustworthy for
  what; facts from data that describes something else at the same place, without it creating things; rules of a
  kind's own where the general ones fall short; a result that can be traced back to its inputs.
- **Non-functional:** repeatable — the same inputs give the same result; scales to tens of thousands of records per
  data set and a handful of data sets per kind; adding a kind or a data set costs configuration, and code only where
  a kind needs rules the defaults lack.

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
configured order per merge, such as majority and then the authority of data sets. A data set added for a kind joins
without a change, and each kind can be settled the way that suits it, but a new data set's values take part before
anyone has judged them, and some contradictions stay unsettled.

#### Data that describes something else

**Matching every input as a record of the thing.** One rule covers every input. A record of something else at the
same place then either forms a merged entity of its own, adding things that do not exist, or joins a group as if it
were a record of that thing, and a record that concerns many things can join only one group.

**A separate augment step for inputs that only add attributes.** Inputs are configured either to define merged
entities or to augment them, and augmenting inputs add attributes to resolved entities at their location without
creating any. Data about something else adds facts without being taken for a record of a thing, at the cost of a
further step and role, and of depending on how a location is tested against an area.

#### Rules of a kind's own

**Configuration only.** Every merge is expressed in configuration, so its behaviour can be read and reviewed in one
place. Every rule a kind needs has to become a configuration option, and cases such as matching points against areas
or reconciling models of different shape would grow the configuration into a language of its own.

**Default rules that a configured service can replace or extend per step.** A kind described simply needs
configuration only, and a kind described in several ways gets code where configuration cannot express its rules. One
service can serve several merges, but a merge's rules are spread over its configuration and up to three further
classes, each service needs tests of its own, and the interface of each step becomes a contract the services depend
on.

**A base class with the default rules, which each merge extends.** Every step is a method of a base class, and a
merge replaces a step by overriding it or extends it by calling the default from its override. A merge's
configuration and its rules of its own stay in one class, and the steps that must not change can be closed to
overriding. A rule needed by several merges has to live in a helper they share, a merge with overrides can no longer
be read from its configuration alone, and the methods that can be overridden become a contract every merge depends
on.

#### Declaring a merge

**Configuration files.** Merges are declared in configuration files, apart from the code. A merge can be changed
without touching a class, but classes are named by strings, approaches with arguments need a schema and validation
of their own, and merges are declared differently from the data sets they read.

**A class with a definition attribute, the way data sets are declared.** Each merge is a class carrying its
configuration as an attribute, and the merges are collected by a service tag. Approaches are referenced as objects
that static analysis checks, rules of the merge's own sit beside its configuration, and merges and data sets are
declared alike. Changing a merge is a code change and a deploy.

## Decision

Merges are configured by **model**, run in **five steps** — fetch, merge by location, resolve conflicts, augment,
create — and publish their result as **a data set of its own**. Inputs either **define** merged entities or only
**augment** them. Records are matched **one-to-one, closest first** by default. Each merge is **a class with a
definition attribute**, the way data sets are declared, that **extends a base class** holding the default rules of
every step and overrides the steps it needs to replace or extend.

### Configuration per merge

- The data model of the result and the match radius.
- The defining models: models whose records are matched and can each form a merged entity.
- Optionally, augmenting models, each with the attributes it supplies, named as in the result model. A model is
  either defining or augmenting in one merge, not both.
- An ordered list of resolution approaches, for every attribute in conflict.

A merge is declared like this, where the names, keys and values are illustrative and the exact shape of the attribute
and the base class is settled when they are implemented:

```php
#[Conflation(
    id: 'merged-result',
    resultModel: 'ResultModel',
    radius: 5.0,
    defining: ['ModelA', 'ModelB'],
    augmenting: ['ModelC' => ['attributeX']],
    conflictResolution: [new Majority(), new SourceAuthority(['data-set-1', 'data-set-2'])],
)]
final class MergedResult extends AbstractConflation
{
    // Replaces merging by location.
    protected function mergeByLocation(array $records): array
    {
        // …
    }

    // Extends augmenting: the defaults first, then rules of this merge's own.
    protected function augment(array $entities): array
    {
        $entities = parent::augment($entities);

        // …
    }
}
```

A merge that needs no rules of its own overrides nothing, and its class body is empty.

### Default rules and rules of a merge's own

- The base class holds the default rules of every step, and a merge that overrides nothing runs the defaults alone.
- A merge can override merging by location, resolving conflicts and augmenting, one, several or none of them. An
  override replaces that step's default rules, or extends them by calling the default, such as to match points
  against areas, to settle an attribute by a rule of the kind's own, or to reconcile models of different shape.
- Fetching and creating cannot be overridden, so every merge keeps the same rules for its inputs, identity,
  provenance, publication and removal.
- A rule needed by several merges lives in a helper they share, not in a class between them and the base class.
- Whatever rules a merge runs, only records of defining models form groups, and a record belongs to at most one group.
  The base class checks the groups an override of merging by location returns against these rules, and a run whose
  groups break them fails without publishing.

### 1. Fetch

- Every entity of the defining and augmenting models is read from the broker, from every data set that publishes
  them, with the data set it came from and its full geometry. The default rules compare its representative point,
  and a merge's own rules can use the geometry.
- The merged data set's own entities are left out, as are data sets kept for testing when published data is merged,
  and the other way round.

### 2. Merge by location

- **Inputs:** only records of defining models take part.
- **Matching:** records are compared by the distance between one representative point each: a point's own position,
  or an area's centroid. Pairs within the radius are accepted closest first while a group holds at most one record per
  data set and every member lies within the radius of every other. Ties are broken by identifier.
- **Within one data set:** two records from the same data set are never matched or merged, however close they lie. A
  data set's records are taken to describe distinct things.
- **Completeness:** a record that matches nothing forms a group of one, so the merged data set covers every thing the
  defining models describe.
- **Geometry:** whether areas are compared by their full shape through GEOS, instead of by one representative point,
  is left undecided. It becomes relevant where data sets describe the same things as areas or at different
  granularity.

### 3. Resolve conflicts

- An attribute is in conflict when the members of a group that state it state different values. An absent value never
  contradicts a stated one.
- The configured approaches are tried in order until one settles the conflict, the same for every attribute. An
  attribute that needs rules of its own is settled by the merge's override of the step:
  - **Majority:** the value stated by more members than any other settles it. A tie does not.
  - **Source authority:** configured with an ordered list of data sets. The value of the earliest listed data set that
    states one settles it. Members whose data sets are not listed cannot settle it.
- A conflict no approach settles leaves the attribute out, and the conflicting values and the data sets stating them
  are recorded on the merged entity.
- **Location:** a merged entity's location is resolved like any other attribute. Where no approach settles it, the
  location of the member whose identifier sorts first is taken, so every merged entity has one.

### 4. Augment

- Augmenting works on the resolved entities, at their resolved location. It adds attributes, and never creates,
  removes or regroups entities or changes their location.
- **Augmenting models:** a record of an augmenting model supplies its configured attributes to every merged entity
  whose location lies within its area, or within the radius of its point. One record can augment many entities.
- **Rules of the merge's own:** a merge that overrides augmenting replaces or extends the default, such as to add
  attributes it derives or looks up, under the same limits.
- Augmenting only fills attributes that the resolved entity lacks. Where several augmenting records supply one
  attribute differently, the same configured approaches settle it, and an unsettled one is recorded like any other
  conflict.

### 5. Create the merged entity

- Every merged entity is built in the configured data model, an existing model of the kind, with input attributes
  taken by name. Where an input model names an attribute differently, the merge's override of the step that uses it
  relates the two.
- **Identity:** a merged entity's identifier derives from the member whose identifier sorts first.
- **Provenance:** a merged entity lists the identifiers of the input entities it was built from, augmenting ones
  included.
- **Publication:** the merged data set has its own source identifier. The input data sets stay published unchanged.
- **Removal:** entities of the merged data set that a run no longer produces are deleted.

### Timing and records lost upstream

- A merge runs after a full import, and only when every data set known to publish its defining or augmenting models
  imported successfully.
- What a merge does when a data set loses a record is left undecided, with the lifecycle of records. Until a record
  that disappears upstream is removed from the broker, it keeps taking part in merges. Once it is removed, the next run
  drops it from its group, and a group left with no members no longer produces an entity.

Rationale:

- One-to-one matching separates neighbouring things that a fixed radius merges, without the training data linkage
  needs.
- Configuring by model lets data sets come and go without touching the merge, and ordered approaches let each kind be
  settled the way that suits it, with an override for attributes that need rules of their own.
- Separating defining from augmenting inputs keeps data about something else from creating things or being taken for
  a record of them.
- Default rules with replaceable steps keep simply described kinds in configuration and give the others code where
  configuration cannot express their rules, while fetching and creating stay common to every merge.
- Declaring a merge as a class lets static analysis check its approaches, and declares merges the way data sets are
  declared.
- A base class keeps a merge's configuration and its rules of its own in one class, and closes the steps every merge
  must share.
- Separate steps keep matching, resolution, augmentation and publication testable on their own.
- A separate data set leaves every input intact and attributable, so a wrong merge is fixed by rerunning, not by
  repairing inputs.

## Consequences

### Positive

- One entity per real-world thing is available for every kind with a merge configured.
- A data set added for a model takes part in its merges without any change to them.
- Consumers who need a single picture read one data set; those who need a specific source keep reading it.
- A new kind needs a class with its configuration, and code in it only where the defaults do not suit it.
- Augmenting data adds facts to merged entities without creating entities or becoming part of their identity.
- Rules of one kind's own are confined to its class and do not change other merges.
- Unsettled conflicts are visible on the entity, so they can be followed up at their source.
- An override of merging by location that breaks the shared rules fails its run instead of publishing a wrong merge.
- The default matching depends only on positions and identifiers, so it can be tested and tuned without a broker.

### Negative / Trade-offs

- A data set added for a model affects merges before anyone has judged its values, including through majority votes.
- Majority cannot settle a conflict between two members, so merges of two data sets depend on source authority.
- Each thing is published once per input data set and once more merged, so queries that span data sets see it several
  times.
- A data set that records one thing more than once defeats the one-record-per-data-set rule; such duplicates are
  merged with different neighbours, or left apart.
- A thing described at different granularity by different data sets, such as a facility and the units within it, is
  not one-to-one and is not resolved by the default matching.
- An area is matched by its centroid alone, so a record of the same thing placed elsewhere within a large area can lie
  beyond the radius and stay apart.
- Augmenting by area depends on how areas are compared, which is left undecided; a location near an area's edge, or in
  two overlapping areas, takes its value from whichever test that decision settles on.
- A merge with overrides cannot be understood from its configuration alone, and its overrides need tests of their own.
- The methods a merge can override become a contract; changing one touches every merge that overrides it.
- A rule needed by several merges has to be moved into a shared helper.
- Changing a merge's configuration is a code change and a deploy.
- A merge that combines models naming the same attribute differently needs an override to relate them.
- A merge with an attribute that needs approaches in a different order from the rest has to override resolving
  conflicts.
- A merged entity's identifier, and its location where no approach settles it, change when its first-sorting member
  disappears, or when a new member sorts before it.
- Until records lost upstream are removed from the broker, merges keep publishing what they said.
- The licence of a merged data set follows from its inputs, and inputs without a stated licence leave it unsettled.
