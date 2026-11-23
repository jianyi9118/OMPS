/* Roles & permissions (super admin) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var data = null, selId = null;

  function scopeName(s) { return t("scope." + s); }

  function load(keepSel) {
    O.api("GET", "/api/v1/roles").then(function (r) {
      data = r;
      if (!keepSel || !find(selId)) selId = r.roles.length ? r.roles[0].id : null;
      render();
    }, O.failed);
  }

  function find(id) {
    return data ? data.roles.filter(function (r) { return r.id === id; })[0] : null;
  }

  function render() {
    var nb = data.roles.filter(function (r) { return r.builtin; }).length;
    view.innerHTML = '<div class="split" style="grid-template-columns:minmax(330px,420px) minmax(0,1fr)">' +
      '<div class="card"><div class="card-h">' + O.esc(t("role.list_title")) + ' <span class="muted" style="font-weight:400">· ' + O.esc(t("role.count", { builtin: nb, custom: data.roles.length - nb })) +
      '</span><span class="r"><button type="button" class="btn pri" id="r_add">' + O.icon("plus", 14) + " " + O.esc(t("role.add")) + "</button></span></div><div id=\"r_list\"></div></div>" +
      '<div class="card" id="r_matrix"></div></div>';
    var list = document.getElementById("r_list");
    list.appendChild(O.table({
      cols: [
        { t: t("role.field.name"), f: function (r) {
          return '<button type="button" class="lk" data-sel="' + r.id + '">' + O.esc(r.name) + "</button> " + (r.builtin ? O.pill(t("role.builtin"), "info") : O.pill(t("role.custom"), "")) +
            (r.description ? '<div class="muted" style="font-size:12px;white-space:normal">' + O.esc(r.description) + "</div>" : "");
        } },
        { t: t("role.users"), cls: "num", f: function (r) { return String(r.users); } }
      ],
      rows: data.roles,
      acts: function (r) {
        return [
          { icon: "eye", tip: t("role.view_perms"), fn: function () { selId = r.id; render(); } },
          { icon: "copy", tip: t("role.copy"), fn: function () { roleForm(null, r); } },
          { icon: "edit", tip: t("common.edit"), fn: function () { roleForm(r, null); } },
          r.builtin ? null : { icon: "trash", tip: t("common.delete"), danger: true, fn: function () { del(r); } }
        ];
      }
    }));
    list.insertAdjacentHTML("beforeend", O.legend([["eye", t("role.view_perms")], ["copy", t("role.copy")], ["edit", t("common.edit")], ["trash", t("role.delete_custom_only")]]));
    var rows = list.querySelectorAll("tbody tr");
    data.roles.forEach(function (r, i) { if (r.id === selId && rows[i]) rows[i].classList.add("rsel"); });
    list.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-sel]") : null;
      if (b) { selId = +b.getAttribute("data-sel"); render(); }
    });
    document.getElementById("r_add").addEventListener("click", function () { roleForm(null, null); });
    renderMatrix();
  }

  function renderMatrix() {
    var box = document.getElementById("r_matrix");
    var r = find(selId);
    if (!r) { box.innerHTML = ""; return; }
    var lock = r.code === "super";
    var acts = data.matrix.actions;
    var head = "<tr><th>" + O.esc(t("role.module")) + "</th>" + acts.map(function (a) { return "<th>" + O.esc(a.title) + "</th>"; }).join("") + "</tr>";
    var body = data.matrix.groups.map(function (g) {
      return "<tr><td>" + O.esc(g.title) + "</td>" + acts.map(function (a) {
        var code = g.codes[a.action];
        if (!code) return '<td class="muted">—</td>';
        var on = lock || r.perms.indexOf(code) >= 0;
        return '<td><input type="checkbox" data-p="' + O.esc(code) + '"' + (on ? " checked" : "") + (lock ? " disabled" : "") + ' aria-label="' + O.esc(g.title + " " + a.title) + '"></td>';
      }).join("") + "</tr>";
    }).join("");
    box.innerHTML = '<div class="card-h">' + O.esc(r.name) + ' <span class="muted" style="font-weight:400">· ' + O.esc(t("role.users_n", { n: r.users })) + " · " + O.esc(t("role.default_scope")) + O.esc(scopeName(r.scope)) +
      '</span><span class="r">' + (lock ? "" : '<button type="button" class="btn pri" id="r_save">' + O.esc(t("role.save_perms")) + "</button>") + "</span></div>" +
      '<div class="card-b"><div class="note ' + (lock ? "" : "warn") + '" style="margin-bottom:10px">' + O.esc(lock ? t("role.super_note") : t("role.sensitive_note")) + "</div>" +
      '<div class="tw"><table class="tbl matrix"><thead>' + head + "</thead><tbody>" + body + "</tbody></table></div>" +
      '<div class="note" style="margin-top:10px">' + O.esc(t("role.staff_note")) + "</div>" +
      '<p class="muted">' + O.esc(t("role.api_note")) + "</p></div>";
    var save = document.getElementById("r_save");
    if (save) save.addEventListener("click", function () {
      var codes = [];
      var cbs = box.querySelectorAll("input[data-p]");
      for (var i = 0; i < cbs.length; i++) if (cbs[i].checked) codes.push(cbs[i].getAttribute("data-p"));
      O.api("PUT", "/api/v1/roles/" + r.id + "/permissions", { codes: codes }).then(function () { O.status(t("role.perms_saved")); load(true); }, O.failed);
    });
  }

  function roleForm(r, copyOf) {
    var isNew = !r;
    var builtin = r && r.builtin;
    var fields = [];
    data.languages.forEach(function (l) {
      fields.push({ name: "name_" + l.code, label: t("role.field.name") + " (" + l.name + ")", req: true, max: 30, readonly: builtin, hint: builtin ? t("role.builtin_name_fixed") : "" });
    });
    fields.push({ name: "description", label: t("role.field.description"), max: 200, span2: true });
    fields.push({ name: "scope", label: t("role.field.scope"), type: "select", req: true, readonly: r && r.code === "super",
      opts: (r && r.code === "super" ? [["all", t("scope.all")]] : [["self", t("scope.self")], ["dept", t("scope.dept")], ["unit", t("scope.unit")]]) });
    if (isNew) {
      fields.push({ name: "template_id", label: t("role.field.template"), type: "select", req: true, int: true, readonly: !!copyOf,
        opts: data.roles.map(function (x) { return [x.id, x.name]; }), hint: t("role.template_hint") });
    }
    var vals = { description: r ? r.description : (copyOf ? copyOf.description : ""), scope: r ? r.scope : (copyOf ? copyOf.scope : "self") };
    data.languages.forEach(function (l) {
      var v = "";
      if (r) v = r.builtin ? r.name : (r.names[l.code] || "");
      else if (copyOf) v = (copyOf.builtin ? copyOf.name : (copyOf.names[l.code] || copyOf.name)) + t("role.copy_suffix");
      vals["name_" + l.code] = v;
    });
    if (isNew) {
      var member = data.roles.filter(function (x) { return x.code === "member"; })[0];
      vals.template_id = copyOf ? copyOf.id : (member ? member.id : "");
      if (copyOf && vals.scope === "all") vals.scope = "unit";
    }
    var f = O.form(fields, vals);
    O.modal({ title: isNew ? (copyOf ? t("role.copy") : t("role.add")) : t("role.edit"), width: 580, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var v = f.values();
      var body = { description: v.description, scope: v.scope, names: {} };
      data.languages.forEach(function (l) { body.names[l.code] = v["name_" + l.code]; });
      if (isNew) body.template_id = copyOf ? copyOf.id : v.template_id;
      var p = isNew ? O.api("POST", "/api/v1/roles", body) : O.api("PUT", "/api/v1/roles/" + r.id, body);
      return O.submit(f, p).then(function (res) {
        if (isNew && res && res.id) selId = res.id;
        O.status(t("common.saved"));
        load(true);
      });
    } }] });
  }

  function del(r) {
    O.confirm({ title: t("role.delete"), msg: t("role.delete_confirm", { name: r.name }), ok: t("common.delete"), danger: true }).then(function (ok) {
      if (!ok) return;
      O.api("DELETE", "/api/v1/roles/" + r.id).then(function () { O.status(t("common.deleted")); load(false); }, function (err) {
        if (err.status === 409) {
          O.info(t("role.cannot_delete"), "<p>" + O.esc(err.message) + '</p><p><a class="btn" href="' + O.esc(O.url("/system/users")) + '">' + O.esc(t("role.go_users")) + "</a></p>");
        } else O.failed(err);
      });
    });
  }

  load(false);
})();
