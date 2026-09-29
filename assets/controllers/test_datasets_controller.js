import { Controller } from "@hotwired/stimulus";

/*
 * The data sets the map may draw: the sources, fetched from the list, given
 * a colour each, and offered as toggles grouped by model. Nothing here knows
 * about the map; what is known and what is switched is dispatched as events
 * for the map controller on the same element.
 */

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
 * Fetch the sources from the list. Each is a data set the map may draw.
 */
async function loadDatasets(url) {
    const response = await fetch(url, {
        headers: { accept: "application/json" },
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    const datasets = await response.json();

    return datasets.map((dataset, index) => ({
        ...dataset,
        index,
        colour: COLOURS[index % COLOURS.length],
    }));
}

/*
 * Groups the datasets by model, keeping the order they came in.
 */
function groupByModel(datasets) {
    const groups = new Map();

    datasets.forEach((dataset) => {
        if (!groups.has(dataset.model)) {
            groups.set(dataset.model, []);
        }
        groups.get(dataset.model).push(dataset);
    });

    return groups;
}

/*
 * Builds a Bootstrap checkbox row. The attributes end up as data-* on the
 * input, which is how the change handler tells a model from a dataset.
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

/* One fieldset per model, headed by a checkbox that switches the whole model. */
function renderToggles(form, groups) {
    groups.forEach((datasets, model) => {
        const fieldset = document.createElement("fieldset");
        fieldset.className = "test-map-group mb-3";

        const head = checkbox(`model-${model}`, model, { model });
        head.classList.add("fw-bold");
        fieldset.append(head);

        datasets.forEach((dataset) => {
            const swatch = document.createElement("span");
            swatch.className = "test-map-swatch";
            swatch.style.backgroundColor = dataset.colour;

            const label = document.createDocumentFragment();
            label.append(swatch, dataset.title);

            fieldset.append(
                checkbox(`dataset-${dataset.index}`, label, {
                    dataset: dataset.index,
                }),
            );
        });

        form.append(fieldset);
    });
}

/* The model's checkbox mirrors its data sets: all, none or some of them on. */
function reflectGroup(fieldset) {
    const inputs = [...fieldset.querySelectorAll("input[data-dataset]")];
    const on = inputs.filter((input) => input.checked).length;
    const head = fieldset.querySelector("input[data-model]");

    head.checked = on === inputs.length;
    head.indeterminate = on > 0 && on < inputs.length;
}

export default class extends Controller {
    static targets = ["form"];
    static values = { url: String };

    async connect() {
        this.datasets = await loadDatasets(this.urlValue);

        this.dispatch("loaded", { detail: { datasets: this.datasets } });

        renderToggles(this.formTarget, groupByModel(this.datasets));
    }

    /*
     * Handles toggling. A model checkbox switches every dataset in its
     * group, a dataset checkbox just that one. Either way the group's
     * checkbox is updated afterward.
     */
    toggle(event) {
        const input = event.target;
        const fieldset = input.closest("fieldset");

        if (input.dataset.model !== undefined) {
            fieldset
                .querySelectorAll("input[data-dataset]")
                .forEach((child) => {
                    child.checked = input.checked;
                    this.report(child);
                });
        } else {
            this.report(input);
        }

        reflectGroup(fieldset);
    }

    /* Tells the map which data set was switched, and which way. */
    report(input) {
        this.dispatch("toggle", {
            detail: {
                dataset: this.datasets[input.dataset.dataset],
                on: input.checked,
            },
        });
    }
}
