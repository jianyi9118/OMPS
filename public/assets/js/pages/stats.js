/* 数据统计 (phase 7): 人员统计 · 部门统计 · 自定义统计, within the viewer's data scope; export and print */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t, C = O.chart;
  var view = document.getElementById("view");
  var page = view.getAttribute("data-page");
  var canExport = O.has("stat.export");

  function toolbar(note, which, id) {
    return '<div class="bar"><span class="muted" style="font-size:12.5px">' + O.esc(note) + '</span><span class="sp"></span>' +
      '<button type="button" class="btn" data-print="1">' + O.esc(t("stat.print")) + "</button>" +
      (canExport ? '<a class="btn" href="' + O.esc(O.url("/api/v1/stats/export", { which: which, id: id || "" })) + '">' + O.icon("download", 14) + " " + O.esc(t("stat.export")) + "</a>" : "") + "</div>";
  }

  /* print: the statistics area only, on its own page */
  function printView(title) {
    var area = document.createElement("div");
    area.id = "printArea";
    area.className = "printpage";
    var src = document.getElementById("st_print");
    area.innerHTML = '<h2 style="margin:0 0 12px">' + O.esc(title) + '</h2><div class="muted" style="margin-bottom:12px">' + O.esc(t("stat.printed_at", { at: new Date().toLocaleString() })) + "</div>" + src.innerHTML;
    document.body.appendChild(area);
    document.body.classList.add("printing");
    var done = false;
    var cleanup = function () { if (done) return; done = true; document.body.classList.remove("printing"); if (area.parentNode) area.parentNode.removeChild(area); };
    window.addEventListener("afterprint", cleanup, { once: true });
    setTimeout(function () { window.print(); setTimeout(cleanup, 500); }, 50);
  }

  function bindPrint(title) {
    var b = view.querySelector("[data-print]");
    if (b) b.addEventListener("click", function () { printView(title); });
  }

  function scopeNote(scope) { return t("stat.scope_note", { scope: t("scope." + scope) }); }

  /* ---------- 人员统计 ---------- */
  function people() {
    O.api("GET", "/api/v1/stats/people").then(function (d) {
      view.innerHTML = toolbar(scopeNote(d.scope), "people") + '<div id="st_print" style="display:grid;gap:12px"><div class="kpis stkpi">' + d.kpis.map(function (k) {
        return '<div class="kpi"><div class="k">' + O.esc(t(k.k)) + '</div><div class="v">' + O.esc(k.v === null ? "—" : C.fmt(k.v)) + "</div></div>";
      }).join("") + '</div><div class="wgrid" id="st_w"></div></div>';
      var g = document.getElementById("st_w");
      d.widgets.forEach(function (w) { g.appendChild(C.card(w)); });
      bindPrint(t("menu.stat_people"));
    }, O.failed);
  }

  /* ---------- 部门统计 ---------- */
  function depts() {
    O.api("GET", "/api/v1/stats/depts").then(function (d) {
      view.innerHTML = toolbar(t("stat.dept_note"), "depts") + '<div id="st_print" style="display:grid;gap:12px"><div class="card" id="st_tbl"></div><div class="wgrid" id="st_w"></div></div>';
      var cols = [
        { t: t("stat.col.dept"), f: function (r) { return '<span style="padding-left:' + (r.depth * 16) + 'px">' + O.esc(r.name) + "</span>"; } },
        { t: t("stat.col.leader"), f: function (r) { return O.esc(r.leader || "—"); } },
        { t: t("stat.col.people"), cls: "num", f: function (r) { return C.fmt(r.people); } },
        { t: t("stat.col.plans"), cls: "num", f: function (r) { return C.fmt(r.plans); } },
        { t: t("stat.col.progress"), f: function (r) { return r.progress === null ? '<span class="muted">—</span>' : (O.perf ? O.perf.progress(r.progress) : O.esc(r.progress + "%")); } },
        { t: t("stat.col.lagging"), cls: "num", f: function (r) { return r.lagging ? '<b style="color:var(--bad)">' + r.lagging + "</b>" : '<span class="muted">0</span>'; } },
        { t: t("stat.col.results"), cls: "num", f: function (r) { return C.fmt(r.results); } }
      ];
      if (d.devices) {
        cols.push({ t: t("stat.col.devices"), cls: "num", f: function (r) { return C.fmt(r.devices); } });
        cols.push({ t: t("stat.col.repair"), cls: "num", f: function (r) { return r.repair ? '<b style="color:var(--warn)">' + r.repair + "</b>" : '<span class="muted">0</span>'; } });
      }
      document.getElementById("st_tbl").appendChild(O.table({ cols: cols, rows: d.rows, empty: t("stat.no_data") }));
      document.getElementById("st_tbl").insertAdjacentHTML("beforeend", '<div class="card-b muted" style="font-size:12.5px">' + O.esc(t("stat.lag_hint")) + "</div>");
      document.getElementById("st_w").appendChild(C.card({ chart: "bar", title: t("stat.people_by_dept"), result: d.chart }));
      bindPrint(t("menu.stat_depts"));
    }, O.failed);
  }

  /* ---------- 自定义统计 ---------- */
  function custom() {
    O.api("GET", "/api/v1/stats/pages").then(function (d) {
      if (!d.pages.length) {
        view.innerHTML = '<div class="card"><div class="card-b muted">' + O.esc(t("stat.no_pages")) + "</div></div>";
        return;
      }
      var cur = +O.qs("id") || d.pages[0].id;
      view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="st_tabs">' + d.pages.map(function (p) {
        return '<button type="button" data-id="' + p.id + '">' + O.esc(p.title) + "</button>"; }).join("") + '</div><div id="st_body" style="display:grid;gap:12px"></div>';
      document.getElementById("st_tabs").addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-id]") : null;
        if (b) show(+b.getAttribute("data-id"));
      });
      show(cur);
    }, O.failed);
  }

  function show(id) {
    var bs = document.querySelectorAll("#st_tabs button");
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle("on", +bs[i].getAttribute("data-id") === id);
    O.api("GET", "/api/v1/stats/pages/" + id).then(function (p) {
      var body = document.getElementById("st_body");
      body.innerHTML = toolbar(t("stat.custom_note"), "page", p.id) + '<div id="st_print"><div class="wgrid" id="st_w"></div></div>';
      var g = document.getElementById("st_w");
      if (!p.widgets.length) g.innerHTML = '<div class="card"><div class="card-b muted">' + O.esc(t("stat.no_widgets")) + "</div></div>";
      p.widgets.forEach(function (w) { g.appendChild(C.card(w)); });
      var pb = body.querySelector("[data-print]");
      if (pb) pb.addEventListener("click", function () { printView(p.title); });
    }, O.failed);
  }

  ({ people: people, depts: depts, custom: custom })[page]();
})();
