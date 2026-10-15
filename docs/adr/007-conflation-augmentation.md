# 007: Conflation, augmentation — data about something else only adds attributes, through an abstract default

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

004 runs conflation as a pipeline whose fourth step, augment, adds facts to the merged entities from data sets that
describe something else at the same place.

Not every data set that concerns a thing describes it. A data set of ground cover describes areas of asphalt, gravel
and grass. It says something about every parking space standing on one of those areas, namely its surface, but none
of its records is a parking space. One area of asphalt can cover forty spaces, and a space can lie where two areas
meet.

Such a data set cannot be a source of entries in the merged data set:

- **It would create things that do not exist.** A record that matches no parking space would become a merged parking
  space of its own, and the merged data set would list an area of asphalt as a place to park.
- **It would reach only one thing.** Merging by location groups records one-to-one, so an area covering forty spaces
  would join at most one of them, and the other thirty-nine would not learn their surface.
- **It would change identity and conflicts.** As a member of a group, its identifier could become the merged entity's
  identity, and its values would vote in conflicts about the thing it does not describe.

Other examples are areas of a district register, which give a bench the name of the district it stands in, or zones
of a parking regulation, which give a space its time limit.

How data about something else takes part in a merge, which merged entities it reaches, what it may change, and how a
kind uses rules of its own are undecided.

This ADR serves to decide how data sets that describe something else add facts to merged entities, the default way of
adding them, and how a merge replaces or extends it.

### Drivers

- **Functional:** facts from data about something else reach every merged entity they concern; such data never
  creates, removes or regroups merged entities, nor takes part in their identity; a kind can derive or look up
  attributes by rules of its own.
- **Non-functional:** repeatable; testable on its own; an augmentation written for one kind can be reused by another.

### Options Considered

**Matching every input as a record of the thing.** One rule covers every input. Data about something else then
creates things that do not exist, reaches only one thing, and takes part in identity and conflicts, as described
above.

**Leaving it to consumers.** Data about something else stays in its own data set, and a consumer who wants a parking
space's surface looks up the area it lies in. Nothing is added to conflation, but every consumer has to know which
data sets to combine and how, and the merged data set is not the single picture 003 aims for.

**A separate augment step.** The merge names, per model, whether its records define merged entities or only augment
them. Augmenting records add attributes to the merged entities at their location without creating any, and one record
can augment many entities. Data about something else adds facts without being taken for a record of a thing, at the
cost of a further step and a further role, and it depends on how a location is tested against an area.

## Decision

Data about something else takes part through **a separate augment step**. Its models are named as **augmenting** in
the merge's declaration, and the step runs an implementation of **an abstract augmentation class**. A merge that needs
other rules names a subclass of it.

### Configuration

```php
#[Conflation(
    id: 'merged-parking',
    resultModel: 'ResultModel',
    defining: ['ModelA', 'ModelB'],
    augmenting: ['ModelC' => ['attributeX']],
    augmentation: new WithinAreaOrRadius(radius: 5.0),
)]
final class ParkingConflation implements ConflationInterface
{
}
```

- **Augmenting models:** each augmenting model with the attributes it supplies, named as in the result model. A model
  is either defining or augmenting in one merge, not both.
- **Implementation:** a merge that leaves the key out uses `WithinAreaOrRadius` with the merge's radius.

### The default: within the area, or within the radius of the point

- Augmenting works on the merged entities after conflicts are resolved, at their resolved location.
- An augmenting record given as an area supplies its attributes to every merged entity whose location lies within the
  area, tested against its full shape. One given as a point supplies them to every merged entity within the radius.
- Augmenting only fills attributes the merged entity lacks; a value stated by its own members is kept.
- Where several augmenting records supply one attribute differently, such as two overlapping areas, the merge's
  conflict-resolution approaches (006) settle it, and an unsettled one is recorded like any other conflict.
- The identifiers of the augmenting records used are added to the merged entity's provenance.

#### Example

An area of asphalt, `C-5`, covers spaces `P-1` to `P-40`. An area of gravel, `C-6`, lies next to it, and space `P-41`
lies on its edge, within both:

| Merged entity               | Surface before | Augmenting records | Surface after                     |
|-----------------------------|----------------|--------------------|-----------------------------------|
| `P-1`–`P-40`, except `P-12` | not stated     | `C-5`              | asphalt                           |
| `P-12`                      | gravel         | `C-5`              | gravel, as its members state      |
| `P-41`                      | not stated     | `C-5`, `C-6`       | settled by the merge's approaches |

No merged entity is created for `C-5` or `C-6`, and both stay published in their own data set.

### Customizing

A kind that needs other rules names a subclass of the abstract augmentation class. It receives the merged entities
and the augmenting records with their full geometry, and returns the attributes to add to each entity. It replaces
the default, or extends it by calling the default and adding to its result, such as to translate a value or derive
an attribute:

```php
augmentation: new SurfaceFromCoverType(radius: 5.0),
```

Here `SurfaceFromCoverType` runs the default, then translates the ground cover's own categories, such as "asphalt" and
"paving stones", into the surface values the result model uses, such as "paved".

### Limits every implementation keeps

- It adds attributes of the result model only.
- It never creates, removes or regroups merged entities, and never changes their location.
- It never overwrites a value the merged entity already has.

The pipeline compares the entities before and after the step, and a run that breaks these limits fails without
publishing.

Rationale:

- A separate step keeps data about something else from creating things or being taken for a record of them, and lets
  one record reach every thing it concerns.
- Only filling missing attributes keeps the members' own statements ahead of facts about their surroundings.
- Settling disagreeing augmenting records with the merge's own approaches avoids a second way of settling conflicts.
- An abstract class with a default lets most kinds add facts by configuration, and gives a kind that needs to
  translate or derive values code of its own that another kind can reuse.

## Consequences

### Positive

- Facts from data about something else reach every merged entity they concern, without adding entities.
- The identity of merged entities depends only on the records that describe them.
- Consumers get those facts on the merged entity, without combining data sets themselves.
- An augmentation that breaks the limits fails its run instead of publishing a wrong merge.

### Negative / Trade-offs

- Every augmenting model has to be named, with its attributes, by every merge that uses it.
- A merged entity whose location is off by a few metres can take its value from the wrong area, near an edge.
- An attribute supplied only under another name, or in other categories, needs a customization to translate it.
- A data set that both describes some things and only concerns others has to be split into models before it can be
  defining for one and augmenting for the other.
- Testing a location against an area's full shape is code of our own, apart from the representative point merging
  by location compares.
