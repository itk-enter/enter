import { Controller } from "@hotwired/stimulus";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.min.css";

/*
 * The developer map: each source's entities read from the broker and drawn
 * by MapLibre in one colour per data set. It shares its element with the
 * data sets controller and draws whatever that one reports switched on.
 */

/* Center at DOKK1, zoomed out a bunch. */
const CENTER = [10.2144, 56.1535];
const ZOOM = 11;

/* What every data set's layer id starts with, so they can be told apart. */
const LAYER_PREFIX = "dataset-";

/* The most entities the broker hands out per request. */
const PAGE_SIZE = 1000;

/*
 * Init MapLibre
 */
function createMap(container) {
    return new maplibregl.Map({
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
 * Reads a data set's entities from the broker, page by page, into one
 * GeoJSON collection.
 *
 * The broker is asked for the data set's model, filtered on the source id
 * every entity is stamped with. The source's context goes in the Link
 * header so the broker reads the model's name and answers with the names
 * the source declared; key values make the answer plain GeoJSON.
 */
async function loadFeatures(url, dataset) {
    const features = [];

    for (let offset = 0; ; offset += PAGE_SIZE) {
        const query = new URLSearchParams({
            type: dataset.model,
            q: `sourceId=="${dataset.id}"`,
            options: "keyValues",
            limit: PAGE_SIZE,
            offset,
            count: "true",
        });
        const response = await fetch(`${url}?${query}`, {
            headers: {
                link: `<${dataset.context_url}>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"`,
            },
        });

        if (!response.ok) {
            throw new Error(`${url} answered ${response.status}`);
        }

        const page = (await response.json()).features ?? [];
        features.push(...page);

        const total = Number(response.headers.get("ngsild-results-count"));
        if (page.length === 0 || features.length >= total) {
            break;
        }
    }

    return { type: "FeatureCollection", features };
}

/* Adds a data set to the map as a source with an area layer and a point layer. */
function addDataset(map, id, dataset, collection) {
    map.addSource(id, { type: "geojson", data: collection });

    map.addLayer({
        id: `${id}-areas`,
        type: "fill",
        source: id,
        paint: {
            "fill-color": dataset.colour,
            "fill-opacity": 0.35,
            "fill-outline-color": dataset.colour,
        },
    });

    /* Left to itself a circle layer dots every corner of an area. */
    map.addLayer({
        id: `${id}-points`,
        type: "circle",
        source: id,
        filter: ["==", ["geometry-type"], "Point"],
        paint: {
            "circle-color": dataset.colour,
            "circle-radius": 5,
            "circle-stroke-color": "#212121",
            "circle-stroke-width": 1.5,
        },
    });
}

function setVisible(map, id, visible) {
    const visibility = visible ? "visible" : "none";
    map.setLayoutProperty(`${id}-areas`, "visibility", visibility);
    map.setLayoutProperty(`${id}-points`, "visibility", visibility);
}

export default class extends Controller {
    static targets = ["canvas"];
    static values = { entitiesUrl: String };

    connect() {
        this.map = createMap(this.canvasTarget);

        /* Nothing may be added to the map before its style has loaded. */
        this.ready = new Promise((resolve) => this.map.on("load", resolve));

        /* Per data set: the fetch under way, and whether it is wanted on. */
        this.loading = new Map();
        this.wanted = new Map();
    }

    disconnect() {
        this.map.remove();
    }

    /*
     * A data set was switched on or off. The first time one is switched on
     * its entities are fetched; a toggle while that is under way takes
     * effect once the fetch is done.
     */
    async show(event) {
        await this.ready;

        const { dataset, on } = event.detail;
        const id = `${LAYER_PREFIX}${dataset.index}`;
        this.wanted.set(id, on);

        if (this.map.getSource(id) === undefined) {
            if (!on) {
                return;
            }
            if (!this.loading.has(id)) {
                this.loading.set(id, this.load(id, dataset));
            }
            await this.loading.get(id);
        }

        setVisible(this.map, id, this.wanted.get(id));
    }

    async load(id, dataset) {
        try {
            const collection = await loadFeatures(
                this.entitiesUrlValue,
                dataset,
            );
            addDataset(this.map, id, dataset, collection);
        } catch (error) {
            /* Leave the data set loadable again on the next toggle. */
            this.loading.delete(id);
            throw error;
        }
    }
}
