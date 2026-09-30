import { Controller } from "@hotwired/stimulus";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.min.css";
import { MARKS_LAYER, wireClicks } from "../test/popup.js";

/*
 * The developer map: each source's entities read from the broker and drawn
 * by MapLibre in one colour per data set. It shares its element with the
 * data sets controller and draws whatever that one reports switched on.
 * What a click opens lives in popup.js.
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
 * The URL of the page after this one, or null on the last page. The broker
 * links to the next page under its own path, which the proxy does not
 * serve, so only the link's query is taken and put on the page's URL.
 */
function nextPage(response, url) {
    const match = /<([^>]*)>[^,]*rel="next"/.exec(
        response.headers.get("link") ?? "",
    );

    if (match === null) {
        return null;
    }

    const next = new URL(url);
    next.search = new URL(match[1], url).search;

    return next;
}

/*
 * Reads a data set's entities from the broker, page by page, into one
 * GeoJSON collection.
 *
 * The data set says where its entities are; this only asks for them as
 * GeoJSON in the simplified format, which leaves bare values, and follows
 * the broker's link to each next page. The source's context goes in the
 * Link header so the broker answers with the names the source declared.
 */
async function loadFeatures(dataset) {
    const features = [];

    let url = new URL(dataset.entities_url, window.location.href);
    url.searchParams.set("format", "simplified");
    url.searchParams.set("limit", PAGE_SIZE);

    while (url !== null) {
        const response = await fetch(url, {
            headers: {
                accept: "application/geo+json",
                link: `<${dataset.context_url}>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"`,
            },
        });

        if (!response.ok) {
            throw new Error(`${url} answered ${response.status}`);
        }

        /*
         * MapLibre hands a clicked feature's properties back, not its
         * string id, and the popup tells features apart by the id.
         */
        const page = ((await response.json()).features ?? []).map(
            (feature) => ({
                ...feature,
                properties: { ...feature.properties, id: feature.id },
            }),
        );
        features.push(...page);

        url = nextPage(response, url);
    }

    return { type: "FeatureCollection", features };
}

/*
 * Adds a data set to the map as a source with an area layer and a point
 * layer. Its layers go in below the marks, so a mark is never hidden by
 * what it marks.
 */
function addDataset(map, id, dataset, collection) {
    map.addSource(id, { type: "geojson", data: collection });

    map.addLayer(
        {
            id: `${id}-areas`,
            type: "fill",
            source: id,
            paint: {
                "fill-color": dataset.colour,
                "fill-opacity": 0.35,
                "fill-outline-color": dataset.colour,
            },
        },
        MARKS_LAYER,
    );

    /* Left to itself a circle layer dots every corner of an area. */
    map.addLayer(
        {
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
        },
        MARKS_LAYER,
    );

    [`${id}-areas`, `${id}-points`].forEach((layer) => {
        map.on("mouseenter", layer, () => {
            map.getCanvas().style.cursor = "pointer";
        });
        map.on("mouseleave", layer, () => {
            map.getCanvas().style.cursor = "";
        });
    });
}

function setVisible(map, id, visible) {
    const visibility = visible ? "visible" : "none";
    map.setLayoutProperty(`${id}-areas`, "visibility", visibility);
    map.setLayoutProperty(`${id}-points`, "visibility", visibility);
}

export default class extends Controller {
    static targets = ["canvas"];

    connect() {
        this.map = createMap(this.canvasTarget);

        /* What the popup calls each data set, filled in once they are known. */
        this.titles = {};

        /* Nothing may be added to the map before its style has loaded. */
        this.ready = new Promise((resolve) =>
            this.map.on("load", resolve),
        ).then(() => {
            wireClicks(this.map, {
                titles: this.titles,
                layers: () => this.layers(),
            });
        });

        /* Per data set: the fetch under way, and whether it is wanted on. */
        this.loading = new Map();
        this.wanted = new Map();
    }

    disconnect() {
        this.map.remove();
    }

    /* The data sets are known: the popup can name them. */
    datasets(event) {
        event.detail.datasets.forEach((dataset) => {
            this.titles[dataset.id] = dataset.title;
        });
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
            const collection = await loadFeatures(dataset);
            addDataset(this.map, id, dataset, collection);
        } catch (error) {
            /* Leave the data set loadable again on the next toggle. */
            this.loading.delete(id);
            throw error;
        }
    }

    /* The ids of every data set layer on the map, hidden ones included. */
    layers() {
        return this.map
            .getStyle()
            .layers.map((layer) => layer.id)
            .filter((id) => id.startsWith(LAYER_PREFIX));
    }
}
