/* Charts (phase 7): self-drawn, no library. Bars for comparing groups, a donut for shares (at most 8
 * slices), a line for values over ordered years, a single number, and a table view for every chart.
 * Colours are the validated categorical palette (CSS --c1…--c8, own steps for dark mode); values are
 * always written next to the marks, so colour never carries meaning alone. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var tip = null;

  function fmt(v) {
    if (v === null || v === undefined) return "—";
    var n = Number(v);
    return Math.round(n) === n ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
  }
  function pct(v, total) { return total ? Math.round(v * 1000 / total) / 10 : 0; }

  function showTip(e, html) {
    if (!tip) {
      tip = document.createElement("div");
      tip.className = "ctip";
      document.body.appendChild(tip);
    }
    tip.innerHTML = html;
    tip.style.display = "block";
    var x = e.clientX + 14, y = e.clientY + 14;
    if (x + tip.offsetWidth > window.innerWidth - 8) x = e.clientX - tip.offsetWidth - 14;
    if (y + tip.offsetHeight > window.innerHeight - 8) y = e.clientY - tip.offsetHeight - 14;
    tip.style.left = x + "px";
    tip.style.top = y + "px";
  }
  function hideTip() { if (tip) tip.style.display = "none"; }
  function tipHtml(g, r) {
    return "<b>" + O.esc(g.label) + "</b><br>" + O.esc(fmt(g.value)) + (r.avg ? "" : " · " + pct(g.value, r.total) + "%");
  }

  /* horizontal bars: one hue, value at the end of each bar */
  function bar(r) {
    var max = 0;
    r.groups.forEach(function (g) { max = Math.max(max, g.value); });
    var el = document.createElement("div");
    el.className = "hb";
    el.innerHTML = r.groups.map(function (g, i) {
      var w = max > 0 ? Math.max(g.value > 0 ? 1.5 : 0, g.value * 100 / max) : 0;
      return '<div class="hb-r" data-i="' + i + '"><span class="hb-l" title="' + O.esc(g.label) + '">' + O.esc(g.label) + '</span><span class="hb-t"><i style="width:' + w + '%"></i></span><span class="hb-v">' + O.esc(fmt(g.value)) + "</span></div>";
    }).join("");
    el.addEventListener("mousemove", function (e) {
      var row = e.target.closest ? e.target.closest(".hb-r") : null;
      if (row) showTip(e, tipHtml(r.groups[+row.getAttribute("data-i")], r)); else hideTip();
    });
    el.addEventListener("mouseleave", hideTip);
    return el;
  }

  /* donut for shares; more than 8 groups fold into "other" */
  function pie(r) {
    var groups = r.groups.slice();
    if (groups.length > 8) {
      var rest = groups.splice(7);
      groups.push({ label: t("stat.other"), value: rest.reduce(function (s, g) { return s + g.value; }, 0) });
    }
    var total = groups.reduce(function (s, g) { return s + g.value; }, 0);
    var R = 60, C = 2 * Math.PI * R, off = 0, gap = groups.length > 1 ? 2 : 0;
    var arcs = groups.map(function (g, i) {
      var len = total ? g.value / total * C : 0;
      var dash = Math.max(0, len - gap);
      var s = '<circle data-i="' + i + '" cx="80" cy="80" r="' + R + '" fill="none" stroke="var(--c' + (i + 1) + ')" stroke-width="22" stroke-dasharray="' + dash + " " + (C - dash) +
        '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90 80 80)"></circle>';
      off += len;
      return s;
    }).join("");
    var el = document.createElement("div");
    el.className = "pie";
    el.innerHTML = '<svg viewBox="0 0 160 160" width="160" height="160" role="img" aria-label="' + O.esc(groups.map(function (g) { return g.label + " " + fmt(g.value); }).join(", ")) + '">' +
      '<circle cx="80" cy="80" r="' + R + '" fill="none" stroke="var(--line-2)" stroke-width="22"></circle>' + arcs +
      '<text x="80" y="78" text-anchor="middle" class="pie-n">' + O.esc(fmt(total)) + '</text><text x="80" y="96" text-anchor="middle" class="pie-s">' + O.esc(t("stat.total")) + "</text></svg>" +
      '<ul class="lgd">' + groups.map(function (g, i) {
        return '<li data-i="' + i + '"><i style="background:var(--c' + (i + 1) + ')"></i><span class="grow">' + O.esc(g.label) + '</span><b>' + O.esc(fmt(g.value)) + '</b><span class="muted">' + pct(g.value, total) + "%</span></li>";
      }).join("") + "</ul>";
    var rr = { total: total, avg: false };
    el.addEventListener("mousemove", function (e) {
      var x = e.target.closest ? e.target.closest("[data-i]") : null;
      if (x) showTip(e, tipHtml(groups[+x.getAttribute("data-i")], rr)); else hideTip();
    });
    el.addEventListener("mouseleave", hideTip);
    return el;
  }

  /* line over ordered groups (years): 2px line, 8px markers, crosshair with tooltip */
  function line(r) {
    var g = r.groups.filter(function (x) { return x.key !== "~"; });
    var W = 600, H = 230, L = 44, Rm = 16, T = 28, B = 30;
    var max = 0;
    g.forEach(function (x) { max = Math.max(max, x.value); });
    var step = niceStep(max / 3 || 1), top = Math.max(step, Math.ceil(max / step) * step);
    var X = function (i) { return g.length < 2 ? (L + W - Rm) / 2 : L + i * (W - L - Rm) / (g.length - 1); };
    var Y = function (v) { return T + (H - T - B) * (1 - v / top); };
    var grid = "";
    for (var v = 0; v <= top + 1e-9; v += step) {
      grid += '<line x1="' + L + '" x2="' + (W - Rm) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" class="gl"></line><text x="' + (L - 6) + '" y="' + (Y(v) + 4) + '" text-anchor="end" class="ax">' + O.esc(fmt(v)) + "</text>";
    }
    var every = Math.max(1, Math.ceil(g.length / 10));
    var xl = g.map(function (x, i) { return i % every === 0 || i === g.length - 1 ? '<text x="' + X(i) + '" y="' + (H - 10) + '" text-anchor="middle" class="ax">' + O.esc(x.label) + "</text>" : ""; }).join("");
    var pts = g.map(function (x, i) { return X(i) + "," + Y(x.value); }).join(" ");
    var maxI = 0;
    g.forEach(function (x, i) { if (x.value > g[maxI].value) maxI = i; });
    var label = function (i) { return '<text x="' + X(i) + '" y="' + (Y(g[i].value) - 10) + '" text-anchor="middle" class="vl">' + O.esc(fmt(g[i].value)) + "</text>"; };
    var el = document.createElement("div");
    el.className = "lc";
    el.innerHTML = '<svg viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="' + O.esc(g.map(function (x) { return x.label + " " + fmt(x.value); }).join(", ")) + '">' + grid + xl +
      '<polyline points="' + pts + '" fill="none" stroke="var(--c1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>' +
      g.map(function (x, i) { return '<circle cx="' + X(i) + '" cy="' + Y(x.value) + '" r="4" fill="var(--c1)" stroke="var(--panel)" stroke-width="2"></circle>'; }).join("") +
      (g.length ? label(g.length - 1) + (maxI !== g.length - 1 ? label(maxI) : "") : "") +
      '<line class="xh" x1="0" x2="0" y1="' + T + '" y2="' + (H - B) + '" style="display:none"></line></svg>';
    var svg = el.querySelector("svg"), xh = el.querySelector(".xh");
    svg.addEventListener("mousemove", function (e) {
      if (!g.length) return;
      var box = svg.getBoundingClientRect();
      var x = (e.clientX - box.left) * W / box.width;
      var best = 0;
      g.forEach(function (p, i) { if (Math.abs(X(i) - x) < Math.abs(X(best) - x)) best = i; });
      xh.setAttribute("x1", X(best));
      xh.setAttribute("x2", X(best));
      xh.style.display = "";
      showTip(e, tipHtml(g[best], r));
    });
    svg.addEventListener("mouseleave", function () { xh.style.display = "none"; hideTip(); });
    return el;
  }

  function niceStep(raw) {
    var p = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10)), n = raw / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
  }

  function number(r) {
    var el = document.createElement("div");
    el.className = "hero";
    el.innerHTML = '<div class="hero-v">' + O.esc(fmt(r.total)) + "</div>";
    return el;
  }

  function table(r) {
    return O.table({
      cols: [{ t: t("stat.col.item"), f: function (g) { return O.esc(g.label); } },
        { t: t("stat.col.value"), cls: "num", f: function (g) { return O.esc(fmt(g.value)); } }].concat(r.avg ? [] : [{ t: t("stat.col.share"), cls: "num", f: function (g) { return pct(g.value, r.total) + "%"; } }]),
      rows: r.groups, empty: t("stat.no_data")
    });
  }

  /* a widget card: title, chart / table switch, the chart */
  function card(w) {
    var el = document.createElement("div");
    el.className = "card wcard" + (w.chart === "number" ? " wnum" : "");
    var r = w.result;
    var canTable = r && w.chart !== "table" && w.chart !== "number";
    el.innerHTML = '<div class="card-h"><span>' + O.esc(w.title) + "</span>" + (canTable ? '<span class="r"><button type="button" class="btn link sm" data-view="1">' + O.esc(t("stat.as_table")) + "</button></span>" : "") +
      '</div><div class="card-b wbody"></div>';
    var body = el.querySelector(".wbody");
    var asTable = false;
    function draw() {
      body.innerHTML = "";
      if (!r) { body.innerHTML = '<p class="muted">' + O.esc(t("stat.widget_broken_hint")) + "</p>"; return; }
      if (w.chart !== "number" && !r.groups.length) { body.innerHTML = '<p class="muted">' + O.esc(t("stat.no_data")) + "</p>"; return; }
      var kind = asTable ? "table" : w.chart;
      body.appendChild(({ bar: bar, pie: pie, line: line, table: table, number: number })[kind](r));
      if (w.chart !== "number" && kind !== "table") body.insertAdjacentHTML("beforeend", '<div class="muted wfoot">' + O.esc(t(r.avg ? "stat.foot_avg" : "stat.foot_total", { n: fmt(r.total), rows: r.n })) + "</div>");
    }
    var b = el.querySelector("[data-view]");
    if (b) b.addEventListener("click", function () { asTable = !asTable; b.textContent = t(asTable ? "stat.as_chart" : "stat.as_table"); draw(); });
    draw();
    return el;
  }

  O.chart = { card: card, fmt: fmt, bar: bar, pie: pie, line: line, table: table };
})();
