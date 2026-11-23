/* 数据备份 (phase 9): back up now, daily automatic backup, list, download, restore (password again), delete. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  if (!view || view.getAttribute("data-page") !== "backup") return;

  function load() { O.api("GET", "/api/v1/backups").then(draw, O.failed); }

  function kindPill(k) { return O.pill(t("backup.kind." + k), k === "manual" ? "info" : (k === "external" ? "warn" : "")); }

  function draw(d) {
    var s = d.settings;
    view.innerHTML = '<div class="split" style="grid-template-columns:minmax(0,1fr) minmax(300px,380px)"><div class="card"><div class="card-b" style="display:grid;gap:10px">' +
      '<div><button type="button" class="btn pri" id="bk_now">' + O.icon("db", 14) + " " + O.esc(t("backup.now")) + "</button></div>" +
      '<div class="muted" style="font-size:12.5px">' + O.esc(t("backup.what")) + "</div>" +
      '<div style="font-size:12.5px">' + O.esc(t("backup.folder")) + ' <span class="mono">' + O.esc(d.dir) + "</span>" + (d.free !== null ? ' · ' + O.esc(t("backup.free", { size: O.fileSize(d.free) })) : "") + "</div>" +
      '<div class="note warn" style="margin:0">' + O.esc(t("backup.key_note")) + "</div></div></div>" +
      '<div class="card"><div class="card-h">' + O.esc(t("backup.auto")) + '</div><div class="card-b" style="display:grid;gap:10px">' +
      '<label style="display:flex;gap:8px;align-items:center"><span class="switch"><input type="checkbox" id="bk_auto"' + (s.auto ? " checked" : "") + "><span></span></span> " + O.esc(t("backup.auto_on")) + "</label>" +
      '<div class="fld"><label>' + O.esc(t("backup.time")) + '</label><input class="inp mono" id="bk_time" maxlength="5" style="width:90px" value="' + O.esc(s.time) + '"><span class="hint">' + O.esc(t("backup.time_hint")) + "</span></div>" +
      '<div class="fld"><label>' + O.esc(t("backup.keep_days")) + '</label><input class="inp num" id="bk_keep" style="width:90px" value="' + O.esc(String(s.keep_days)) + '"><span class="hint">' + O.esc(t("backup.keep_hint")) + "</span></div>" +
      '<div><button type="button" class="btn" id="bk_save">' + O.esc(t("common.save")) + '</button></div></div></div></div><div class="card" id="bk_list" style="margin-top:12px"></div>';
    view.querySelector("#bk_list").appendChild(O.table({
      cols: [
        { t: t("backup.col.time"), f: function (r) { return O.esc(r.at.slice(0, 16)) + " " + kindPill(r.kind) + '<div class="mono muted" style="font-size:11.5px">' + O.esc(r.name) + "</div>"; } },
        { t: t("backup.col.result"), cls: "wrap", f: function (r) {
          if (r.status === "ok") return O.pill(t("backup.ok"), "ok") + (r.exists ? "" : " " + O.pill(t("backup.gone"), "warn"));
          if (r.status === "running") return O.pill(t("backup.running"), "info");
          return O.pill(t("backup.failed"), "bad") + '<div style="font-size:12px">' + O.esc(r.message) + "</div>";
        } },
        { t: t("backup.col.content"), cls: "wrap", f: function (r) {
          if (r.external || r.status !== "ok") return '<span class="muted">—</span>';
          return O.esc(t("backup.content", { db: O.fileSize(r.db_bytes), n: r.files_count, files: O.fileSize(r.files_bytes) }));
        } },
        { t: t("backup.col.size"), cls: "num", f: function (r) { return r.size ? O.esc(O.fileSize(r.size)) : "—"; } },
        { t: t("backup.col.by"), f: function (r) { return O.esc(r.by || (r.kind === "external" ? "—" : t("backup.system"))) + (r.seconds !== null ? '<div class="muted" style="font-size:12px">' + O.esc(t("backup.seconds", { n: r.seconds })) + "</div>" : ""); } }
      ],
      rows: d.rows, empty: t("backup.empty"),
      acts: function (r) {
        var usable = r.exists && r.status === "ok";
        return [
          usable ? { icon: "download", tip: t("backup.download"), fn: function () { window.location.href = O.url("/api/v1/backups/" + encodeURIComponent(r.name) + "/download"); } } : null,
          usable ? { icon: "restore", tip: t("backup.restore"), fn: function () { restore(r); } } : null,
          r.exists ? { icon: "trash", tip: t("common.delete"), danger: true, fn: function () { remove(r); } } : null
        ];
      }, slots: 3
    }));
    var now = view.querySelector("#bk_now");
    now.addEventListener("click", function () {
      now.disabled = true;
      now.textContent = t("backup.working");
      O.api("POST", "/api/v1/backups", {}).then(function (r) { O.status(t("backup.done", { size: O.fileSize(r.size) })); load(); }, function (e) { O.failed(e); load(); });
    });
    view.querySelector("#bk_save").addEventListener("click", function () {
      O.api("PUT", "/api/v1/backups/settings", { auto: view.querySelector("#bk_auto").checked, time: view.querySelector("#bk_time").value.trim(), keep_days: +view.querySelector("#bk_keep").value })
        .then(function (d2) { O.status(t("common.saved")); draw(d2); }, function (e) {
          if (e && e.fields) { O.status(e.fields.time || e.fields.keep_days || e.message, "err"); return; }
          O.failed(e);
        });
    });
  }

  function remove(r) {
    O.confirm({ title: t("backup.delete"), msg: t("backup.delete_confirm", { name: r.name }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/backups/" + encodeURIComponent(r.name)).then(function () { O.status(t("common.deleted")); load(); }, O.failed);
    });
  }

  function restore(r) {
    O.api("GET", "/api/v1/backups/" + encodeURIComponent(r.name) + "/inspect").then(function (m) {
      var box = document.createElement("div");
      box.innerHTML = '<table class="tbl sm" style="margin-bottom:12px"><tbody>' +
        "<tr><td>" + O.esc(t("backup.col.time")) + "</td><td>" + O.esc(m.created_at.slice(0, 16)) + "</td></tr>" +
        "<tr><td>" + O.esc(t("backup.version")) + "</td><td>" + O.esc(m.version) + (m.version !== m.current ? ' <span class="muted">' + O.esc(t("backup.version_now", { v: m.current })) + "</span>" : "") + "</td></tr>" +
        "<tr><td>" + O.esc(t("backup.col.content")) + "</td><td>" + O.esc(t("backup.inspect", { tables: m.tables, rows: m.rows, n: m.files, files: O.fileSize(m.files_bytes) })) + "</td></tr></tbody></table>" +
        (m.key_ok ? '<div class="note warn">' + O.esc(t("backup.restore_warn")) + "</div>" +
          '<div class="fld" style="margin-top:10px"><label>' + O.esc(t("backup.password")) + '</label><input class="inp" type="password" id="bk_pw" autocomplete="current-password"></div><div id="bk_err" style="color:var(--bad);font-size:12.5px"></div>'
          : '<div class="note bad">' + O.esc(t("backup.err.key")) + "</div>");
      O.modal({ title: t("backup.restore") + " · " + r.name, width: 560, sticky: true, body: box, buttons: [{ t: t("common.cancel") }].concat(m.key_ok ? [{ t: t("backup.restore_go"), cls: "danger", fn: function () {
        var pw = box.querySelector("#bk_pw").value;
        box.querySelector("#bk_err").textContent = t("backup.restoring");
        return O.api("POST", "/api/v1/backups/" + encodeURIComponent(r.name) + "/restore", { password: pw }).then(function () {
          O.info(t("backup.restore"), "<p>" + O.esc(t("backup.restored")) + "</p>");
          setTimeout(function () { window.location.href = O.url("/login"); }, 2500);
        }, function (e) {
          if (e && e.fields && e.fields.password) { box.querySelector("#bk_err").textContent = e.fields.password; return Promise.reject(null); }
          box.querySelector("#bk_err").textContent = (e && e.message) || t("error.server");
          return Promise.reject(null);
        });
      } }] : []) });
    }, O.failed);
  }

  load();
})();
