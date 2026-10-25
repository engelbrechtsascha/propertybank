// FINATUM PropertyBank — the schematic map.
//
// North up, the flat in the middle, the city's first two landmarks and the
// two nearest metro stations around it, each joined to the flat by a line
// with its distance in metres. A local equirectangular projection — x east,
// y north, in metres — is exact enough over a few kilometres.
//
// Pure SVG, drawn here; no map tiles, nothing loaded from another site.

const NS = "http://www.w3.org/2000/svg";
const M_PER_DEG_LAT = 110574;

function node(tag, attrs, text) {
  const n = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs || {})) if (v !== null && v !== undefined) n.setAttribute(k, String(v));
  if (text !== undefined) n.textContent = text;
  return n;
}

function nice(m) {
  for (const s of [50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000]) if (s >= m) return s;
  return 20000;
}

const fmt = (m) => new Intl.NumberFormat("en-GB").format(Math.round(m)) + " m";

/**
 * @param {object} l   a listing (lat, lon, geo, q)
 * @param {object} city  its city (landmarks)
 * @param {{w:number,h:number,labels:boolean}} opt
 */
export function schematicMap(l, city, { w = 560, h = 400, labels = true } = {}) {
  const svg = node("svg", { viewBox: `0 0 ${w} ${h}`, width: w, height: h, class: "smap" + (labels ? "" : " thumb"), role: "img" });
  if (l.lat === null || !city || !l.geo) {
    svg.append(node("text", { x: w / 2, y: h / 2, "text-anchor": "middle", class: "smap-none" }, labels ? "No location for this listing" : "—"));
    return svg;
  }
  const lat0 = l.lat, lon0 = l.lon;
  const kx = 111320 * Math.cos((lat0 * Math.PI) / 180);   // metres per degree of longitude here
  const proj = (lat, lon) => [(lon - lon0) * kx, (lat - lat0) * M_PER_DEG_LAT];

  const pts = [];
  for (const lm of (city.landmarks || []).slice(0, 2)) {
    pts.push({ kind: "lm", name: lm.name, m: l.geo.lm ? l.geo.lm[lm.key] : null, xy: proj(lm.lat, lm.lon) });
  }
  for (const st of (l.geo.st || []).slice(0, 2)) {
    pts.push({ kind: "st", name: st.n, line: st.l, colour: st.c || "#64748B", m: st.m, xy: proj(st.lat, st.lon) });
  }

  // Fit the flat and the four points into the frame, north up, equal scale on both axes.
  const padX = labels ? 30 : 8, padTop = labels ? 54 : 8, padBottom = labels ? 40 : 8;
  const xs = [0, ...pts.map((p) => p.xy[0])], ys = [0, ...pts.map((p) => p.xy[1])];
  let minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
  const span = Math.max(300, maxX - minX, (maxY - minY) * (w / h));
  const midX = (minX + maxX) / 2, midY = (minY + maxY) / 2;
  minX = midX - span / 2; maxX = midX + span / 2;
  const spanY = span * (h / w);
  minY = midY - spanY / 2; maxY = midY + spanY / 2;
  // Room for names to the right of the points: shrink a little more when labelled.
  const scale = Math.min((w - 2 * padX - (labels ? 150 : 0)) / (maxX - minX), (h - padTop - padBottom) / (maxY - minY));
  const ox = padX + (labels ? 40 : 0) + ((w - 2 * padX - (labels ? 150 : 0)) - (maxX - minX) * scale) / 2;
  const oy = padTop + ((h - padTop - padBottom) - (maxY - minY) * scale) / 2;
  const toPx = ([x, y]) => [ox + (x - minX) * scale, oy + (maxY - y) * scale];
  const [cx, cy] = toPx([0, 0]);

  svg.append(node("rect", { x: 0, y: 0, width: w, height: h, rx: labels ? 10 : 6, class: "smap-bg" }));

  const ext = span / 2;
  // Rings around the flat every "nice" distance, for a sense of scale.
  if (labels) {
    const step = nice(span / 5);
    for (let r = step; r * scale < Math.max(w, h) * 1.2; r += step) {
      svg.append(node("circle", { cx, cy, r: r * scale, class: "smap-ring" }));
    }
  }

  // Labels are placed where they collide with nothing placed before them.
  const boxes = [];
  const hit = (b) => b.x0 < 4 || b.x1 > w - 4 || b.y0 < 4 || b.y1 > h - 4 || boxes.some((o) => b.x0 < o.x1 && b.x1 > o.x0 && b.y0 < o.y1 && b.y1 > o.y0);
  const textW = (t, size) => t.length * size * 0.56;
  function place(text, size, candidates, cls) {
    let pick = null;
    for (const [x, y, anchor] of candidates) {
      const tw = textW(text, size);
      const x0 = anchor === "start" ? x : anchor === "end" ? x - tw : x - tw / 2;
      const b = { x0: x0 - 2, x1: x0 + tw + 2, y0: y - size, y1: y + 3 };
      if (!hit(b)) { pick = [x, y, anchor, b]; break; }
      if (!pick) pick = [x, y, anchor, b];
    }
    boxes.push(pick[3]);
    svg.append(node("text", { x: pick[0], y: pick[1], "text-anchor": pick[2], class: cls }, text));
  }

  // Lines first, then markers, then labels on top.
  for (const p of pts) {
    p.px = toPx(p.xy);
    svg.append(node("line", { x1: cx, y1: cy, x2: p.px[0], y2: p.px[1], class: "smap-line " + p.kind, stroke: p.kind === "st" ? p.colour : null }));
  }
  for (const p of pts) {
    const [x, y] = p.px;
    if (p.kind === "lm") {
      const sz = labels ? 7 : 4;
      svg.append(node("path", { d: `M${x} ${y - sz}L${x + sz} ${y}L${x} ${y + sz}L${x - sz} ${y}Z`, class: "smap-lm" }));
    } else {
      svg.append(node("circle", { cx: x, cy: y, r: labels ? 8 : 4, fill: p.colour, class: "smap-st" }));
      if (labels) svg.append(node("text", { x, y: y + 3.5, "text-anchor": "middle", class: "smap-m" }, "M"));
    }
    if (labels) boxes.push({ x0: x - 9, x1: x + 9, y0: y - 9, y1: y + 9 });
  }
  const approx = l.q === "approx" || l.q === "street";
  if (approx && labels) svg.append(node("circle", { cx, cy, r: 16, class: "smap-approx" }));
  svg.append(node("circle", { cx, cy, r: labels ? 7 : 4, class: "smap-home" }));

  if (labels) {
    boxes.push({ x0: cx - 9, x1: cx + 9, y0: cy - 9, y1: cy + 9 });
    // Fixed furniture first, so labels keep clear of it.
    const nx = w - 28, ny = 30;
    boxes.push({ x0: nx - 10, x1: nx + 10, y0: ny - 16, y1: ny + 22 });
    const bar = nice(ext / 3);
    const bx = 16, by = h - 18, bw = bar * scale;
    boxes.push({ x0: bx, x1: bx + bw, y0: by - 20, y1: by + 2 });
    for (const p of pts) {
      const [x, y] = p.px;
      const name = p.name + (p.kind === "st" && p.line ? " (" + p.line + ")" : "");
      place(name, 12, [[x + 12, y + 4, "start"], [x - 12, y + 4, "end"], [x, y - 13, "middle"], [x, y + 22, "middle"],
        [x + 12, y - 10, "start"], [x - 12, y - 10, "end"], [x + 12, y + 18, "start"], [x - 12, y + 18, "end"]], "smap-name");
    }
    for (const p of pts) {
      if (p.m === null || p.m === undefined) continue;
      const [x, y] = p.px;
      const cands = [];
      for (const f of [0.5, 0.35, 0.65, 0.25, 0.75]) {
        const mx = cx + (x - cx) * f, my = cy + (y - cy) * f;
        cands.push([mx, my - 5, "middle"], [mx, my + 14, "middle"], [mx + 6, my + 4, "start"], [mx - 6, my + 4, "end"]);
      }
      place(fmt(p.m), 11, cands, "smap-dist");
    }
    svg.append(node("path", { d: `M${nx} ${ny - 14}L${nx + 7} ${ny + 6}L${nx} ${ny + 2}L${nx - 7} ${ny + 6}Z`, class: "smap-north" }));
    svg.append(node("text", { x: nx, y: ny + 20, "text-anchor": "middle", class: "smap-n" }, "N"));
    svg.append(node("path", { d: `M${bx} ${by - 5}V${by}H${bx + bw}V${by - 5}`, class: "smap-scale" }));
    svg.append(node("text", { x: bx + bw / 2, y: by - 8, "text-anchor": "middle", class: "smap-dist" }, fmt(bar)));
    if (approx) {
      svg.append(node("text", { x: w - 12, y: h - 12, "text-anchor": "end", class: "smap-note" },
        l.q === "street" ? "Position: the street, not the house" : "Position approximate (OLX map, ≈1 km)"));
    }
  }
  return svg;
}
