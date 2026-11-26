/* 业绩审核 → 执行统计: assigned plans executed, daily to annual reports submitted, per person; every figure opens its list */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var KINDS = ["plans", "day", "week", "month", "quarter", "annual"];
  var CYCLES = ["day", "week", "month", "quarter", "annual"];

  function tone(rate) { return rate === null ? "" : (rate >= 90 ? "ok" : (rate >= 60 ? "warn" : "bad")); }
  function pctText(v) { return v === null ? "—" : (Math.round(v * 10) / 10) + "%"; }
  function meter(rate) {
    return '<span class="ps-meter" aria-hidden="true"><b class="' + tone(rate) + '" style="width:' + (rate === null ? 0 : Math.max(0, Math.min(100, rate))) + '%"></b></span>';
  }
  /** [ok, missing, base] of a figure */
  function nums(kind, f) { return kind === "plans" ? [f.executed, f.none, f.due] : [f.submitted, f.missing, f.expected]; }
  function tip(kind, f) {
    return kind === "plans" ? t("perf.st.tip_plans", { done: f.done, doing: f.doing, none: f.none, future: f.future, overdue: f.overdue })
      : t("perf.st.tip_rep", { due: f.expected, ok: f.submitted, appr: f.approved, miss: f.missing, early: f.early });
  }
  function kindName(k) { return t("perf.st.kind." + k); }

  function mount(root) {
    var r0 = O.periodRange("month");
    var st = { period: "month", from: r0[0], to: r0[1], kw: "", field: "", dept_id: "", person_id: "", gaps: "", sort: "", dir: 1 };
    var data = null, lists = null;

    root.innerHTML = '<div class="bar" id="ps_bar"><select id="ps_dept"><option value="">' + O.esc(t("staff.all_depts")) + "</option></select>" +
      '<select id="ps_person"><option value="">' + O.esc(t("plan.all_people")) + "</option></select>" +
      '<select id="ps_gaps"><option value="">' + O.esc(t("perf.st.scope_all")) + '</option><option value="1">' + O.esc(t("perf.st.scope_gaps")) + "</option></select>" +
      '<span class="sp"></span><button type="button" class="btn" id="ps_export">' + O.icon("download", 14) + " " + O.esc(t("perf.st.export")) + "</button></div>" +
      '<div class="kpis ps-kpis" id="ps_kpis"></div><div class="card" id="ps_card"></div>';
    var bar = root.querySelector("#ps_bar");
    bar.insertBefore(O.filters({ state: st, onChange: load, period: t("perf.st.period"), placeholder: t("perf.st.search") }), bar.querySelector(".sp"));
    bar.addEventListener("change", function (e) {
      var id = e.target.id;
      if (id === "ps_dept") { st.dept_id = e.target.value; st.person_id = ""; fillPeople(); load(); }
      if (id === "ps_person") { st.person_id = e.target.value; load(); }
      if (id === "ps_gaps") { st.gaps = e.target.value; load(); }
    });
    root.querySelector("#ps_export").addEventListener("click", function () { window.location.href = O.url("/api/v1/perf/stats/export", query()); });
    root.querySelector("#ps_kpis").addEventListener("click", function (e) {
      var k = e.target.closest ? e.target.closest("[data-kind]") : null;
      if (k) items(k.getAttribute("data-kind"), null, "");
    });
    root.querySelector("#ps_kpis").addEventListener("keydown", function (e) {
      var k = e.target.closest ? e.target.closest("[data-kind]") : null;
      if (k && (e.key === "Enter" || e.key === " ")) { e.preventDefault(); items(k.getAttribute("data-kind"), null, ""); }
    });
    var card = root.querySelector("#ps_card");
    card.addEventListener("click", function (e) {
      var s = e.target.closest ? e.target.closest("[data-sort]") : null;
      if (s) {
        var k = s.getAttribute("data-sort");
        if (st.sort === k) st.dir = -st.dir; else { st.sort = k; st.dir = 1; }
        table();
        return;
      }
      var c = e.target.closest ? e.target.closest("[data-cell]") : null;
      if (c) {
        var pid = c.getAttribute("data-pid");
        items(c.getAttribute("data-cell"), pid ? rowOf(+pid) : null, "");
      }
    });

    function rowOf(pid) { for (var i = 0; i < data.rows.length; i++) if (data.rows[i].person_id === pid) return data.rows[i]; return null; }

    function query() {
      var q = O.filterQuery(st);
      q.dept_id = st.dept_id;
      q.person_id = st.person_id;
      q.gaps = st.gaps;
      return q;
    }

    function fillDepts() {
      var el = root.querySelector("#ps_dept");
      el.innerHTML = '<option value="">' + O.esc(t("staff.all_depts")) + "</option>" + O.deptOpts(lists.depts).map(function (o) {
        return '<option value="' + o[0] + '"' + (String(o[0]) === String(st.dept_id) ? " selected" : "") + ">" + O.esc(o[1]) + "</option>"; }).join("");
      el.hidden = lists.depts.length < 2;
    }
    function fillPeople() {
      // with a department chosen: the people of the department and its groups (the list is in tree order)
      var el = root.querySelector("#ps_person"), keep = null;
      if (st.dept_id) {
        keep = {};
        var base = null;
        for (var i = 0; i < lists.depts.length; i++) {
          var d = lists.depts[i], dep = d.depth || 0;
          if (base === null) { if (String(d.id) === String(st.dept_id)) { base = dep; keep[d.id] = true; } }
          else if (dep > base) keep[d.id] = true;
          else break;
        }
      }
      el.innerHTML = '<option value="">' + O.esc(t("plan.all_people")) + "</option>" + lists.people.filter(function (p) { return !keep || keep[p.dept_id]; }).map(function (p) {
        return '<option value="' + p.id + '"' + (String(p.id) === String(st.person_id) ? " selected" : "") + ">" + O.esc(p.name) + "</option>"; }).join("");
    }

    function load() {
      O.api("GET", "/api/v1/perf/stats", null, query()).then(function (res) {
        data = res;
        if (!lists) { lists = res.filters; fillDepts(); fillPeople(); }
        kpis();
        table();
      }, O.failed);
    }

    function kpis() {
      var el = root.querySelector("#ps_kpis");
      el.innerHTML = KINDS.map(function (k) {
        var f = data.total[k], n = nums(k, f), rate = f.rate;
        var label = k === "plans" ? t("perf.st.kpi_plans") : t("perf.st.kpi_rep", { kind: kindName(k) });
        var sub = n[2] ? t(k === "plans" ? "perf.st.sub_plans" : "perf.st.sub_rep", { ok: n[0], miss: n[1], due: n[2] })
          : t(k === "plans" ? "perf.st.none_plans" : "perf.st.none_rep", { kind: kindName(k) });
        return '<div class="kpi link ps-k ' + tone(rate) + '" data-kind="' + k + '" role="button" tabindex="0" title="' + O.esc(n[2] ? tip(k, f) : t("perf.st.open_list")) + '">' +
          '<div class="k">' + O.esc(label) + '</div><div class="v">' + O.esc(pctText(rate)) + "</div>" + meter(rate) + '<div class="s">' + O.esc(sub) + "</div></div>";
      }).join("");
    }

    function shownKinds() {
      // a cycle with nothing due for anyone in the period has no column
      return KINDS.filter(function (k) { return k === "plans" || data.total[k].expected > 0; });
    }

    function cell(k, f, pid) {
      var n = nums(k, f);
      if (!n[2] && !(k === "plans" && f.future)) return '<span class="muted">—</span>';
      var miss = n[1] ? ' <span class="ps-miss">' + O.esc(t(k === "plans" ? "perf.st.miss_plans" : "perf.st.miss_rep", { n: n[1] })) + "</span>" : "";
      var extra = k === "plans" && f.overdue ? ' <span class="ps-over">' + O.esc(t("perf.st.over_n", { n: f.overdue })) + "</span>" : "";
      return '<button type="button" class="ps-cell" data-cell="' + k + '"' + (pid ? ' data-pid="' + pid + '"' : "") + ' title="' + O.esc(tip(k, f)) + '">' +
        '<span class="ps-top">' + meter(f.rate) + '<b class="mono">' + O.esc(pctText(f.rate)) + "</b></span>" +
        '<span class="ps-sub"><span class="mono">' + n[0] + "/" + n[2] + "</span>" + miss + extra + "</span></button>";
    }

    function sorted() {
      var rows = data.rows.slice();
      if (!st.sort) return rows;
      var k = st.sort, dir = st.dir;
      rows.sort(function (a, b) {
        if (k === "name") return dir * a.name.localeCompare(b.name);
        var x = a[k].rate, y = b[k].rate;
        if (x === null && y === null) return 0;
        if (x === null) return 1;
        if (y === null) return -1;
        return dir * (x - y) || (nums(k, b[k])[1] - nums(k, a[k])[1]);
      });
      return rows;
    }

    function th(key, label, cls) {
      var on = st.sort === key;
      return '<th class="' + (cls || "") + '" aria-sort="' + (on ? (st.dir > 0 ? "ascending" : "descending") : "none") + '"><button type="button" class="ps-sort' + (on ? " on" : "") + '" data-sort="' + key + '" title="' + O.esc(t("perf.st.sort")) + '">' +
        O.esc(label) + (on ? (st.dir > 0 ? " ▲" : " ▼") : "") + "</button></th>";
    }

    function table() {
      var ks = shownKinds();
      var head = "<tr>" + th("name", t("perf.st.col.person")) + "<th>" + O.esc(t("perf.st.col.dept")) + "</th>" + ks.map(function (k) { return th(k, kindName(k), "ps-c"); }).join("") + "</tr>";
      var body = sorted().map(function (r) {
        return "<tr><td><b>" + O.esc(r.name) + "</b></td><td class=\"muted\">" + O.esc(r.dept || "—") + "</td>" + ks.map(function (k) { return '<td class="ps-c">' + cell(k, r[k], r.person_id) + "</td>"; }).join("") + "</tr>";
      }).join("");
      var foot = data.rows.length ? '<tfoot><tr><td colspan="2"><b>' + O.esc(t("perf.st.total_people", { n: data.people })) + "</b></td>" +
        ks.map(function (k) { return '<td class="ps-c">' + cell(k, data.total[k], null) + "</td>"; }).join("") + "</tr></tfoot>" : "";
      card.innerHTML = '<div class="card-h">' + O.esc(t("perf.st.by_person")) + '<span class="r muted" style="font-size:12px">' + O.esc(rangeText(data.range)) + "</span></div>" +
        '<div class="tw"><table class="tbl ps-tbl"><thead>' + head + "</thead><tbody>" +
        (body || '<tr class="empty"><td colspan="' + (ks.length + 2) + '">' + O.esc(t("perf.st.empty")) + "</td></tr>") + "</tbody>" + foot + "</table></div>" +
        '<div class="card-b muted ps-rules">' + O.esc(t("perf.st.rules")) + "</div>";
    }

    function rangeText(r) {
      if (!r.from && !r.to) return t("perf.st.range_all", { today: r.today });
      return (r.from || "…") + " – " + (r.to || "…");
    }

    /* ---------- the list behind a figure ---------- */
    function items(kind, row, state0) {
      var plans = kind === "plans";
      var ist = { state: state0 || "", page: 1 };
      var box = document.createElement("div");
      box.innerHTML = '<div class="bar" style="margin-bottom:8px"><span class="seg ps-seg" role="group"></span><span class="sp"></span><span class="muted" style="font-size:12px">' +
        O.esc(rangeText(data.range)) + '</span></div><div class="ps-items"></div>';
      var who = row ? row.name : (st.person_id ? personName(st.person_id) : t("perf.st.everyone"));
      O.modal({ title: t("perf.st.items_title", { who: who, kind: kindName(kind) }), width: 980, body: box });
      box.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-st]") : null;
        if (b) { ist.state = b.getAttribute("data-st"); ist.page = 1; fetch(); }
      });
      function fetch() {
        var q = query();
        q.kind = kind;
        q.state = ist.state;
        q.page = ist.page;
        if (row) q.person_id = row.person_id;
        O.api("GET", "/api/v1/perf/stats/items", null, q).then(draw, O.failed);
      }
      function draw(res) {
        var states = plans ? ["", "executed", "none", "future", "overdue"] : ["", "submitted", "missing"];
        box.querySelector(".ps-seg").innerHTML = states.map(function (s) {
          var n = s === "" ? res.counts.all : (res.counts[s] || 0);
          return '<button type="button" data-st="' + s + '" aria-pressed="' + (ist.state === s) + '"' + (ist.state === s ? ' class="on"' : "") + ">" +
            O.esc(t("perf.st.state." + (s || "all"))) + " " + n + "</button>";
        }).join("");
        var cols = plans ? planCols(!row) : repCols(kind, !row);
        var el = box.querySelector(".ps-items");
        el.innerHTML = "";
        el.appendChild(O.table({ cols: cols, rows: res.rows, empty: t("perf.st.items_empty"), slots: 1, acts: function (x) {
          return [x.plan ? { icon: "eye", tip: t("perf.st.view_plan"), fn: function (y) { O.plan.openDetail(y.plan.id); } } : null];
        } }));
        el.appendChild(O.pager(res, function (p) { ist.page = p; fetch(); }));
      }
      fetch();
    }

    function personName(id) {
      for (var i = 0; i < lists.people.length; i++) if (String(lists.people[i].id) === String(id)) return lists.people[i].name;
      return "";
    }

    function planCols(withPerson) {
      var cols = [{ t: t("perf.st.col.plan"), cls: "wrap", f: function (x) {
        return O.plan.levelPill(x.plan.level) + " " + O.esc(x.plan.title) + (x.overdue ? " " + O.pill(t("perf.st.state.overdue"), "warn") : ""); } },
        { t: t("perf.st.col.time"), f: function (x) { return '<span class="mono">' + O.esc(O.plan.label(x.plan)) + "</span>"; } }];
      if (withPerson) cols.push({ t: t("perf.st.col.person"), f: function (x) { return O.esc(x.person) + (x.dept ? '<div class="muted" style="font-size:12px">' + O.esc(x.dept) + "</div>" : ""); } });
      cols.push({ t: t("perf.st.col.assigned_by"), f: function (x) { return O.esc(x.assigned_by || "—"); } });
      cols.push({ t: t("perf.st.col.status"), f: function (x) {
        var kind = { done: "ok", doing: "info", none: "bad", future: "" }[x.state];
        return O.pill(t("perf.st.state." + x.state), kind) + (x.via_sub ? '<div class="muted" style="font-size:12px">' + O.esc(t("perf.st.via_sub")) + "</div>" : "");
      } });
      cols.push({ t: t("perf.st.col.progress"), cls: "num", f: function (x) { return O.esc(x.progress === null ? "—" : O.perf.pct(x.progress)); } });
      cols.push({ t: t("perf.st.col.last"), f: function (x) { return '<span class="mono">' + O.esc(x.last_at || "—") + "</span>"; } });
      return cols;
    }

    function repCols(kind, withPerson) {
      var cols = [{ t: t("perf.st.col.period"), f: function (x) {
        var lbl = kind === "day" ? x.start : O.plan.autoLabel(kind, x.start, x.end);
        return '<span class="mono">' + O.esc(lbl) + "</span>" + (kind === "day" ? "" : '<div class="muted mono" style="font-size:12px">' + O.esc(x.start + " – " + x.end) + "</div>");
      } }];
      if (withPerson) cols.push({ t: t("perf.st.col.person"), f: function (x) { return O.esc(x.person) + (x.dept ? '<div class="muted" style="font-size:12px">' + O.esc(x.dept) + "</div>" : ""); } });
      if (kind !== "day") cols.push({ t: t("perf.st.col.due"), f: function (x) { return '<span class="mono">' + O.esc(x.due) + "</span>"; } });
      cols.push({ t: t("perf.st.col.status"), f: function (x) {
        var k = { approved: "ok", submitted: "info", returned: "warn", draft: "bad", withdrawn: "bad", none: "bad", no_plan: "bad" }[x.status];
        return O.pill(t("perf.st.rs." + x.status), k) + (x.early ? " " + O.pill(t("perf.st.early"), "xs") : "");
      } });
      cols.push({ t: t("perf.st.col.plan"), cls: "wrap", f: function (x) { return x.plan ? O.esc(x.plan.title) : '<span class="muted">—</span>'; } });
      cols.push({ t: t("perf.st.col.submitted"), f: function (x) { return '<span class="mono">' + O.esc(x.submitted_at || "—") + "</span>"; } });
      return cols;
    }

    load();
  }

  O.perfStats = { mount: mount, CYCLES: CYCLES };
})();
