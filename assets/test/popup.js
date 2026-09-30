import maplibregl from "maplibre-gl";

/*
 * What a click on the map opens: the features under it are outlined and a
 * popup lists their attributes. The marks are drawn on layers of their own,
 * which the map keeps every data set beneath.
 */

/*
 * The colours a clicked feature is outlined in, one per feature under the
 * click: bright and unlike the data set colours, so the marking is not taken
 * for the thing it marks.
 */
const MARKS = ["#ffea00", "#00e5ff", "#76ff03", "#ff4081"];
const MARK_WIDTH = 3;
const MARK_RADIUS = 9;

/* The layer the map inserts every data set below, so marks stay on top. */
export const MARKS_LAYER = "marked-areas";
const MARKED_POINTS = "marked-points";
const EMPTY = { type: "FeatureCollection", features: [] };

/* How far off a marker a click still counts as being on it, in pixels. */
const HIT_TOLERANCE = 6;

/* How many features one click may open a popup for. */
const FEATURES_PER_CLICK = 10;

/* How much map is left around a popup that had to be brought into view. */
const POPUP_MARGIN = 12;
const POPUP_PAN = 300;

/*
 * The attributes the popup leaves out: the source is what the heading says,
 * the type is what the toggle group says, and the location is the geometry.
 */
const HIDDEN = ["sourceId", "source", "type", "location"];

/*
 * The layers a clicked feature is outlined on. They are added before any
 * data set, and every data set is inserted below them, so a mark is never
 * hidden by what it marks.
 */
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

/* The square around a click that counts as the click. */
function box(point) {
    return [
        [point.x - HIT_TOLERANCE, point.y - HIT_TOLERANCE],
        [point.x + HIT_TOLERANCE, point.y + HIT_TOLERANCE],
    ];
}

/* Every position of a geometry, whatever its nesting. */
function positionsOf(coordinates) {
    if (!Array.isArray(coordinates)) {
        return [];
    }

    if (typeof coordinates[0] === "number") {
        return [coordinates];
    }

    return coordinates.flatMap(positionsOf);
}

/*
 * The top of what was clicked. A popup hangs its bottom edge from wherever
 * it is put, so anchoring it here leaves the thing it describes below it
 * rather than underneath it.
 */
function northernmost(features) {
    return features
        .flatMap((feature) => positionsOf(feature.geometry?.coordinates))
        .reduce(
            (top, position) =>
                top === null || position[1] > top[1] ? position : top,
            null,
        );
}

/*
* easeInOutCubic from https://easings.net/#easeInOutCubic
*/
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

/*
 * A value as the popup shows it. MapLibre hands lists and objects back as
 * JSON text, which reads better as a list again.
 */
function formatValue(value) {
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

/*
 * The popup for whatever a click landed on: a section per feature, headed by
 * the data set it came from in the colour it was marked with. Which
 * attributes an entity carries is the source's business, so this walks
 * whatever arrived rather than naming fields.
 */
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
                        `<dt>${escapeHtml(key)}</dt><dd>${escapeHtml(formatValue(properties[key]))}</dd>`,
                )
                .join("");

            return [
                '<div class="test-map-section">',
                '<div class="test-map-title">',
                `<span class="test-map-swatch" style="background:${escapeHtml(colour)}"></span>`,
                escapeHtml(title),
                "</div>",
                `<dl class="test-map-details">${rows}</dl>`,
                "</div>",
            ].join("");
        })
        .join("");
}

/*
 * What a click landed on, once over. A shape drawn by both a fill and an
 * outline is hit twice, and the popup has no business saying the same thing
 * twice. Points come first, as they are what sits on top.
 */
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

/*
 * Opens a popup for whatever a click lands on, and outlines it on the map.
 * Clicking nothing closes the popup and clears the outlines.
 *
 * titles maps a source id to what the popup calls it, and layers() names
 * the layers a click may land on.
 */
export function wireClicks(map, { titles, layers }) {
    addMarks(map);

    /*
     * Closing on a click is this popup's own doing, not MapLibre's. Left to
     * MapLibre, opening it registers a listener that closes it on the next
     * click of the map, which is how the next popup is asked for.
     */
    const popup = new maplibregl.Popup({
        className: "test-map-popup",
        anchor: "bottom",
        maxWidth: "620px",
        closeButton: true,
        closeOnClick: false,
    });

    /*
     * The popup hangs above the northernmost corner of what was clicked,
     * which for a shape near the top of the screen puts it off the map.
     * MapLibre leaves it where it falls, so the map is moved instead.
     */
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

        /*
         * A click hands back the geometry as the tile holds it, which for a
         * shape crossing a tile edge can be the part that is not here.
         * Falling back on where the click landed beats dropping the popup.
         */
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
