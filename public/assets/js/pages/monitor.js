/* 使用与监测 (phase 6): usage records · hardware anomalies · collector clients · collection scope */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t, D = O.device;
  var view = document.getElementById("view");
  var TABS = ["usage", "anomaly", "clients", "scope"];
  var state = { tab: TABS.indexOf(O.qs("tab")) >= 0 ? O.qs("tab") : "usage", kw: "", page: 1, astatus: "pending", cstatus: "active" };
  var focusId = +O.qs("id") || 0;
  var M = null;

  function shell() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="mo_tabs">' + TABS.map(function (k) {
      return '<button type="button" data-tab="' + k + '">' + O.esc(t("device.mon." + k)) + '<em class="mbadge" data-mo-badge="' + k + '" hidden></em></button>'; }).join("") +
      '</div><div id="mo_body" style="display:grid;gap:12px"></div>';
    document.getElementById("mo_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { state.tab = b.getAttribute("data-tab"); state.page = 1; render(); }
    });
  }

  function render() {
    var bs = document.querySelectorAll("#mo_tabs button");
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle("on", bs[i].getAttribute("data-tab") === state.tab);
    ({ usage: usage, anomaly: anomalies, clients: clients, scope: scope })[state.tab]();
  }

  function body(html) {
    var b = document.getElementById("mo_body");
    b.innerHTML = html;
    return b;
  }

  /* ---------- usage records ---------- */
  function usage() {
    body('<div class="bar"><input type="search" id="mo_kw" placeholder="' + O.esc(t("device.mon.usage_search")) + '" value="' + O.esc(state.kw) + '"><span class="sp"></span><span class="muted" style="font-size:12.5px">' +
      O.esc(t("device.mon.usage_hint")) + '</span></div><div class="card" id="mo_card"></div>');
    document.getElementById("mo_kw").addEventListener("input", O.debounce(function (e) { state.kw = e.target.value.trim(); state.page = 1; loadUsage(); }, 350));
    loadUsage();
  }

  function loadUsage() {
    O.api("GET", "/api/v1/device-usage", null, { kw: state.kw, page: state.page }).then(function (res) {
      var card = document.getElementById("mo_card");
      if (!card) return;
      card.innerHTML = "";
      card.appendChild(O.table({ cols: [
        { t: t("device.mon.time"), f: function (r) { return '<span class="mono">' + O.esc(r.at.slice(0, 16)) + "</span>"; } },
        { t: t("device.col.device"), cls: "wrap", f: function (r) { return '<a class="lk" href="#" data-dev="' + r.device_id + '">' + O.esc(r.asset_no + " " + r.device) + "</a>"; } },
        { t: t("device.mon.event"), f: function (r) { return O.esc(t("device.event." + r.event)); } },
        { t: t("device.coll.computer"), f: function (r) { return O.esc(r.computer_name || "—"); } },
        { t: t("device.mon.ip"), f: function (r) { return '<span class="mono">' + O.esc(r.ip) + "</span>"; } },
        { t: t("device.mon.hw"), f: function (r) { return r.changed ? O.pill(t("device.mon.hw_changed"), "warn") : '<span class="muted">' + O.esc(t("device.mon.hw_same")) + "</span>"; } }
      ], rows: res.rows, empty: t("device.mon.usage_empty") }));
      card.appendChild(O.pager(res, function (p) { state.page = p; loadUsage(); }));
      card.onclick = function (e) { var a = e.target.closest ? e.target.closest("[data-dev]") : null; if (a) { e.preventDefault(); D.detail(+a.getAttribute("data-dev")); } };
    }, O.failed);
  }

  /* ---------- anomalies ---------- */
  function partText(x) { return D.ctype(x.ctype) + " · " + [x.model, x.serial_no, x.spec].filter(Boolean).join(" · "); }

  function anomalies() {
    body('<div class="bar"><div class="chips">' + [["pending", t("device.mon.a_pending")], ["done", t("device.mon.a_done")]].map(function (c) {
      return '<button type="button" class="chip' + (state.astatus === c[0] ? " on" : "") + '" data-as="' + c[0] + '">' + O.esc(c[1]) + "</button>"; }).join("") +
      '</div><span class="sp"></span><span class="muted" style="font-size:12.5px">' + O.esc(t("device.mon.a_hint")) + '</span></div><div class="card" id="mo_card"></div>');
    document.querySelector("#mo_body .chips").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-as]") : null;
      if (b) { state.astatus = b.getAttribute("data-as"); state.page = 1; anomalies(); }
    });
    loadAnomalies();
  }

  function loadAnomalies() {
    O.api("GET", "/api/v1/device-anomalies", null, { status: state.astatus, page: state.page }).then(function (res) {
      badge("anomaly", res.pending);
      var card = document.getElementById("mo_card");
      if (!card) return;
      card.innerHTML = "";
      var pend = state.astatus === "pending";
      var tw = O.table({ cols: [
        { t: t("device.mon.found_at"), f: function (a) { return '<span class="mono">' + O.esc(a.at.slice(0, 16)) + "</span>"; } },
        { t: t("device.col.device"), cls: "wrap", f: function (a) { return '<a class="lk" href="#" data-dev="' + a.device_id + '">' + O.esc(a.asset_no + " " + a.device) + "</a>"; } },
        { t: t("device.mon.changes"), cls: "wrap", f: function (a) {
          return a.removed.map(function (x) { return '<div style="color:var(--bad)">− ' + O.esc(partText(x)) + "</div>"; }).join("") +
            a.added.map(function (x) { return '<div style="color:var(--ok)">+ ' + O.esc(partText(x)) + "</div>"; }).join("");
        } },
        { t: t("device.mon.result"), f: function (a) {
          if (a.status === "pending") return O.pill(t("device.anomaly.pending"), "warn");
          if (a.note === "#superseded") return O.pill(t("device.anomaly.superseded"));
          return O.pill(t("device.anomaly." + a.status), a.status === "confirmed" ? "ok" : "") + '<div class="muted" style="font-size:12px">' + O.esc(a.handler + " · " + a.handled_at.slice(0, 16) + (a.note ? " · " + a.note : "")) + "</div>";
        } }
      ], rows: res.rows, empty: t(pend ? "device.mon.a_empty" : "device.mon.a_none"),
      acts: pend && M.caps.edit ? function () { return [{ icon: "check", tip: t("device.anomaly.confirm"), pri: true, fn: confirmA }, { icon: "x", tip: t("device.anomaly.ignore"), fn: ignoreA }]; } : null });
      card.appendChild(tw);
      card.appendChild(O.pager(res, function (p) { state.page = p; loadAnomalies(); }));
      if (pend && M.caps.edit) card.insertAdjacentHTML("beforeend", O.legend([["check", t("device.anomaly.confirm")], ["x", t("device.anomaly.ignore")]]));
      card.onclick = function (e) { var a = e.target.closest ? e.target.closest("[data-dev]") : null; if (a) { e.preventDefault(); D.detail(+a.getAttribute("data-dev"), { tab: 1 }); } };
      if (focusId) {
        var i = -1;
        res.rows.forEach(function (r, k) { if (r.id === focusId) i = k; });
        if (i >= 0) { O.focusBar(res.rows[i].asset_no + " " + res.rows[i].device); O.flash(tw.querySelectorAll("tbody tr")[i]); }
        focusId = 0;
      }
    }, O.failed);
  }

  function noteDialog(title, hint, ok, cls, url) {
    var f = O.form([{ name: "note", label: t("device.field.note"), type: "textarea", rows: 2, max: 200, hint: hint }], {});
    O.modal({ title: title, width: 520, body: f.el, buttons: [{ t: t("common.cancel") }, { t: ok, cls: cls, fn: function () {
      return O.submit(f, O.api("POST", url, f.values())).then(function () { O.status(t("common.saved")); O.pulse(); loadAnomalies(); });
    } }] });
  }
  function confirmA(a) { noteDialog(t("device.anomaly.confirm"), t("device.anomaly.confirm_hint"), t("device.anomaly.confirm"), "pri", "/api/v1/device-anomalies/" + a.id + "/confirm"); }
  function ignoreA(a) { noteDialog(t("device.anomaly.ignore"), t("device.anomaly.ignore_hint"), t("device.anomaly.ignore"), "", "/api/v1/device-anomalies/" + a.id + "/ignore"); }

  /* ---------- collector clients ---------- */
  function clients() {
    var files = ["install.cmd", "omps-collector.ps1", "uninstall.cmd", "readme.txt"];
    body('<div class="note">' + O.esc(t("device.mon.clients_intro")) + (M.caps.edit ? '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">' + files.map(function (f) {
        return '<a class="btn sm" href="' + O.esc(O.url("/api/v1/collector-client/" + f)) + '">' + O.icon("download", 14) + " " + O.esc(f) + "</a>"; }).join("") + "</div>" : "") + "</div>" +
      '<div class="bar"><div class="chips">' + [["active", t("device.coll.st.active")], ["pending", t("device.coll.st.pending")], ["revoked", t("device.coll.st.revoked")]].map(function (c) {
        return '<button type="button" class="chip' + (state.cstatus === c[0] ? " on" : "") + '" data-cs="' + c[0] + '">' + O.esc(c[1]) + "</button>"; }).join("") + '</div></div><div class="card" id="mo_card"></div>');
    document.querySelector("#mo_body .chips").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-cs]") : null;
      if (b) { state.cstatus = b.getAttribute("data-cs"); state.page = 1; clients(); }
    });
    O.api("GET", "/api/v1/device-collectors", null, { status: state.cstatus, page: state.page }).then(function (res) {
      var card = document.getElementById("mo_card");
      card.appendChild(O.table({ cols: [
        { t: t("device.col.device"), cls: "wrap", f: function (c) { return '<a class="lk" href="#" data-dev="' + c.device_id + '">' + O.esc(c.asset_no + " " + c.device) + "</a>"; } },
        { t: t("device.coll.computer"), f: function (c) { return O.esc(c.computer_name || "—") + (c.os ? '<div class="muted" style="font-size:12px">' + O.esc(c.os) + "</div>" : ""); } },
        { t: t("device.coll.version"), f: function (c) { return O.esc(c.version || "—"); } },
        { t: state.cstatus === "pending" ? t("device.coll.expires") : t("device.coll.last_seen"), f: function (c) {
          return '<span class="mono">' + O.esc(state.cstatus === "pending" ? c.enroll_expires.slice(0, 16) : (c.last_seen ? c.last_seen.slice(0, 16) + " · " + c.last_ip : "—")) + "</span>"; } }
      ], rows: res.rows, empty: t("device.coll.list_empty"),
      acts: M.caps.edit && state.cstatus !== "revoked" ? function () { return [{ icon: "ban", tip: t("device.coll.revoke"), danger: true, fn: revoke }]; } : null }));
      card.appendChild(O.pager(res, function (p) { state.page = p; clients(); }));
      card.onclick = function (e) { var a = e.target.closest ? e.target.closest("[data-dev]") : null; if (a) { e.preventDefault(); D.detail(+a.getAttribute("data-dev"), { tab: 6 }); } };
    }, O.failed);
  }

  function revoke(c) {
    O.confirm({ title: t("device.coll.revoke"), msg: t("device.coll.revoke_confirm", { device: c.asset_no + " " + c.device }), danger: true, ok: t("device.coll.revoke") }).then(function (ok) {
      if (ok) O.api("POST", "/api/v1/device-collectors/" + c.id + "/revoke", {}).then(function () { O.status(t("device.coll.revoked")); clients(); }, O.failed);
    });
  }

  /* ---------- collection scope ---------- */
  function scope() {
    O.api("GET", "/api/v1/collect-scope").then(function (s) {
      var b = body('<div class="card" style="max-width:760px"><div class="card-h">' + O.esc(t("device.mon.scope")) + '</div><div class="card-b" style="display:grid;gap:12px">' +
        '<p style="margin:0">' + O.esc(t("device.mon.scope_intro")) + "</p>" +
        '<div style="display:grid;gap:6px">' + s.all.map(function (k) {
          return '<label class="opts"><input type="checkbox" data-item="' + k + '" style="width:auto"' + (s.items.indexOf(k) >= 0 ? " checked" : "") + (M.caps.edit ? "" : " disabled") + "> " +
            "<b>" + O.esc(t("device.item." + k)) + '</b> <span class="muted" style="font-size:12.5px">' + O.esc(t("device.item_desc." + k)) + "</span></label>";
        }).join("") + "</div>" +
        '<div class="note warn">' + O.esc(t("device.coll.boundary")) + "</div>" +
        (M.caps.edit ? '<div><button type="button" class="btn pri" id="sc_save">' + O.esc(t("common.save")) + "</button></div>" : "") + "</div></div>");
      var sv = b.querySelector("#sc_save");
      if (sv) sv.addEventListener("click", function () {
        var items = Array.prototype.map.call(b.querySelectorAll("input[data-item]:checked"), function (i) { return i.getAttribute("data-item"); });
        O.api("PUT", "/api/v1/collect-scope", { items: items }).then(function () { O.status(t("device.mon.scope_saved")); }, O.failed);
      });
    }, O.failed);
  }

  function badge(k, n) {
    var el = document.querySelector('[data-mo-badge="' + k + '"]');
    if (el) { el.textContent = n; el.hidden = !n; }
  }

  D.meta().then(function (m) {
    M = m;
    shell();
    render();
    if (state.tab !== "anomaly") O.api("GET", "/api/v1/device-anomalies", null, { status: "pending", size: 5 }).then(function (r) { badge("anomaly", r.pending); });
  }, O.failed);
})();
