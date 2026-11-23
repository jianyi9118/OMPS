/* User accounts */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var state = { kw: "", role_id: "", status: "", page: 1 };
  var opts = { roles: [], persons: [], canAdd: false, canEdit: false, canDel: false };

  view.innerHTML = '<div class="bar"><input type="search" id="u_kw" placeholder="' + O.esc(t("user.search")) + '">' +
    '<select id="u_role"><option value="">' + O.esc(t("user.all_roles")) + '</option></select>' +
    '<select id="u_status"><option value="">' + O.esc(t("user.all_status")) + '</option><option value="active">' + O.esc(t("status.active")) + '</option><option value="disabled">' + O.esc(t("status.disabled")) + '</option><option value="locked">' + O.esc(t("status.locked")) + "</option></select>" +
    '<span class="sp"></span><button type="button" class="btn pri" id="u_add" hidden>' + O.icon("plus", 14) + " " + O.esc(t("user.add")) + "</button></div>" +
    '<div class="card" id="u_card"></div>';

  function statusPill(u) {
    if (u.locked) return O.pill(t("status.locked"), "bad");
    if (u.status === "disabled") return O.pill(t("status.disabled"), "");
    if (u.must_change) return O.pill(t("user.must_change"), "warn");
    return O.pill(t("status.active"), "ok");
  }

  function load() {
    O.api("GET", "/api/v1/users", null, { kw: state.kw, role_id: state.role_id, status: state.status, page: state.page }).then(render, O.failed);
  }

  function render(res) {
    var card = document.getElementById("u_card");
    card.innerHTML = "";
    card.appendChild(O.table({
      cols: [
        { t: t("user.field.username"), f: function (r) { return '<span class="mono">' + O.esc(r.username) + "</span>"; } },
        { t: t("user.field.name"), f: function (r) { return O.esc(r.name); } },
        { t: t("user.field.dept"), f: function (r) { return O.esc(r.dept || "—"); } },
        { t: t("user.field.role"), f: function (r) { return O.esc(r.role) + (r.device_admin ? " " + O.pill(t("user.device_admin_short"), "info") : ""); } },
        { t: t("user.field.status"), f: statusPill },
        { t: t("user.field.last_login"), f: function (r) { return '<span class="mono">' + O.esc(r.last_login || "—") + "</span>"; } }
      ],
      rows: res.rows,
      acts: function (r) {
        var a = [];
        if (opts.canEdit) {
          a.push({ icon: "key", tip: t("user.reset_password"), fn: resetPassword });
          a.push(r.locked ? { icon: "unlock", tip: t("user.unlock"), fn: unlock } : null);
          a.push(r.id !== O.boot.user.id ? { icon: "logout", tip: t("user.force_logout"), fn: forceLogout } : null);
          a.push({ icon: "edit", tip: t("common.edit"), fn: edit });
        }
        if (opts.canDel) a.push(r.id !== O.boot.user.id ? { icon: "trash", tip: t("common.delete"), danger: true, fn: del } : null);
        return a;
      }
    }));
    card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
    card.insertAdjacentHTML("beforeend", O.legend([["key", t("user.reset_password")], ["unlock", t("user.unlock")], ["logout", t("user.force_logout")], ["edit", t("common.edit")], ["trash", t("common.delete")]]));
  }

  function personOpts() {
    return opts.persons.map(function (p) { return [p.id, p.name + " · " + p.no + (p.dept ? " · " + p.dept : "")]; });
  }

  function formFields(u) {
    return [
      { name: "username", label: t("user.field.username"), req: true, max: 50, readonly: !!u, hint: u ? "" : t("user.username_hint"), autocomplete: "off" },
      { name: "role_id", label: t("user.field.role"), type: "select", req: true, int: true, opts: opts.roles.map(function (r) { return [r.id, r.name]; }) },
      { name: "person_id", label: t("user.field.person"), type: "select", int: true, empty: t("user.no_person"), opts: personOpts(), hint: t("user.person_hint") },
      { name: "display_name", label: t("user.field.display_name"), max: 50, hint: t("user.display_name_hint") },
      { name: "is_device_admin", label: t("user.field.device_admin"), type: "checkbox", span2: true },
      { name: "status", label: t("user.field.status"), type: "radio", hide: !u, req: true, opts: [["active", t("status.active")], ["disabled", t("status.disabled")]] }
    ];
  }

  function showTemp(title, username, pwd) {
    O.info(title, '<p>' + O.esc(t("user.temp_password_intro", { name: username })) + '</p><p style="font-size:20px" class="mono"><b>' + O.esc(pwd) + "</b></p><div class=\"note warn\">" + O.esc(t("user.temp_password_note")) + "</div>");
  }

  function add() {
    loadOptions(0).then(function () {
      var f = O.form(formFields(null), { role_id: (opts.roles[opts.roles.length - 1] || {}).id });
      O.modal({ title: t("user.add"), width: 620, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
        var v = f.values();
        return O.submit(f, O.api("POST", "/api/v1/users", v)).then(function (r) {
          load();
          showTemp(t("user.created"), v.username, r.temp_password);
        });
      } }] });
    });
  }

  function edit(u) {
    loadOptions(u.person_id || 0).then(function () {
      var f = O.form(formFields(u), { username: u.username, role_id: u.role_id, person_id: u.person_id, display_name: u.display_name, is_device_admin: u.device_admin, status: u.status });
      O.modal({ title: t("user.edit") + " · " + u.username, width: 620, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
        return O.submit(f, O.api("PUT", "/api/v1/users/" + u.id, f.values())).then(function () { O.status(t("common.saved")); load(); });
      } }] });
    });
  }

  function resetPassword(u) {
    O.confirm({ title: t("user.reset_password"), msg: t("user.reset_confirm", { name: u.username }), ok: t("user.reset_password") }).then(function (ok) {
      if (!ok) return;
      O.api("POST", "/api/v1/users/" + u.id + "/reset-password", {}).then(function (r) { load(); showTemp(t("user.reset_password"), u.username, r.temp_password); }, O.failed);
    });
  }

  function unlock(u) {
    O.api("POST", "/api/v1/users/" + u.id + "/unlock", {}).then(function () { O.status(t("user.unlocked", { name: u.username })); load(); }, O.failed);
  }

  function forceLogout(u) {
    O.confirm({ title: t("user.force_logout"), msg: t("user.force_logout_confirm", { name: u.username }), ok: t("user.force_logout") }).then(function (ok) {
      if (!ok) return;
      O.api("POST", "/api/v1/users/" + u.id + "/force-logout", {}).then(function () { O.status(t("user.logged_out", { name: u.username })); }, O.failed);
    });
  }

  function del(u) {
    O.confirm({ title: t("common.delete"), msg: t("user.delete_confirm", { name: u.username }), ok: t("common.delete"), danger: true }).then(function (ok) {
      if (!ok) return;
      O.api("DELETE", "/api/v1/users/" + u.id).then(function () { O.status(t("common.deleted")); load(); }, O.failed);
    });
  }

  function loadOptions(includePerson) {
    return O.api("GET", "/api/v1/users/options", null, { include_person: includePerson || "" }).then(function (o) { opts = o; return o; }, function (e) { O.failed(e); throw e; });
  }

  // The row buttons depend on opts.canEdit / canDel, so the list is drawn only after the options are in
  // (loading both at once sometimes drew the rows before the permissions arrived, without any buttons).
  loadOptions(0).then(function () {
    var sel = document.getElementById("u_role");
    opts.roles.forEach(function (r) { sel.insertAdjacentHTML("beforeend", '<option value="' + r.id + '">' + O.esc(r.name) + "</option>"); });
    document.getElementById("u_add").hidden = !opts.canAdd;
  }).then(load, load);
  document.getElementById("u_add").addEventListener("click", add);
  document.getElementById("u_kw").addEventListener("input", O.debounce(function (e) { state.kw = e.target.value.trim(); state.page = 1; load(); }, 350));
  document.getElementById("u_role").addEventListener("change", function (e) { state.role_id = e.target.value; state.page = 1; load(); });
  document.getElementById("u_status").addEventListener("change", function (e) { state.status = e.target.value; state.page = 1; load(); });
})();
