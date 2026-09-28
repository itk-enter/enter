/*
 * The test map: the test sources' data sets, drawn by MapLibre in one colour
 * per data set. Nothing is drawn until a data set is switched on.
 */

/* Dokk1, from far enough out to take in the town. */
const CENTER = [10.2144, 56.1535];
const ZOOM = 11;

/* One colour per data set, in the order the sources come. */
const COLOURS = ["#e6194b", "#3e7bfa", "#2ca02c", "#ff7f0e", "#9467bd"];

function createMap(container) {
    return new window.maplibregl.Map({
        container,
        style: {
            version: 8,
            sources: {
                background: {
                    type: "raster",
                    tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
                    tileSize: 256,
                    maxzoom: 19,
                    attribution:
                        '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-bidragydere',
                },
            },
            layers: [
                { id: "background", type: "raster", source: "background" },
            ],
        },
        center: CENTER,
        zoom: ZOOM,
    });
}

/*
 * A data set is fetched the first time it is switched on; after that its
 * layers are only shown or hidden.
 */
function showDataset(map, dataset, visible) {
    const id = `dataset-${dataset.index}`;

    if (map.getSource(id) === undefined) {
        if (!visible) {
            return;
        }

        map.addSource(id, { type: "geojson", data: dataset.url });

        map.addLayer({
            id: `${id}-areas`,
            type: "fill",
            source: id,
            paint: { "fill-color": dataset.colour, "fill-opacity": 0.35 },
        });

        /* Left to itself a circle layer dots every corner of an area. */
        map.addLayer({
            id: `${id}-points`,
            type: "circle",
            source: id,
            filter: ["==", ["geometry-type"], "Point"],
            paint: { "circle-color": dataset.colour, "circle-radius": 4 },
        });

        return;
    }

    const visibility = visible ? "visible" : "none";
    map.setLayoutProperty(`${id}-areas`, "visibility", visibility);
    map.setLayoutProperty(`${id}-points`, "visibility", visibility);
}

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

function wireToggles(form, map, datasets) {
    form.addEventListener("change", (event) => {
        const input = event.target;
        const fieldset = input.closest("fieldset");

        if (input.dataset.model !== undefined) {
            fieldset
                .querySelectorAll("input[data-dataset]")
                .forEach((child) => {
                    child.checked = input.checked;
                    showDataset(
                        map,
                        datasets[child.dataset.dataset],
                        input.checked,
                    );
                });
        } else {
            showDataset(map, datasets[input.dataset.dataset], input.checked);
        }

        reflectGroup(fieldset);
    });
}

async function fetchDatasets(url) {
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

const form = document.querySelector(".test-map-datasets[data-datasets-url]");
const container = document.querySelector(".test-map");

if (form !== null && container !== null) {
    const map = createMap(container);
    const loaded = new Promise((resolve) => map.on("load", resolve));

    Promise.all([loaded, fetchDatasets(form.dataset.datasetsUrl)]).then(
        ([, datasets]) => {
            renderToggles(form, groupByModel(datasets));
            wireToggles(form, map, datasets);
        },
    );
}
