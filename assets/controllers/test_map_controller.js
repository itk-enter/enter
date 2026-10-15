import { Controller } from "@hotwired/stimulus";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.min.css";
import { MARKS_LAYER, wireClicks } from "../test/popup.js";

/*
 * The developer map: each source's entities read from the broker into a
 * layer of their own, drawn by MapLibre in one colour per data set. It
 * shares its element with the data sets controller and draws whatever that
 * one reports switched on. What a click opens lives in popup.js.
 */

/* Center at DOKK1, zoomed out a bunch. */
const CENTER = [10.2144, 56.1535];
const ZOOM = 11;

/* What every source layer's id starts with, so they can be told apart. */
const LAYER_PREFIX = "source-";

/* The most entities the broker hands out per request. */
const PAGE_SIZE = 1000;

/* The stroke around every dot and area, dark so it reads on any colour. */
const STROKE_COLOUR = "#212121";
const STROKE_WIDTH = 1.5;

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
 * Reads a source's entities from the broker, page by page, into one
 * GeoJSON collection.
 *
 * The source says where its entities are; this adds its model, which the
 * broker requires, asks for GeoJSON in the simplified format, which leaves
 * bare values, and follows the broker's link to each next page. The
 * source's context goes in the Link header so the broker expands the
 * model's short name and answers with the names the source declared.
 */
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
 * Adds a source to the map with an area layer, an outline layer and a point
 * layer, in its data set's colour. Its layers go in below the marks, so a
 * mark is never hidden by what it marks.
 */
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

    /* A fill's own outline is one pixel wide; the dots have a thicker one. */
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

    /* Left to itself a circle layer dots every corner of an area. */
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

        /* Per layer: the fetch under way, and whether it is wanted on. */
        this.loading = new Map();
        this.wanted = new Map();
    }

    disconnect() {
        this.map.remove();
    }

    /*
     * The sources are known: the popup can name them, by the data set they
     * belong to, as the toggles do.
     */
    sources(event) {
        event.detail.sources.forEach((source) => {
            this.titles[source.id] = source.dataset.title;
        });
    }

    /*
     * A source was switched on or off. The first time it is switched on its
     * entities are fetched; a toggle while that is under way takes effect
     * once the fetch is done.
     */
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
            /* Leave the layer loadable again on the next toggle. */
            this.loading.delete(id);
            throw error;
        }
    }

    /* The ids of every source layer on the map, hidden ones included. */
    layers() {
        return this.map
            .getStyle()
            .layers.map((layer) => layer.id)
            .filter((id) => id.startsWith(LAYER_PREFIX));
    }
}
