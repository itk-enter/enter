# CLAUDE.md — writing ADRs in this project

Guidance for Claude Code when creating or editing files in `docs/adr/`.

An ADR here **decides a specific choice on general grounds**. The choice is
concrete; the reasoning must hold beyond any one data set, developer or machine.

## Never include

- **Local project or repository names.** These are organisation-level documents;
  someone's working copies have no place in them.
- **Specific data sources, their fields, or their quirks.** Not field names, not
  envelope shapes, not "the source provides…". State the *condition* the
  decision applies under instead: "This decision applies where a data set
  provides a count of units per location…".
- **Measurements or counts taken from a data set.** No record counts, no
  "measured across N records", no byte or centimetre figures derived from one
  export. Standards identifiers (EPSG codes, RFC numbers, framework versions)
  and general domain facts are fine.
- **Educational or self-referential framing.** No "two things make this easy to
  get wrong", no "note that", no "the real lesson is", no bolded aphorism
  followed by the actual fact, and no commentary on the ADR's own importance
  ("this is the hardest to reverse"). State the fact and stop.

## Structure

```text
# NNN: Area — the choice

| Field | Value |            Created By, Date, Decision Maker, Stakeholders, Status

## Context                    the situation; what is undecided
                              ends with: "This ADR serves to decide …"
### Drivers                   Functional / Non-functional, brief
### Options Considered        one paragraph each, upsides then downsides
## Decision                   the choice, then terse rationale bullets
## Consequences
### Positive
### Negative / Trade-offs     the honest costs, including ones found by hitting them
```

The Context section **must end with a single "This ADR serves to …" sentence**
so the purpose is unmissable.

Status is `Draft` while it needs review, then `Accepted`. Other values:
`Rejected`, `Deprecated by NNN`, `Supersedes NNN`.

## Numbering

Numbering follows **dependency order**: every ADR may reference only
lower-numbered ones. Check for forward references after adding or renumbering.
Dates therefore need not run in the same order as numbers.

Renumbering is only acceptable while nothing external cites the numbers. Once
cited, supersede instead.

## Before writing, ask whether it earns its place

- **Is there a real alternative?** An ADR comparing one viable candidate against
  a dead end documents a decision that made itself, and dilutes the ones
  recording genuine trade-offs. Reframe it around the decisions that did have
  alternatives, or fold it into a neighbour.
- **Would folding it into an existing ADR be better?** Prefer fewer, shorter
  documents. Consolidation has been chosen over adding a document more than
  once.
- **Are the rules distinct?** One principle restated against several targets is
  one rule with sub-points, not several rules.

## Before finishing

- Grep for source field names, model names in the general ADRs, project names,
  record counts and measured figures.
- Grep for `Note that`, `worth noting`, `is the lesson`, `easy to get wrong`,
  `Two things`, `earns its`, `is the point`.
- Check no ADR cites a higher number than its own.
- `task coding-standards:markdown:check` — recurring failures are line length
  over 120 (MD013), misaligned table pipes (MD060), and bold used as a heading
  (MD036); use `####` for sub-headings inside a section.
