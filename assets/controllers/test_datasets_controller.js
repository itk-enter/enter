import { Controller } from "@hotwired/stimulus";

/*
 * The data sets the map may draw: the test sources, fetched from the list,
 * grouped by the data set they belong to, given a colour per data set, and
 * offered as toggles: one per source, named by the model it publishes, under
 * a toggle for the data set as a whole. Nothing here knows about the map;
 * what is known and what is switched is dispatched as events for the map
 * controller on the same element.
 */

/* By convention a test source's id starts with this. */
const TEST_PREFIX = "test:";

/*
 * One color per data set, in the order the sources come: saturated and far
 * apart in hue, so they stand out from the pale base map and from each other.
 */
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

/*
 * Fetch the sources from the list and keep the test ones, grouped into data
 * sets in the order they first appear. A source that shares no data set
 * with others is a data set of its own. Each source is numbered and takes
 * its data set's colour, so the map can draw it on its own.
 */
async function loadDatasets(url) {
    const response = await fetch(url, {
        headers: { accept: "application/json" },
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    const datasets = new Map();
    const sources = (await response.json())
        .filter((source) => source.id.startsWith(TEST_PREFIX))
        .map((source, index) => {
            if (!datasets.has(source.dataset.id)) {
                datasets.set(source.dataset.id, {
                    ...source.dataset,
                    index: datasets.size,
                    colour: COLOURS[datasets.size % COLOURS.length],
                    sources: [],
                });
            }

            const dataset = datasets.get(source.dataset.id);
            const numbered = { ...source, index, colour: dataset.colour };
            dataset.sources.push(numbered);

            return numbered;
        });

    return { datasets: [...datasets.values()], sources };
}

/*
 * Builds a Bootstrap checkbox row. The attributes end up as data-* on the
 * input, which is how the change handler tells a data set from a source.
 */
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

/*
 * One fieldset per data set, headed by a checkbox that switches every source
 * of it, with a checkbox per source below, named by its model.
 */
function renderToggles(form, datasets) {
    datasets.forEach((dataset) => {
        const fieldset = document.createElement("fieldset");
        fieldset.className = "test-map-group mb-3";

        const swatch = document.createElement("span");
        swatch.className = "test-map-swatch";
        swatch.style.backgroundColor = dataset.colour;

        const label = document.createDocumentFragment();
        label.append(swatch, dataset.title);

        const head = checkbox(`dataset-${dataset.index}`, label, {});
        head.classList.add("fw-bold");
        fieldset.append(head);

        dataset.sources.forEach((source) => {
            const row = checkbox(`source-${source.index}`, source.model, {
                source: source.index,
            });
            row.classList.add("ms-3");
            fieldset.append(row);
        });

        form.append(fieldset);
    });
}

/* The data set's checkbox mirrors its sources: all, none or some of them on. */
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
        const { datasets, sources } = await loadDatasets(this.urlValue);
        this.sources = sources;

        this.dispatch("loaded", { detail: { sources } });

        renderToggles(this.formTarget, datasets);
    }

    /*
     * Handles toggling. A data set's checkbox switches every source of it, a
     * source's checkbox just that one. Either way the data set's checkbox is
     * updated afterward.
     */
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

    /* Tells the map which source was switched, and which way. */
    report(input) {
        this.dispatch("toggle", {
            detail: {
                source: this.sources[input.dataset.source],
                on: input.checked,
            },
        });
    }
}
