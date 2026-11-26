/* 审核与统计 → 请假: approve or reject the applications of my people, record leave for them directly */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var STATUS_PILL = { pending: "info", approved: "ok", rejected: "bad", withdrawn: "" };

  function dates(r) { return r.start === r.end ? r.start : r.start + " – " + r.end; }
  function statusHtml(r) {
    return O.pill(t("leave.status." + r.status), STATUS_PILL[r.status]) +
      (r.reviewer && r.status !== "pending" && r.status !== "withdrawn" ? '<div class="muted" style="font-size:12px">' + O.esc(r.reviewer + " · " + r.reviewed_at) + "</div>" : "") +
      (r.comment ? '<div class="muted" style="font-size:12px">' + O.esc(r.comment) + "</div>" : "");
  }

  function mount(root) {
    var st = { tab: "", kw: "", dept_id: "", person_id: "", kind: "", page: 1, only: 0 };
    var data = null, first = true;
    // jump from a to-do or a notification: flash that row (design §17)
    var focusId = O.qs("view") === "leaves" ? +O.qs("id") || 0 : 0;
    root.innerHTML = '<div class="bar" id="lv_bar"><button type="button" class="btn" data-tab="pending"></button><button type="button" class="btn" data-tab="all"></button>' +
      '<select id="lv_dept"><option value="">' + O.esc(t("staff.all_depts")) + "</option></select>" +
      '<select id="lv_person"><option value="">' + O.esc(t("plan.all_people")) + "</option></select>" +
      '<select id="lv_kind"><option value="">' + O.esc(t("leave.all_kinds")) + "</option></select>" +
      '<span class="sp"></span><button type="button" class="btn" id="lv_add">' + O.icon("plus", 14) + " " + O.esc(t("leave.add")) + "</button></div>" +
      '<div class="card" id="lv_card"></div>';
    var bar = root.querySelector("#lv_bar");
    bar.insertBefore(O.filters({ state: st, onChange: function () { st.only = 0; load(); }, period: t("leave.period"), placeholder: t("leave.search") }), bar.querySelector(".sp"));
    bar.addEventListener("change", function (e) {
      var k = { lv_dept: "dept_id", lv_person: "person_id", lv_kind: "kind" }[e.target.id];
      if (!k) return;
      st[k] = e.target.value;
      if (k === "dept_id") st.person_id = "";
      st.page = 1;
      st.only = 0;
      load();
    });
    bar.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { st.tab = b.getAttribute("data-tab"); st.page = 1; st.only = 0; load(); }
    });
    root.querySelector("#lv_add").addEventListener("click", function () { edit(null); });

    function opts(list, cur, all) {
      return '<option value="">' + O.esc(all) + "</option>" + list.map(function (o) {
        return '<option value="' + O.esc(o[0]) + '"' + (String(o[0]) === String(cur) ? " selected" : "") + ">" + O.esc(o[1]) + "</option>"; }).join("");
    }
    function filled() {
      var d = root.querySelector("#lv_dept");
      d.innerHTML = opts(O.deptOpts(data.depts), st.dept_id, t("staff.all_depts"));
      d.hidden = data.depts.length < 2;
      root.querySelector("#lv_person").innerHTML = opts(data.people.map(function (p) { return [p.id, p.name]; }), st.person_id, t("plan.all_people"));
      root.querySelector("#lv_kind").innerHTML = opts(data.kinds.map(function (k) { return [k, t("leave.kind." + k)]; }), st.kind, t("leave.all_kinds"));
      var tp = bar.querySelector('[data-tab="pending"]'), ta = bar.querySelector('[data-tab="all"]');
      tp.textContent = t("leave.tab_pending", { n: data.pending });
      ta.textContent = t("leave.tab_all");
      tp.className = "btn" + (st.tab === "pending" ? " pri" : "");
      ta.className = "btn" + (st.tab === "all" ? " pri" : "");
    }

    function load() {
      if (!st.tab) st.tab = "pending";
      var q = O.filterQuery(st);
      q.dept_id = st.dept_id;
      q.person_id = st.person_id;
      q.kind = st.kind;
      q.status = st.tab === "pending" ? "pending" : "";
      q.page = st.page;
      q.id = st.only || "";
      O.api("GET", "/api/v1/perf/leaves", null, q).then(function (res) {
        data = res;
        // the first time: nothing waiting → show all records
        if (first && st.tab === "pending" && !res.pending) { first = false; st.tab = "all"; load(); return; }
        first = false;
        filled();
        render();
        locate();
      }, O.failed);
    }

    function locate() {
      if (!focusId) return;
      var r = data.rows.filter(function (x) { return x.id === focusId; })[0];
      if (r) {
        focusId = 0;
        O.focusBar(t("leave.located", { name: r.person, kind: t("leave.kind." + r.kind), dates: dates(r) }));
        O.flashRow(r.id, root);
      } else if (!st.only) {
        // not on this list: show just that record (any status); the tabs and filters bring the full list back
        st.only = focusId;
        st.tab = "all";
        st.page = 1;
        load();
      } else {
        focusId = 0; // not visible to me any more
      }
    }

    function render() {
      var pend = st.tab === "pending";
      var card = root.querySelector("#lv_card");
      card.innerHTML = "";
      var cols = [
        { t: t("leave.field.person"), f: function (r) { return O.esc(r.person) + (r.dept ? '<div class="muted" style="font-size:12px">' + O.esc(r.dept) + "</div>" : ""); } },
        { t: t("leave.field.kind"), f: function (r) { return O.esc(t("leave.kind." + r.kind)); } },
        { t: t("leave.col.dates"), f: function (r) { return '<span class="mono">' + O.esc(dates(r)) + "</span>"; } },
        { t: t("leave.col.days"), f: function (r) { return O.esc(t("leave.days", { n: r.days, w: r.workdays })); } },
        { t: t("leave.field.note"), cls: "wrap", f: function (r) { return O.esc(r.note || "—"); } },
        { t: t(pend ? "leave.col.applied_at" : "leave.col.by"), f: function (r) {
          return (pend ? "" : O.esc(r.applied ? t("leave.self_applied") : (r.by || "—"))) + '<div class="' + (pend ? "mono" : "muted mono") + '" style="font-size:12px">' + O.esc(r.at) + "</div>"; } }
      ];
      if (!pend) cols.push({ t: t("leave.col.status"), f: statusHtml });
      card.appendChild(O.table({
        cols: cols, rows: data.rows, empty: t(pend ? "leave.empty_pending" : "leave.empty"), slots: 2,
        acts: function (r) {
          if (r.status === "pending") return [{ icon: "check", tip: t("leave.approve"), pri: true, fn: approve }, { icon: "undo", tip: t("leave.reject"), danger: true, fn: reject }];
          return [{ icon: "edit", tip: t("common.edit"), fn: function (x) { edit(x); } }, { icon: "trash", tip: t("common.delete"), danger: true, fn: remove }];
        }
      }));
      card.appendChild(O.pager(data, function (p) { st.page = p; load(); }));
      card.insertAdjacentHTML("beforeend", '<div class="card-b muted" style="font-size:12.5px">' + O.esc(t("leave.hint")) + "</div>");
    }

    function approve(r) {
      O.confirm({ title: t("leave.approve"), msg: t("leave.approve_confirm", { name: r.person, kind: t("leave.kind." + r.kind), dates: dates(r) }), ok: t("leave.approve") }).then(function (ok) {
        if (ok) O.api("POST", "/api/v1/perf/leaves/" + r.id + "/approve", {}).then(function () { O.status(t("leave.approved")); load(); }, function (e) { O.failed(e); load(); });
      });
    }
    function reject(r) {
      var f = O.form([{ name: "reason", label: t("leave.field.reason"), type: "textarea", req: true, max: 200, rows: 3 }], {});
      O.modal({ title: t("leave.reject_title", { name: r.person, dates: dates(r) }), width: 520, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("leave.reject"), cls: "danger fill", fn: function () {
        return O.submit(f, O.api("POST", "/api/v1/perf/leaves/" + r.id + "/reject", f.values())).then(function () { O.status(t("leave.rejected")); load(); });
      } }] });
    }

    function edit(r) {
      var f = O.form([
        { name: "person_id", label: t("leave.field.person"), type: "select", req: true, opts: [["", ""]].concat(data.people.map(function (p) { return [p.id, p.name]; })) },
        { name: "kind", label: t("leave.field.kind"), type: "select", req: true, opts: data.kinds.map(function (k) { return [k, t("leave.kind." + k)]; }) },
        { name: "start", label: t("leave.field.start"), type: "date", req: true },
        { name: "end", label: t("leave.field.end"), type: "date", hint: t("leave.end_hint") },
        { name: "note", label: t("leave.field.note"), type: "textarea", rows: 2, max: 200 }
      ], r ? { person_id: String(r.person_id), kind: r.kind, start: r.start, end: r.end, note: r.note } : { person_id: st.person_id, kind: "annual" });
      var box = document.createElement("div");
      if (!r) box.innerHTML = '<p class="muted" style="font-size:12.5px;margin-top:0">' + O.esc(t("leave.add_hint")) + "</p>";
      box.appendChild(f.el);
      O.modal({ title: t(r ? "leave.edit" : "leave.add"), width: 560, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
        var v = f.values();
        if (!v.end) v.end = v.start;
        return O.submit(f, O.api(r ? "PUT" : "POST", "/api/v1/perf/leaves" + (r ? "/" + r.id : ""), v)).then(function () { O.status(t("common.saved")); load(); });
      } }] });
    }

    function remove(r) {
      O.confirm({ title: t("leave.delete"), msg: t("leave.delete_confirm", { name: r.person, dates: dates(r) }), danger: true, ok: t("common.delete") }).then(function (ok) {
        if (!ok) return;
        O.api("DELETE", "/api/v1/perf/leaves/" + r.id).then(function () { O.status(t("common.deleted")); load(); }, O.failed);
      });
    }

    load();
  }

  O.perfLeaves = { mount: mount, statusHtml: statusHtml, dates: dates };
})();
