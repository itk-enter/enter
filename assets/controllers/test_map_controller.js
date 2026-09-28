import { Controller } from "@hotwired/stimulus";

/*
 * The test map: the test sources' data sets, drawn by MapLibre in one colour
 * per data set. It shares its element with the data sets controller and
 * draws whatever that one reports switched on.
 */

/* Center at DOKK1, zoomed out a bunch. */
const CENTER = [10.2144, 56.1535];
const ZOOM = 11;

/* What every data set's layer id starts with, so they can be told apart. */
const LAYER_PREFIX = "dataset-";

/*
 * Init MapLibre
 */
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
 * Fired when a dataset is toggled via the UI. After a dataset is loaded
 * it will be hidden or shown upon toggling.
 */
function showDataset(map, dataset, visible) {
    const id = `${LAYER_PREFIX}${dataset.index}`;

    if (map.getSource(id) === undefined) {
        if (!visible) {
            return;
        }

        map.addSource(id, { type: "geojson", data: dataset.url });

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

        return;
    }

    const visibility = visible ? "visible" : "none";
    map.setLayoutProperty(`${id}-areas`, "visibility", visibility);
    map.setLayoutProperty(`${id}-points`, "visibility", visibility);
}

export default class extends Controller {
    static targets = ["canvas"];

    connect() {
        this.map = createMap(this.canvasTarget);

        /* Nothing may be added to the map before its style has loaded. */
        this.ready = new Promise((resolve) => this.map.on("load", resolve));
    }

    disconnect() {
        this.map.remove();
    }

    /* A data set was switched on or off. */
    async show(event) {
        await this.ready;

        showDataset(this.map, event.detail.dataset, event.detail.on);
    }
}
