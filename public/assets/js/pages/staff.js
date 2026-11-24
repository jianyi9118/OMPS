/* Staff records: list, review of submitted changes, transfers, import (phase 2) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var qs = new URLSearchParams(window.location.search);
  // list filters live on state; the transfer records (ts) and the review list (rs) keep their own
  var state = { tab: qs.get("tab") === "review" ? 2 : 0, dept_id: qs.get("dept_id") || "", status: "", unassigned: qs.get("unassigned") === "1" ? 1 : "", page: 1 };
  var ts = { type: "", page: 1 }, rs = { status: "pending", page: 1 };
  var pmeta = null;
  var meta = null;

  function genderName(g) { return t("gender." + g); }
  function statusPill(r) {
    if (r.unassigned) return O.pill(t("staff.unassigned"), "warn");
    var k = { active: "ok", disabled: "", left: "", retired: "" }[r.status];
    return O.pill(t("staff.status." + r.status), k);
  }

  function shell() {
    var tabs = [[0, t("staff.tab.list")]];
    if (pmeta.canReview) tabs.push([2, t("staff.tab.review") + (pmeta.pending ? " (" + pmeta.pending + ")" : "")]);
    tabs.push([1, t("staff.tab.transfers")]);
    if (pmeta.canImport) tabs.push([3, t("staff.tab.import")]);
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="s_tabs">' + tabs.map(function (x) { return '<button type="button" data-tab="' + x[0] + '">' + O.esc(x[1]) + "</button>"; }).join("") +
      '</div><div id="s_body" style="display:grid;gap:12px"></div>';
    document.getElementById("s_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (!b) return;
      state.tab = +b.getAttribute("data-tab");
      renderTab();
    });
  }

  function renderTab() {
    var tabs = document.querySelectorAll("#s_tabs button");
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle("on", +tabs[i].getAttribute("data-tab") === state.tab);
    if (state.tab === 0) listView(); else if (state.tab === 2) reviewView(); else if (state.tab === 3) importView(); else transfersView();
  }

  /* ---------- list ---------- */
  function listView() {
    var body = document.getElementById("s_body");
    var c = meta.caps;
    body.innerHTML = '<div class="bar">' + (c.scope === "self" ? "" :
      '<select id="s_dept"><option value="">' + O.esc(t("staff.all_depts")) + "</option>" + O.deptOpts(meta.depts).map(function (o) {
        return '<option value="' + o[0] + '"' + (String(o[0]) === String(state.dept_id) ? " selected" : "") + ">" + O.esc(o[1]) + "</option>";
      }).join("") + "</select>" +
      '<select id="s_status"><option value="">' + O.esc(t("staff.all_status")) + "</option>" + ["active", "left", "retired", "disabled"].map(function (s) {
        return '<option value="' + s + '"' + (s === state.status ? " selected" : "") + ">" + O.esc(t("staff.status." + s)) + "</option>";
      }).join("") + "</select>") +
      '<span class="scope">' + O.esc(t("staff.scope_label")) + " " + O.esc(t("scope." + c.scope)) + '</span><span class="sp"></span>' +
      (pmeta.canExport ? '<button type="button" class="btn" id="s_export">' + O.icon("download", 14) + " " + O.esc(t("staff.export_btn")) + "</button>" : "") +
      (c.add ? '<button type="button" class="btn pri" id="s_add">' + O.icon("plus", 14) + " " + O.esc(t("staff.add")) + "</button>" : "") + "</div>" +
      (c.scope === "self" ? '<div class="note">' + O.esc(t("staff.self_note")) + "</div>" : "") + '<div class="card" id="s_card"></div>';
    var dept = document.getElementById("s_dept");
    if (dept) {
      document.getElementById("s_status").parentNode.insertBefore(O.filters({ state: state, onChange: loadList, period: t("staff.field.hire_date"), placeholder: t("staff.search"),
        fields: [["staff_no", t("staff.field.staff_no")], ["name", t("staff.field.name")], ["dept", t("staff.field.dept")], ["position", t("staff.field.position")], ["phone", t("staff.field.phone")]] }),
        document.querySelector("#s_body .scope"));
      dept.addEventListener("change", function (e) { state.dept_id = e.target.value; state.page = 1; loadList(); });
      document.getElementById("s_status").addEventListener("change", function (e) { state.status = e.target.value; state.page = 1; loadList(); });
    }
    var add = document.getElementById("s_add");
    if (add) add.addEventListener("click", function () { personForm(null); });
    var exp = document.getElementById("s_export");
    // the file holds what the list shows: same filters
    if (exp) exp.addEventListener("click", function () {
      var q = O.filterQuery(state);
      q.dept_id = state.dept_id;
      q.status = state.status;
      window.location.href = O.url("/api/v1/staff-export", q);
    });
    loadList();
  }

  function loadList() {
    var q = O.filterQuery(state);
    q.dept_id = state.dept_id;
    q.status = state.status;
    q.unassigned = state.unassigned;
    q.page = state.page;
    O.api("GET", "/api/v1/staff", null, q).then(function (res) {
      var c = meta.caps;
      var card = document.getElementById("s_card");
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("staff.field.staff_no"), f: function (r) { return '<span class="mono">' + O.esc(r.staff_no) + "</span>"; } },
          { t: t("staff.field.name"), f: function (r) { return '<button type="button" class="lk" data-view="' + r.id + '">' + O.esc(r.name) + "</button>"; } },
          { t: t("staff.field.gender"), f: function (r) { return O.esc(genderName(r.gender)); } },
          { t: t("staff.field.dept"), f: function (r) { return r.unassigned ? O.pill(t("staff.unassigned"), "warn") : O.esc(r.dept || "—"); } },
          { t: t("staff.field.position"), f: function (r) { return O.esc(r.position || "—"); } },
          { t: t("staff.field.id_number"), f: function (r) { return '<span class="mono">' + O.esc(r.id_mask || "—") + "</span>"; } },
          { t: t("staff.field.phone"), f: function (r) { return '<span class="mono">' + O.esc(r.phone || "—") + "</span>"; } },
          { t: t("staff.field.status"), f: statusPill }
        ].concat(O.cf.cols(res.custom_cols)),
        rows: res.rows,
        acts: function (r) {
          var self = O.boot.user.personId !== null && r.id === O.boot.user.personId;
          return [
            { icon: "eye", tip: t("staff.view_record"), fn: function (x) { openRecord(x, false); } },
            { icon: "review", tip: t("staff.history"), fn: history },
            c.transfer ? { icon: "transfer", tip: t("staff.transfer"), fn: transfer } : undefined,
            c.remove && !self && r.status === "active" && !r.unassigned ? { icon: "logout", tip: t("staff.remove"), danger: true, fn: remove } : (c.remove ? null : undefined),
            c.edit ? { icon: "edit", tip: t("common.edit"), fn: function (x) { openRecord(x, true); } } : undefined,
            c.delete ? { icon: "trash", tip: t("common.delete"), danger: true, fn: del } : undefined
          ].filter(function (a) { return a !== undefined; });
        }
      }));
      card.appendChild(O.pager(res, function (p) { state.page = p; loadList(); }));
      var leg = [["eye", t("staff.view_record")], ["review", t("staff.history")]];
      if (c.transfer) leg.push(["transfer", t("staff.transfer")]);
      if (c.remove) leg.push(["logout", t("staff.remove")]);
      if (c.edit) leg.push(["edit", t("common.edit")]);
      if (c.delete) leg.push(["trash", t("common.delete")]);
      card.insertAdjacentHTML("beforeend", O.legend(leg));
      card.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-view]") : null;
        if (!b) return;
        var row = res.rows.filter(function (x) { return x.id === +b.getAttribute("data-view"); })[0];
        if (row) openRecord(row, false);
      });
    }, O.failed);
  }

  function personForm(p) {
    var c = meta.caps;
    var fields = [
      { name: "staff_no", label: t("staff.field.staff_no"), max: 50, readonly: !!p, hint: p ? "" : t("staff.no_hint") },
      { name: "name", label: t("staff.field.name"), req: true, max: 50 },
      { name: "gender", label: t("staff.field.gender"), type: "radio", req: true, opts: [["male", t("gender.male")], ["female", t("gender.female")]] },
      { name: "dept_id", label: t("staff.field.dept"), type: "select", int: true, hide: !!p, req: c.scope === "dept", noEmpty: c.scope === "dept", empty: t("staff.no_dept"), opts: O.deptOpts(meta.depts) },
      { name: "position", label: t("staff.field.position"), max: 100 },
      { name: "phone", label: t("staff.field.phone"), max: 30 },
      { name: "hire_date", label: t("staff.field.hire_date"), type: "date" }
    ];
    if (!p) fields = fields.concat(O.cf.fields(meta.custom || [], false));
    var vals = p ? p : { gender: "male", dept_id: state.dept_id || (c.scope === "dept" && meta.depts.length ? meta.depts[0].id : "") };
    var f = O.form(fields, vals);
    var foot = !p && c.scope === "dept" ? '<div class="note" style="margin-top:12px">' + O.esc(t("staff.add_dept_note")) + "</div>" : "";
    var body = document.createElement("div");
    body.appendChild(f.el);
    body.insertAdjacentHTML("beforeend", foot);
    O.modal({ title: p ? t("staff.edit") + " · " + p.name : t("staff.add"), width: 640, body: body, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var v = f.values();
      var custom = O.cf.collect(v);
      if (p) v.version = p.version; else v.custom = custom;
      return O.submit(f, p ? O.api("PUT", "/api/v1/staff/" + p.id, v) : O.api("POST", "/api/v1/staff", v)).then(function (r) {
        O.status(t("common.saved"));
        loadList();
        if (!p && r && r.id) setTimeout(function () { O.person.open(r.id, { edit: true, onSaved: loadList }); }, 50);
      });
    } }] });
  }

  function history(p) {
    O.api("GET", "/api/v1/staff/" + p.id + "/history").then(function (rows) {
      var html = '<div class="pd-row"><b>' + O.esc(p.name) + '</b> <span class="mono muted">' + O.esc(p.staff_no) + "</span> · " + O.esc(p.dept || t("staff.no_dept")) + " · " + O.esc(p.position || "") + "</div>" +
        '<ul class="timeline" style="margin-top:12px">' + (rows.length ? rows.map(function (a) {
          return "<li><b>" + O.esc(a.date) + "</b> " + O.pill(t("staff.change." + a.type), "info") + " " +
            O.esc(a.from && a.from !== a.dept ? a.from + " → " : "") + O.esc(a.dept || (a.type === "move_out" ? t("staff.unassigned") : "")) +
            (a.position ? " · " + O.esc(a.position) : "") + (a.note ? '<div class="muted">' + O.esc(a.note) + "</div>" : "") + "</li>";
        }).join("") : '<li class="muted">' + O.esc(t("common.empty")) + "</li>") + "</ul>";
      O.info(t("staff.history") + " · " + p.name, html);
    }, O.failed);
  }

  function transfer(p) {
    var f = O.form([
      { name: "type", label: t("staff.field.change_type"), type: "radio", req: true, opts: [["transfer", t("staff.change.transfer")], ["promote", t("staff.change.promote")], ["leave", t("staff.change.leave")], ["retire", t("staff.change.retire")]] },
      { name: "dept_id", label: t("staff.field.to_dept"), type: "select", int: true, empty: t("staff.no_dept"), opts: O.deptOpts(meta.allDepts) },
      { name: "position", label: t("staff.field.position"), max: 100 },
      { name: "date", label: t("staff.field.date"), type: "date", req: true, value: O.today() },
      { name: "note", label: t("staff.field.note"), type: "textarea", max: 500 }
    ], { type: "transfer", dept_id: p.dept_id, position: p.position });
    O.modal({ title: t("staff.transfer") + " · " + p.name, width: 600, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("staff.confirm_transfer"), cls: "pri", fn: function () {
      return O.submit(f, O.api("POST", "/api/v1/staff/" + p.id + "/transfer", f.values())).then(function () { O.status(t("staff.change_recorded", { name: p.name })); loadList(); });
    } }] });
  }

  function remove(p) {
    var body = document.createElement("div");
    var f = O.form([
      { name: "type", label: t("staff.field.reason"), type: "radio", req: true, span2: true, opts: [["leave", t("staff.remove.leave")], ["retire", t("staff.remove.retire")], ["move_out", t("staff.remove.move_out")]] },
      { name: "date", label: t("staff.field.date"), type: "date", req: true, value: O.today() },
      { name: "note", label: t("staff.field.note"), max: 500 }
    ], { type: "leave" });
    body.appendChild(f.el);
    body.insertAdjacentHTML("beforeend", '<div class="note" style="margin-top:12px">' + O.esc(t("staff.remove_note")) + '</div><div id="rm_impact"></div>');
    O.api("GET", "/api/v1/staff/" + p.id + "/remove-impact").then(function (im) {
      var el = body.querySelector("#rm_impact");
      if (el && (im.plans || im.devices)) el.innerHTML = '<div class="note warn" style="margin-top:8px">' + O.esc(t("staff.remove_impact", { plans: im.plans, devices: im.devices })) + "</div>";
    }, function () { /* the note above is enough */ });
    O.modal({ title: t("staff.remove") + " · " + p.name, width: 560, body: body, buttons: [{ t: t("common.cancel") }, { t: t("staff.confirm_remove"), cls: "danger fill", fn: function () {
      return O.submit(f, O.api("POST", "/api/v1/staff/" + p.id + "/remove", f.values())).then(function () { O.status(t("staff.change_recorded", { name: p.name })); loadList(); });
    } }] });
  }

  function del(p) {
    O.api("GET", "/api/v1/staff/" + p.id + "/delete-impact").then(function (im) {
      var sep = t("common.list_sep");
      var also = [];
      if (im.accounts.length) also.push(t("staff.delete_accounts", { names: im.accounts.join(sep) }));
      if (im.leads.length) also.push(t("staff.delete_leads", { names: im.leads.join(sep) }));
      var html = O.esc(t("staff.delete_intro", { name: p.name })) +
        (also.length ? '</p><p><b>' + O.esc(t("staff.delete_also")) + "</b></p><ul style=\"margin:0;padding-left:20px\">" + also.map(function (x) { return "<li>" + O.esc(x) + "</li>"; }).join("") + "</ul><p>" : "");
      O.confirm({ title: t("staff.delete_title"), html: html, ok: t("common.delete"), danger: true, width: 480 }).then(function (ok) {
        if (!ok) return;
        O.api("DELETE", "/api/v1/staff/" + p.id).then(function () { O.status(t("common.deleted")); loadList(); }, O.failed);
      });
    }, O.failed);
  }

  function openRecord(p, edit) {
    O.person.open(p.id, { edit: edit, onSaved: loadList });
  }

  /* ---------- review of submitted changes ---------- */
  function reviewView() {
    var body = document.getElementById("s_body");
    body.innerHTML = '<div class="bar"><select id="r_status">' + ["pending", "approved", "returned", "withdrawn"].map(function (k) {
      return '<option value="' + k + '"' + (k === rs.status ? " selected" : "") + ">" + O.esc(t("review.status." + k)) + "</option>"; }).join("") +
      '</select><span class="muted" style="font-size:12.5px">' + O.esc(t("review.hint")) + '</span><span class="sp"></span>' +
      (rs.status === "pending" ? '<button type="button" class="btn pri" id="r_batch">' + O.icon("check", 14) + " " + O.esc(t("review.approve_selected")) + "</button>" : "") + '</div><div class="card" id="r_card"></div>';
    var sel = document.getElementById("r_status");
    sel.addEventListener("change", function (e) { rs.status = e.target.value; rs.page = 1; reviewView(); });
    sel.parentNode.insertBefore(O.filters({ state: rs, onChange: loadReviews, period: t("review.submitted_at"), placeholder: t("review.search"),
      fields: [["name", t("staff.field.name")], ["dept", t("staff.field.dept")], ["reviewer", t("review.reviewer")], ["comment", t("review.return_reason")]] }), sel.nextSibling);
    var batch = document.getElementById("r_batch");
    if (batch) batch.addEventListener("click", function () {
      var ids = Array.prototype.map.call(document.querySelectorAll("#r_card input[data-rid]:checked"), function (x) { return +x.getAttribute("data-rid"); });
      if (!ids.length) { O.status(t("review.select_first"), "warn"); return; }
      approve(ids);
    });
    loadReviews();
  }

  function loadReviews() {
    var q = O.filterQuery(rs);
    q.status = rs.status;
    q.page = rs.page;
    O.api("GET", "/api/v1/reviews", null, q).then(function (res) {
      var pending = rs.status === "pending";
      var card = document.getElementById("r_card");
      card.innerHTML = "";
      var cols = [];
      if (pending) cols.push({ t: "", f: function (r) { return '<input type="checkbox" data-rid="' + r.id + '" aria-label="' + O.esc(t("review.select")) + '">'; } });
      cols.push({ t: t("review.submitted_at"), f: function (r) { return '<span class="mono">' + O.esc(r.submitted_at) + "</span>"; } });
      cols.push({ t: t("staff.field.name"), f: function (r) { return O.esc(r.person) + '<div class="muted" style="font-size:12px">' + O.esc(r.dept) + "</div>"; } });
      cols.push({ t: t("review.content"), cls: "wrap", f: function (r) { return O.person.describe(r); } });
      if (!pending) cols.push({ t: t("review.reviewer"), f: function (r) { return O.esc(r.reviewer) + '<div class="muted" style="font-size:12px">' + O.esc(r.reviewed_at) + "</div>" + (r.comment ? '<div class="muted" style="font-size:12px;white-space:normal">' + O.esc(r.comment) + "</div>" : ""); } });
      card.appendChild(O.table({
        cols: cols, rows: res.rows, empty: t(pending ? "review.empty" : "common.empty"),
        acts: pending ? function (r) {
          return [{ icon: "check", tip: t("review.approve"), pri: true, fn: function () { approve([r.id]); } }, { icon: "back", tip: t("review.return"), danger: true, fn: returnOne }];
        } : null
      }));
      card.appendChild(O.pager(res, function (p) { rs.page = p; loadReviews(); }));
      if (pending) card.insertAdjacentHTML("beforeend", O.legend([["check", t("review.approve")], ["back", t("review.return")]]));
    }, O.failed);
  }

  function refreshPending() {
    O.api("GET", "/api/v1/personnel/meta").then(function (m) {
      pmeta.pending = m.pending;
      var b = document.querySelector('#s_tabs button[data-tab="2"]');
      if (b) b.textContent = t("staff.tab.review") + (m.pending ? " (" + m.pending + ")" : "");
    });
  }

  function approve(ids) {
    O.api("POST", "/api/v1/reviews/approve", { ids: ids }).then(function (r) {
      if (r.failed.length) O.info(t("error.title_refused"), "<p>" + r.failed.map(function (x) { return O.esc(x.message); }).join("<br>") + "</p>");
      O.status(t("review.approved_n", { n: r.approved }));
      loadReviews();
      refreshPending();
    }, O.failed);
  }

  function returnOne(r) {
    var f = O.form([{ name: "comment", label: t("review.return_reason"), type: "textarea", req: true, max: 500 }], {});
    O.modal({ title: t("review.return") + " · " + r.person, width: 520, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("review.return"), cls: "danger fill", fn: function () {
      return O.submit(f, O.api("POST", "/api/v1/reviews/" + r.id + "/return", f.values())).then(function () { O.status(t("review.returned")); loadReviews(); refreshPending(); });
    } }] });
  }

  /* ---------- import ---------- */
  function importView() {
    var body = document.getElementById("s_body");
    body.innerHTML = '<div class="card"><div class="card-h">' + O.esc(t("import.title")) + '</div><div class="card-b" style="display:grid;gap:12px">' +
      '<div class="steps"><span class="on">1 ' + O.esc(t("import.step1")) + "</span><span>2 " + O.esc(t("import.step2")) + "</span><span>3 " + O.esc(t("import.step3")) + "</span></div>" +
      '<p class="muted" style="margin:0">' + O.esc(t("import.intro")) + "</p>" +
      '<div><a class="btn" href="' + O.esc(O.url("/api/v1/staff-import/template")) + '">' + O.icon("download", 14) + " " + O.esc(t("import.template")) + '</a> <button type="button" class="btn pri" id="i_pick">' +
      O.icon("upload", 14) + " " + O.esc(t("import.choose")) + '</button></div><div id="i_result"></div></div></div>';
    document.getElementById("i_pick").addEventListener("click", function () {
      O.pickFiles({ accept: ".xlsx,.csv" }, function (files) {
        O.upload("/api/v1/staff-import/check", files.slice(0, 1)).then(showCheck, function (err) {
          if (err && err.fields) O.info(t("error.title_refused"), "<p>" + O.esc(err.fields.file || err.message) + "</p>"); else O.failed(err);
        });
      });
    });
  }

  function showCheck(res) {
    var steps = document.querySelectorAll("#s_body .steps span");
    if (steps.length) { steps[0].className = "done"; steps[1].className = "on"; }
    var box = document.getElementById("i_result");
    var cols = [{ t: t("import.line"), f: function (r) { return String(r.line); } }];
    res.columns.forEach(function (k) { cols.push({ t: t("staff.field." + k), f: function (r) { return O.esc(r.values[k] || ""); } }); });
    cols.push({ t: t("import.result"), cls: "wrap", f: function (r) { return r.errors.length ? '<span class="pill bad">' + O.esc(t("import.bad")) + '</span> <span style="color:var(--bad);font-size:12px">' + O.esc(r.errors.join("; ")) + "</span>" : O.pill(t("import.ok"), "ok"); } });
    box.innerHTML = '<div class="note ' + (res.bad ? "warn" : "") + '">' + O.esc(t("import.summary", { ok: res.ok, bad: res.bad })) + "</div>";
    var tw = O.table({ cols: cols, rows: res.rows });
    tw.style.maxHeight = "420px";
    box.appendChild(tw);
    if (res.ok) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn pri";
      btn.style.marginTop = "10px";
      btn.textContent = t("import.run", { n: res.ok });
      btn.addEventListener("click", function () {
        btn.disabled = true;
        O.api("POST", "/api/v1/staff-import/run", { token: res.token }).then(function (r) {
          if (steps.length) { steps[1].className = "done"; steps[2].className = "on"; }
          box.innerHTML = '<div class="note">' + O.esc(t("import.done", { created: r.created, skipped: r.skipped })) + "</div>";
          O.status(t("import.done", { created: r.created, skipped: r.skipped }));
        }, function (e) { btn.disabled = false; O.failed(e); });
      });
      box.appendChild(btn);
    }
  }

  /* ---------- transfers ---------- */
  function transfersView() {
    var body = document.getElementById("s_body");
    body.innerHTML = '<div class="bar"><select id="t_type"><option value="">' + O.esc(t("staff.all_types")) + "</option>" +
      ["join", "transfer", "promote", "move_out", "leave", "retire"].map(function (k) { return '<option value="' + k + '"' + (k === ts.type ? " selected" : "") + ">" + O.esc(t("staff.change." + k)) + "</option>"; }).join("") +
      '</select></div><div class="card" id="t_card"></div>';
    document.getElementById("t_type").addEventListener("change", function (e) { ts.type = e.target.value; ts.page = 1; loadTransfers(); });
    document.querySelector("#s_body .bar").appendChild(O.filters({ state: ts, onChange: loadTransfers, period: t("staff.field.date"), placeholder: t("staff.transfer_search"),
      fields: [["name", t("staff.field.name")], ["from_dept", t("staff.field.from_dept")], ["to_dept", t("staff.field.to_dept")], ["position", t("staff.field.position")],
        ["by", t("staff.field.by")], ["note", t("staff.field.note")]] }));
    loadTransfers();
  }

  function loadTransfers() {
    var q = O.filterQuery(ts);
    q.type = ts.type;
    q.page = ts.page;
    O.api("GET", "/api/v1/staff/transfers", null, q).then(function (res) {
      var card = document.getElementById("t_card");
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("staff.field.date"), f: function (r) { return '<span class="mono">' + O.esc(r.date) + "</span>"; } },
          { t: t("staff.field.name"), f: function (r) { return O.esc(r.person); } },
          { t: t("staff.field.change_type"), f: function (r) { return O.pill(t("staff.change." + r.type), "info"); } },
          { t: t("staff.field.from_dept"), f: function (r) { return O.esc(r.from || "—"); } },
          { t: t("staff.field.to_dept"), f: function (r) { return r.type === "move_out" ? O.pill(t("staff.unassigned"), "warn") : O.esc(r.dept || "—"); } },
          { t: t("staff.field.position"), f: function (r) { return O.esc(r.position || "—"); } },
          { t: t("staff.field.by"), f: function (r) { return O.esc(r.by || "—"); } },
          { t: t("staff.field.note"), cls: "wrap", f: function (r) { return O.esc(r.note); } }
        ],
        rows: res.rows
      }));
      card.appendChild(O.pager(res, function (p) { ts.page = p; loadTransfers(); }));
    }, O.failed);
  }

  Promise.all([O.api("GET", "/api/v1/staff/meta"), O.person.meta()]).then(function (all) {
    var m = all[0];
    pmeta = all[1];
    meta = m;
    if (state.tab === 2 && !pmeta.canReview) state.tab = 0;
    shell();
    renderTab();
    if (state.unassigned) O.focusBar(t("staff.located_unassigned"));
    var focus = +qs.get("focus");
    if (focus) O.person.open(focus, { edit: false, onSaved: loadList });
  }, O.failed);
})();
