import maplibregl from "maplibre-gl";

/*
 * Marks the features at a map click and shows their attributes in a popup.
 */

/* The colours that mark the selected features. */
const MARKS = ["#ffea00", "#00e5ff", "#76ff03", "#ff4081"];
const MARK_WIDTH = 3;
const MARK_RADIUS = 9;

/* The layer that the map puts all data set layers below. */
export const MARKS_LAYER = "marked-areas";
const MARKED_POINTS = "marked-points";
const EMPTY = { type: "FeatureCollection", features: [] };

/* The click tolerance in pixels. */
const HIT_TOLERANCE = 6;

/* The maximum number of features for each click. */
const FEATURES_PER_CLICK = 10;

/* The margin and pan duration that move a popup into view. */
const POPUP_MARGIN = 12;
const POPUP_PAN = 300;

/* The attributes that the popup does not show. */
const HIDDEN = ["sourceId", "source", "type", "location"];

/* Adds the layers that mark the selected features. */
function addMarks(map) {
    map.addSource("marks", { type: "geojson", data: EMPTY });

    map.addLayer({
        id: MARKS_LAYER,
        source: "marks",
        type: "line",
        filter: ["==", ["geometry-type"], "Polygon"],
        paint: { "line-color": ["get", "_mark"], "line-width": MARK_WIDTH },
    });

    map.addLayer({
        id: MARKED_POINTS,
        source: "marks",
        type: "circle",
        filter: ["==", ["geometry-type"], "Point"],
        paint: {
            "circle-color": "rgba(0, 0, 0, 0)",
            "circle-radius": MARK_RADIUS,
            "circle-stroke-color": ["get", "_mark"],
            "circle-stroke-width": MARK_WIDTH,
        },
    });
}

/* Gives the square around a click point. */
function box(point) {
    return [
        [point.x - HIT_TOLERANCE, point.y - HIT_TOLERANCE],
        [point.x + HIT_TOLERANCE, point.y + HIT_TOLERANCE],
    ];
}

/* Gives all positions of a geometry. */
function positionsOf(coordinates) {
    if (!Array.isArray(coordinates)) {
        return [];
    }

    if (typeof coordinates[0] === "number") {
        return [coordinates];
    }

    return coordinates.flatMap(positionsOf);
}

/* Gives the northernmost position of the features. */
function northernmost(features) {
    return features
        .flatMap((feature) => positionsOf(feature.geometry?.coordinates))
        .reduce(
            (top, position) =>
                top === null || position[1] > top[1] ? position : top,
            null,
        );
}

/* Gives the easeInOutCubic value from https://easings.net/#easeInOutCubic. */
function easing(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;");
}

/* Changes a JSON list to a comma-separated text. */
function rejoinList(value) {
    if (typeof value === "string" && /^[[{]/.test(value)) {
        try {
            const parsed = JSON.parse(value);

            return Array.isArray(parsed) ? parsed.join(", ") : value;
        } catch {
            return value;
        }
    }

    return value;
}

/* Makes the popup HTML with one section for each feature. */
function popupHtml(sections) {
    return sections
        .map(({ title, colour, properties }) => {
            const rows = Object.keys(properties)
                .filter(
                    (key) =>
                        key.charAt(0) !== "_" &&
                        !HIDDEN.includes(key) &&
                        properties[key] !== null &&
                        properties[key] !== "" &&
                        properties[key] !== undefined,
                )
                .map(
                    (key) =>
                        `<dt>${escapeHtml(key)}</dt><dd>${escapeHtml(rejoinList(properties[key]))}</dd>`,
                )
                .join("");

            return [
                '<div class="test-map-section">',
                '<div class="test-map-title">',
                `<span class="test-map-swatch" style="background:${escapeHtml(colour)}"></span>`,
                escapeHtml(title),
                `<span class="test-map-model">${escapeHtml(properties.type ?? "")}</span>`,
                "</div>",
                `<dl class="test-map-details">${rows}</dl>`,
                "</div>",
            ].join("");
        })
        .join("");
}

/* Removes duplicate features and puts the points first. */
function pick(hits) {
    const seen = new Set();
    const found = [];

    hits.forEach((hit) => {
        const id = hit.properties?.id ?? JSON.stringify(hit.geometry);

        if (!seen.has(id)) {
            seen.add(id);
            found.push({
                type: "Feature",
                geometry: hit.geometry,
                properties: hit.properties,
            });
        }
    });

    found.sort(
        (a, b) =>
            Number(b.geometry.type === "Point") -
            Number(a.geometry.type === "Point"),
    );

    return found.slice(0, FEATURES_PER_CLICK);
}

/* Shows a popup and marks the features when the user clicks the map. */
export function wireClicks(map, { titles, layers }) {
    addMarks(map);

    const popup = new maplibregl.Popup({
        className: "test-map-popup",
        anchor: "bottom",
        maxWidth: "620px",
        closeButton: true,
        closeOnClick: false,
    });

    /* Moves the map so that the popup is fully visible. */
    function panPopupIntoView() {
        const node = popup.getElement();

        if (!node) {
            return;
        }

        const canvas = map.getCanvas().getBoundingClientRect();
        const bounds = node.getBoundingClientRect();

        const over = (low, high, edge, extent) => {
            if (low < edge + POPUP_MARGIN) {
                return low - edge - POPUP_MARGIN;
            }

            return high > edge + extent - POPUP_MARGIN
                ? high - edge - extent + POPUP_MARGIN
                : 0;
        };

        const x = over(bounds.left, bounds.right, canvas.left, canvas.width);
        const y = over(bounds.top, bounds.bottom, canvas.top, canvas.height);

        if (x !== 0 || y !== 0) {
            map.panBy([x, y], { duration: POPUP_PAN, easing });
        }
    }

    function select(features, at) {
        const marked = features.map((feature, position) => ({
            ...feature,
            properties: {
                ...feature.properties,
                _mark: MARKS[position % MARKS.length],
            },
        }));

        map.getSource("marks").setData({
            type: "FeatureCollection",
            features: marked,
        });

        if (features.length === 0) {
            popup.remove();

            return;
        }

        const top = northernmost(features) ?? [at.lng, at.lat];

        popup
            .setLngLat(top)
            .setHTML(
                popupHtml(
                    marked.map((feature) => ({
                        title:
                            titles[feature.properties.sourceId] ??
                            feature.properties.sourceId,
                        colour: feature.properties._mark,
                        properties: feature.properties,
                    })),
                ),
            )
            .addTo(map);

        window.requestAnimationFrame(panPopupIntoView);
    }

    map.on("click", (event) => {
        select(
            pick(
                map.queryRenderedFeatures(box(event.point), {
                    layers: layers(),
                }),
            ),
            event.lngLat,
        );
    });
}
