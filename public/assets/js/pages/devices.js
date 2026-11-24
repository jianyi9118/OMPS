/* 设备档案 (phase 6): list, add / edit / delete, detail drawer, Excel import and export */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t, D = O.device;
  var view = document.getElementById("view");
  var state = { tab: "list", category: "", status: "", dept_id: "", page: 1 };
  var M = null;

  function shell() {
    var tabs = [["list", t("device.tab.list")]];
    if (M.caps.add) tabs.push(["import", t("device.tab.import")]);
    view.innerHTML = (tabs.length > 1 ? '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="dv_tabs">' + tabs.map(function (x) {
      return '<button type="button" data-tab="' + x[0] + '">' + O.esc(x[1]) + "</button>"; }).join("") + "</div>" : "") + '<div id="dv_body" style="display:grid;gap:12px"></div>';
    var tb = document.getElementById("dv_tabs");
    if (tb) tb.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { state.tab = b.getAttribute("data-tab"); render(); }
    });
  }

  function render() {
    var bs = document.querySelectorAll("#dv_tabs button");
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle("on", bs[i].getAttribute("data-tab") === state.tab);
    if (state.tab === "import") importView(); else listView();
  }

  function listView() {
    var body = document.getElementById("dv_body");
    body.innerHTML = '<div class="bar"><select id="dv_cat"><option value="">' + O.esc(t("device.all_categories")) + "</option>" + M.categories.map(function (c) { return '<option value="' + c + '"' + (c === state.category ? " selected" : "") + ">" + O.esc(D.cat(c)) + "</option>"; }).join("") + "</select>" +
      '<select id="dv_status"><option value="">' + O.esc(t("device.all_statuses")) + "</option>" + M.statuses.map(function (s) { return '<option value="' + s + '"' + (s === state.status ? " selected" : "") + ">" + O.esc(t("device.status." + s)) + "</option>"; }).join("") + "</select>" +
      '<select id="dv_dept"><option value="">' + O.esc(t("staff.all_depts")) + "</option>" + O.deptOpts(M.depts).map(function (o) { return '<option value="' + o[0] + '"' + (String(o[0]) === String(state.dept_id) ? " selected" : "") + ">" + O.esc(o[1]) + "</option>"; }).join("") + "</select>" +
      '<span class="sp"></span>' + (M.caps.export ? '<button type="button" class="btn" id="dv_export">' + O.icon("download", 14) + " " + O.esc(t("device.export")) + "</button>" : "") +
      (M.caps.add ? '<button type="button" class="btn pri" id="dv_add">' + O.icon("plus", 14) + " " + O.esc(t("device.add")) + "</button>" : "") + '</div><div class="card" id="dv_card"></div>';
    var bar = body.querySelector(".bar");
    bar.insertBefore(O.filters({ state: state, onChange: load, period: t("device.field.purchase_date"), placeholder: t("device.search"), fields: [["asset_no", t("device.field.asset_no")],
      ["name", t("device.field.name")], ["model", t("device.f_model")], ["serial_no", t("device.field.serial_no")], ["holder", t("device.field.holder")], ["dept", t("device.field.dept")],
      ["location", t("device.field.location")], ["remark", t("device.field.remark")]] }), bar.querySelector(".sp"));
    ["dv_cat", "dv_status", "dv_dept"].forEach(function (id, i) {
      document.getElementById(id).addEventListener("change", function (e) { state[["category", "status", "dept_id"][i]] = e.target.value; state.page = 1; load(); });
    });
    var ex = document.getElementById("dv_export");
    if (ex) ex.addEventListener("click", function () { window.location.href = O.url("/api/v1/device-export", query()); });
    var add = document.getElementById("dv_add");
    if (add) add.addEventListener("click", function () { D.form(null, function (id) { load(); D.detail(id, { onChange: load }); }); });
    load();
  }

  /** The list filters (the export follows them too). */
  function query() {
    var q = O.filterQuery(state);
    q.category = state.category;
    q.status = state.status;
    q.dept_id = state.dept_id;
    return q;
  }

  function load() {
    var q = query();
    q.page = state.page;
    O.api("GET", "/api/v1/devices", null, q).then(function (res) {
      var card = document.getElementById("dv_card");
      if (!card) return;
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("device.field.asset_no"), f: function (d) { return '<span class="mono">' + O.esc(d.asset_no) + "</span>"; } },
          { t: t("device.field.name"), cls: "wrap", f: function (d) { return O.esc(d.name) + (d.model ? '<div class="muted" style="font-size:12px">' + O.esc((d.brand + " " + d.model).trim()) + "</div>" : ""); } },
          { t: t("device.field.category"), f: function (d) { return O.esc(D.cat(d.category)); } },
          { t: t("device.col.use"), f: function (d) { return O.esc(d.holder || "—") + (d.dept ? '<div class="muted" style="font-size:12px">' + O.esc(d.dept) + "</div>" : ""); } },
          { t: t("device.field.location"), f: function (d) { return O.esc(d.location || "—"); } },
          { t: t("device.field.run_status"), f: function (d) { return D.statusPill(d.run_status); } },
          { t: t("device.col.collector"), f: function (d) { return d.last_seen ? '<span class="mono" style="font-size:12px">' + O.esc(d.last_seen.slice(5, 16)) + "</span>" : '<span class="muted">' + O.esc(t("device.coll.none_short")) + "</span>"; } }
        ].concat(O.cf.cols(res.custom_cols)),
        rows: res.rows, empty: t("device.empty"),
        acts: function () {
          return [
            { icon: "eye", tip: t("common.view"), fn: function (d) { D.detail(d.id, { onChange: load }); } },
            M.caps.edit ? { icon: "edit", tip: t("common.edit"), fn: function (d) { O.api("GET", "/api/v1/devices/" + d.id).then(function (x) { D.form(x, load); }, O.failed); } } : null,
            M.caps.del ? { icon: "trash", tip: t("common.delete"), danger: true, fn: remove } : null
          ];
        }, slots: 3
      }));
      card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
      card.insertAdjacentHTML("beforeend", O.legend([["eye", t("common.view")], ["edit", t("common.edit")], ["trash", t("common.delete")]]));
    }, O.failed);
  }

  function remove(d) {
    O.confirm({ title: t("device.delete"), msg: t("device.delete_confirm", { device: d.asset_no + " " + d.name }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/devices/" + d.id).then(function () { O.status(t("device.deleted")); load(); }, O.failed);
    });
  }

  /* ---------- import ---------- */
  function importView() {
    var body = document.getElementById("dv_body");
    body.innerHTML = '<div class="card"><div class="card-h">' + O.esc(t("device.import.title")) + '</div><div class="card-b" style="display:grid;gap:12px">' +
      '<div class="steps"><span class="on">1 ' + O.esc(t("import.step1")) + "</span><span>2 " + O.esc(t("import.step2")) + "</span><span>3 " + O.esc(t("import.step3")) + "</span></div>" +
      '<p class="muted" style="margin:0">' + O.esc(t("device.import.intro")) + "</p>" +
      '<div><a class="btn" href="' + O.esc(O.url("/api/v1/device-import/template")) + '">' + O.icon("download", 14) + " " + O.esc(t("import.template")) + '</a> <button type="button" class="btn pri" id="di_pick">' +
      O.icon("upload", 14) + " " + O.esc(t("import.choose")) + '</button></div><div id="di_result"></div></div></div>';
    document.getElementById("di_pick").addEventListener("click", function () {
      O.pickFiles({ accept: ".xlsx,.csv" }, function (files) {
        O.upload("/api/v1/device-import/check", files.slice(0, 1)).then(showCheck, function (err) {
          if (err && err.fields) O.info(t("error.title_refused"), "<p>" + O.esc(err.fields.file || err.message) + "</p>"); else O.failed(err);
        });
      });
    });
  }

  function showCheck(res) {
    var steps = document.querySelectorAll("#dv_body .steps span");
    if (steps.length) { steps[0].className = "done"; steps[1].className = "on"; }
    var box = document.getElementById("di_result");
    var cols = [{ t: t("import.line"), f: function (r) { return String(r.line); } }];
    res.columns.forEach(function (k) { cols.push({ t: t("device.field." + k), f: function (r) { return O.esc(r.values[k] || ""); } }); });
    cols.push({ t: t("import.result"), cls: "wrap", f: function (r) { return r.errors.length ? '<span class="pill bad">' + O.esc(t("import.bad")) + '</span> <span style="color:var(--bad);font-size:12px">' + O.esc(r.errors.join("; ")) + "</span>" : O.pill(t("import.ok"), "ok"); } });
    box.innerHTML = '<div class="note ' + (res.bad ? "warn" : "") + '">' + O.esc(t("import.summary", { ok: res.ok, bad: res.bad })) + "</div>";
    var tw = O.table({ cols: cols, rows: res.rows });
    tw.style.maxHeight = "420px";
    box.appendChild(tw);
    if (!res.ok) return;
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn pri";
    btn.style.marginTop = "10px";
    btn.textContent = t("import.run", { n: res.ok });
    btn.addEventListener("click", function () {
      btn.disabled = true;
      O.api("POST", "/api/v1/device-import/run", { token: res.token }).then(function (r) {
        if (steps.length) { steps[1].className = "done"; steps[2].className = "on"; }
        box.innerHTML = '<div class="note">' + O.esc(t("device.import.done", { created: r.created, skipped: r.skipped })) + "</div>";
        O.status(t("device.import.done", { created: r.created, skipped: r.skipped }));
      }, function (e) { btn.disabled = false; O.failed(e); });
    });
    box.appendChild(btn);
  }

  D.meta().then(function (m) {
    M = m;
    shell();
    render();
    var focus = +O.qs("id");
    if (focus) D.detail(focus, { tab: O.qs("tab") === "licenses" ? 2 : 0, onChange: load }).then(function (d) { if (d) O.focusBar(d.asset_no + " " + d.name); });
  }, O.failed);
})();
