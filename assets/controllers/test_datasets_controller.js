import { Controller } from "@hotwired/stimulus";

/*
 * The sources the map may draw: the test sources, fetched from the list,
 * grouped by the model they publish so sources of one model can be laid
 * over each other and compared, and offered as toggles: one per source,
 * named by its data set, under a toggle for the model as a whole. Colour
 * follows the data set, so a feed split over several models keeps one
 * colour on the map. Nothing here knows about the map; what is known and
 * what is switched is dispatched as events for the map controller on the
 * same element.
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
 * What every test data set's title starts and ends with; left out of the
 * toggles, where it would only repeat.
 */
const TITLE_NOISE = /^Test:\s*|,\s*Aarhus Kommune$/g;

/*
 * Fetch the sources from the list and keep the test ones. Each is numbered,
 * so the map can draw it on its own, and takes a colour per data set, in
 * the order the data sets first appear. The sources are then grouped by
 * model, in alphabetical order.
 */
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

/*
 * Builds a Bootstrap checkbox row. The attributes end up as data-* on the
 * input, which is how the change handler tells a model from a source.
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
 * One fieldset per model, headed by a checkbox that switches every source
 * of it, with a checkbox per source below, named by its data set in the
 * data set's colour.
 */
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
            label.append(swatch, source.dataset.title.replace(TITLE_NOISE, ""));

            const row = checkbox(`source-${source.index}`, label, {
                source: source.index,
            });
            row.classList.add("ms-3");
            fieldset.append(row);
        });

        form.append(fieldset);
    });
}

/* The model's checkbox mirrors its sources: all, none or some of them on. */
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

    /*
     * Handles toggling. A model's checkbox switches every source of it, a
     * source's checkbox just that one. Either way the model's checkbox is
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
