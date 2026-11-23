/* Own profile: view and edit one's own record (fields follow their policy), my change requests */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var page = 1;

  function statusPill(s) {
    return O.pill(t("review.status." + s), { pending: "warn", approved: "ok", returned: "bad", withdrawn: "" }[s]);
  }

  function render(data) {
    if (data.none) {
      view.innerHTML = '<div class="card"><div class="card-b muted" style="padding:40px;text-align:center">' + O.esc(t("profile.no_record")) + "</div></div>";
      return;
    }
    var p = data.profile;
    var photo = (data.files || []).filter(function (f) { return f.group === "photo" && f.id === p.photo_file_id; })[0];
    view.innerHTML = '<div class="card"><div class="card-b" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">' +
      '<div class="thumb">' + (photo ? '<img alt="" src="' + O.esc(O.url("/api/v1/files/" + photo.relation_id, { inline: 1 })) + '">' : O.esc(t("person.no_photo"))) + "</div>" +
      '<div style="flex:1;min-width:200px"><div style="font-size:18px;font-weight:500">' + O.esc(p.name) + '</div><div class="muted">' + O.esc(p.staff_no + " · " + (p.dept || t("staff.no_dept")) + (p.position ? " · " + p.position : "")) + "</div>" +
      (data.pending && data.pending.length ? '<div class="note warn" style="margin-top:8px">' + O.esc(t("person.pending_note", { n: data.pending.length })) + "</div>" : "") + "</div>" +
      '<div style="display:flex;gap:8px"><button type="button" class="btn" id="pf_view">' + O.icon("eye", 14) + " " + O.esc(t("profile.view")) + '</button><button type="button" class="btn pri" id="pf_edit">' + O.icon("edit", 14) + " " + O.esc(t("profile.edit")) + "</button></div></div>" +
      '<div class="card-b muted" style="padding-top:0;font-size:12.5px">' + O.esc(t("profile.rules")) + "</div></div>" +
      '<div class="card"><div class="card-h">' + O.esc(t("profile.my_requests")) + '</div><div id="pf_reqs"></div></div>';
    document.getElementById("pf_view").addEventListener("click", function () { O.person.open(p.id, { self: true, onSaved: load }); });
    document.getElementById("pf_edit").addEventListener("click", function () { O.person.open(p.id, { self: true, edit: true, onSaved: load }); });
    loadRequests();
  }

  function loadRequests() {
    O.api("GET", "/api/v1/me/requests", null, { page: page }).then(function (res) {
      var box = document.getElementById("pf_reqs");
      box.innerHTML = "";
      box.appendChild(O.table({
        cols: [
          { t: t("review.submitted_at"), f: function (r) { return '<span class="mono">' + O.esc(r.submitted_at) + "</span>"; } },
          { t: t("review.content"), cls: "wrap", f: function (r) { return O.person.describe(r); } },
          { t: t("review.status_col"), f: function (r) { return statusPill(r.status) + (r.comment ? '<div class="muted" style="font-size:12px;white-space:normal">' + O.esc(r.comment) + "</div>" : ""); } },
          { t: t("review.reviewer"), f: function (r) { return O.esc(r.reviewer || "—") + (r.reviewed_at ? '<div class="muted mono" style="font-size:12px">' + O.esc(r.reviewed_at) + "</div>" : ""); } }
        ],
        rows: res.rows, empty: t("profile.no_requests"),
        acts: function (r) { return [r.status === "pending" ? { icon: "undo", tip: t("profile.withdraw"), fn: withdraw } : null]; }
      }));
      box.appendChild(O.pager(res, function (n) { page = n; loadRequests(); }));
    }, O.failed);
  }

  function withdraw(r) {
    O.confirm({ title: t("profile.withdraw"), msg: t("profile.withdraw_confirm"), ok: t("profile.withdraw") }).then(function (ok) {
      if (!ok) return;
      O.api("POST", "/api/v1/me/requests/" + r.id + "/withdraw", {}).then(function () { O.status(t("profile.withdrawn")); load(); }, O.failed);
    });
  }

  function load() {
    O.api("GET", "/api/v1/me/profile").then(render, O.failed);
  }

  O.person.meta().then(load, O.failed);
})();
