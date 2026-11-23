/* "My tasks" and "Plans & results" pages: one tree, filters, add / edit / delete (phase 3); completion and results (phase 4) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var mode = view.getAttribute("data-page") === "tree" ? "tree" : "mine";
  var state = { per: mode === "tree" ? "year" : "month", dept_id: "", person_id: "", kw: "" };
  var closed = {};
  var META = null;
  var rows = [];

  function bar() {
    var chips = [["week", "plan.per.week"], ["month", "plan.per.month"], ["year", "plan.per.year"], ["all", "plan.per.all"]];
    var html = '<div class="bar">' + chips.map(function (c) { return '<button type="button" class="btn' + (state.per === c[0] ? " pri" : "") + '" data-per="' + c[0] + '">' + O.esc(t(c[1])) + "</button>"; }).join("");
    if (mode === "tree") {
      html += '<select id="pl_dept"><option value="">' + O.esc(t("plan.all_depts")) + "</option>" + O.deptOpts(META.filterDepts).map(function (o) {
        return '<option value="' + o[0] + '"' + (String(o[0]) === String(state.dept_id) ? " selected" : "") + ">" + O.esc(o[1]) + "</option>"; }).join("") + "</select>";
      html += '<select id="pl_person"><option value="">' + O.esc(t("plan.all_people")) + "</option>" + META.candidates.map(function (c) {
        return '<option value="' + c.id + '"' + (String(c.id) === String(state.person_id) ? " selected" : "") + ">" + O.esc(c.name) + "</option>"; }).join("") + "</select>";
    }
    html += '<input type="search" id="pl_kw" placeholder="' + O.esc(t("plan.search")) + '" value="' + O.esc(state.kw) + '"><span class="sp"></span>';
    if (META.canAddTop) html += '<button type="button" class="btn pri" id="pl_add">' + O.icon("plus", 14) + " " + O.esc(t(mode === "tree" ? "plan.add_main" : "plan.add_new")) + "</button>";
    if (mode === "mine" && O.device) html += '<button type="button" class="btn" data-a="reportFault" title="' + O.esc(t("device.report.hint")) + '">' + O.icon("wrench", 14) + " " + O.esc(t("device.report.title")) + "</button>";
    return html + "</div>";
  }

  function shell() {
    view.innerHTML = (mode === "mine" ? '<div class="card"><div class="card-b" id="pl_sum" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center"></div></div>' : "") + bar() + '<div class="card" id="pl_card"></div>';
    view.querySelector(".bar").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-per]") : null;
      if (b) { state.per = b.getAttribute("data-per"); shell(); load(); }
    });
    var add = document.getElementById("pl_add");
    if (add) add.addEventListener("click", function () { O.plan.openForm({ top: true, levels: META.topLevels, defaultSelf: mode === "mine", onSaved: load }); });
    var dept = document.getElementById("pl_dept");
    if (dept) dept.addEventListener("change", function (e) { state.dept_id = e.target.value; load(); });
    var person = document.getElementById("pl_person");
    if (person) person.addEventListener("change", function (e) { state.person_id = e.target.value; load(); });
    document.getElementById("pl_kw").addEventListener("input", O.debounce(function (e) { state.kw = e.target.value.trim(); load(); }, 350));
  }

  function visibleRows() {
    var out = [], hideBelow = null;
    rows.forEach(function (r) {
      if (hideBelow !== null) { if (r.depth > hideBelow) return; hideBelow = null; }
      out.push(r);
      if (closed[r.id] && r.has_children) hideBelow = r.depth;
    });
    return out;
  }

  function render() {
    var card = document.getElementById("pl_card");
    card.innerHTML = "";
    var list = visibleRows();
    var tbl = O.table({
      cols: [
        { t: t(mode === "mine" ? "plan.col.task" : "plan.col.plan"), cls: "wrap", f: function (r) {
          var tog = r.has_children ? '<button type="button" class="lk" data-tog="' + r.id + '" style="width:16px;display:inline-block">' + (closed[r.id] ? "▸" : "▾") + "</button>" : '<span class="muted" style="width:16px;display:inline-block">·</span>';
          return '<div style="padding-left:' + (r.depth * 22) + 'px;display:flex;gap:6px;align-items:center' + (r.context ? ";opacity:.55" : "") + '">' + tog + O.plan.levelPill(r.level) +
            '<button type="button" class="lk" data-open="' + r.id + '" style="text-align:left' + (r.depth === 0 ? ";font-weight:500" : "") + '">' + O.esc(r.title) + "</button>" + O.plan.scopeTag(r) + "</div>";
        } },
        { t: t(mode === "mine" ? "plan.col.assigned" : "plan.col.assignees"), f: function (r) {
          if (mode === "mine") {
            var mineOwn = META.me && r.owner_person_id === META.me;
            var others = r.assignee_names.filter(function (n, i) { return r.assignees[i] !== META.me; });
            return '<span class="muted">' + O.esc(mineOwn ? t("plan.self_made") : (r.owner || "—") + (others.length ? " · " + others.join(t("common.list_sep")) : "")) + "</span>";
          }
          return O.esc(r.assignee_names.join(t("common.list_sep")) || "—");
        } },
        { t: t("plan.col.time"), f: function (r) {
          var late = !r.context && r.end < META.today && r.progress < 100;
          return '<span class="mono">' + O.esc(O.plan.label(r)) + "</span>" + (late ? " " + O.pill(t("plan.state.ended"), "warn") : "");
        } },
        { t: t("plan.col.progress"), f: function (r) { return r.context ? '<span class="muted">' + O.esc(O.perf.pct(r.progress)) + "</span>" : O.perf.progress(r.progress); } },
        { t: t(mode === "mine" ? "plan.col.my_result" : "plan.col.results"), cls: "wrap", f: resultCell }
      ],
      rows: list,
      empty: t(mode === "mine" ? "plan.empty_mine" : "plan.empty"),
      acts: function (r) {
        if (r.context) return [];
        return [
          r.caps.write ? { icon: "write", tip: t("perf.write"), pri: true, fn: function (x) { O.perf.write(x.id, load); } }
            : (r.caps.results ? { icon: "eye", tip: t("perf.view"), fn: function (x) { O.perf.view(x.id, load); } } : null),
          r.caps.add.length ? { icon: "plus", tip: t("plan.add_child"), fn: addChild } : null,
          r.caps.edit ? { icon: "edit", tip: t("common.edit"), fn: function (x) { O.api("GET", "/api/v1/plans/" + x.id).then(function (p) { O.plan.openForm({ plan: p, onSaved: load }); }, O.failed); } } : null,
          r.caps.del ? { icon: "trash", tip: t("common.delete"), danger: true, fn: function (x) { O.plan.remove(x, load); } } : null
        ];
      },
      slots: 4
    });
    card.appendChild(tbl);
    card.insertAdjacentHTML("beforeend", O.legend([["write", t("perf.write")], ["eye", t("perf.view")], ["plus", t("plan.add_child")], ["edit", t("common.edit")], ["trash", t("common.delete")]]) +
      (mode === "tree" ? '<div class="card-b muted" style="font-size:12.5px;padding-top:0">' + O.esc(t("plan.tree_hint")) + "</div>" : ""));
    card.onclick = function (e) {
      var tg = e.target.closest ? e.target.closest("[data-tog]") : null;
      if (tg) { var id = +tg.getAttribute("data-tog"); closed[id] = !closed[id]; render(); return; }
      var op = e.target.closest ? e.target.closest("[data-open]") : null;
      if (op) O.plan.openDetail(+op.getAttribute("data-open"), { onChanged: load });
    };
  }

  /* my result (My tasks) or a summary of everyone's results (Plans & results) */
  function resultCell(r) {
    if (r.context) return "";
    if (mode === "mine") {
      if (r.due_today) return O.pill(t("perf.due_today"), "bad");
      if (META.me && r.assignees.indexOf(META.me) < 0) return '<span class="muted">—</span>';
      return O.perf.statusPill(r.my ? r.my.status : "none");
    }
    if (!r.assignees.length) return '<span class="muted">—</span>';
    var c = r.results.counts, order = ["approved", "submitted", "returned", "withdrawn", "draft", "none"];
    var pills = order.filter(function (k) { return c[k]; }).map(function (k) {
      return r.assignees.length === 1 ? O.perf.statusPill(k) : O.perf.statusPill(k).replace("</span>", " " + c[k] + "</span>");
    }).join(" ");
    return pills + (r.results.snippet ? ' <span class="muted" style="font-size:12px">' + O.esc(r.results.snippet) + "</span>" : "");
  }

  function addChild(r) {
    O.api("GET", "/api/v1/plans/" + r.id).then(function (p) {
      O.plan.openForm({ parent: p, levels: p.caps.add, manager: p.caps.assign || (META.manager && !p.dept_id), defaultSelf: !META.manager || mode === "mine", onSaved: function () { closed[r.id] = false; load(); } });
    }, O.failed);
  }

  function summary() {
    var box = document.getElementById("pl_sum");
    if (!box) return;
    var roots = rows.filter(function (r) { return r.depth === 0; }).length;
    var todayDays = rows.filter(function (r) { return r.due_today; }).length;
    box.innerHTML = '<div style="font-size:16px">' + O.esc(t("plan.sum_tasks")) + ' <b class="num">' + roots + "</b></div>" +
      '<div style="font-size:16px">' + O.esc(t("plan.sum_today")) + ' <b class="num">' + todayDays + "</b></div>" +
      '<span class="muted" style="flex:1;min-width:220px">' + O.esc(t(META.manager ? "plan.mine_hint_leader" : "plan.mine_hint")) + "</span>";
  }

  function load() {
    O.api("GET", "/api/v1/plans", null, { mode: mode, per: state.per === "all" ? "" : state.per, dept_id: state.dept_id, person_id: state.person_id, kw: state.kw }).then(function (res) {
      rows = res.rows;
      summary();
      render();
    }, O.failed);
  }

  O.plan.meta().then(function (m) {
    META = m;
    shell();
    load();
    var focus = +O.qs("plan");
    if (focus) O.plan.openDetail(focus).then(null, function () { /* not visible any more */ });
  }, O.failed);
})();
