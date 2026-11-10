import { Controller } from "@hotwired/stimulus";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.min.css";
import { MARKS_LAYER, wireClicks } from "../test/popup.js";

/*
 * Shows the entities of the test sources on a MapLibre map.
 */

/* The start view of the map. */
const CENTER = [10.2144, 56.1535];
const ZOOM = 11;

/* The prefix of a source layer id. */
const LAYER_PREFIX = "source-";

/* The maximum number of entities for each broker request. */
const PAGE_SIZE = 1000;

/* The stroke around each point and area. */
const STROKE_COLOUR = "#212121";
const STROKE_WIDTH = 1.5;

/* Makes the MapLibre map. */
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

/* Gives the URL of the next page, or null on the last page. */
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

/* Reads the entities of a source from the broker into a GeoJSON collection. */
async function loadFeatures(source) {
    const features = [];

    let url = new URL(source.entities_url, window.location.href);
    url.searchParams.set("type", source.model);
    url.searchParams.set("format", "simplified");
    url.searchParams.set("limit", PAGE_SIZE);

    while (url !== null) {
        const response = await fetch(url, {
            headers: {
                accept: "application/geo+json",
                link: `<${source.context_url}>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"`,
            },
        });

        if (!response.ok) {
            throw new Error(`${url} answered ${response.status}`);
        }

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

/* Adds the area, outline and point layers of a source to the map. */
function addSource(map, id, source, collection) {
    map.addSource(id, { type: "geojson", data: collection });

    map.addLayer(
        {
            id: `${id}-areas`,
            type: "fill",
            source: id,
            paint: {
                "fill-color": source.colour,
                "fill-opacity": 0.35,
            },
        },
        MARKS_LAYER,
    );

    map.addLayer(
        {
            id: `${id}-outlines`,
            type: "line",
            source: id,
            filter: ["==", ["geometry-type"], "Polygon"],
            paint: {
                "line-color": STROKE_COLOUR,
                "line-width": STROKE_WIDTH,
            },
        },
        MARKS_LAYER,
    );

    map.addLayer(
        {
            id: `${id}-points`,
            type: "circle",
            source: id,
            filter: ["==", ["geometry-type"], "Point"],
            paint: {
                "circle-color": source.colour,
                "circle-radius": 5,
                "circle-stroke-color": STROKE_COLOUR,
                "circle-stroke-width": STROKE_WIDTH,
            },
        },
        MARKS_LAYER,
    );

    [`${id}-areas`, `${id}-outlines`, `${id}-points`].forEach((layer) => {
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
    map.setLayoutProperty(`${id}-outlines`, "visibility", visibility);
    map.setLayoutProperty(`${id}-points`, "visibility", visibility);
}

export default class extends Controller {
    static targets = ["canvas"];

    connect() {
        this.map = createMap(this.canvasTarget);

        this.titles = {};

        this.ready = new Promise((resolve) =>
            this.map.on("load", resolve),
        ).then(() => {
            wireClicks(this.map, {
                titles: this.titles,
                layers: () => this.layers(),
            });
        });

        this.loading = new Map();
        this.wanted = new Map();
    }

    disconnect() {
        this.map.remove();
    }

    /* Keeps the data set title of each source for the popup. */
    sources(event) {
        event.detail.sources.forEach((source) => {
            this.titles[source.id] = source.dataset.title;
        });
    }

    /* Shows or hides a source, and loads it the first time. */
    async show(event) {
        await this.ready;

        const { source, on } = event.detail;
        const id = `${LAYER_PREFIX}${source.index}`;
        this.wanted.set(id, on);

        if (this.map.getSource(id) === undefined) {
            if (!on) {
                return;
            }
            if (!this.loading.has(id)) {
                this.loading.set(id, this.load(id, source));
            }
            await this.loading.get(id);
        }

        setVisible(this.map, id, this.wanted.get(id));
    }

    async load(id, source) {
        try {
            const collection = await loadFeatures(source);
            addSource(this.map, id, source, collection);
        } catch (error) {
            this.loading.delete(id);
            throw error;
        }
    }

    /* Gives the ids of all source layers on the map. */
    layers() {
        return this.map
            .getStyle()
            .layers.map((layer) => layer.id)
            .filter((id) => id.startsWith(LAYER_PREFIX));
    }
}
