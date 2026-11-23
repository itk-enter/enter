import { Controller } from "@hotwired/stimulus";
import maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.min.css";

/*
 * Shows the merged entities with conflicts on a MapLibre map. A click on an
 * entity shows its attributes in conflict, with a link to its values in the
 * list below the map.
 */

/* The start view of the map, if no entity has a geometry. */
const CENTER = [10.2144, 56.1535];
const ZOOM = 11;

/* The colours of an entity with conflicts. */
const FILL_COLOUR = "#f5b800";
const STROKE_COLOUR = "#212121";

const SOURCE = "conflicts";
const LAYERS = ["conflict-areas", "conflict-outlines", "conflict-points"];

/* Makes the MapLibre map, with OpenStreetMap as the background. */
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

/* Gives each position of a GeoJSON geometry. */
function positions(geometry) {
    const flatten = (coordinates) =>
        typeof coordinates[0] === "number"
            ? [coordinates]
            : coordinates.flatMap(flatten);

    return flatten(geometry.coordinates);
}

/* Gives the bounds around all the features, or null if there are none. */
function bounds(features) {
    const all = features.flatMap((feature) => positions(feature.geometry));
    if (all.length === 0) {
        return null;
    }

    return all.reduce(
        (box, position) => box.extend(position),
        new maplibregl.LngLatBounds(all[0], all[0]),
    );
}

/* Makes the content of the popup of one entity. */
function popupContent(properties) {
    const content = document.createElement("div");

    const title = document.createElement("strong");
    title.textContent = properties.title;
    content.append(title);

    const attributes = document.createElement("div");
    attributes.className = "small";
    attributes.textContent = JSON.parse(properties.attributes).join(", ");
    content.append(attributes);

    const link = document.createElement("a");
    link.href = `#conflict-${properties.number}`;
    link.className = "small";
    link.textContent = "Show the values";
    link.addEventListener("click", () => {
        document
            .querySelector(`#conflict-${properties.number} details`)
            ?.setAttribute("open", "");
    });
    content.append(link);

    return content;
}

export default class extends Controller {
    static values = { features: Object };

    connect() {
        this.map = createMap(this.element);
        this.map.addControl(new maplibregl.NavigationControl(), "top-right");

        this.map.on("load", () => {
            const features = this.featuresValue.features ?? [];
            this.map.addSource(SOURCE, {
                type: "geojson",
                data: this.featuresValue,
            });
            this.addLayers();

            const box = bounds(features);
            if (box !== null) {
                this.map.fitBounds(box, {
                    padding: 40,
                    maxZoom: 16,
                    duration: 0,
                });
            }
        });

        LAYERS.forEach((layer) => {
            this.map.on("click", layer, (event) => {
                new maplibregl.Popup({ closeButton: true })
                    .setLngLat(event.lngLat)
                    .setDOMContent(popupContent(event.features[0].properties))
                    .addTo(this.map);
            });
            this.map.on("mouseenter", layer, () => {
                this.map.getCanvas().style.cursor = "pointer";
            });
            this.map.on("mouseleave", layer, () => {
                this.map.getCanvas().style.cursor = "";
            });
        });
    }

    disconnect() {
        this.map?.remove();
    }

    addLayers() {
        this.map.addLayer({
            id: "conflict-areas",
            type: "fill",
            source: SOURCE,
            filter: [
                "in",
                ["geometry-type"],
                ["literal", ["Polygon", "MultiPolygon"]],
            ],
            paint: { "fill-color": FILL_COLOUR, "fill-opacity": 0.35 },
        });
        this.map.addLayer({
            id: "conflict-outlines",
            type: "line",
            source: SOURCE,
            filter: [
                "in",
                ["geometry-type"],
                [
                    "literal",
                    [
                        "Polygon",
                        "MultiPolygon",
                        "LineString",
                        "MultiLineString",
                    ],
                ],
            ],
            paint: { "line-color": STROKE_COLOUR, "line-width": 1.5 },
        });
        this.map.addLayer({
            id: "conflict-points",
            type: "circle",
            source: SOURCE,
            filter: [
                "in",
                ["geometry-type"],
                ["literal", ["Point", "MultiPoint"]],
            ],
            paint: {
                "circle-radius": 7,
                "circle-color": FILL_COLOUR,
                "circle-stroke-color": STROKE_COLOUR,
                "circle-stroke-width": 1.5,
            },
        });
    }
}
