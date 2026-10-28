# CLAUDE.md

Instructions for Claude Code and other AI agents in this repository. The audience is the agent. For
documentation for people, see [README.md](README.md).

If an instruction here conflicts with the user's global `~/.claude/CLAUDE.md`, this file wins.

## Project overview

Enter collects open data sets and publishes them in an NGSI-LD context broker. The application is a Symfony 8 app on
the ITK Dev Docker setup. The repository is [`itk-enter/enter`](https://github.com/itk-enter/enter).

The application does two jobs:

- **Import.** A source reads one feed, converts each record to an NGSI-LD entity of one data model, and upserts the
  entities into the broker. Then the import deletes the entities of that source that the feed no longer gives.
- **Conflation.** A merge reads the data sets of one or more models from the broker. It groups the records that
  describe the same thing, settles the attributes that they disagree on, and publishes the result as a merged data
  set. An attribute that no approach settles stays out of the entity. Its values go into
  `additionalInformation.conflicts`. The merge runs after each import run that imports all of its data sets.

The data sets are published on `/sources` and `/sources.json`. The `/test` page shows the test data sets on a map.

## Tech stack

- **PHP 8.4 or later** in the `phpfpm` service (Symfony 8.1, PSR-4 `App\` in `src/`).
- **Nginx** in front of `phpfpm`, at `https://enter.local.itkdev.dk` through the shared **Traefik** proxy. Nginx also
  serves the broker API under `/ngsi-ld/v1/`.
- **Scorpio Broker** (`scorpio`, port 9090) is the NGSI-LD context broker. It uses PostGIS (`scorpio-db`). The
  broker service is in `docker-compose.scorpio.yml`. The app reaches it through the scoped HTTP client `broker.client`
  and `APP_BROKER_BASE_URI`.
- **Symfony HttpClient** reads the feeds and calls the broker. **Monolog** logs.
- **Twig**, **Stimulus** and **AssetMapper** (`importmap.php`) make the front end. **MapLibre GL** draws the map.
- **proj4php** and **geoPHP** reproject and measure geometries.
- **Mailpit** catches the mail that the app sends.
- There is no application database. The `mariadb` service from the template is removed in
  `docker-compose.override.yml`.

## Project structure

```text
bin/                  Symfony console entry
config/               Symfony configuration (packages, routes, services)
public/               Web root
src/
  Broker/             NgsiLdBroker: upsert, delete and read entities in the broker
  Command/            app:* console commands (source import and list, conflation run, broker delete)
  Conflation/         The merge engine: Conflation attribute, pipeline, steps, fetch and publish
    Augmentation/     Adds attributes from records of augmenting models
    ConflictResolution/  Approaches that settle attributes the records disagree on
    Data/             Record, Cluster, StatedValue, MergedEntity
    EventListener/    Runs the merges after an import run
    Io/               Reads records from the broker and makes the merged entities
    MergeByLocation/  Groups the records that describe the same thing
    Pipeline/         Runs the steps and checks their limits
    Spatial/          Distances and the grid index
  Controller/         Pages and JSON endpoints
  Geo/, GeometryHelper/  Reprojection and geometry functions
  Import/             SourcesImporter and SourcesImportedEvent
  Merge/              The merges that the app publishes
  Ngsi/               NgsiEntity, one entity in normalized NGSI-LD
  Source/             One class per source, with its #[Definition]
  SourceImporter/     Reads a feed and imports it, one importer per feed type
  SourceReader/       Reads the feeds over HTTP and keeps them in a cache
  Test/               Test sources, test merges and test:* commands (dev only)
tests/                PHPUnit tests, mirrors src/
assets/               Stimulus controllers and styles
templates/            Twig templates
docs/                 Notes on NGSI-LD, Scorpio, tests and conflation
docs/adr/             Architecture Decision Records. Read docs/adr/CLAUDE.md before you write one
.docker/              Nginx configuration and templates
.github/workflows/    CI workflows, mirrored from upstream. Do not edit them here
```

## Coding standards

The configuration files are in the repository root:

- `.php-cs-fixer.dist.php`: PHP CS Fixer, Symfony rule set.
- `rector.php`: Rector. `task coding-standards:php:apply` runs Rector and then PHP CS Fixer.
- `.twig-cs-fixer.dist.php`: Twig CS Fixer.
- `.prettierrc.yaml`: Prettier for assets and YAML.
- `.markdownlint.jsonc` and `.markdownlintignore`: Markdown lint. The maximum line length is 120.
- `phpstan.dist.neon`: PHPStan, level 6. There is no baseline. Do not add one. Fix the code.

Run the check for each area that you change before you commit:

```sh
task coding-standards:check          # every check
task coding-standards:php:check      # also php, twig, yaml, markdown, assets, composer
task coding-standards:php:apply      # each check has an apply task
task code-analysis                   # PHPStan
task test                            # PHPUnit, one task for each folder in tests/
```

`task test` does not run `tests/Import`, `tests/Merge` and `tests/Unit`. To run all the tests, use
`docker compose exec phpfpm vendor/bin/phpunit`.

### Tests are not modified without approval

Do **not** edit, rename, delete or skip a test file without approval from the user. This rule applies to all the
files in `tests/`, and to the test fixtures. It also applies when a failure looks like an old assertion.

If your change needs a test change, stop. Tell the user, in short:

- Which test files and test methods must change.
- What the change is: an assertion update, a fixture change, a new case or a removal.
- Why the change is necessary: for example, the behaviour of the code changed.

Wait for approval before you change the files. The user decides if the code or the test is wrong.

You can add a new test file for new code without approval. Tell the user about it.

### Language: ASD-STE100

All English text that people read is in ASD-STE100 Simplified Technical English. This applies to:

- Docblocks and comments in PHP, Twig and JavaScript.
- Markdown files: `README.md`, `docs/`, ADRs and this file.
- `CHANGELOG.md` entries, commit messages and PR descriptions.
- Console output and log messages.

The rules that apply most often:

- Write sentences of 20 words or fewer for instructions, and 25 words or fewer for descriptions.
- Write one instruction in each sentence. Use the imperative: "Run the import." Put a condition first: "If the
  broker is not available, start Scorpio."
- Write one topic in each paragraph, with 6 sentences or fewer.
- Use the active voice: "The merge deletes the stale entities", not "The stale entities are deleted".
- Use the simple tenses: present, past and future.
- Use each word with one meaning only. Use the same term for the same thing each time: "data set", "source",
  "merge", "merged entity".
- Do not use words that end in "-ing" as verbs or nouns. "To merge the records", not "merging the records".
- Use "the", "a" and "an". Do not leave them out to make text shorter.
- Do not use noun clusters of more than three words. Use "of" or write the text again.
- Use a vertical list for steps or for three or more items.

Code identifiers, technical names and quoted text are not changed. Examples are class names, routes, NGSI-LD terms
and the Danish titles of data sets. If you are not sure that a word is approved, use the most simple word with one
meaning.

Apply the rules to the text that you write or change. Do not write old text again in an unrelated change.

## Coding practices

These are conventions in addition to the linter rules in "Coding standards".

### Use symfony.com/doc for Symfony features

Before you add or change a feature on a Symfony component, read the applicable chapter on
<https://symfony.com/doc>. Examples are console commands, event listeners, HTTP clients and Twig. Use the approach
that the documentation shows.

If the documentation shows more than one approach, use the one that this codebase uses. This codebase uses
attributes and autowiring: `#[AsCommand]`, `#[AsEventListener]`, `#[Autowire]`, `#[When('dev')]` and scoped HTTP
clients. If nothing similar exists, use the most recent approach in the documentation.

If a change uses a Symfony approach for the first time, give the documentation URL in the PR description.

### Controllers stay thin

A controller receives a request, calls a service and returns a response. Put logic in a service class. A controller
class has a one-line docblock or none.

### Docblocks explain the code

A docblock tells what the code does and how to use it. If the reason is necessary to understand the code, write the
reason in plain words in the docblock.

Do not refer to project history in code. Do not write:

- ADR numbers (`ADR 004`, `see docs/adr/...`).
- Issue, ticket or PR numbers.
- Follow-up work, milestones or sprints.
- Examples.

Put this context in the PR description, the commit message or the CHANGELOG.

You can write a `@todo` for a known defect that the code does not fix yet. The `@todo` tells what to correct, without
an issue number. Do not write a `@todo` for a new feature or an idea. Do not remove a `@todo` while the defect exists.

```php
 * @todo Calculate the correct centroid without the GEOS extension.
```

A class or method that other code extends needs more than `@param` lines. Examples are the abstract steps in
`src/Conflation/` and `AbstractSource`. The docblock tells:

- When the code calls the method.
- What each parameter holds.
- What the method must return.
- A small, concrete example.

Give `@param` and `@return` when they tell more than the signature, for example an array shape or a `list<…>`.

### Sources and merges

- A source publishes one data model. If a feed holds more than one kind of record, make one source for each kind.
  These sources share `dataset` and `datasetTitle`.
- The id of a test source or a test merge starts with `test:`. A test merge reads only test data sets. A merge
  without the prefix reads only the published data sets. Test classes are in `src/Test/` and are registered only in
  `dev` and `test`.
- Do not write an ADR for a new source. Write an ADR only for a decision that applies to more than one source.

## Branching and PRs

- The base branch is **`develop`**. Make a PR to `develop` in `itk-enter/enter`.
- Branch name: **`feature/<ticket>-<short-slug>`**, for example `feature/8395-developer-map`. If there is no ticket,
  use `feature/<short-slug>`.
- If your account cannot push to `itk-enter/enter`, push to your fork. Then make a cross-fork PR:
  `gh pr create --repo itk-enter/enter --base develop --head <fork-owner>:<branch>`.
- Open a new PR as a draft.
- A PR branch holds only its own commits. In a stack of PRs, make each branch from its parent PR branch only. Do not
  merge a branch that combines many PRs into a PR branch. If you do, that PR brings the other PRs into `develop`
  without review.
- The CI workflows in `.github/workflows/` come from
  [`itk-dev/devops_itkdev-docker`](https://github.com/itk-dev/devops_itkdev-docker). Do not edit them here. Make a PR
  upstream.
- Before a merge, all CI checks must pass. One check makes sure that `CHANGELOG.md` changed.

### PR description

Use the sections in `.github/PULL_REQUEST_TEMPLATE.md`: "Link to ticket", "Description", "Screenshot of the result"
and "Checklist". Keep the order of the sections. Write in ASD-STE100.

- A reviewer must understand the description in 30 seconds.
- Each bullet is one or two lines. If a bullet needs more, make two bullets.
- Give only the primary files of the change. The diff shows all the files.
- In the checklist, tick only the items that are true. If a test fails, give its name and tell if it also fails on
  `develop`.

## Commits

Write the subject in the past tense, with a capital letter and no prefix. This repository does not use Conventional
Commits.

```text
Added the conflation fetch step
Grouped the conflation classes into Data, Spatial, Pipeline and Io
Applied coding standards
```

- Keep the subject shorter than 70 characters.
- Use the body to tell why. Write the body in ASD-STE100.
- Make a separate commit for a coding-standards run, for example "Applied coding standards".

## CHANGELOG

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). There is no release yet. All entries
go under `## [Unreleased]`.

Each PR adds one entry at the top of `[Unreleased]`. The entry starts with a link to the PR, so add it after the PR
has a number:

```markdown
* [PR-82](https://github.com/itk-enter/enter/pull/82)
  Listed the merged data sets on /sources, in sources.json and on the map
```

### Entry style

An entry tells **what** changed. It does not tell why, how or which files.

- Start with a verb in the past tense: "Added", "Changed", "Fixed", "Removed".
- Write one or two lines. Three lines is the maximum. If an entry needs more, split the PR or cut the entry.
- Do not give the reason, the alternatives or the mechanism. Put them in the PR description or the commit body.
- Name one or two identifiers at most: a command, a class, a route or an environment variable. Do not give a list
  of files.
- Write one entry for each PR. Do not put an unrelated change into the entry.
- If a later PR in the same release reverts or replaces a change, edit or delete the old entry.
- Write in ASD-STE100.

Good:

```markdown
* [PR-83](https://github.com/itk-enter/enter/pull/83)
  Grouped the conflation classes into Data, Spatial, Pipeline and Io
```

Bad, with the reason, the mechanism and a file list in the entry:

```markdown
* [PR-83](https://github.com/itk-enter/enter/pull/83)
  The conflation classes were grouped into folders, because src/Conflation had become too large to read.
  Record, Cluster and StatedValue were moved to Data, and the tests were moved to match.
```
