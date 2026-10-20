import { Controller } from "@hotwired/stimulus";

/*
 * Shows a toggle for each test source and sends toggle events to the map.
 */

/* The prefix of a test source id. */
const TEST_PREFIX = "test:";

/* The colours for the data sets. */
const COLOURS = [
    "#d50000",
    "#2962ff",
    "#00a000",
    "#ff6d00",
    "#7b1fa2",
    "#00acc1",
    "#c51162",
    "#6d4c41",
];

/* Gets the test sources and groups them by model. */
async function loadSources(url) {
    const response = await fetch(url, {
        headers: { accept: "application/json" },
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    const colours = new Map();
    const sources = (await response.json())
        .filter((source) => source.id.startsWith(TEST_PREFIX))
        .map((source, index) => {
            if (!colours.has(source.dataset.id)) {
                colours.set(
                    source.dataset.id,
                    COLOURS[colours.size % COLOURS.length],
                );
            }

            return {
                ...source,
                index,
                colour: colours.get(source.dataset.id),
            };
        });

    const models = new Map();
    sources.forEach((source) => {
        if (!models.has(source.model)) {
            models.set(source.model, { name: source.model, sources: [] });
        }
        models.get(source.model).sources.push(source);
    });

    return {
        models: [...models.values()].sort((a, b) =>
            a.name.localeCompare(b.name),
        ),
        sources,
    };
}

/* Makes a Bootstrap checkbox row. */
function checkbox(id, label, attributes) {
    const wrapper = document.createElement("div");
    wrapper.className = "form-check";

    const input = document.createElement("input");
    input.className = "form-check-input";
    input.type = "checkbox";
    input.id = id;
    Object.assign(input.dataset, attributes);

    const text = document.createElement("label");
    text.className = "form-check-label";
    text.htmlFor = id;
    text.append(label);

    wrapper.append(input, text);

    return wrapper;
}

/* Shows one fieldset of checkboxes for each model. */
function renderToggles(form, models) {
    models.forEach((model) => {
        const fieldset = document.createElement("fieldset");
        fieldset.className = "test-map-group mb-3";

        const head = checkbox(`model-${model.name}`, model.name, {});
        head.classList.add("fw-bold");
        fieldset.append(head);

        model.sources.forEach((source) => {
            const swatch = document.createElement("span");
            swatch.className = "test-map-swatch";
            swatch.style.backgroundColor = source.colour;

            const label = document.createDocumentFragment();
            label.append(swatch, source.dataset.title);

            const row = checkbox(`source-${source.index}`, label, {
                source: source.index,
            });
            row.classList.add("ms-3");
            fieldset.append(row);
        });

        form.append(fieldset);
    });
}

/* Sets the model checkbox from the checkboxes of its sources. */
function reflectGroup(fieldset) {
    const inputs = [...fieldset.querySelectorAll("input[data-source]")];
    const on = inputs.filter((input) => input.checked).length;
    const head = fieldset.querySelector("input:not([data-source])");

    head.checked = on === inputs.length;
    head.indeterminate = on > 0 && on < inputs.length;
}

export default class extends Controller {
    static targets = ["form"];
    static values = { url: String };

    async connect() {
        const { models, sources } = await loadSources(this.urlValue);
        this.sources = sources;

        this.dispatch("loaded", { detail: { sources } });

        renderToggles(this.formTarget, models);
    }

    /* Switches a model or a source on or off. */
    toggle(event) {
        const input = event.target;
        const fieldset = input.closest("fieldset");

        if (input.dataset.source === undefined) {
            fieldset.querySelectorAll("input[data-source]").forEach((child) => {
                child.checked = input.checked;
                this.report(child);
            });
        } else {
            this.report(input);
        }

        reflectGroup(fieldset);
    }

    /* Sends a toggle event for a source. */
    report(input) {
        this.dispatch("toggle", {
            detail: {
                source: this.sources[input.dataset.source],
                on: input.checked,
            },
        });
    }
}
