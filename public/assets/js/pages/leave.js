/* 我的事务 → 我的请假: apply, change or withdraw while waiting, see the decision */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var STATUS_PILL = { pending: "info", approved: "ok", rejected: "bad", withdrawn: "" };
  var st = { kind: "", status: "", page: 1, only: 0 };
  var data = null;
  // jump from a notification: flash that row (design §17)
  var focusId = +O.qs("id") || 0;

  function dates(r) { return r.start === r.end ? r.start : r.start + " – " + r.end; }

  view.innerHTML = '<div class="bar" id="ml_bar"><select id="ml_status"><option value="">' + O.esc(t("leave.all_statuses")) + "</option>" +
    ["pending", "approved", "rejected", "withdrawn"].map(function (s) { return '<option value="' + s + '">' + O.esc(t("leave.status." + s)) + "</option>"; }).join("") + "</select>" +
    '<span class="sp"></span><button type="button" class="btn pri" id="ml_add">' + O.icon("plus", 14) + " " + O.esc(t("leave.apply")) + "</button></div>" +
    '<div class="card" id="ml_card"></div>';
  var bar = document.getElementById("ml_bar");
  bar.insertBefore(O.filters({ state: st, onChange: function () { st.only = 0; load(); }, period: t("leave.period") }), bar.querySelector(".sp"));
  document.getElementById("ml_status").addEventListener("change", function (e) { st.status = e.target.value; st.page = 1; st.only = 0; load(); });
  document.getElementById("ml_add").addEventListener("click", function () { form(null); });

  function load() {
    var q = O.filterQuery(st);
    q.status = st.status;
    q.page = st.page;
    q.id = st.only || "";
    O.api("GET", "/api/v1/leave/mine", null, q).then(function (res) {
      // an account without a personnel record (e.g. the system administrator) has no leave of its own
      if (res.no_person) { view.innerHTML = '<div class="note">' + O.esc(t("leave.err.no_person")) + "</div>"; return; }
      data = res;
      render();
      if (!focusId) return;
      var r = res.rows.filter(function (x) { return x.id === focusId; })[0];
      if (r) { focusId = 0; O.focusBar(t("leave.located_mine", { kind: t("leave.kind." + r.kind), dates: dates(r) })); O.flashRow(r.id); }
      else if (!st.only) { st.only = focusId; st.page = 1; load(); }
      else focusId = 0;
    }, O.failed);
  }

  function render() {
    var card = document.getElementById("ml_card");
    card.innerHTML = "";
    card.appendChild(O.table({
      cols: [
        { t: t("leave.field.kind"), f: function (r) { return O.esc(t("leave.kind." + r.kind)); } },
        { t: t("leave.col.dates"), f: function (r) { return '<span class="mono">' + O.esc(dates(r)) + "</span>"; } },
        { t: t("leave.col.days"), f: function (r) { return O.esc(t("leave.days", { n: r.days, w: r.workdays })); } },
        { t: t("leave.field.note"), cls: "wrap", f: function (r) { return O.esc(r.note || "—"); } },
        { t: t("leave.col.status"), cls: "wrap", f: function (r) {
          var who = r.applied ? r.reviewer : t("leave.recorded_by", { name: r.by });
          return O.pill(t("leave.status." + r.status), STATUS_PILL[r.status]) +
            (r.status === "approved" || r.status === "rejected" ? '<div class="muted" style="font-size:12px">' + O.esc((who ? who + " · " : "") + r.reviewed_at) + "</div>" : "") +
            (r.comment ? '<div style="font-size:12px">' + O.esc(t("leave.reason_is", { reason: r.comment })) + "</div>" : "");
        } },
        { t: t("leave.col.applied_at"), f: function (r) { return '<span class="mono">' + O.esc(r.at) + "</span>"; } }
      ],
      rows: data.rows, empty: t("leave.empty_mine"), slots: 2,
      acts: function (r) {
        return r.status === "pending" ? [{ icon: "edit", tip: t("common.edit"), fn: function (x) { form(x); } }, { icon: "undo", tip: t("leave.withdraw"), danger: true, fn: withdraw }] : [];
      }
    }));
    card.appendChild(O.pager(data, function (p) { st.page = p; load(); }));
    card.insertAdjacentHTML("beforeend", '<div class="card-b muted" style="font-size:12.5px">' + O.esc(t(data.self_approves ? "leave.mine_hint_direct" : "leave.mine_hint")) + "</div>");
  }

  function form(r) {
    var f = O.form([
      { name: "kind", label: t("leave.field.kind"), type: "select", req: true, span2: true, opts: data.kinds.map(function (k) { return [k, t("leave.kind." + k)]; }) },
      { name: "start", label: t("leave.field.start"), type: "date", req: true },
      { name: "end", label: t("leave.field.end"), type: "date", hint: t("leave.end_hint") },
      { name: "note", label: t("leave.field.reason_apply"), type: "textarea", rows: 3, max: 200 }
    ], r ? { kind: r.kind, start: r.start, end: r.end, note: r.note } : { kind: "annual" });
    O.modal({ title: t(r ? "leave.edit_mine" : "leave.apply"), width: 520, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t(data.self_approves ? "common.save" : "leave.submit"), cls: "pri", fn: function () {
      var v = f.values();
      if (!v.end) v.end = v.start;
      return O.submit(f, O.api(r ? "PUT" : "POST", "/api/v1/leave/mine" + (r ? "/" + r.id : ""), v)).then(function (res) {
        O.status(t(res.status === "approved" ? "leave.saved_direct" : "leave.submitted"));
        load();
      });
    } }] });
  }

  function withdraw(r) {
    O.confirm({ title: t("leave.withdraw"), msg: t("leave.withdraw_confirm", { dates: dates(r) }), ok: t("leave.withdraw") }).then(function (ok) {
      if (ok) O.api("POST", "/api/v1/leave/mine/" + r.id + "/withdraw", {}).then(function () { O.status(t("leave.withdrawn")); load(); }, function (e) { O.failed(e); load(); });
    });
  }

  load();
})();
