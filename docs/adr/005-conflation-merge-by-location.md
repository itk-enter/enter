# 005: Conflation - merge by location

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath, Jeppe Julius Krogh                 |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

Different data sets do not use the same identifiers for the same thing, which leaves us with the geospatial location
as our only universal point of matching. However, each data set is collected in its own way, so the same thing can
have a slightly different location in each of them. Therefore, two records of one thing can be some metres apart.

But position alone is not sufficient. Benches along a promenade can stand 2–3 m apart, while two data sets can put the
same bench 1–2 m apart. A rule that joins the two records of one bench can also join it to the next bench.

The simplest way to find the records that lie close to each other is to compare each record with all other records.
But the data sets of one kind of thing can each have a lot of records, and the number of comparisons grows with the
square of the number of records. Two data sets of 10,000 records each give 100 million comparisons, which is
not a realistic approach.

As a result of this, we need a method of matching by location. It must join all the records of the same thing, and
keep things that stand close together apart. It must be fast enough to run after each import. As kind of data may
require different rules for conflation, these must be configurable.

This ADR serves to decide that method.

## Decision

We will match records one to one, closest first. For each kind of thing, we set a radius. The radius is the largest
distance between two records of the same thing. It is a few metres for benches, and more for things that data sets
locate by address.

Pairs of records from different data sets that are within the radius are joined in order of distance, closest first. A
group can have at most one record from each data set. Each record in a group must be within the radius of all other
records in the group. Two records from the same data set are never joined, because we assume that a data set describes
each thing only once. A record that matches nothing becomes a group of its own, so the merged data set includes every
thing.

For example, three benches stand 2.5 m apart. Two data sets each list the three benches, and each pair of records is
0.8 m apart. The radius is 5 m. A simple rule that joins all records within the radius makes one group of all six
records. The closest-first rule joins the three pairs that are 0.8 m apart first. After that, each group has a record
from each data set, so no more records can join. The result is three benches.

Each record is compared by one point: its position, or the centre of its area. Distance is measured along the surface
of the earth. To keep the work small, each record is compared only with the records near it, and not with all other
records. The same records always give the same groups, in any order.

A kind of thing that the standard rule does not suit can use a rule of its own, or add to the standard rule. For
example, one data set can give a toilet as a point at its door, and another data set can give it as the outline of its
building. A rule of its own can match the point to the area that contains it. Every rule must keep the same limits.
Only records of the kind of thing take part, each record is in exactly one group, and a group has at most one record
from each data set. If the groups break these limits, the run stops and nothing is published.

## Consequences

### Positive

- Things of one kind that stand close together stay apart, also when they are closer than the radius.
- A group cannot grow along a row of things, because each record in it is within the radius of all the others.
- Matching tens of thousands of records takes seconds, not hours.
- The rule uses only positions and identifiers, so we can test it without the broker.

### Negative

- A data set that lists one thing twice breaks the rule of one record for each data set. Its copies can join different
  things or stay apart.
- When two candidates are at the same distance, the identifier decides.
- A thing that data sets describe at different levels, such as a car park and its bays, needs a rule of its own.
- A large area is compared by its centre, so a record of the same thing elsewhere in the area can stay apart. The
  comparison of full shapes is not decided yet.
