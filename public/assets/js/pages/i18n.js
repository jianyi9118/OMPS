/* 多语言管理 (phase 9): 语言列表 · 翻译条目. One language at a time; enabling needs 100 %. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  if (!view || view.getAttribute("data-page") !== "i18n") return;
  var st = { tab: O.qs("tab") === "entries" ? "entries" : "langs", code: O.qs("lang") || "", module: "", kw: "", missing: false, page: 1 };
  var L = null, changes = {};

  function modLabel(m) {
    if (m.indexOf("db:") === 0) { var k = "i18n.ns." + m.slice(3); return O.boot.i18n && O.boot.i18n[k] ? t(k) : m.slice(3); }
    var k2 = "i18n.mod." + m;
    return O.boot.i18n && O.boot.i18n[k2] ? t(k2) : m;
  }

  function load(then) {
    O.api("GET", "/api/v1/languages").then(function (d) {
      L = d;
      if (!st.code || !L.languages.some(function (l) { return l.code === st.code; })) {
        var other = L.languages.filter(function (l) { return !l.base; })[0];
        st.code = other ? other.code : L.base;
      }
      draw();
      if (then) then();
    }, O.failed);
  }

  function pct(l) {
    return '<span class="pct' + (l.pct >= 100 && !l.missing ? " full" : "") + '"><span class="tr"><b style="width:' + Math.min(100, l.pct) + '%"></b></span><span class="num" style="font-size:12px">' +
      O.esc(l.missing ? t("i18n.pct", { pct: l.pct, n: l.missing }) : t("i18n.pct_full")) + "</span></span>";
  }

  function draw() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="i_tabs">' + ["langs", "entries"].map(function (k) {
      return '<button type="button" data-tab="' + k + '" class="' + (k === st.tab ? "on" : "") + '">' + O.esc(t("i18n.tab." + k)) + "</button>"; }).join("") + '</div><div id="i_body" style="display:grid;gap:12px;margin-top:12px"></div>';
    view.querySelector("#i_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b && confirmLeave()) { st.tab = b.getAttribute("data-tab"); draw(); }
    });
    if (st.tab === "langs") langs(); else entries();
  }

  /* unsaved translations: save or undo them first (no browser dialog, so no other-language buttons) */
  function confirmLeave() {
    if (!Object.keys(changes).length) return true;
    O.status(t("i18n.unsaved", { n: Object.keys(changes).length }), "warn");
    return false;
  }

  /* ---------- languages ---------- */
  function langs() {
    var b = document.getElementById("i_body");
    b.innerHTML = '<div class="note">' + O.esc(t("i18n.hint")) + '</div><div class="bar"><span class="sp"></span><button type="button" class="btn pri" id="i_add">' + O.icon("plus", 14) + " " + O.esc(t("i18n.add")) + '</button></div><div class="card" id="i_list"></div>';
    b.querySelector("#i_add").addEventListener("click", addForm);
    b.querySelector("#i_list").appendChild(O.table({
      cols: [
        { t: t("i18n.col.lang"), f: function (l) { return "<b>" + O.esc(l.name) + '</b> <span class="mono muted" style="font-size:12px">' + O.esc(l.code) + "</span>"; } },
        { t: t("cf.field.state"), cls: "wrap", f: function (l) {
          return [l.base ? O.pill(t("i18n.base"), "info") : "", l.default ? O.pill(t("i18n.default"), "ok") : "", l.enabled ? O.pill(t("i18n.enabled"), "ok") : O.pill(t("i18n.disabled"))].join(" ");
        } },
        { t: t("i18n.col.done"), f: pct },
        { t: t("i18n.col.count"), cls: "num", f: function (l) { return O.esc(l.done + " / " + l.total); } }
      ],
      rows: L.languages, empty: "",
      acts: function (l) {
        return [
          { icon: "edit", tip: t("i18n.edit"), fn: function () { st.tab = "entries"; st.code = l.code; st.page = 1; draw(); } },
          l.enabled && !l.default ? { icon: "check", tip: t("i18n.make_default"), fn: function () { update(l, { default: true }, t("i18n.defaulted", { name: l.name })); } } : null,
          { icon: "power", tip: t(l.enabled ? "i18n.disable" : "i18n.enable"), fn: function () { toggle(l); } },
          l.enabled || l.base ? null : { icon: "trash", tip: t("common.delete"), danger: true, fn: function () { removeLang(l); } }
        ];
      }, slots: 4
    }));
    if (L.missing && L.missing.length) {
      var mc = document.createElement("div");
      mc.className = "card";
      mc.innerHTML = '<div class="card-h">' + O.esc(t("i18n.missing")) + '<span class="r"><button type="button" class="btn sm" id="i_mclear">' + O.esc(t("i18n.missing_clear")) + "</button></span></div>" +
        '<div class="card-b muted" style="font-size:12.5px;padding-bottom:0">' + O.esc(t("i18n.missing_hint")) + "</div>";
      mc.appendChild(O.table({
        cols: [
          { t: t("i18n.col.lang"), f: function (m) { return '<span class="mono">' + O.esc(m.lang) + "</span>"; } },
          { t: t("i18n.col.key"), f: function (m) { return '<span class="mono" style="word-break:break-all">' + O.esc(m.key) + "</span>"; } },
          { t: t("i18n.col.hits"), cls: "num", f: function (m) { return String(m.hits); } },
          { t: t("i18n.col.last"), f: function (m) { return O.esc(m.last.slice(0, 16)); } }
        ], rows: L.missing, empty: ""
      }));
      b.appendChild(mc);
      mc.querySelector("#i_mclear").addEventListener("click", function () {
        O.api("DELETE", "/api/v1/missing-translations").then(function () { O.status(t("i18n.missing_cleared")); load(); }, O.failed);
      });
    }
  }

  function update(l, body, msg) {
    O.api("PUT", "/api/v1/languages/" + encodeURIComponent(l.code), body).then(function (r) {
      if (r && r.default_moved) msg = t("i18n.disabled_moved", { name: l.name, def: r.default_name });
      O.status(msg);
      // the page itself was in that language, or the default changed: show it in the language now in use
      if (body.enabled === false && (l.code === O.boot.lang || (r && r.default_moved))) setTimeout(function () { window.location.reload(); }, 800);
      else load();
    }, O.failed);
  }

  function toggle(l) {
    if (l.enabled) {
      var next = L.languages.filter(function (x) { return x.enabled && x.code !== l.code; })[0];
      if (!next) { O.info(t("i18n.disable"), "<p>" + O.esc(t("i18n.err.last_on")) + "</p>"); return; }
      var msg = l.default ? t("i18n.disable_default_confirm", { name: l.name, def: next.name }) : t("i18n.disable_confirm", { name: l.name });
      O.confirm({ title: t("i18n.disable"), msg: msg, ok: t("i18n.disable") }).then(function (ok) { if (ok) update(l, { enabled: false }, t("i18n.disabled_msg", { name: l.name })); });
    } else if (l.missing) {
      O.info(t("i18n.enable"), "<p>" + O.esc(t("i18n.err.incomplete", { n: l.missing })) + "</p>");
    } else {
      update(l, { enabled: true }, t("i18n.enabled_msg", { name: l.name }));
    }
  }

  function removeLang(l) {
    O.confirm({ title: t("common.delete"), msg: t("i18n.delete_confirm", { name: l.name }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/languages/" + encodeURIComponent(l.code)).then(function () { O.status(t("common.deleted")); load(); }, O.failed);
    });
  }

  function addForm() {
    var box = document.createElement("div");
    box.innerHTML = '<div class="fld"><label>' + O.esc(t("i18n.lang_code")) + '</label><input class="inp mono" name="code" maxlength="20" placeholder="ja-JP"><span class="hint">' + O.esc(t("i18n.code_hint")) + "</span></div>" +
      '<div class="fld"><label>' + O.esc(t("i18n.lang_name")) + '</label><input class="inp" name="name" maxlength="50"><span class="hint">' + O.esc(t("i18n.name_hint")) + '</span></div><div id="i_err" style="color:var(--bad);font-size:12.5px"></div>';
    O.modal({ title: t("i18n.add"), width: 480, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var body = { code: box.querySelector("[name=code]").value.trim(), name: box.querySelector("[name=name]").value.trim() };
      return O.api("POST", "/api/v1/languages", body).then(function (r) { O.status(t("i18n.added")); st.tab = "entries"; st.code = r.code; st.page = 1; st.missing = true; load(); }, function (e) {
        if (e && e.fields) { box.querySelector("#i_err").textContent = e.fields.code || e.fields.name || e.message; return Promise.reject(null); }
        return Promise.reject(e);
      });
    } }] });
  }

  /* ---------- entries ---------- */
  function entries() {
    var b = document.getElementById("i_body");
    var langOpts = L.languages.map(function (l) { return '<option value="' + O.esc(l.code) + '"' + (l.code === st.code ? " selected" : "") + ">" + O.esc(l.name) + "</option>"; }).join("");
    var modOpts = '<option value="">' + O.esc(t("i18n.all_modules")) + "</option>" + L.modules.map(function (m) { return '<option value="' + O.esc(m) + '"' + (m === st.module ? " selected" : "") + ">" + O.esc(modLabel(m)) + "</option>"; }).join("");
    b.innerHTML = '<div class="bar"><label class="muted" style="font-size:12.5px">' + O.esc(t("i18n.target")) + '</label><select id="e_lang">' + langOpts + '</select><select id="e_mod">' + modOpts + "</select>" +
      '<input id="e_kw" placeholder="' + O.esc(t("i18n.search")) + '" value="' + O.esc(st.kw) + '"><label style="font-size:13px"><input type="checkbox" id="e_miss" style="width:auto"' + (st.missing ? " checked" : "") + "> " + O.esc(t("i18n.only_missing")) + "</label>" +
      '<span class="sp"></span><button type="button" class="btn" id="e_exp">' + O.icon("download", 14) + " " + O.esc(t("i18n.export")) + '</button><button type="button" class="btn" id="e_imp">' + O.icon("upload", 14) + " " + O.esc(t("i18n.import")) + "</button>" +
      '<button type="button" class="btn pri" id="e_save">' + O.esc(t("i18n.save", { n: Object.keys(changes).length })) + '</button></div><div class="card"><div class="card-h" id="e_head"></div><div id="e_list"></div><div class="card-b" id="e_pager" style="display:flex;gap:8px;align-items:center"></div></div>';
    var refetch = function () { st.page = 1; fetchPage(); };
    b.querySelector("#e_lang").addEventListener("change", function (e) {
      if (!confirmLeave()) { e.target.value = st.code; return; }
      changes = {}; st.code = e.target.value; refetch(); updateSave();
    });
    b.querySelector("#e_mod").addEventListener("change", function (e) { st.module = e.target.value; refetch(); });
    var timer = null;
    b.querySelector("#e_kw").addEventListener("input", function (e) { clearTimeout(timer); timer = setTimeout(function () { st.kw = e.target.value.trim(); refetch(); }, 350); });
    b.querySelector("#e_miss").addEventListener("change", function (e) { st.missing = e.target.checked; refetch(); });
    b.querySelector("#e_save").addEventListener("click", save);
    b.querySelector("#e_exp").addEventListener("click", function () { window.location.href = O.url("/api/v1/languages/" + encodeURIComponent(st.code) + "/export"); });
    b.querySelector("#e_imp").addEventListener("click", importFile);
    fetchPage();
  }

  function updateSave() {
    var b = document.getElementById("e_save");
    if (b) b.textContent = t("i18n.save", { n: Object.keys(changes).length });
  }

  function fetchPage() {
    O.api("GET", "/api/v1/languages/" + encodeURIComponent(st.code) + "/entries", null, { module: st.module, kw: st.kw, missing: st.missing ? 1 : "", page: st.page }).then(function (d) {
      var lang = L.languages.filter(function (l) { return l.code === st.code; })[0];
      var refName = d.ref ? (L.languages.filter(function (l) { return l.code === d.ref; })[0] || { name: d.ref }).name : "";
      var baseName = (L.languages.filter(function (l) { return l.base; })[0] || { name: L.base }).name;
      document.getElementById("e_head").innerHTML = O.esc(t("i18n.translating", { name: lang ? lang.name : st.code })) + '<span class="r">' + pct({ pct: d.completeness.pct, missing: d.completeness.missing }) + "</span>";
      var box = document.getElementById("e_list");
      box.innerHTML = "";
      var cols = [
        { t: t("i18n.col.key"), f: function (r) { return '<span class="mono" style="font-size:11.5px;word-break:break-all">' + O.esc(r.key) + '</span><div class="muted" style="font-size:11.5px">' + O.esc(modLabel(r.module)) + "</div>"; } },
        { t: t("i18n.col.source") + " · " + baseName, cls: "src", f: function (r) { return O.esc(r.source); } }
      ];
      if (d.ref) cols.push({ t: t("i18n.col.ref") + " · " + refName, cls: "src", f: function (r) { return O.esc(r.ref); } });
      cols.push({ t: t("i18n.col.text") + (lang ? " · " + lang.name : ""), f: function (r) {
        var v = Object.prototype.hasOwnProperty.call(changes, r.key) ? changes[r.key] : r.text;
        return '<textarea class="i18n-tx' + (Object.prototype.hasOwnProperty.call(changes, r.key) ? " chg" : "") + (v.trim() ? "" : " miss") + '" rows="' + Math.min(4, Math.max(1, Math.ceil(r.source.length / 40))) + '" data-key="' + O.esc(r.key) + '" data-orig="' + O.esc(r.text) + '">' + O.esc(v) + "</textarea>";
      } });
      box.appendChild(O.table({ cols: cols, rows: d.rows, empty: t("i18n.none") }));
      box.oninput = function (e) {
        var ta = e.target;
        if (!ta.getAttribute || !ta.getAttribute("data-key")) return;
        var k = ta.getAttribute("data-key");
        if (ta.value === ta.getAttribute("data-orig")) delete changes[k]; else changes[k] = ta.value;
        ta.classList.toggle("chg", Object.prototype.hasOwnProperty.call(changes, k));
        ta.classList.toggle("miss", !ta.value.trim());
        updateSave();
      };
      var pages = Math.max(1, Math.ceil(d.total / d.per_page));
      var pg = document.getElementById("e_pager");
      pg.innerHTML = '<span class="muted" style="font-size:12.5px">' + O.esc(t("i18n.page", { page: d.page, pages: pages, total: d.total })) + '</span><span style="flex:1"></span>' +
        (d.page > 1 ? '<button type="button" class="btn sm" data-pg="-1">' + O.esc(t("i18n.prev")) + "</button>" : "") + (d.page < pages ? '<button type="button" class="btn sm" data-pg="1">' + O.esc(t("i18n.next")) + "</button>" : "");
      pg.onclick = function (e) { var x = e.target.closest ? e.target.closest("[data-pg]") : null; if (x) { st.page += +x.getAttribute("data-pg"); fetchPage(); } };
    }, O.failed);
  }

  function save() {
    var items = Object.keys(changes).map(function (k) { return { key: k, text: changes[k] }; });
    if (!items.length) { O.status(t("i18n.nothing")); return; }
    O.api("PUT", "/api/v1/languages/" + encodeURIComponent(st.code) + "/entries", { items: items }).then(function (r) {
      changes = {};
      O.status(t("i18n.saved", { n: r.saved }));
      load(function () { st.tab = "entries"; });
    }, function (e) {
      if (e && e.fields) {
        var keys = Object.keys(e.fields);
        O.info(t("error.title_refused"), "<p>" + keys.slice(0, 20).map(function (k) { return '<span class="mono">' + O.esc(k) + "</span>: " + O.esc(e.fields[k]); }).join("<br>") + "</p>");
        return;
      }
      O.failed(e);
    });
  }

  function importFile() {
    O.pickFiles({ accept: ".xlsx,.csv" }, function (files) {
      var path = "/api/v1/languages/" + encodeURIComponent(st.code) + "/import";
      O.upload(path, files.slice(0, 1), { run: "0" }).then(function (r) {
        var html = "<p>" + O.esc(t("i18n.import_check", { ok: r.ok, skipped: r.skipped, bad: r.error_count })) + "</p>" + (r.errors.length ? '<div class="tw" style="max-height:260px;overflow:auto"><table class="tbl sm"><tbody>' + r.errors.map(function (x) {
          return "<tr><td>" + O.esc(t("i18n.row", { n: x.row })) + '</td><td class="mono">' + O.esc(x.key) + "</td><td>" + O.esc(x.msg) + "</td></tr>"; }).join("") + "</tbody></table></div>" : "");
        var box = document.createElement("div");
        box.innerHTML = html;
        O.modal({ title: t("i18n.import"), width: 640, body: box, buttons: [{ t: t("common.cancel") }].concat(r.ok ? [{ t: t("i18n.import_run", { n: r.ok }), cls: "pri", fn: function () {
          return O.upload(path, files.slice(0, 1), { run: "1" }).then(function (r2) { O.status(t("i18n.imported", { n: r2.ok })); changes = {}; load(function () { st.tab = "entries"; }); }, O.failed);
        } }] : []) });
      }, O.failed);
    });
  }

  window.addEventListener("beforeunload", function (e) { if (Object.keys(changes).length) { e.preventDefault(); e.returnValue = ""; } });
  load();
})();
