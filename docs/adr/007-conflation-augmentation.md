# 007: Conflation - augmentation

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath, Jeppe Julius Krogh                 |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

Some data sets concern a thing without describing it. For example, an area of asphalt in a data set of ground cover
gives its surface to every parking space on it, but it is a separate entity from the parking space itself. Such a data
set cannot take part in the merge in the same way as the data sets that describe the actual thing.

Merge by location joins records one to one, so an area would join at most one of the spaces that it covers, and the
others would not get their surface. Its records could also give a merged entity its identifier, and
its values would take part in conflicts about a thing that it does not describe.

We could leave this to the consumers, so that each of them looks up the area that a parking space lies in. But then
every consumer must know which data sets to combine and how, and the merged data set is not the single picture that
003 aims for.

This ADR serves to decide how data sets that concern a thing, without describing it, add details to the merged
entities.

## Decision

Data sets that concern a thing without describing it take part in a separate step, augmentation, which runs after
conflicts are resolved. For each merge, a person names the data models that augment the merged entities, and the
details that each of them gives. In one merge, a data model either describes the thing or augments it, never both.

Augmenting records never become merged entities, and they take no part in merge by location or in the identifier of
a merged entity. They only add details to the merged entities that already exist, at the location that conflict
resolution gave them.

The standard rule uses the shape of each augmenting record. An area gives its details to every merged entity whose
location lies within it, tested against the full shape of the area. A point gives its details to every merged entity
within the radius of the merge. Thus, one augmenting record can reach many merged entities.

Augmentation only fills in details that a merged entity is missing. A value that the records of the thing itself give
is always kept. When several augmenting records give different values for the same detail, such as two areas that
overlap, the rules of the merge from 006 settle the conflict, and an unsettled conflict is recorded like any other.
The identifiers of the augmenting records that were used are added to the list of records that the merged entity was
built from.

A kind of thing that the standard rule does not suit can use a rule of its own, or add to the standard rule. A rule of
its own can run the standard rule first and then translate the values. Every rule must keep the same limits. It adds
only the details that the merge names, and it never creates, removes, regroups or moves merged entities. It also never
overwrites a value that a merged entity already has. If a rule breaks these limits, the run stops and nothing is
published.

## Consequences

### Positive

- Details from a data set that concerns a thing reach every merged entity that they concern, without adding entities.
- The identifier of a merged entity depends only on the records that describe the thing.
- Consumers get those details on the merged entity, without combining data sets themselves.
- A rule that breaks the limits stops the run instead of publishing a wrong merge.

### Negative

- Every merge that uses an augmenting data model must name it, with the details that it gives.
- A merged entity whose location is a few metres off can take its value from the wrong area, near an edge.
- A detail that a data set gives under another name, or in other categories, needs a rule of its own to translate it.
- A data set that describes some things and only concerns others must be split into two data models before it can
  take part in both roles.
- The test of a location against the full shape of an area is our own code, separate from the single point that merge
  by location compares.
