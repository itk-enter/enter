import { Controller } from "@hotwired/stimulus";

/*
 * The data sets the map may draw: the test sources, fetched from the list,
 * given a colour each, and offered as toggles: one per model a data set
 * publishes, under a toggle for the data set as a whole. Nothing here knows
 * about the map; what is known and what is switched is dispatched as events
 * for the map controller on the same element.
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
 * Fetch the sources from the list and keep the test ones. Each is a data set
 * the map may draw.
 */
async function loadDatasets(url) {
    const response = await fetch(url, {
        headers: { accept: "application/json" },
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    const sources = await response.json();

    return sources
        .filter((source) => source.id.startsWith(TEST_PREFIX))
        .map((dataset, index) => ({
            ...dataset,
            index,
            colour: COLOURS[index % COLOURS.length],
        }));
}

/*
 * Builds a Bootstrap checkbox row. The attributes end up as data-* on the
 * input, which is how the change handler tells a data set from a model.
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
 * One fieldset per data set, headed by a checkbox that switches every model
 * of it, with a checkbox per model below.
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

        const head = checkbox(`dataset-${dataset.index}`, label, {
            dataset: dataset.index,
        });
        head.classList.add("fw-bold");
        fieldset.append(head);

        dataset.models.forEach((model) => {
            const row = checkbox(`dataset-${dataset.index}-${model}`, model, {
                dataset: dataset.index,
                model,
            });
            row.classList.add("ms-3");
            fieldset.append(row);
        });

        form.append(fieldset);
    });
}

/* The data set's checkbox mirrors its models: all, none or some of them on. */
function reflectGroup(fieldset) {
    const inputs = [...fieldset.querySelectorAll("input[data-model]")];
    const on = inputs.filter((input) => input.checked).length;
    const head = fieldset.querySelector("input:not([data-model])");

    head.checked = on === inputs.length;
    head.indeterminate = on > 0 && on < inputs.length;
}

export default class extends Controller {
    static targets = ["form"];
    static values = { url: String };

    async connect() {
        this.datasets = await loadDatasets(this.urlValue);

        this.dispatch("loaded", { detail: { datasets: this.datasets } });

        renderToggles(this.formTarget, this.datasets);
    }

    /*
     * Handles toggling. A data set's checkbox switches every model of it, a
     * model checkbox just that one. Either way the data set's checkbox is
     * updated afterward.
     */
    toggle(event) {
        const input = event.target;
        const fieldset = input.closest("fieldset");

        if (input.dataset.model === undefined) {
            fieldset.querySelectorAll("input[data-model]").forEach((child) => {
                child.checked = input.checked;
                this.report(child);
            });
        } else {
            this.report(input);
        }

        reflectGroup(fieldset);
    }

    /* Tells the map which model of which data set was switched, and which way. */
    report(input) {
        this.dispatch("toggle", {
            detail: {
                dataset: this.datasets[input.dataset.dataset],
                model: input.dataset.model,
                on: input.checked,
            },
        });
    }
}
