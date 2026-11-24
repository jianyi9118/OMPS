/* 系统管理 → 自定义 (phase 7, 8): 字段设计 · 统计页面设计 · 系统主题 · Banner 管理 · 文档模板 (super admin) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var TABS = ["fields", "stats", "theme", "banner", "docs"];
  var state = { tab: TABS.indexOf(O.qs("tab")) >= 0 ? O.qs("tab") : "fields", entity: "personnel" };

  function shell() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="cu_tabs">' + TABS.map(function (k) {
      return '<button type="button" data-tab="' + k + '">' + O.esc(t("custom.tab." + k)) + "</button>"; }).join("") + '</div><div id="cu_body" style="display:grid;gap:12px"></div>';
    document.getElementById("cu_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { state.tab = b.getAttribute("data-tab"); render(); }
    });
  }

  function render() {
    var bs = document.querySelectorAll("#cu_tabs button");
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle("on", bs[i].getAttribute("data-tab") === state.tab);
    ({ fields: fields, stats: stats, theme: theme, banner: banner, docs: docs })[state.tab]();
  }

  function body() { return document.getElementById("cu_body"); }

  /* inputs for a name in every enabled language */
  function nameFields(langs, prefix, label, values, max) {
    return langs.map(function (l) { return { name: prefix + l.code, label: label + " · " + l.name, req: true, max: max || 40, value: values ? values[l.code] || "" : "" }; });
  }

  /* ================= 字段设计 ================= */
  var FD = null;
  function fields() {
    O.api("GET", "/api/v1/custom-fields", null, { entity: state.entity }).then(function (d) {
      FD = d;
      var b = body();
      b.innerHTML = '<div class="bar"><div class="chips">' + d.entities.map(function (e) {
        return '<button type="button" class="chip' + (e === state.entity ? " on" : "") + '" data-ent="' + e + '">' + O.esc(t("cf.entity." + e)) + "</button>"; }).join("") +
        '</div><span class="sp"></span><button type="button" class="btn pri" id="cf_add">' + O.icon("plus", 14) + " " + O.esc(t("cf.add")) + '</button></div><div class="card" id="cf_card"></div>' +
        '<div class="note">' + O.esc(t("cf.hint")) + "</div>";
      b.querySelector(".chips").addEventListener("click", function (e) {
        var x = e.target.closest ? e.target.closest("[data-ent]") : null;
        if (x) { state.entity = x.getAttribute("data-ent"); fields(); }
      });
      document.getElementById("cf_add").addEventListener("click", function () { fieldForm(null); });
      var flags = function (f) {
        return ["required", "searchable", "statable", "in_list"].filter(function (k) { return f[k]; }).map(function (k) { return O.pill(t("cf.flag." + k), "info"); }).join(" ");
      };
      document.getElementById("cf_card").appendChild(O.table({
        cols: [
          { t: t("cf.field.name"), f: function (f) { return "<b>" + O.esc(f.label) + '</b><div class="muted mono" style="font-size:12px">' + O.esc(f.key) + "</div>"; } },
          { t: t("cf.field.type"), f: function (f) { return O.esc(t("cf.type." + f.type)) + (f.type === "select" ? '<div class="muted" style="font-size:12px">' + O.esc(f.options.map(function (o) { return o.label; }).join(t("common.list_sep"))) + "</div>" : ""); } },
          { t: t("cf.field.flags"), cls: "wrap", f: flags },
          { t: t("cf.field.values"), cls: "num", f: function (f) { return String(f.values); } },
          { t: t("cf.field.state"), f: function (f) { return f.enabled ? O.pill(t("cf.on"), "ok") : O.pill(t("cf.off")); } }
        ],
        rows: d.rows, empty: t("cf.empty"),
        acts: function (f) {
          var i = d.rows.indexOf(f);
          return [
            i > 0 ? { icon: "up", tip: t("cf.up"), fn: function () { move(i, -1); } } : null,
            i < d.rows.length - 1 ? { icon: "down", tip: t("cf.down"), fn: function () { move(i, 1); } } : null,
            { icon: "edit", tip: t("common.edit"), fn: fieldForm },
            f.values ? null : { icon: "trash", tip: t("common.delete"), danger: true, fn: removeField }
          ];
        }, slots: 4
      }));
    }, O.failed);
  }

  function move(i, dir) {
    var ids = FD.rows.map(function (r) { return r.id; });
    var x = ids[i];
    ids[i] = ids[i + dir];
    ids[i + dir] = x;
    O.api("POST", "/api/v1/custom-fields/order", { ids: ids }).then(fields, O.failed);
  }

  function removeField(f) {
    O.confirm({ title: t("cf.delete"), msg: t("cf.delete_confirm", { name: f.label }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/custom-fields/" + f.id).then(function () { O.status(t("common.deleted")); fields(); }, O.failed);
    });
  }

  function fieldForm(f) {
    var langs = FD.languages, edit = !!f;
    var defs = nameFields(langs, "name_", t("cf.field.name"), f ? f.names : null);
    if (!edit) defs.push({ name: "field_key", label: t("cf.field.key"), req: true, max: 40, hint: t("cf.key_hint") });
    defs.push({ name: "data_type", label: t("cf.field.type"), type: "select", req: true, readonly: edit && f.values > 0, opts: FD.types.map(function (x) { return [x, t("cf.type." + x)]; }),
      hint: edit && f.values > 0 ? t("cf.type_locked") : "" });
    var form = O.form(defs, { data_type: f ? f.type : "text" });
    var box = document.createElement("div");
    box.style.display = "grid";
    box.style.gap = "12px";
    box.appendChild(form.el);
    box.insertAdjacentHTML("beforeend", '<div class="fld"><label>' + O.esc(t("cf.field.flags")) + '</label><div class="opts" id="cf_flags">' + ["required", "searchable", "statable", "in_list", "enabled"].map(function (k) {
      var on = f ? (k === "enabled" ? f.enabled : f[k]) : k === "enabled";
      return '<label><input type="checkbox" data-flag="' + k + '" style="width:auto"' + (on ? " checked" : "") + "> " + O.esc(t("cf.flag." + k)) + "</label>";
    }).join("") + '</div><span class="hint" id="cf_flag_hint"></span></div><div class="fld" id="cf_opts" hidden><label>' + O.esc(t("cf.options")) + '</label><div id="cf_opt_rows"></div>' +
      '<div style="margin-top:6px"><button type="button" class="btn sm" id="cf_opt_add">' + O.icon("plus", 14) + " " + O.esc(t("cf.option_add")) + '</button></div><span class="em" id="cf_opt_err" style="color:var(--bad);font-size:12px"></span></div>');
    var opts = f ? f.options.map(function (o) { return { key: o.key, names: o.names, enabled: o.enabled, used: o.used }; }) : [];
    function typeNow() { var s = box.querySelector('[name="data_type"]'); return s ? s.value : f.type; }
    function drawOpts() {
      var el = box.querySelector("#cf_opt_rows");
      el.innerHTML = '<div class="tw"><table class="tbl sm cfopt"><thead><tr>' + langs.map(function (l) { return "<th>" + O.esc(l.name) + "</th>"; }).join("") + "<th>" + O.esc(t("cf.on")) + "</th><th></th></tr></thead><tbody>" +
        (opts.length ? opts.map(function (o, i) {
          return '<tr data-i="' + i + '">' + langs.map(function (l) { return '<td><input class="inp" maxlength="40" data-lang="' + l.code + '" value="' + O.esc(o.names[l.code] || "") + '"></td>'; }).join("") +
            '<td><input type="checkbox" data-en="1" style="width:auto"' + (o.enabled ? " checked" : "") + '></td><td class="actc">' + (o.used ? '<span class="muted" style="font-size:12px">' + O.esc(t("cf.used_n", { n: o.used })) + "</span>"
              : '<button type="button" class="ib danger" data-del="' + i + '" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button>") + "</td></tr>";
        }).join("") : '<tr class="empty"><td colspan="' + (langs.length + 2) + '">' + O.esc(t("cf.no_options")) + "</td></tr>") + "</tbody></table></div>";
    }
    function sync() {
      Array.prototype.forEach.call(box.querySelectorAll("#cf_opt_rows tr[data-i]"), function (tr) {
        var o = opts[+tr.getAttribute("data-i")];
        Array.prototype.forEach.call(tr.querySelectorAll("[data-lang]"), function (inp) { o.names[inp.getAttribute("data-lang")] = inp.value.trim(); });
        o.enabled = tr.querySelector("[data-en]").checked;
      });
    }
    function typeChanged() {
      var ty = typeNow();
      box.querySelector("#cf_opts").hidden = ty !== "select";
      var s = box.querySelector('[data-flag="searchable"]'), st = box.querySelector('[data-flag="statable"]');
      s.disabled = FD.search_types.indexOf(ty) < 0;
      st.disabled = FD.stat_types.indexOf(ty) < 0;
      if (s.disabled) s.checked = false;
      if (st.disabled) st.checked = false;
      box.querySelector("#cf_flag_hint").textContent = t("cf.flag_hint");
    }
    box.addEventListener("change", function (e) { if (e.target.getAttribute("name") === "data_type") typeChanged(); });
    box.querySelector("#cf_opt_add").addEventListener("click", function () { sync(); opts.push({ key: "", names: {}, enabled: true, used: 0 }); drawOpts(); });
    box.querySelector("#cf_opt_rows").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-del]") : null;
      if (b) { sync(); opts.splice(+b.getAttribute("data-del"), 1); drawOpts(); }
    });
    drawOpts();
    typeChanged();
    O.modal({ title: t(edit ? "cf.edit" : "cf.add") + " · " + t("cf.entity." + (f ? f.entity : state.entity)), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      sync();
      var v = form.values();
      var payload = { entity: state.entity, names: {}, data_type: v.data_type || (f && f.type), field_key: v.field_key, options: opts.map(function (o) { return { key: o.key, names: o.names, enabled: o.enabled }; }) };
      langs.forEach(function (l) { payload.names[l.code] = v["name_" + l.code]; });
      Array.prototype.forEach.call(box.querySelectorAll("[data-flag]"), function (c) { payload[c.getAttribute("data-flag")] = c.checked; });
      var optErr = box.querySelector("#cf_opt_err");
      optErr.textContent = "";
      form.errors(null);
      var req = edit ? O.api("PUT", "/api/v1/custom-fields/" + f.id, payload) : O.api("POST", "/api/v1/custom-fields", payload);
      return req.then(function () { O.status(t("common.saved")); fields(); }, function (e) {
        if (e && e.fields) {
          form.errors(e.fields);
          optErr.textContent = e.fields.options || "";
          O.status(e.message, "err");
          return Promise.reject(null);
        }
        return Promise.reject(e);
      });
    } }] });
  }

  /* ================= 统计页面设计 ================= */
  var SD = null;
  function stats() {
    O.api("GET", "/api/v1/stat-pages").then(function (d) {
      SD = d;
      var b = body();
      b.innerHTML = '<div class="bar"><span class="muted" style="font-size:12.5px">' + O.esc(t("sd.hint")) + '</span><span class="sp"></span><button type="button" class="btn pri" id="sd_add">' + O.icon("plus", 14) + " " +
        O.esc(t("sd.add")) + '</button></div><div class="card" id="sd_card"></div>';
      document.getElementById("sd_add").addEventListener("click", function () { pageForm(null); });
      document.getElementById("sd_card").appendChild(O.table({
        cols: [{ t: t("sd.col.title"), f: function (p) { return "<b>" + O.esc(p.title) + "</b>"; } }, { t: t("sd.col.widgets"), cls: "num", f: function (p) { return String(p.widgets); } },
          { t: t("cf.field.state"), f: function (p) { return p.enabled ? O.pill(t("cf.on"), "ok") : O.pill(t("cf.off")); } }],
        rows: d.pages, empty: t("sd.empty"),
        acts: function () { return [{ icon: "edit", tip: t("common.edit"), fn: function (p) { O.api("GET", "/api/v1/stat-pages/" + p.id).then(pageForm, O.failed); } },
          { icon: "trash", tip: t("common.delete"), danger: true, fn: removePage }]; }
      }));
    }, O.failed);
  }

  function removePage(p) {
    O.confirm({ title: t("sd.delete"), msg: t("sd.delete_confirm", { name: p.title }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/stat-pages/" + p.id).then(function () { O.status(t("common.deleted")); stats(); }, O.failed);
    });
  }

  function pageForm(p) {
    var langs = SD.languages, opts = SD.options;
    var form = O.form(nameFields(langs, "name_", t("sd.col.title"), p ? p.names : null, 60).concat([{ name: "enabled", type: "checkbox", label: t("cf.flag.enabled"), span2: true }]),
      { enabled: p ? p.enabled : true });
    var widgets = p ? p.widgets.map(function (w) { return { chart: w.chart, def: w.def }; }) : [];
    var box = document.createElement("div");
    box.style.display = "grid";
    box.style.gap = "12px";
    box.appendChild(form.el);
    box.insertAdjacentHTML("beforeend", '<div class="fld"><label>' + O.esc(t("sd.widgets")) + '</label><div class="rfrows" id="sd_rows"></div><div style="margin-top:6px"><button type="button" class="btn sm" id="sd_wadd">' +
      O.icon("plus", 14) + " " + O.esc(t("sd.widget_add")) + '</button></div><span class="em" id="sd_err" style="color:var(--bad);font-size:12px"></span></div><div id="sd_prev"></div>');
    function sel(k, list, cur, label) {
      return '<select class="inp" data-k="' + k + '">' + list.map(function (x) { return '<option value="' + O.esc(x[0]) + '"' + (x[0] === cur ? " selected" : "") + ">" + O.esc(x[1]) + "</option>"; }).join("") + "</select>";
    }
    function fix(w) {
      var e = opts[w.def.entity] ? w.def.entity : "personnel";
      w.def.entity = e;
      var dims = opts[e].dims.map(function (x) { return x.key; }), ms = opts[e].measures.map(function (x) { return x.key; });
      if (dims.indexOf(w.def.dim) < 0) w.def.dim = dims[0];
      if (ms.indexOf(w.def.measure) < 0) w.def.measure = "count";
      if (!w.def.only) w.def.only = "active";
      if (SD.charts.indexOf(w.chart) < 0) w.chart = "bar";
    }
    function draw() {
      var el = box.querySelector("#sd_rows");
      el.innerHTML = widgets.length ? widgets.map(function (w, i) {
        fix(w);
        var e = opts[w.def.entity];
        return '<div class="rfrow" data-i="' + i + '"><span class="muted">' + (i + 1) + ".</span>" +
          sel("entity", Object.keys(opts).map(function (k) { return [k, opts[k].label]; }), w.def.entity) +
          (w.chart === "number" ? "" : sel("dim", e.dims.map(function (x) { return [x.key, t("sd.by", { dim: x.label })]; }), w.def.dim)) +
          sel("measure", e.measures.map(function (x) { return [x.key, x.label]; }), w.def.measure) +
          sel("chart", SD.charts.map(function (c) { return [c, t("sd.chart." + c)]; }), w.chart) +
          sel("only", [["active", t("sd.only.active")], ["all", t("sd.only.all")]], w.def.only) +
          '<button type="button" class="ib" data-act="prev" title="' + O.esc(t("sd.preview")) + '">' + O.icon("eye") + "</button>" +
          (i > 0 ? '<button type="button" class="ib" data-act="up" title="' + O.esc(t("cf.up")) + '">' + O.icon("up") + "</button>" : '<span class="ib gap"></span>') +
          '<button type="button" class="ib danger" data-act="del" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button></div>";
      }).join("") : '<p class="muted" style="margin:0">' + O.esc(t("sd.no_widgets")) + "</p>";
    }
    function preview(i) {
      var w = widgets[i];
      O.api("POST", "/api/v1/stat-pages/preview", { chart: w.chart, def: w.def }).then(function (r) {
        var pv = box.querySelector("#sd_prev");
        pv.innerHTML = '<div class="muted" style="font-size:12.5px;margin-bottom:6px">' + O.esc(t("sd.preview_note")) + "</div>";
        pv.appendChild(O.chart.card(r));
      }, O.failed);
    }
    box.querySelector("#sd_rows").addEventListener("change", function (e) {
      var row = e.target.closest(".rfrow"), k = e.target.getAttribute("data-k");
      if (!row || !k) return;
      var w = widgets[+row.getAttribute("data-i")];
      if (k === "chart") w.chart = e.target.value; else w.def[k] = e.target.value;
      draw();
      preview(+row.getAttribute("data-i"));
    });
    box.querySelector("#sd_rows").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-act]") : null;
      if (!b) return;
      var i = +b.closest(".rfrow").getAttribute("data-i"), a = b.getAttribute("data-act");
      if (a === "prev") preview(i);
      if (a === "up") { var x = widgets[i]; widgets[i] = widgets[i - 1]; widgets[i - 1] = x; draw(); }
      if (a === "del") { widgets.splice(i, 1); draw(); }
    });
    box.querySelector("#sd_wadd").addEventListener("click", function () {
      widgets.push({ chart: "bar", def: { entity: "personnel", dim: "dept", measure: "count", only: "active" } });
      draw();
      preview(widgets.length - 1);
    });
    draw();
    O.modal({ title: t(p ? "sd.edit" : "sd.add"), width: 900, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var v = form.values();
      var payload = { names: {}, enabled: v.enabled, widgets: widgets };
      langs.forEach(function (l) { payload.names[l.code] = v["name_" + l.code]; });
      box.querySelector("#sd_err").textContent = "";
      var req = p ? O.api("PUT", "/api/v1/stat-pages/" + p.id, payload) : O.api("POST", "/api/v1/stat-pages", payload);
      return req.then(function () { O.status(t("common.saved")); stats(); }, function (e) {
        if (e && e.fields) { form.errors(e.fields); box.querySelector("#sd_err").textContent = e.fields.widgets || ""; O.status(e.message, "err"); return Promise.reject(null); }
        return Promise.reject(e);
      });
    } }] });
  }

  /* ================= 系统主题 ================= */
  function theme() {
    O.api("GET", "/api/v1/theme").then(function (d) {
      var b = body();
      b.innerHTML = '<div class="card" style="max-width:820px"><div class="card-b" style="display:grid;gap:16px">' +
        '<div class="fld"><label>' + O.esc(t("theme.primary")) + '</label><div class="swatches" id="th_sw">' + Object.keys(d.presets).map(function (k) {
          return '<button type="button" data-p="' + k + '" class="' + (k === d.primary ? "on" : "") + '" style="background:' + d.presets[k][0] + '" title="' + O.esc(t("theme.color." + k)) + '" aria-label="' + O.esc(t("theme.color." + k)) + '"></button>';
        }).join("") + '</div><span class="hint" id="th_swname">' + O.esc(t("theme.color." + d.primary)) + "</span></div>" +
        radio("th_nav", t("theme.nav"), [["dark", t("theme.nav_dark")], ["light", t("theme.nav_light")]], d.nav) +
        radio("th_top", t("theme.top"), [["white", t("theme.top_white")], ["primary", t("theme.top_primary")]], d.top) +
        radio("th_def", t("theme.default"), [["auto", t("theme.mode.auto")], ["light", t("theme.mode.light")], ["dark", t("theme.mode.dark")]], d.default) +
        '<div class="fld"><label>' + O.esc(t("theme.logo")) + '</label><div class="isrow"><div class="logo' + (d.logo ? "" : " def") + '" style="width:40px;height:40px"><img alt="" src="' + O.esc(d.logo || O.boot.base + "/assets/img/logo.png") + '">' +
        '</div><button type="button" class="btn sm" id="th_logo">' + O.icon("upload", 14) + " " + O.esc(t("theme.logo_upload")) + "</button>" + (d.logo ? '<button type="button" class="btn sm danger" id="th_nologo">' + O.esc(t("theme.logo_remove")) + "</button>" : "") +
        '</div><span class="hint">' + O.esc(t("theme.logo_hint")) + "</span></div>" +
        '<div><button type="button" class="btn pri" id="th_save">' + O.esc(t("common.save")) + '</button> <span class="muted" style="font-size:12.5px">' + O.esc(t("theme.save_hint")) + "</span></div></div></div>";
      var primary = d.primary;
      b.querySelector("#th_sw").addEventListener("click", function (e) {
        var x = e.target.closest ? e.target.closest("[data-p]") : null;
        if (!x) return;
        primary = x.getAttribute("data-p");
        Array.prototype.forEach.call(b.querySelectorAll("#th_sw button"), function (y) { y.classList.toggle("on", y === x); });
        b.querySelector("#th_swname").textContent = t("theme.color." + primary);
      });
      b.querySelector("#th_save").addEventListener("click", function () {
        var val = function (n) { var c = b.querySelector('input[name="' + n + '"]:checked'); return c ? c.value : ""; };
        O.api("PUT", "/api/v1/theme", { primary: primary, nav: val("th_nav"), top: val("th_top"), default: val("th_def") }).then(function () {
          O.status(t("common.saved"));
          setTimeout(function () { window.location.href = O.url("/system/custom", { tab: "theme" }); }, 400);
        }, O.failed);
      });
      b.querySelector("#th_logo").addEventListener("click", function () {
        O.pickFiles({ accept: "image/*" }, function (files) { O.upload("/api/v1/theme/logo", files.slice(0, 1)).then(function () { window.location.href = O.url("/system/custom", { tab: "theme" }); }, O.failed); });
      });
      var nl = b.querySelector("#th_nologo");
      if (nl) nl.addEventListener("click", function () { O.api("DELETE", "/api/v1/theme/logo").then(function () { window.location.href = O.url("/system/custom", { tab: "theme" }); }, O.failed); });
    }, O.failed);
  }

  function radio(name, label, list, cur) {
    return '<div class="fld"><label>' + O.esc(label) + '</label><div class="opts">' + list.map(function (x) {
      return '<label><input type="radio" name="' + name + '" value="' + x[0] + '"' + (x[0] === cur ? " checked" : "") + "> " + O.esc(x[1]) + "</label>"; }).join("") + "</div></div>";
  }

  /* ================= Banner 管理 ================= */
  function banner() {
    O.api("GET", "/api/v1/theme").then(function (d) {
      var list = d.banners.map(function (x) { return { rel: x.rel, url: x.url, name: x.name, enabled: x.enabled, captions: x.captions || {} }; });
      var b = body();
      function draw() {
        b.innerHTML = '<div class="bar"><span class="muted" style="font-size:12.5px">' + O.esc(t("banner.hint")) + '</span><span class="sp"></span>' +
          (list.length < 5 ? '<button type="button" class="btn" id="bn_up">' + O.icon("upload", 14) + " " + O.esc(t("banner.upload")) + "</button>" : "") +
          '<button type="button" class="btn pri" id="bn_save">' + O.esc(t("common.save")) + '</button></div><div class="card"><div class="card-b thumbs">' + (list.length ? list.map(function (x, i) {
            return '<div class="tb" data-i="' + i + '"><img alt="" src="' + O.esc(x.url) + '"><div style="display:grid;gap:6px"><label class="opts" style="font-size:13px"><input type="checkbox" data-en="1" style="width:auto"' + (x.enabled ? " checked" : "") + "> " +
              O.esc(t("banner.enabled")) + '</label>' + d.languages.map(function (l) {
                return '<input class="inp" maxlength="80" data-lang="' + l.code + '" placeholder="' + O.esc(t("banner.caption") + " · " + l.name) + '" value="' + O.esc(x.captions[l.code] || "") + '">'; }).join("") +
              '</div><div class="ibs">' + (i > 0 ? '<button type="button" class="ib" data-up="' + i + '" title="' + O.esc(t("cf.up")) + '">' + O.icon("up") + "</button>" : "") +
              '<button type="button" class="ib danger" data-del="' + i + '" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button></div></div>";
          }).join("") : '<p class="muted" style="margin:0">' + O.esc(t("banner.empty")) + "</p>") + "</div></div>";
        var up = b.querySelector("#bn_up");
        if (up) up.addEventListener("click", function () {
          O.pickFiles({ accept: "image/*" }, function (files) { O.upload("/api/v1/theme/banners", files.slice(0, 1)).then(function () { O.status(t("banner.uploaded")); banner(); }, O.failed); });
        });
        b.querySelector("#bn_save").addEventListener("click", function () {
          sync();
          O.api("PUT", "/api/v1/theme/banners", { banners: list }).then(function () { O.status(t("common.saved")); banner(); }, O.failed);
        });
      }
      function sync() {
        Array.prototype.forEach.call(b.querySelectorAll(".tb[data-i]"), function (row) {
          var x = list[+row.getAttribute("data-i")];
          x.enabled = row.querySelector("[data-en]").checked;
          Array.prototype.forEach.call(row.querySelectorAll("[data-lang]"), function (inp) { x.captions[inp.getAttribute("data-lang")] = inp.value.trim(); });
        });
      }
      b.onclick = function (e) {
        var u = e.target.closest ? e.target.closest("[data-up]") : null, del = e.target.closest ? e.target.closest("[data-del]") : null;
        if (u) { sync(); var i = +u.getAttribute("data-up"), x = list[i]; list[i] = list[i - 1]; list[i - 1] = x; draw(); }
        if (del) { sync(); list.splice(+del.getAttribute("data-del"), 1); draw(); O.status(t("banner.removed_hint"), "warn"); }
      };
      draw();
    }, O.failed);
  }

  /* ================= 文档模板 ================= */
  function docs() {
    O.api("GET", "/api/v1/doc-templates").then(function (d) {
      var b = body();
      b.innerHTML = '<div class="note">' + O.esc(t("doc.hint")) + "</div>" + (d.available ? "" : '<div class="note warn">' + O.esc(t("doc.err.no_zip")) + "</div>");
      d.types.forEach(function (tp) {
        var card = document.createElement("div");
        card.className = "card";
        card.innerHTML = '<div class="card-h">' + O.esc(t("doc.type." + tp.type)) + '<span class="r">' +
          '<button type="button" class="btn sm" data-sample="1">' + O.icon("download", 14) + " " + O.esc(t("doc.sample")) + "</button> " +
          '<button type="button" class="btn sm pri" data-upload="1">' + O.icon("upload", 14) + " " + O.esc(t("doc.upload")) + "</button></span></div>" +
          '<div class="dt-list"></div><div class="card-b" style="padding-top:0"><details class="dt-help"><summary>' + O.esc(t("doc.help")) + "</summary>" + helpHtml(tp.help) + "</details></div>";
        card.querySelector(".dt-list").appendChild(O.table({
          cols: [
            { t: t("doc.col.name"), f: function (x) { return "<b>" + O.esc(x.name) + "</b>" + (x.builtin ? '<div class="muted" style="font-size:12px">' + O.esc(t("doc.builtin_hint")) + "</div>" : '<div class="muted" style="font-size:12px">' + O.esc(x.file) + " · " + O.esc(O.fileSize(x.size)) + "</div>"); } },
            { t: t("doc.col.uploaded"), f: function (x) { return x.builtin ? '<span class="muted">—</span>' : O.esc(x.by || "—") + '<div class="muted" style="font-size:12px">' + O.esc(x.at.slice(0, 16)) + "</div>"; } },
            { t: t("doc.col.check"), cls: "wrap", f: function (x) {
              if (x.builtin) return '<span class="muted">—</span>';
              return x.unknown.length ? O.pill(t("doc.unknown_n", { n: x.unknown.length }), "warn") + '<div class="muted" style="font-size:12px">' + O.esc(x.unknown.map(function (n) { return "{{" + n + "}}"; }).join(t("common.list_sep"))) + "</div>"
                : O.pill(t("doc.recognized_n", { n: x.ok }), "ok");
            } },
            { t: t("cf.field.state"), f: function (x) { return x.default ? O.pill(t("doc.in_use"), "ok") : ""; } }
          ],
          rows: tp.templates, empty: "",
          acts: function (x) {
            return [
              { icon: "eye", tip: t("doc.test"), fn: function () { window.location.href = O.url("/api/v1/doc-templates/test", { type: tp.type, id: x.id }); } },
              x.builtin ? null : { icon: "download", tip: t("doc.download_file"), fn: function () { window.location.href = O.url("/api/v1/doc-templates/" + x.id + "/file"); } },
              x.default ? null : { icon: "check", tip: t("doc.use"), fn: function () { useTemplate(tp.type, x); } },
              x.builtin ? null : { icon: "trash", tip: t("common.delete"), danger: true, fn: function () { removeTemplate(x); } }
            ];
          }, slots: 4
        }));
        card.querySelector("[data-sample]").addEventListener("click", function () { window.location.href = O.url("/api/v1/doc-templates/sample", { type: tp.type }); });
        card.querySelector("[data-upload]").addEventListener("click", function () {
          O.pickFiles({ accept: ".docx" }, function (files) {
            O.upload("/api/v1/doc-templates", files.slice(0, 1), { type: tp.type }).then(function (r) {
              docs();
              if (r.unknown.length) {
                O.info(t("doc.uploaded"), "<p>" + O.esc(t("doc.uploaded_unknown", { n: r.ok.length })) + '</p><p class="mono" style="white-space:normal">' + O.esc(r.unknown.map(function (n) { return "{{" + n + "}}"; }).join("  ")) + "</p>");
              } else {
                O.status(t("doc.uploaded_ok", { n: r.ok.length }));
              }
            }, function (e) {
              if (e && e.fields && e.fields.file) { O.status(e.fields.file, "err"); return; }
              O.failed(e);
            });
          });
        });
        b.appendChild(card);
      });
    }, O.failed);
  }

  function helpHtml(h) {
    var chip = function (n) { return '<code class="ph">{{' + O.esc(n) + "}}</code>"; };
    var row = function (label, hint, items) {
      return items.length ? '<div class="ph-g"><b>' + O.esc(label) + "</b>" + (hint ? '<span class="muted">' + O.esc(hint) + "</span>" : "") + '<div class="ph-list">' + items.map(chip).join("") + "</div></div>" : "";
    };
    return '<div class="ph-help">' + row(t("doc.help.fields"), "", h.fields) + row(t("doc.help.blocks"), t("doc.help.blocks_hint"), h.blocks) + row(t("doc.help.images"), t("doc.help.images_hint"), h.images) +
      h.lists.map(function (l) { return row(t("doc.help.list", { name: l.name }), t("doc.help.list_hint"), l.cols); }).join("") +
      row(t("doc.help.custom"), t("doc.help.custom_hint"), h.custom) + "</div>";
  }

  function useTemplate(type, x) {
    O.api("POST", "/api/v1/doc-templates/default", { type: type, id: x.id }).then(function () { O.status(t("doc.used", { name: x.name })); docs(); }, O.failed);
  }

  function removeTemplate(x) {
    O.confirm({ title: t("doc.delete"), msg: t("doc.delete_confirm", { name: x.name }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/doc-templates/" + x.id).then(function () { O.status(t("common.deleted")); docs(); }, O.failed);
    });
  }

  shell();
  render();
})();
